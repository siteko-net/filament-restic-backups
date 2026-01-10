<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Siteko\FilamentResticBackups\Exceptions\ResticProcessException;
use Siteko\FilamentResticBackups\Models\BackupRun;
use Siteko\FilamentResticBackups\Models\BackupSetting;
use Siteko\FilamentResticBackups\Services\ResticRunner;
use Siteko\FilamentResticBackups\Support\OperationLock;
use Siteko\FilamentResticBackups\Support\OperationLockHandle;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class RunBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const LOCK_TTL_SECONDS = 7200;
    private const META_OUTPUT_LIMIT = 204800;
    private const LOCK_BLOCK_SECONDS = 30;
    private const REQUEUE_DELAYS = [60, 120, 300];

    public int $timeout = 7200;
    public int $tries = 1;
    public array $backoff = [60];

    /**
     * @param  array<int, string>  $tags
     */
    public function __construct(
        public array $tags = [],
        public ?string $trigger = null,
        public ?string $connectionName = null,
        public bool $runRetention = true,
    ) {
    }

    public function handle(OperationLock $operationLock, ResticRunner $runner): void
    {
        $lockHandle = $operationLock->acquire(
            'backup',
            $this->lockTtl(),
            self::LOCK_BLOCK_SECONDS,
            [
                'trigger' => $this->normalizeTrigger($this->trigger),
                'tags' => $this->normalizeTags($this->tags),
                'connection' => $this->connectionName ?? (string) config('database.default'),
            ],
        );

        if (! $lockHandle instanceof OperationLockHandle) {
            $this->requeueOrReturn();

            return;
        }

        $run = null;
        $meta = [];
        $step = null;
        $settings = null;

        try {
            $settings = BackupSetting::singleton();
            $projectRoot = $this->resolveProjectRoot($settings);
            $connectionName = $this->connectionName ?? (string) config('database.default');
            $dumpPath = storage_path('app/_backup/db.sql.gz');

            $meta = [
                'trigger' => $this->normalizeTrigger($this->trigger),
                'tags' => $this->normalizeTags($this->tags),
                'project_root' => $projectRoot,
                'dump_path' => $dumpPath,
                'connection' => $connectionName,
                'host' => $this->hostname(),
                'app_env' => (string) config('app.env'),
            ];

            $run = BackupRun::query()->create([
                'type' => 'backup',
                'status' => 'running',
                'started_at' => now(),
                'meta' => $meta,
            ]);
            $lockHandle->setRunId($run->id);

            $step = 'dump';
            $lockHandle->heartbeat(['step' => $step]);
            $dumpMeta = $this->dumpDatabase($connectionName, $dumpPath);
            $dumpMeta = $this->normalizeDumpMeta($dumpMeta);
            $meta['dump'] = $dumpMeta;
            $run->update(['meta' => $meta]);

            if (($dumpMeta['exit_code'] ?? 1) !== 0) {
                throw new \RuntimeException('Database dump failed.');
            }

            $step = 'restic_backup';
            $lockHandle->heartbeat(['step' => $step]);
            $backupTags = $this->buildTags($meta['tags'], $meta['trigger']);
            $meta['tags'] = $backupTags;
            $backupResult = $runner->backup(
                $projectRoot,
                $backupTags,
                [
                    'timeout' => $this->timeout,
                    'capture_output' => true,
                    'max_output_bytes' => self::META_OUTPUT_LIMIT,
                    'heartbeat' => function (array $context = []) use ($lockHandle, $step): void {
                        $lockHandle->heartbeat(array_merge(['step' => $step], $context));
                    },
                    'heartbeat_every' => 20,
                ],
            );

            $meta['backup'] = $this->formatProcessResult($backupResult);
            $run->update(['meta' => $meta]);

            if ($backupResult->exitCode !== 0) {
                throw new ResticProcessException($backupResult);
            }

            if (! $this->runRetention) {
                $meta['retention'] = [
                    'skipped' => true,
                    'reason' => 'disabled',
                ];
            } else {
                $retention = is_array($settings->retention) ? $settings->retention : [];

                if ($retention === []) {
                    $meta['retention'] = [
                        'skipped' => true,
                        'reason' => 'empty_retention',
                    ];
                } else {
                    $step = 'retention';
                    $lockHandle->heartbeat(['step' => $step]);
                    $forgetResult = $runner->forget($retention, [
                        'prune' => true,
                        'timeout' => $this->timeout,
                        'capture_output' => true,
                        'max_output_bytes' => self::META_OUTPUT_LIMIT,
                        'heartbeat' => function (array $context = []) use ($lockHandle, $step): void {
                            $lockHandle->heartbeat(array_merge(['step' => $step], $context));
                        },
                        'heartbeat_every' => 20,
                    ]);

                    $meta['retention'] = $this->formatProcessResult($forgetResult);
                    $run->update(['meta' => $meta]);

                    if ($forgetResult->exitCode !== 0) {
                        throw new ResticProcessException($forgetResult);
                    }
                }
            }

            $run->update([
                'status' => 'success',
                'finished_at' => now(),
                'meta' => $meta,
            ]);
        } catch (Throwable $exception) {
            if ($run instanceof BackupRun) {
                $meta['error_class'] = $exception::class;
                $meta['error_message'] = $this->sanitizeErrorMessage($exception->getMessage(), $settings);
                $meta['step'] = $step;

                $run->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'meta' => $meta,
                ]);
            }

            throw $exception;
        } finally {
            $lockHandle->release();
        }
    }

    protected function lockTtl(): int
    {
        return max(self::LOCK_TTL_SECONDS, $this->timeout);
    }

    protected function requeueOrReturn(): void
    {
        if (! $this->job) {
            return;
        }

        if (($this->connection ?? config('queue.default')) === 'sync') {
            return;
        }

        $delay = $this->nextRequeueDelay();

        $pending = self::dispatch($this->tags, $this->trigger, $this->connectionName, $this->runRetention)
            ->delay($delay);

        if ($this->queue) {
            $pending->onQueue($this->queue);
        }

        if ($this->connection) {
            $pending->onConnection($this->connection);
        }
    }

    protected function nextRequeueDelay(): int
    {
        $attempt = method_exists($this, 'attempts') ? max(1, (int) $this->attempts()) : 1;
        $index = min($attempt - 1, count(self::REQUEUE_DELAYS) - 1);

        return self::REQUEUE_DELAYS[$index] ?? 60;
    }

    /**
     * @param  array<string, mixed>  $dumpMeta
     * @return array<string, mixed>
     */
    protected function normalizeDumpMeta(array $dumpMeta): array
    {
        if (($dumpMeta['driver'] ?? null) !== 'mysql') {
            return $dumpMeta;
        }

        if (($dumpMeta['exit_code'] ?? 0) === 0) {
            return $dumpMeta;
        }

        $sizeBytes = (int) ($dumpMeta['size_bytes'] ?? 0);
        if ($sizeBytes <= 0) {
            return $dumpMeta;
        }

        $warningFlags = $this->mysqlDumpWarningFlagsFromStderr((string) ($dumpMeta['stderr'] ?? ''));
        if ($warningFlags === []) {
            return $dumpMeta;
        }

        $dumpMeta['exit_code'] = 0;
        $dumpMeta['warnings'] = array_values(array_merge(
            $dumpMeta['warnings'] ?? [],
            [
                'Mysql dump completed with permission warnings for: ' . implode(', ', $warningFlags),
            ],
        ));

        return $dumpMeta;
    }

    protected function resolveProjectRoot(BackupSetting $settings): string
    {
        $projectRoot = $this->normalizeScalar($settings->project_root)
            ?? $this->normalizeScalar(config('restic-backups.paths.project_root', base_path()))
            ?? base_path();

        return $projectRoot;
    }

    protected function normalizeTrigger(?string $trigger): string
    {
        $trigger = $this->normalizeScalar($trigger) ?? 'manual';

        if (! in_array($trigger, ['manual', 'schedule', 'system'], true)) {
            return 'manual';
        }

        return $trigger;
    }

    /**
     * @param  array<int, string>  $tags
     * @return array<int, string>
     */
    protected function buildTags(array $tags, string $trigger): array
    {
        $defaults = [
            'app:' . $this->normalizeTagValue((string) config('app.name')),
            'env:' . $this->normalizeTagValue((string) config('app.env')),
            'host:' . $this->normalizeTagValue($this->hostname()),
            'trigger:' . $trigger,
            'type:backup',
        ];

        $merged = array_merge($defaults, $tags);
        $normalized = $this->normalizeTags($merged);

        return array_values(array_unique($normalized));
    }

    /**
     * @param  array<int, string>  $tags
     * @return array<int, string>
     */
    protected function normalizeTags(array $tags): array
    {
        $normalized = [];

        foreach ($tags as $tag) {
            if (! is_string($tag) && ! is_numeric($tag)) {
                continue;
            }

            $value = trim((string) $tag);

            if ($value === '') {
                continue;
            }

            $normalized[] = $value;
        }

        return $normalized;
    }

    protected function normalizeTagValue(string $value): string
    {
        $value = Str::of($value)->replace(' ', '-')->lower()->toString();

        return $value === '' ? 'unknown' : $value;
    }

    protected function hostname(): string
    {
        $host = gethostname();

        return is_string($host) && $host !== '' ? $host : 'unknown';
    }

    protected function dumpDatabase(string $connectionName, string $dumpPath): array
    {
        $connection = config("database.connections.{$connectionName}");

        if (! is_array($connection)) {
            throw new \RuntimeException("Database connection [{$connectionName}] not found.");
        }

        $driver = $this->normalizeScalar($connection['driver'] ?? null);

        if ($driver === null) {
            throw new \RuntimeException("Database driver for [{$connectionName}] is not configured.");
        }

        return match ($driver) {
            'mysql', 'mariadb' => $this->dumpMysql($connectionName, $connection, $dumpPath),
            'pgsql' => $this->dumpPostgres($connection, $dumpPath),
            'sqlite' => $this->dumpSqlite($connection, $dumpPath),
            default => throw new \RuntimeException("Database driver [{$driver}] is not supported for dumps."),
        };
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array<string, mixed>
     */
    protected function dumpMysql(string $connectionName, array $connection, string $dumpPath): array
    {
        $isMariaDb = $this->isMariaDbConnection($connectionName, $connection);
        $binary = $this->findBinaryFromCandidates(
            $isMariaDb ? ['mariadb-dump', 'mysqldump'] : ['mysqldump', 'mariadb-dump'],
        );

        $baseCommand = [
            $binary,
            '--single-transaction',
            '--quick',
        ];

        if (! $isMariaDb) {
            $baseCommand[] = '--set-gtid-purged=OFF';
        }

        $host = $this->normalizeScalar($connection['host'] ?? null);
        $port = $this->normalizeScalar($connection['port'] ?? null);
        $user = $this->normalizeScalar($connection['username'] ?? null);
        $database = $this->normalizeScalar($connection['database'] ?? null);
        $socket = $this->normalizeScalar($connection['unix_socket'] ?? null);
        $prefix = $this->normalizeScalar($connection['prefix'] ?? null);

        if ($database === null) {
            throw new \RuntimeException('Database name is not configured for the dump.');
        }

        $optionalFlags = ['--routines', '--triggers', '--events'];
        $connectionParams = [
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'database' => $database,
            'socket' => $socket,
        ];

        $env = [];
        $password = $this->normalizeScalar($connection['password'] ?? null);

        if ($password !== null) {
            $env['MYSQL_PWD'] = $password;
        }

        $ignoreFlags = $this->buildIgnoreTableFlags($database, $prefix, $this->dumpExcludeTables());
        $command = $this->buildMysqlDumpCommand($baseCommand, $connectionParams, $optionalFlags, $ignoreFlags);
        $result = $this->streamDumpProcess($command, $env, $dumpPath, 'mysql');

        if (($result['exit_code'] ?? 1) !== 0) {
            $reducedFlags = $this->filterMysqlDumpFlags($optionalFlags, (string) ($result['stderr'] ?? ''));

            if ($reducedFlags !== $optionalFlags) {
                $command = $this->buildMysqlDumpCommand($baseCommand, $connectionParams, $reducedFlags, $ignoreFlags);
                $retry = $this->streamDumpProcess($command, $env, $dumpPath, 'mysql');

                if (($retry['exit_code'] ?? 1) === 0) {
                    $retry['warnings'] = array_values(array_merge(
                        $retry['warnings'] ?? [],
                        $this->mysqlDumpWarningsFromFlags($optionalFlags, $reducedFlags),
                    ));

                    return $retry;
                }

                return $this->acceptMysqlPermissionWarnings($retry, $optionalFlags, $reducedFlags);
            }

            return $this->acceptMysqlPermissionWarnings($result, $optionalFlags, $optionalFlags);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array<string, mixed>
     */
    protected function dumpPostgres(array $connection, string $dumpPath): array
    {
        $binary = $this->findBinary('pg_dump');

        $command = [
            $binary,
            '--format=plain',
            '--no-owner',
            '--no-privileges',
        ];

        $host = $this->normalizeScalar($connection['host'] ?? null);
        $port = $this->normalizeScalar($connection['port'] ?? null);
        $user = $this->normalizeScalar($connection['username'] ?? null);
        $database = $this->normalizeScalar($connection['database'] ?? null);

        if ($database === null) {
            throw new \RuntimeException('Database name is not configured for the dump.');
        }

        if ($host !== null) {
            $command[] = '--host=' . $host;
        }

        if ($port !== null) {
            $command[] = '--port=' . $port;
        }

        if ($user !== null) {
            $command[] = '--username=' . $user;
        }

        $command[] = '--dbname=' . $database;

        $env = [];
        $password = $this->normalizeScalar($connection['password'] ?? null);

        if ($password !== null) {
            $env['PGPASSWORD'] = $password;
        }

        return $this->streamDumpProcess($command, $env, $dumpPath, 'pgsql');
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array<string, mixed>
     */
    protected function dumpSqlite(array $connection, string $dumpPath): array
    {
        $database = $this->normalizeScalar($connection['database'] ?? null);

        if ($database === null || $database === ':memory:') {
            throw new \RuntimeException('SQLite database path is not configured for the dump.');
        }

        $this->ensureDirectory(dirname($dumpPath));

        $start = microtime(true);
        $bytes = 0;
        $stderr = '';

        $source = @fopen($database, 'rb');
        if ($source === false) {
            throw new \RuntimeException('Unable to read SQLite database file.');
        }

        $target = @gzopen($dumpPath, 'wb9');
        if ($target === false) {
            fclose($source);
            throw new \RuntimeException('Unable to write SQLite dump file.');
        }

        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);

                if ($chunk === false) {
                    throw new \RuntimeException('Failed to read SQLite database file.');
                }

                if ($chunk === '') {
                    continue;
                }

                $written = gzwrite($target, $chunk);

                if ($written === false) {
                    throw new \RuntimeException('Failed to write SQLite dump file.');
                }

                $bytes += $written;
            }
        } finally {
            fclose($source);
            gzclose($target);
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $sizeBytes = file_exists($dumpPath) ? (int) filesize($dumpPath) : $bytes;

        return [
            'driver' => 'sqlite',
            'exit_code' => 0,
            'duration_ms' => $durationMs,
            'stderr' => $stderr,
            'size_bytes' => $sizeBytes,
            'command' => 'sqlite-copy',
        ];
    }

    /**
     * @param  array<int, string>  $command
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    protected function streamDumpProcess(array $command, array $env, string $dumpPath, string $driver): array
    {
        $this->ensureDirectory(dirname($dumpPath));

        $start = microtime(true);
        $stderr = '';

        $handle = @gzopen($dumpPath, 'wb9');
        if ($handle === false) {
            throw new \RuntimeException('Unable to write database dump file.');
        }

        $process = new Process($command, null, $env, null, (float) $this->timeout);

        try {
            $exitCode = $process->run(function (string $type, string $buffer) use (&$stderr, $handle): void {
                if ($type === Process::OUT) {
                    $written = gzwrite($handle, $buffer);

                    if ($written === false) {
                        throw new \RuntimeException('Failed to write database dump output.');
                    }

                    return;
                }

                $stderr .= $buffer;

                if (strlen($stderr) > self::META_OUTPUT_LIMIT) {
                    $stderr = substr($stderr, 0, self::META_OUTPUT_LIMIT);
                }
            });
        } finally {
            gzclose($handle);
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $sizeBytes = file_exists($dumpPath) ? (int) filesize($dumpPath) : 0;

        return [
            'driver' => $driver,
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
            'stderr' => $this->truncateString($stderr, self::META_OUTPUT_LIMIT),
            'size_bytes' => $sizeBytes,
            'command' => $this->safeCommandString($command),
        ];
    }

    /**
     * @param  array<int, string>  $baseCommand
     * @param  array{host: ?string, port: ?string, user: ?string, database: string, socket: ?string}  $params
     * @param  array<int, string>  $extraFlags
     * @param  array<int, string>  $ignoreFlags
     * @return array<int, string>
     */
    protected function buildMysqlDumpCommand(array $baseCommand, array $params, array $extraFlags, array $ignoreFlags = []): array
    {
        $command = array_merge($baseCommand, $extraFlags, $ignoreFlags);

        $host = $params['host'];
        $port = $params['port'];
        $user = $params['user'];
        $database = $params['database'];
        $socket = $params['socket'];

        $hostNormalized = $host !== null ? strtolower($host) : null;
        $useSocket = $socket !== null && ($hostNormalized === null || $hostNormalized === 'localhost');

        if ($useSocket) {
            $command[] = '--socket=' . $socket;
        } else {
            if ($hostNormalized !== null && $hostNormalized !== 'localhost') {
                $command[] = '--host=' . $host;
            }

            if ($port !== null && $hostNormalized !== 'localhost') {
                $command[] = '--port=' . $port;
            }
        }

        if ($user !== null) {
            $command[] = '--user=' . $user;
        }

        $command[] = $database;

        return $command;
    }

    /**
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    protected function buildIgnoreTableFlags(string $database, ?string $prefix, array $tables): array
    {
        $flags = [];

        foreach ($tables as $table) {
            $table = $this->normalizeTableName($table);

            if ($table === null) {
                continue;
            }

            if ($prefix !== null && $prefix !== '' && ! str_starts_with($table, $prefix)) {
                $table = $prefix . $table;
            }

            $flags[] = '--ignore-table=' . $database . '.' . $table;
        }

        return array_values(array_unique($flags));
    }

    /**
     * @return array<int, string>
     */
    protected function dumpExcludeTables(): array
    {
        $tables = config('restic-backups.database.exclude_from_dumps', []);

        if (! is_array($tables)) {
            $tables = [];
        }

        $tables = array_merge($tables, $this->internalTables());

        return $this->normalizeTableNames($tables);
    }

    /**
     * @return array<int, string>
     */
    protected function internalTables(): array
    {
        $tables = config('restic-backups.database.preserve_tables', [
            'backup_runs',
            'backup_settings',
        ]);

        return $this->normalizeTableNames(is_array($tables) ? $tables : []);
    }

    /**
     * @param  array<int, mixed>  $tables
     * @return array<int, string>
     */
    protected function normalizeTableNames(array $tables): array
    {
        $normalized = [];

        foreach ($tables as $table) {
            $name = $this->normalizeTableName($table);

            if ($name === null) {
                continue;
            }

            $normalized[] = $name;
        }

        return array_values(array_unique($normalized));
    }

    protected function normalizeTableName(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $name = trim((string) $value);

        if ($name === '') {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_]+$/', $name) === 1 ? $name : null;
    }

    /**
     * @param  array<int, string>  $flags
     * @return array<int, string>
     */
    protected function filterMysqlDumpFlags(array $flags, string $stderr): array
    {
        $remove = $this->mysqlDumpWarningFlagsFromStderr($stderr);

        if ($remove === []) {
            return $flags;
        }

        return array_values(array_diff($flags, $remove));
    }

    /**
     * @return array<int, string>
     */
    protected function mysqlDumpWarningFlagsFromStderr(string $stderr): array
    {
        $message = strtolower($stderr);

        if (! str_contains($message, 'access denied')) {
            return [];
        }

        $flags = [];

        if (str_contains($message, 'show events')) {
            $flags[] = '--events';
        }

        if (str_contains($message, 'show triggers')) {
            $flags[] = '--triggers';
        }

        if (str_contains($message, 'show create routine') || str_contains($message, 'show create function')) {
            $flags[] = '--routines';
        }

        return $flags;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<int, string>  $originalFlags
     * @param  array<int, string>  $finalFlags
     * @return array<string, mixed>
     */
    protected function acceptMysqlPermissionWarnings(array $result, array $originalFlags, array $finalFlags): array
    {
        if (($result['exit_code'] ?? 1) === 0) {
            return $result;
        }

        $warningFlags = $this->mysqlDumpWarningFlagsFromStderr((string) ($result['stderr'] ?? ''));

        if ($warningFlags === []) {
            return $result;
        }

        $sizeBytes = (int) ($result['size_bytes'] ?? 0);

        if ($sizeBytes <= 0) {
            return $result;
        }

        $result['exit_code'] = 0;
        $warnings = $this->mysqlDumpWarningsFromFlags($originalFlags, $finalFlags);

        if ($warnings === []) {
            $warnings[] = 'Mysql dump completed with permission warnings for: ' . implode(', ', $warningFlags);
        }

        $result['warnings'] = array_values(array_merge(
            $result['warnings'] ?? [],
            $warnings,
        ));

        return $result;
    }

    /**
     * @param  array<int, string>  $originalFlags
     * @param  array<int, string>  $finalFlags
     * @return array<int, string>
     */
    protected function mysqlDumpWarningsFromFlags(array $originalFlags, array $finalFlags): array
    {
        $removed = array_values(array_diff($originalFlags, $finalFlags));

        if ($removed === []) {
            return [];
        }

        return [
            'Mysql dump retried without: ' . implode(', ', $removed),
        ];
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    protected function isMariaDbConnection(string $connectionName, array $connection): bool
    {
        $driver = $this->normalizeScalar($connection['driver'] ?? null);

        if ($driver === 'mariadb') {
            return true;
        }

        if ($driver !== 'mysql') {
            return false;
        }

        try {
            $versionRow = DB::connection($connectionName)->selectOne('select version() as version');
        } catch (Throwable) {
            return false;
        }

        $version = null;

        if (is_object($versionRow) && isset($versionRow->version)) {
            $version = $versionRow->version;
        } elseif (is_array($versionRow)) {
            $version = $versionRow['version'] ?? null;
        }

        if (! is_string($version)) {
            return false;
        }

        return stripos($version, 'mariadb') !== false;
    }

    /**
     * @param  array<int, string>  $candidates
     */
    protected function findBinaryFromCandidates(array $candidates): string
    {
        $finder = new ExecutableFinder();

        foreach ($candidates as $candidate) {
            $path = $finder->find($candidate);

            if ($path !== null) {
                return $path;
            }
        }

        $list = implode(', ', $candidates);

        throw new \RuntimeException("Binary [{$list}] not found.");
    }

    protected function findBinary(string $binary): string
    {
        $finder = new ExecutableFinder();
        $path = $finder->find($binary);

        if ($path === null) {
            throw new \RuntimeException("Binary [{$binary}] not found.");
        }

        return $path;
    }

    protected function ensureDirectory(string $path): void
    {
        $path = trim($path);

        if ($path === '') {
            return;
        }

        if (! is_dir($path) && ! @mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new \RuntimeException("Unable to create directory [{$path}].");
        }
    }

    protected function normalizeScalar(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return null;
    }

    protected function truncateString(string $value, int $limit): string
    {
        if ($value === '') {
            return $value;
        }

        if ($limit <= 0) {
            return '';
        }

        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit) . PHP_EOL . '...[truncated]';
    }

    /**
     * @param  array<int, string>  $command
     */
    protected function safeCommandString(array $command): string
    {
        $escaped = array_map(function (string $argument): string {
            if ($argument === '') {
                return "''";
            }

            if (preg_match('/\s|["\\\\]/', $argument) !== 1) {
                return $argument;
            }

            return '"' . addcslashes($argument, '"\\') . '"';
        }, $command);

        return implode(' ', $escaped);
    }

    protected function formatProcessResult(\Siteko\FilamentResticBackups\DTO\ProcessResult $result): array
    {
        return [
            'exit_code' => $result->exitCode,
            'duration_ms' => $result->durationMs,
            'stdout' => $this->truncateString($result->stdout, self::META_OUTPUT_LIMIT),
            'stderr' => $this->truncateString($result->stderr, self::META_OUTPUT_LIMIT),
            'command' => $result->safeCommandString(),
        ];
    }

    protected function sanitizeErrorMessage(string $message, ?BackupSetting $settings): string
    {
        $message = $this->truncateString($message, self::META_OUTPUT_LIMIT);

        if (! $settings instanceof BackupSetting) {
            return $message;
        }

        $secrets = [
            $this->normalizeScalar($settings->access_key),
            $this->normalizeScalar($settings->secret_key),
            $this->normalizeScalar($settings->restic_password),
        ];

        foreach ($secrets as $secret) {
            if ($secret === null || $secret === '') {
                continue;
            }

            $message = str_replace($secret, '***', $message);
        }

        $repository = $this->normalizeScalar($settings->restic_repository);

        if ($repository !== null && str_contains($repository, '@')) {
            $redacted = preg_replace('#(://)([^/]*):([^@]*)@#', '$1***:***@', $repository) ?? $repository;
            $message = str_replace($repository, $redacted, $message);
        }

        return $message;
    }
}
