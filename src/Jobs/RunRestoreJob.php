<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Siteko\FilamentResticBackups\DTO\ProcessResult;
use Siteko\FilamentResticBackups\Exceptions\ResticConfigurationException;
use Siteko\FilamentResticBackups\Exceptions\ResticProcessException;
use Siteko\FilamentResticBackups\Models\BackupRun;
use Siteko\FilamentResticBackups\Models\BackupSetting;
use Siteko\FilamentResticBackups\Services\ResticRunner;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class RunRestoreJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const LOCK_KEY = 'restic-backups:operation';
    private const LOCK_TTL_SECONDS = 21600;
    private const META_OUTPUT_LIMIT = 204800;
    private const ROLLBACK_CLEANUP_DELAY_HOURS = 24;

    public int $timeout = 21600;
    public int $tries = 1;
    public array $backoff = [60];

    public function __construct(
        public string $snapshotId,
        public string $scope,
        public ?string $mode = null,
        public bool $safetyBackup = true,
        public ?string $trigger = 'manual',
        public ?string $dbConnection = null,
    ) {
    }

    public function handle(ResticRunner $runner): void
    {
        $lock = Cache::lock(self::LOCK_KEY, $this->lockTtl());

        if (! $lock->get()) {
            $this->recordSkippedRun();

            return;
        }

        $run = null;
        $meta = [];
        $step = null;
        $settings = null;
        $scope = null;
        $projectRoot = null;
        $stageTargetDir = null;
        $stagingSwapDir = null;
        $rollbackDir = null;
        $maintenanceStarted = false;
        $maintenanceCompleted = false;
        $swapCompleted = false;
        $rollbackAttempted = false;
        $rollbackSuccess = null;
        $dbWiped = false;
        $dbRollbackAttempted = false;
        $dbRollbackSuccess = null;
        $safetyDumpPath = null;
        $cleanupPaths = [];
        $connectionName = null;

        try {
            $settings = BackupSetting::singleton();
            $scope = $this->normalizeScope($this->scope);
            $mode = $this->normalizeMode($this->mode, $scope);
            $projectRoot = $this->resolveProjectRoot($settings);
            $connectionName = $this->dbConnection ?? (string) config('database.default');

            $meta = [
                'trigger' => $this->normalizeTrigger($this->trigger),
                'scope' => $scope,
                'mode' => $mode,
                'safety_backup' => $this->safetyBackup,
                'snapshot_id' => $this->snapshotId,
                'project_root' => $projectRoot,
                'connection' => $connectionName,
                'host' => $this->hostname(),
                'app_env' => (string) config('app.env'),
            ];

            $run = BackupRun::query()->create([
                'type' => 'restore',
                'status' => 'running',
                'started_at' => now(),
                'meta' => $meta,
            ]);

            $step = 'preflight';
            $versionResult = $runner->version();
            $meta['steps']['restic_version'] = $this->formatProcessResult($versionResult);
            $run->update(['meta' => $meta]);

            if ($versionResult->exitCode !== 0) {
                throw new ResticProcessException($versionResult);
            }

            $snapshotsResult = $runner->snapshots();
            $meta['steps']['restic_snapshots'] = $this->formatProcessResult($snapshotsResult);
            $run->update(['meta' => $meta]);

            if ($snapshotsResult->exitCode !== 0 || ! is_array($snapshotsResult->parsedJson)) {
                throw new ResticProcessException($snapshotsResult);
            }

            $snapshotInfo = $this->resolveSnapshot($snapshotsResult->parsedJson, $this->snapshotId);
            $meta['snapshot'] = $snapshotInfo;
            $run->update(['meta' => $meta]);

            if ($this->requiresFiles($scope)) {
                $this->ensureExistingDirectory($projectRoot, mustBeWritable: true, context: 'project_root');
            }

            if ($this->requiresDb($scope)) {
                $this->verifyDbConnection($connectionName);
            }

            if ($this->requiresFiles($scope) && $mode === 'atomic') {
                $step = 'preflight_fs';
                $sameFs = $this->sameFilesystem($projectRoot, dirname($projectRoot));
                $meta['steps']['preflight_fs'] = [
                    'exit_code' => $sameFs ? 0 : 1,
                    'same_filesystem' => $sameFs,
                    'project_root' => $projectRoot,
                    'staging_parent' => dirname($projectRoot),
                    'duration_ms' => 0,
                ];
                $run->update(['meta' => $meta]);

                if (! $sameFs) {
                    throw new \RuntimeException('Atomic swap requires staging on the same filesystem.');
                }
            }

            $step = 'preflight_space';
            $spaceMeta = $this->preflightSpace($runner, $snapshotInfo['id'], $projectRoot, $scope);
            $meta['steps']['preflight_space'] = $spaceMeta;
            $run->update(['meta' => $meta]);

            if (! ($spaceMeta['ok'] ?? false)) {
                throw new \RuntimeException('Insufficient disk space for restore.');
            }

            $step = 'stage_restic_restore';
            $stageTargetDir = $this->makeStagingTargetDir($projectRoot, $run->id ?? null);
            $cleanupPaths[] = $stageTargetDir;

            $restoreResult = $runner->restore(
                $snapshotInfo['id'],
                $stageTargetDir,
                [
                    'timeout' => $this->timeout,
                    'capture_output' => true,
                    'max_output_bytes' => self::META_OUTPUT_LIMIT,
                ],
            );

            $meta['steps']['stage_restic_restore'] = $this->formatProcessResult($restoreResult);
            $run->update(['meta' => $meta]);

            if ($restoreResult->exitCode !== 0) {
                throw new ResticProcessException($restoreResult);
            }

            $restoredProjectPath = $this->resolveRestoredProjectPath($stageTargetDir, $projectRoot);

            if (! is_dir($restoredProjectPath)) {
                throw new \RuntimeException('Restored project path was not found in the snapshot.');
            }

            $stagingSwapDir = $this->makeStagingSwapDir($projectRoot, $run->id ?? null);
            $stageMove = $this->moveDirectory($restoredProjectPath, $stagingSwapDir, dirname($projectRoot));
            $meta['steps']['stage_prepare'] = $stageMove;
            $meta['restore'] = array_merge($meta['restore'] ?? [], [
                'staging_target' => $stageTargetDir,
                'staging_dir' => $stagingSwapDir,
            ]);
            $run->update(['meta' => $meta]);

            if (($stageMove['exit_code'] ?? 1) !== 0) {
                throw new \RuntimeException('Failed to prepare staging directory.');
            }

            $step = 'stage_validate';
            $validateMeta = $this->validateStaging($stagingSwapDir, $scope);
            $meta['steps']['stage_validate'] = $validateMeta;
            $run->update(['meta' => $meta]);

            if (($validateMeta['exit_code'] ?? 1) !== 0) {
                throw new \RuntimeException('Staging validation failed.');
            }

            if ($this->safetyBackup) {
                $step = 'safety_backup';
                $safetyMeta = $this->runSafetyBackup($runner, $settings, $projectRoot, $connectionName, $snapshotInfo, (int) $run->id);
                $meta['steps']['safety_backup'] = $safetyMeta;
                $run->update(['meta' => $meta]);

                if (($safetyMeta['exit_code'] ?? 1) === 0) {
                    $candidate = storage_path('app/_backup/db.sql.gz');

                    if (is_file($candidate)) {
                        $safetyDumpPath = $candidate;
                        $meta['restore']['safety_dump_path'] = $candidate;
                        $run->update(['meta' => $meta]);
                    }
                } else {
                    throw new \RuntimeException('Safety backup failed.');
                }
            }

            $step = 'cutover_down';
            $downSecret = $this->generateDownSecret();
            $meta['restore'] = array_merge($meta['restore'] ?? [], [
                'secret' => $downSecret,
                'bypass_path' => '/' . $downSecret,
            ]);
            $run->update(['meta' => $meta]);

            $downResult = $this->runArtisanDown($projectRoot, $downSecret);
            $meta['steps']['cutover_down'] = $downResult;
            $run->update(['meta' => $meta]);

            if (($downResult['exit_code'] ?? 1) !== 0) {
                throw new \RuntimeException('Failed to enable maintenance mode.');
            }

            $maintenanceStarted = true;

            if ($this->requiresFiles($scope)) {
                if ($mode === 'atomic') {
                    $step = 'cutover_swap';
                    $rollbackDir = $this->makeRollbackDir($projectRoot);
                    $swapMeta = $this->swapDirectories($projectRoot, $stagingSwapDir, $rollbackDir);
                    $meta['steps']['cutover_swap'] = $swapMeta;
                    $meta['restore'] = array_merge($meta['restore'] ?? [], [
                        'rollback_dir' => $rollbackDir,
                    ]);
                    $run->update(['meta' => $meta]);

                    if (($swapMeta['exit_code'] ?? 1) !== 0) {
                        throw new \RuntimeException('Atomic swap failed.');
                    }

                    $swapCompleted = true;
                    $envMeta = $this->preserveEnvFromRollback($rollbackDir, $projectRoot);
                    $meta['steps']['env_preserve'] = $envMeta;

                    $safetyDumpPath = $this->resolveSafetyDumpPath($rollbackDir);
                    if ($safetyDumpPath !== null) {
                        $meta['restore']['safety_dump_path'] = $safetyDumpPath;
                    }

                    $run->update(['meta' => $meta]);

                    $downAfterSwap = $this->runArtisanDown($projectRoot, $downSecret);
                    $meta['steps']['cutover_down_after_swap'] = $downAfterSwap;
                    $run->update(['meta' => $meta]);

                    if (($downAfterSwap['exit_code'] ?? 1) !== 0) {
                        throw new \RuntimeException('Failed to enable maintenance mode after swap.');
                    }
                } else {
                    $step = 'cutover_swap';
                    $swapMeta = $this->restoreFilesRsync($stagingSwapDir, $projectRoot);
                    $meta['steps']['cutover_swap'] = $swapMeta;
                    $run->update(['meta' => $meta]);

                    if (($swapMeta['exit_code'] ?? 1) !== 0) {
                        throw new \RuntimeException('File restore failed.');
                    }
                }
            }

            if ($this->requiresDb($scope)) {
                $step = 'cutover_db_wipe';
                $wipeMeta = $this->wipeDatabase($connectionName, $projectRoot);
                $meta['steps']['cutover_db_wipe'] = $wipeMeta;
                $run->update(['meta' => $meta]);

                if (($wipeMeta['exit_code'] ?? 1) !== 0) {
                    throw new \RuntimeException('Database wipe failed.');
                }

                $dbWiped = true;

                $step = 'cutover_db_import';
                $dumpPath = $this->resolveDumpPath($projectRoot);
                $importMeta = $this->importMysqlDump($connectionName, $dumpPath, $projectRoot);
                $meta['steps']['cutover_db_import'] = $importMeta;
                $run->update(['meta' => $meta]);

                if (($importMeta['exit_code'] ?? 1) !== 0) {
                    throw new \RuntimeException('Database restore failed.');
                }
            }

            if ($this->requiresFiles($scope)) {
                $step = 'runtime_cleanup';
                $runtimeMeta = $this->cleanupRuntimeArtifacts($projectRoot);
                $meta['steps']['runtime_cleanup'] = $runtimeMeta;
                $run->update(['meta' => $meta]);

                $step = 'storage_link';
                $storageLink = $this->runArtisan(['storage:link'], $projectRoot);
                $meta['steps']['storage_link'] = $storageLink;
                $run->update(['meta' => $meta]);

                $step = 'cutover_optimize_clear';
                $optimizeResult = $this->runArtisan(['optimize:clear'], $projectRoot);
                $meta['steps']['cutover_optimize_clear'] = $optimizeResult;
                $run->update(['meta' => $meta]);

                $step = 'cutover_queue_restart';
                $queueResult = $this->runArtisan(['queue:restart'], $projectRoot);
                $meta['steps']['cutover_queue_restart'] = $queueResult;
                $run->update(['meta' => $meta]);
            }

            if ($maintenanceStarted) {
                $step = 'cutover_up';
                $upResult = $this->runArtisan(['up'], $projectRoot);
                $upResult = $this->normalizeAlreadyUpResult($upResult);
                $meta['steps']['cutover_up'] = $upResult;
                $run->update(['meta' => $meta]);

                if (($upResult['exit_code'] ?? 1) !== 0) {
                    throw new \RuntimeException('Failed to disable maintenance mode.');
                }

                $maintenanceCompleted = true;
            }

            if ($swapCompleted && is_string($rollbackDir)) {
                $cleanupAt = now()->addHours(self::ROLLBACK_CLEANUP_DELAY_HOURS);
                $meta['cleanup'] = [
                    'scheduled' => true,
                    'path' => $rollbackDir,
                    'not_before' => $cleanupAt->toIso8601String(),
                ];

                CleanupRollbackDirJob::dispatch($rollbackDir, (int) $run->id, $cleanupAt->getTimestamp())
                    ->delay($cleanupAt);
            }

            $run->update([
                'status' => 'success',
                'finished_at' => now(),
                'meta' => $meta,
            ]);
        } catch (Throwable $exception) {
            if ($maintenanceStarted && $swapCompleted && is_string($projectRoot) && is_string($rollbackDir)) {
                $step = 'rollback';
                $rollbackAttempted = true;
                $rollbackMeta = $this->attemptRollback($projectRoot, $rollbackDir);
                $meta['steps']['rollback_swap'] = $rollbackMeta;
                $rollbackSuccess = ($rollbackMeta['exit_code'] ?? 1) === 0;
            }

            if (
                $dbWiped
                && is_string($projectRoot)
                && is_string($connectionName)
                && is_string($scope)
                && $this->requiresDb($scope)
            ) {
                $dbRollbackAttempted = true;
                $rollbackDbMeta = $this->attemptDatabaseRollback($connectionName, $safetyDumpPath, $projectRoot);
                $meta['steps']['rollback_db_restore'] = $rollbackDbMeta;
                $dbRollbackSuccess = ($rollbackDbMeta['exit_code'] ?? 1) === 0;
            }

            if ($run instanceof BackupRun) {
                $meta['error_class'] = $exception::class;
                $meta['error_message'] = $this->sanitizeErrorMessage($exception->getMessage(), $settings);
                $meta['step'] = $step;
                $meta['rollback'] = [
                    'attempted' => $rollbackAttempted,
                    'success' => $rollbackSuccess,
                    'db_attempted' => $dbRollbackAttempted,
                    'db_success' => $dbRollbackSuccess,
                ];

                $run->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'meta' => $meta,
                ]);
            }

            throw $exception;
        } finally {
            if ($maintenanceStarted && ! $maintenanceCompleted && is_string($projectRoot)) {
                try {
                    $upResult = $this->runArtisan(['up'], $projectRoot);
                    $upResult = $this->normalizeAlreadyUpResult($upResult);

                    if ($run instanceof BackupRun) {
                        $meta['steps']['cutover_up'] = $upResult;
                        $run->update(['meta' => $meta]);
                    }
                } catch (Throwable) {
                    // Best-effort only.
                }
            }

            foreach ($cleanupPaths as $path) {
                $this->cleanupDirectory($path);
            }

            $lock->release();
        }
    }

    protected function recordSkippedRun(): void
    {
        BackupRun::query()->create([
            'type' => 'restore',
            'status' => 'skipped',
            'started_at' => now(),
            'finished_at' => now(),
            'meta' => [
                'trigger' => $this->normalizeTrigger($this->trigger),
                'snapshot_id' => $this->snapshotId,
                'scope' => $this->normalizeScope($this->scope),
                'mode' => $this->normalizeMode($this->mode, $this->normalizeScope($this->scope)),
                'safety_backup' => $this->safetyBackup,
                'reason' => 'lock_unavailable',
            ],
        ]);
    }

    protected function lockTtl(): int
    {
        return max(self::LOCK_TTL_SECONDS, $this->timeout);
    }

    protected function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));

        return match ($scope) {
            'db', 'database' => 'db',
            'files', 'file' => 'files',
            'both' => 'both',
            default => 'files',
        };
    }

    protected function normalizeMode(?string $mode, string $scope): ?string
    {
        if ($scope === 'db') {
            return null;
        }

        $mode = $mode !== null ? strtolower(trim($mode)) : 'rsync';

        return in_array($mode, ['rsync', 'atomic'], true) ? $mode : 'rsync';
    }

    protected function requiresFiles(string $scope): bool
    {
        return in_array($scope, ['files', 'both'], true);
    }

    protected function requiresDb(string $scope): bool
    {
        return in_array($scope, ['db', 'both'], true);
    }

    protected function normalizeTrigger(?string $trigger): string
    {
        $trigger = strtolower(trim((string) $trigger));

        return in_array($trigger, ['manual', 'schedule', 'system'], true) ? $trigger : 'manual';
    }

    protected function resolveProjectRoot(BackupSetting $settings): string
    {
        $projectRoot = $this->normalizeScalar($settings->project_root)
            ?? $this->normalizeScalar(config('restic-backups.paths.project_root', base_path()))
            ?? base_path();

        return $projectRoot;
    }

    protected function verifyDbConnection(string $connectionName): void
    {
        try {
            DB::connection($connectionName)->getPdo();
        } catch (Throwable) {
            throw new \RuntimeException("Database connection [{$connectionName}] is not available.");
        }
    }

    /**
     * @param  array<int, mixed>  $snapshots
     */
    protected function resolveSnapshot(array $snapshots, string $snapshotId): array
    {
        $snapshotId = trim($snapshotId);

        foreach ($snapshots as $snapshot) {
            if (! is_array($snapshot)) {
                continue;
            }

            $id = $this->normalizeScalar($snapshot['id'] ?? null);
            $shortId = $this->normalizeScalar($snapshot['short_id'] ?? null);

            if ($id === null) {
                continue;
            }

            if ($id === $snapshotId || $shortId === $snapshotId || str_starts_with($id, $snapshotId)) {
                return [
                    'id' => $id,
                    'short_id' => $shortId ?? Str::substr($id, 0, 8),
                    'time' => $this->normalizeScalar($snapshot['time'] ?? null),
                    'hostname' => $this->normalizeScalar($snapshot['hostname'] ?? null),
                    'tags' => $this->normalizeArray($snapshot['tags'] ?? []),
                    'paths' => $this->normalizeArray($snapshot['paths'] ?? []),
                ];
            }
        }

        throw new \RuntimeException('Snapshot was not found in the repository.');
    }

    protected function runSafetyBackup(
        ResticRunner $runner,
        BackupSetting $settings,
        string $projectRoot,
        string $connectionName,
        array $snapshot,
        int $runId,
    ): array {
        $dumpPath = storage_path('app/_backup/db.sql.gz');
        $dumpMeta = $this->dumpDatabase($connectionName, $dumpPath);
        $dumpMeta = $this->normalizeDumpMeta($dumpMeta);

        $tags = [
            'safety-before-restore',
            'restore:' . ($snapshot['short_id'] ?? 'unknown'),
            'run:' . $runId,
            'trigger:restore',
        ];

        $backupResult = $runner->backup(
            $projectRoot,
            $tags,
            [
                'timeout' => $this->timeout,
                'capture_output' => true,
                'max_output_bytes' => self::META_OUTPUT_LIMIT,
            ],
        );

        return [
            'exit_code' => ($dumpMeta['exit_code'] ?? 1) === 0 && $backupResult->exitCode === 0 ? 0 : 1,
            'dump' => $dumpMeta,
            'backup' => $this->formatProcessResult($backupResult),
            'tags' => $tags,
        ];
    }

    protected function restoreFilesRsync(string $sourcePath, string $projectRoot): array
    {
        $rsync = $this->findBinary('rsync');
        $source = rtrim($sourcePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $target = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $command = [
            $rsync,
            '-a',
            '--delete',
            '--exclude=.env',
            '--exclude=storage/framework/down',
            $source,
            $target,
        ];

        return $this->runProcess($command, $projectRoot);
    }

    protected function restoreFilesAtomic(string $sourcePath, string $projectRoot, int $runId): array
    {
        $rsync = $this->findBinary('rsync');
        $source = rtrim($sourcePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);

        $parent = dirname($projectRoot);
        $newDir = $projectRoot . '.__restored_' . $runId;
        $oldDir = $projectRoot . '.__before_restore_' . Carbon::now()->format('YmdHis');

        $this->ensureDirectory($parent, mustBeWritable: true, context: 'project_root');

        $syncCommand = [
            $rsync,
            '-a',
            '--exclude=.env',
            '--exclude=storage/framework/down',
            $source,
            rtrim($newDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
        ];

        $syncResult = $this->runProcess($syncCommand, $projectRoot);

        if (($syncResult['exit_code'] ?? 1) !== 0) {
            return $syncResult;
        }

        $envPath = $projectRoot . DIRECTORY_SEPARATOR . '.env';
        $newEnvPath = $newDir . DIRECTORY_SEPARATOR . '.env';

        if (is_file($envPath)) {
            $this->ensureDirectory($newDir, mustBeWritable: true, context: 'restore_target');
            @copy($envPath, $newEnvPath);
        }

        $swapResult = $this->runProcess(['mv', $projectRoot, $oldDir], $parent);

        if (($swapResult['exit_code'] ?? 1) !== 0) {
            return $this->mergeStepResults($syncResult, $swapResult, 'Failed to move current project root.');
        }

        $moveResult = $this->runProcess(['mv', $newDir, $projectRoot], $parent);

        if (($moveResult['exit_code'] ?? 1) !== 0) {
            $rollback = $this->runProcess(['mv', $oldDir, $projectRoot], $parent);

            return $this->mergeStepResults($syncResult, $moveResult, 'Failed to move restored project into place.', [
                'rollback' => $rollback,
            ]);
        }

        return array_merge($syncResult, [
            'previous_path' => $oldDir,
            'swap' => $swapResult,
            'move' => $moveResult,
        ]);
    }

    protected function restoreDatabase(string $restoredProjectPath, string $connectionName, string $projectRoot): array
    {
        $dumpPath = $restoredProjectPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . '_backup' . DIRECTORY_SEPARATOR . 'db.sql.gz';

        if (! is_file($dumpPath)) {
            return [
                'exit_code' => 1,
                'stderr' => 'Database dump file not found in the snapshot.',
                'duration_ms' => 0,
                'command' => 'db_restore',
            ];
        }

        $wipeResult = $this->dropAllTablesExcept($connectionName, $this->internalTables());

        if (($wipeResult['exit_code'] ?? 1) !== 0) {
            return $this->mergeStepResults($wipeResult, [], 'Database wipe failed.');
        }

        $importResult = $this->importMysqlDump($connectionName, $dumpPath, $projectRoot);

        return [
            'exit_code' => ($wipeResult['exit_code'] ?? 1) === 0 && ($importResult['exit_code'] ?? 1) === 0 ? 0 : 1,
            'wipe' => $wipeResult,
            'import' => $importResult,
        ];
    }

    protected function importMysqlDump(string $connectionName, string $dumpPath, string $projectRoot): array
    {
        $connection = config("database.connections.{$connectionName}");

        if (! is_array($connection)) {
            return [
                'exit_code' => 1,
                'stderr' => "Database connection [{$connectionName}] not found.",
                'duration_ms' => 0,
                'command' => 'mysql',
            ];
        }

        $driver = $this->normalizeScalar($connection['driver'] ?? null);

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return [
                'exit_code' => 1,
                'stderr' => "Database driver [{$driver}] is not supported for restore.",
                'duration_ms' => 0,
                'command' => 'mysql',
            ];
        }

        $binary = $this->findBinaryFromCandidates(['mysql', 'mariadb']);
        $host = $this->normalizeScalar($connection['host'] ?? null);
        $port = $this->normalizeScalar($connection['port'] ?? null);
        $user = $this->normalizeScalar($connection['username'] ?? null);
        $database = $this->normalizeScalar($connection['database'] ?? null);
        $socket = $this->normalizeScalar($connection['unix_socket'] ?? null);
        $prefix = $this->normalizeScalar($connection['prefix'] ?? null);
        $prefix = $this->normalizeScalar($connection['prefix'] ?? null);
        $prefix = $this->normalizeScalar($connection['prefix'] ?? null);

        if ($database === null) {
            return [
                'exit_code' => 1,
                'stderr' => 'Database name is not configured for restore.',
                'duration_ms' => 0,
                'command' => 'mysql',
            ];
        }

        $command = [$binary];

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

        $env = [];
        $password = $this->normalizeScalar($connection['password'] ?? null);

        if ($password !== null) {
            $env['MYSQL_PWD'] = $password;
        }

        $handle = @gzopen($dumpPath, 'rb');

        if ($handle === false) {
            return [
                'exit_code' => 1,
                'stderr' => 'Unable to open database dump for import.',
                'duration_ms' => 0,
                'command' => $this->safeCommandString($command),
            ];
        }

        try {
            return $this->runProcess($command, $projectRoot, $env, $handle);
        } finally {
            gzclose($handle);
        }
    }

    /**
     * @param  array<int, string>  $excludeTables
     * @return array<string, mixed>
     */
    protected function dropAllTablesExcept(string $connectionName, array $excludeTables): array
    {
        $start = microtime(true);

        try {
            $connection = DB::connection($connectionName);
            $driver = $connection->getDriverName();
            $excludeSet = $this->buildExcludeTableSet($connectionName, $excludeTables);
            $droppedTables = 0;
            $droppedViews = 0;

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $connection->statement('SET FOREIGN_KEY_CHECKS=0');

                $tables = $connection->select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');
                foreach ($tables as $table) {
                    $name = array_values((array) $table)[0] ?? null;
                    if (! is_string($name) || $name === '' || isset($excludeSet[$name])) {
                        continue;
                    }
                    $connection->statement('DROP TABLE IF EXISTS `' . str_replace('`', '``', $name) . '`');
                    $droppedTables++;
                }

                $views = $connection->select('SHOW FULL TABLES WHERE Table_type = "VIEW"');
                foreach ($views as $view) {
                    $name = array_values((array) $view)[0] ?? null;
                    if (! is_string($name) || $name === '' || isset($excludeSet[$name])) {
                        continue;
                    }
                    $connection->statement('DROP VIEW IF EXISTS `' . str_replace('`', '``', $name) . '`');
                    $droppedViews++;
                }

                $connection->statement('SET FOREIGN_KEY_CHECKS=1');
            } elseif ($driver === 'pgsql') {
                $tables = $connection->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
                foreach ($tables as $table) {
                    $name = $table->tablename ?? null;
                    if (! is_string($name) || $name === '' || isset($excludeSet[$name])) {
                        continue;
                    }
                    $connection->statement('DROP TABLE IF EXISTS "' . str_replace('"', '""', $name) . '" CASCADE');
                    $droppedTables++;
                }

                $views = $connection->select("SELECT viewname FROM pg_views WHERE schemaname = 'public'");
                foreach ($views as $view) {
                    $name = $view->viewname ?? null;
                    if (! is_string($name) || $name === '' || isset($excludeSet[$name])) {
                        continue;
                    }
                    $connection->statement('DROP VIEW IF EXISTS "' . str_replace('"', '""', $name) . '" CASCADE');
                    $droppedViews++;
                }
            } elseif ($driver === 'sqlite') {
                $entries = $connection->select("SELECT name, type FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%'");
                foreach ($entries as $entry) {
                    $name = $entry->name ?? null;
                    $type = $entry->type ?? null;
                    if (! is_string($name) || $name === '' || isset($excludeSet[$name])) {
                        continue;
                    }
                    if ($type === 'view') {
                        $connection->statement('DROP VIEW IF EXISTS "' . str_replace('"', '""', $name) . '"');
                        $droppedViews++;
                        continue;
                    }
                    $connection->statement('DROP TABLE IF EXISTS "' . str_replace('"', '""', $name) . '"');
                    $droppedTables++;
                }
            } else {
                return [
                    'exit_code' => 1,
                    'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                    'stderr' => "Unsupported driver for safe wipe: {$driver}",
                    'command' => 'drop_all_tables_except',
                ];
            }

            return [
                'exit_code' => 0,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'driver' => $driver,
                'excluded_tables' => array_values(array_keys($excludeSet)),
                'dropped_tables_count' => $droppedTables,
                'dropped_views_count' => $droppedViews,
                'command' => 'drop_all_tables_except',
            ];
        } catch (Throwable $exception) {
            return [
                'exit_code' => 1,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'stderr' => $this->truncateString($exception->getMessage(), self::META_OUTPUT_LIMIT),
                'command' => 'drop_all_tables_except',
            ];
        }
    }

    protected function dropAllTables(string $connectionName): array
    {
        return $this->dropAllTablesExcept($connectionName, []);
    }

    protected function makeRestoreDirectory(?int $runId): string
    {
        $base = storage_path('app/_backup/restore');
        $this->ensureDirectory($base, context: 'restore_tmp');

        $suffix = $runId ? (string) $runId : Str::random(6);
        $path = $base . DIRECTORY_SEPARATOR . 'restic-restore-' . $suffix . '-' . Carbon::now()->format('YmdHis');

        $this->ensureDirectory($path, context: 'restore_tmp');

        return $path;
    }

    protected function resolveRestoredProjectPath(string $restoreDir, string $projectRoot): string
    {
        $relative = ltrim($projectRoot, DIRECTORY_SEPARATOR);

        return rtrim($restoreDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
    }

    protected function runArtisan(array $arguments, string $cwd): array
    {
        $command = array_merge([PHP_BINARY, 'artisan'], $arguments);

        return $this->runProcess($command, $cwd);
    }

    protected function runArtisanDown(string $cwd, ?string $secret = null): array
    {
        $arguments = ['down', '--force'];

        if ($secret !== null && $secret !== '') {
            $arguments[] = '--secret=' . $secret;
        }

        $result = $this->runArtisan($arguments, $cwd);

        if (($result['exit_code'] ?? 1) !== 0 && $this->artisanOptionMissing($result, '--force')) {
            $fallbackArguments = ['down'];

            if ($secret !== null && $secret !== '') {
                $fallbackArguments[] = '--secret=' . $secret;
            }

            $result = $this->runArtisan($fallbackArguments, $cwd);
            $result['note'] = 'Retry without --force (option not supported).';
        }

        $result = $this->normalizeAlreadyDownResult($result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function artisanOptionMissing(array $result, string $option): bool
    {
        $needle = strtolower(ltrim($option, '-'));
        $message = strtolower((string) ($result['stdout'] ?? '') . ' ' . (string) ($result['stderr'] ?? ''));

        return str_contains($message, 'option does not exist') && str_contains($message, $needle);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function normalizeAlreadyDownResult(array $result): array
    {
        if (($result['exit_code'] ?? 1) === 0) {
            return $result;
        }

        if (! $this->artisanAlreadyDown($result)) {
            return $result;
        }

        $result['exit_code'] = 0;
        $result['note'] = trim(($result['note'] ?? '') . ' Application already in maintenance mode.');

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function normalizeAlreadyUpResult(array $result): array
    {
        if (($result['exit_code'] ?? 1) === 0) {
            return $result;
        }

        if (! $this->artisanAlreadyUp($result)) {
            return $result;
        }

        $result['exit_code'] = 0;
        $result['note'] = trim(($result['note'] ?? '') . ' Application already up.');

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function artisanAlreadyDown(array $result): bool
    {
        $message = strtolower((string) ($result['stdout'] ?? '') . ' ' . (string) ($result['stderr'] ?? ''));

        return str_contains($message, 'already down')
            || str_contains($message, 'already in maintenance')
            || str_contains($message, 'already in maintenance mode');
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function artisanAlreadyUp(array $result): bool
    {
        $message = strtolower((string) ($result['stdout'] ?? '') . ' ' . (string) ($result['stderr'] ?? ''));

        return str_contains($message, 'already up')
            || str_contains($message, 'not in maintenance');
    }

    protected function ensureExistingDirectory(string $path, bool $mustBeWritable = false, string $context = 'directory'): void
    {
        $path = trim($path);

        if ($path === '') {
            throw new ResticConfigurationException([$context], "{$context} is empty.");
        }

        if (! is_dir($path)) {
            throw new ResticConfigurationException([$context], "{$context} [{$path}] does not exist.");
        }

        if ($mustBeWritable && ! is_writable($path)) {
            throw new ResticConfigurationException([$context], "{$context} [{$path}] is not writable.");
        }
    }

    protected function sameFilesystem(string $pathA, string $pathB): bool
    {
        $statA = @stat($pathA);
        $statB = @stat($pathB);

        if ($statA === false || $statB === false) {
            return false;
        }

        $devA = $statA['dev'] ?? null;
        $devB = $statB['dev'] ?? null;

        return $devA !== null && $devA === $devB;
    }

    protected function preflightSpace(ResticRunner $runner, string $snapshotId, string $projectRoot, string $scope): array
    {
        $start = microtime(true);
        $expectedBytes = null;
        $source = null;
        $statsMeta = null;

        if ($this->requiresFiles($scope)) {
            try {
                $statsResult = $runner->statsRestoreSize($snapshotId, [
                    'timeout' => min(600, $this->timeout),
                    'capture_output' => true,
                ]);
                $statsMeta = $this->formatProcessResult($statsResult);

                if ($statsResult->exitCode === 0 && is_array($statsResult->parsedJson)) {
                    $expectedBytes = $this->extractRestoreSize($statsResult->parsedJson);
                    $source = $expectedBytes !== null ? 'restic_stats' : null;
                }
            } catch (Throwable $exception) {
                $statsMeta = [
                    'exit_code' => 1,
                    'stderr' => $this->truncateString($exception->getMessage(), self::META_OUTPUT_LIMIT),
                ];
            }

            if ($expectedBytes === null) {
                $expectedBytes = $this->getDirectorySizeBytes($projectRoot);
                $source = $expectedBytes !== null ? 'du' : null;
            }
        }

        $freeBytes = $this->getDiskFreeBytes($projectRoot);
        $requiredBytes = null;
        $ok = false;

        if ($this->requiresFiles($scope)) {
            if ($expectedBytes !== null) {
                $requiredBytes = (int) ceil(($expectedBytes * 1.15) + (2 * 1024 * 1024 * 1024));
            }
        } else {
            $requiredBytes = 2 * 1024 * 1024 * 1024;
        }

        if ($freeBytes !== null && $requiredBytes !== null) {
            $ok = $freeBytes >= $requiredBytes;
        }

        return [
            'exit_code' => $ok ? 0 : 1,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'free_bytes' => $freeBytes,
            'expected_bytes' => $expectedBytes,
            'required_bytes' => $requiredBytes,
            'source' => $source,
            'stats' => $statsMeta,
            'ok' => $ok,
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    protected function extractRestoreSize(array $stats): ?int
    {
        $keys = ['total_size', 'total_size_bytes', 'total_size_in_bytes'];

        foreach ($keys as $key) {
            if (isset($stats[$key]) && is_numeric($stats[$key])) {
                return (int) $stats[$key];
            }
        }

        return null;
    }

    protected function getDiskFreeBytes(string $path): ?int
    {
        if (function_exists('statvfs')) {
            $stat = @statvfs($path);

            if (is_array($stat) && isset($stat['f_bavail'], $stat['f_frsize'])) {
                return (int) ($stat['f_bavail'] * $stat['f_frsize']);
            }
        }

        $free = @disk_free_space($path);

        if ($free === false) {
            return null;
        }

        return (int) $free;
    }

    protected function getDirectorySizeBytes(string $path): ?int
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);

        if ($path === '' || ! is_dir($path)) {
            return null;
        }

        try {
            $du = $this->findBinary('du');
        } catch (Throwable) {
            return null;
        }

        $result = $this->runProcess([$du, '-sb', $path], dirname($path));

        if (($result['exit_code'] ?? 1) !== 0) {
            return null;
        }

        $output = trim((string) ($result['stdout'] ?? ''));

        if ($output === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $output);
        $size = $parts[0] ?? null;

        return is_numeric($size) ? (int) $size : null;
    }

    protected function makeStagingTargetDir(string $projectRoot, ?int $runId): string
    {
        $suffix = $runId ? (string) $runId : Str::random(6);
        $timestamp = Carbon::now()->format('YmdHis');
        $path = $projectRoot . '.__restored_' . $suffix . '_' . $timestamp;

        $this->ensureDirectory($path, context: 'staging_target');

        return $path;
    }

    protected function makeStagingSwapDir(string $projectRoot, ?int $runId): string
    {
        $suffix = $runId ? (string) $runId : Str::random(6);
        $timestamp = Carbon::now()->format('YmdHis');
        $path = $projectRoot . '.__swap_' . $suffix . '_' . $timestamp;

        if (is_dir($path)) {
            throw new \RuntimeException('Staging swap directory already exists.');
        }

        return $path;
    }

    protected function moveDirectory(string $source, string $destination, ?string $cwd = null): array
    {
        return $this->runProcess(['mv', $source, $destination], $cwd);
    }

    protected function validateStaging(string $stagingDir, string $scope): array
    {
        $start = microtime(true);
        $errors = [];

        if (! is_dir($stagingDir)) {
            $errors[] = 'Staging directory was not found.';
        }

        if ($this->requiresFiles($scope)) {
            if (! is_file($stagingDir . DIRECTORY_SEPARATOR . 'artisan')) {
                $errors[] = 'artisan file missing in staged project.';
            }

            if (! is_file($stagingDir . DIRECTORY_SEPARATOR . 'composer.json')) {
                $errors[] = 'composer.json missing in staged project.';
            }

            if (! is_file($stagingDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php')) {
                $errors[] = 'vendor/autoload.php missing in staged project.';
            }
        }

        if ($this->requiresDb($scope)) {
            $dumpPath = $stagingDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . '_backup' . DIRECTORY_SEPARATOR . 'db.sql.gz';
            if (! is_file($dumpPath)) {
                $errors[] = 'Database dump file missing in staged project.';
            }
        }

        return [
            'exit_code' => $errors === [] ? 0 : 1,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'errors' => $errors,
        ];
    }

    protected function generateDownSecret(): string
    {
        return Str::lower(Str::random(32));
    }

    protected function makeRollbackDir(string $projectRoot): string
    {
        return $projectRoot . '.__before_restore_' . Carbon::now()->format('YmdHis');
    }

    protected function swapDirectories(string $projectRoot, string $stagingDir, string $rollbackDir): array
    {
        $parent = dirname($projectRoot);
        $swapResult = $this->runProcess(['mv', $projectRoot, $rollbackDir], $parent);

        if (($swapResult['exit_code'] ?? 1) !== 0) {
            return $this->mergeStepResults($swapResult, [], 'Failed to move current project root.');
        }

        $moveResult = $this->runProcess(['mv', $stagingDir, $projectRoot], $parent);

        if (($moveResult['exit_code'] ?? 1) !== 0) {
            $rollback = $this->runProcess(['mv', $rollbackDir, $projectRoot], $parent);

            return $this->mergeStepResults($swapResult, $moveResult, 'Failed to move staged project into place.', [
                'rollback' => $rollback,
            ]);
        }

        return [
            'exit_code' => 0,
            'rollback_path' => $rollbackDir,
            'swap' => $swapResult,
            'move' => $moveResult,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function preserveEnvFromRollback(string $rollbackDir, string $projectRoot): array
    {
        $start = microtime(true);
        $source = $rollbackDir . DIRECTORY_SEPARATOR . '.env';
        $target = $projectRoot . DIRECTORY_SEPARATOR . '.env';

        if (! is_file($source)) {
            return [
                'exit_code' => 1,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'source' => $source,
                'target' => $target,
                'stderr' => 'Source .env not found in rollback directory.',
            ];
        }

        $copied = @copy($source, $target);

        return [
            'exit_code' => $copied ? 0 : 1,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'source' => $source,
            'target' => $target,
            'stderr' => $copied ? '' : 'Failed to copy .env from rollback directory.',
        ];
    }

    protected function wipeDatabase(string $connectionName, string $projectRoot): array
    {
        return $this->dropAllTablesExcept($connectionName, $this->internalTables());
    }

    protected function resolveDumpPath(string $projectRoot): string
    {
        return $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . '_backup' . DIRECTORY_SEPARATOR . 'db.sql.gz';
    }

    protected function resolveSafetyDumpPath(string $rollbackDir): ?string
    {
        $path = $rollbackDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . '_backup' . DIRECTORY_SEPARATOR . 'db.sql.gz';

        return is_file($path) ? $path : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function attemptDatabaseRollback(string $connectionName, ?string $dumpPath, string $projectRoot): array
    {
        $start = microtime(true);

        if ($dumpPath === null || ! is_file($dumpPath)) {
            return [
                'exit_code' => 1,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'stderr' => 'Safety dump not available for rollback.',
                'command' => 'rollback_db_restore',
            ];
        }

        $connection = config("database.connections.{$connectionName}");

        if (! is_array($connection)) {
            return [
                'exit_code' => 1,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'stderr' => "Database connection [{$connectionName}] not found.",
                'command' => 'rollback_db_restore',
            ];
        }

        $driver = $this->normalizeScalar($connection['driver'] ?? null);

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return [
                'exit_code' => 1,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'stderr' => "Database driver [{$driver}] is not supported for rollback.",
                'command' => 'rollback_db_restore',
            ];
        }

        $result = $this->importMysqlDump($connectionName, $dumpPath, $projectRoot);
        $result['dump_path'] = $dumpPath;

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    protected function cleanupRuntimeArtifacts(string $projectRoot): array
    {
        $start = microtime(true);
        $paths = [
            $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'views',
            $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'cache',
            $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'sessions',
            $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'testing',
        ];
        $errors = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $pathErrors = $this->cleanupDirectoryContents($path);

            if ($pathErrors !== []) {
                $errors[] = [
                    'path' => $path,
                    'errors' => $pathErrors,
                ];
            }
        }

        return [
            'exit_code' => $errors === [] ? 0 : 1,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'paths' => $paths,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function cleanupDirectoryContents(string $path): array
    {
        $errors = [];
        $items = @scandir($path);

        if ($items === false) {
            return ["Unable to read directory: {$path}"];
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fullPath)) {
                $this->cleanupDirectory($fullPath);

                if (is_dir($fullPath)) {
                    $errors[] = "Failed to remove directory: {$fullPath}";
                }

                continue;
            }

            if (@unlink($fullPath) === false && file_exists($fullPath)) {
                $errors[] = "Failed to remove file: {$fullPath}";
            }
        }

        return $errors;
    }

    protected function attemptRollback(string $projectRoot, string $rollbackDir): array
    {
        $parent = dirname($projectRoot);
        $failedDir = $projectRoot . '.__failed_restore_' . Carbon::now()->format('YmdHis');

        $moveFailed = is_dir($projectRoot)
            ? $this->runProcess(['mv', $projectRoot, $failedDir], $parent)
            : ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];

        $restore = $this->runProcess(['mv', $rollbackDir, $projectRoot], $parent);

        return [
            'exit_code' => ($moveFailed['exit_code'] ?? 1) === 0 && ($restore['exit_code'] ?? 1) === 0 ? 0 : 1,
            'failed_path' => $failedDir,
            'move_failed' => $moveFailed,
            'restore' => $restore,
        ];
    }

    /**
     * @param  array<int, string>  $command
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    protected function runProcess(array $command, ?string $cwd = null, array $env = [], mixed $input = null): array
    {
        $start = microtime(true);

        $environment = $env === [] ? null : $env;
        $process = new Process($command, $cwd, $environment, $input, (float) $this->timeout);
        $exitCode = $process->run();

        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $stdout = $this->truncateString($process->getOutput(), self::META_OUTPUT_LIMIT);
        $stderr = $this->truncateString($process->getErrorOutput(), self::META_OUTPUT_LIMIT);

        return [
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'command' => $this->safeCommandString($command),
        ];
    }

    protected function mergeStepResults(array $primary, array $secondary, string $message, array $extra = []): array
    {
        return array_merge($primary, [
            'exit_code' => 1,
            'stderr' => trim(($primary['stderr'] ?? '') . PHP_EOL . $message),
            'secondary' => $secondary,
        ], $extra);
    }

    protected function formatProcessResult(ProcessResult $result): array
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

    protected function hostname(): string
    {
        $host = gethostname();

        return is_string($host) && $host !== '' ? $host : 'unknown';
    }

    protected function cleanupDirectory(?string $path): void
    {
        if ($path === null || trim($path) === '') {
            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($full)) {
                $this->cleanupDirectory($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($path);
    }

    protected function ensureDirectory(string $path, bool $mustBeWritable = false, string $context = 'directory'): void
    {
        $path = trim($path);

        if ($path === '') {
            return;
        }

        if (! is_dir($path)) {
            if (! @mkdir($path, 0755, true) && ! is_dir($path)) {
                throw new ResticConfigurationException([$context], "Unable to create {$context} [{$path}].");
            }
        }

        if ($mustBeWritable && ! is_writable($path)) {
            throw new ResticConfigurationException([$context], "{$context} [{$path}] is not writable.");
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

    /**
     * @return array<int, string>
     */
    protected function normalizeArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                continue;
            }

            $stringValue = trim((string) $item);

            if ($stringValue === '') {
                continue;
            }

            $normalized[] = $stringValue;
        }

        return $normalized;
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

        $process = new Process($command, null, $env === [] ? null : $env, null, (float) $this->timeout);

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
     * @param  array<int, string>  $excludeTables
     * @return array<string, bool>
     */
    protected function buildExcludeTableSet(string $connectionName, array $excludeTables): array
    {
        $connection = DB::connection($connectionName);
        $prefix = method_exists($connection, 'getTablePrefix') ? (string) $connection->getTablePrefix() : '';
        $tables = $this->normalizeTableNames($excludeTables);
        $set = [];

        foreach ($tables as $table) {
            $set[$table] = true;

            if ($prefix !== '' && ! str_starts_with($table, $prefix)) {
                $set[$prefix . $table] = true;
            }
        }

        return $set;
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

    protected function findBinary(string $binary): string
    {
        $finder = new ExecutableFinder();
        $path = $finder->find($binary);

        if ($path === null) {
            throw new \RuntimeException("Binary [{$binary}] not found.");
        }

        return $path;
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
}
