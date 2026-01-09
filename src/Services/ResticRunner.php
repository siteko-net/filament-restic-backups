<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Services;

use DateTimeImmutable;
use Siteko\FilamentResticBackups\DTO\ProcessResult;
use Siteko\FilamentResticBackups\Exceptions\ResticConfigurationException;
use Siteko\FilamentResticBackups\Exceptions\ResticProcessException;
use Siteko\FilamentResticBackups\Models\BackupSetting;
use Symfony\Component\Process\Process;
use Throwable;

class ResticRunner
{
    private const DEFAULT_TIMEOUT_SECONDS = 3600;
    private const DEFAULT_MAX_OUTPUT_BYTES = 5242880;

    private ?BackupSetting $settings;

    public function __construct(?BackupSetting $settings = null)
    {
        $this->settings = $settings;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function snapshots(array $filters = []): ProcessResult
    {
        $command = ['snapshots', '--json'];

        $this->appendMultiOption($command, '--tag', $filters['tag'] ?? $filters['tags'] ?? null);
        $this->appendMultiOption($command, '--host', $filters['host'] ?? $filters['hosts'] ?? null);
        $this->appendMultiOption($command, '--path', $filters['path'] ?? $filters['paths'] ?? null);

        return $this->run($command, expectsJson: true);
    }

    /**
     * @param  array<int, string>  $tags
     * @param  array<string, mixed>  $options
     */
    public function backup(array | string $paths, array $tags = [], array $options = []): ProcessResult
    {
        $command = ['backup'];
        $expectsJson = (bool) ($options['json'] ?? false);

        unset($options['json']);

        if ($expectsJson) {
            $command[] = '--json';
        }

        $this->appendMultiOption($command, '--tag', $tags);
        $this->appendMultiOption($command, '--exclude', $options['exclude'] ?? null);
        $this->appendMultiOption($command, '--include', $options['include'] ?? null);

        $paths = $this->normalizeArray($paths);

        if ($paths === []) {
            throw new ResticConfigurationException(['paths'], 'Backup paths are required.');
        }

        foreach ($paths as $path) {
            $command[] = $path;
        }

        return $this->run($command, options: $options, expectsJson: $expectsJson);
    }

    /**
     * @param  array<string, mixed>  $retention
     * @param  array<string, mixed>  $options
     */
    public function forget(array $retention, array $options = []): ProcessResult
    {
        $command = ['forget'];
        $expectsJson = (bool) ($options['json'] ?? false);
        $prune = (bool) ($options['prune'] ?? true);

        unset($options['json'], $options['prune']);

        if ($expectsJson) {
            $command[] = '--json';
        }

        $map = [
            'keep_last' => '--keep-last',
            'keep_daily' => '--keep-daily',
            'keep_weekly' => '--keep-weekly',
            'keep_monthly' => '--keep-monthly',
            'keep_yearly' => '--keep-yearly',
        ];

        foreach ($map as $key => $flag) {
            if (! array_key_exists($key, $retention)) {
                continue;
            }

            $value = $retention[$key];

            if ($value === null || $value === '') {
                continue;
            }

            if (! is_numeric($value)) {
                continue;
            }

            $command[] = $flag;
            $command[] = (string) $value;
        }

        if ($prune) {
            $command[] = '--prune';
        }

        return $this->run($command, options: $options, expectsJson: $expectsJson);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function check(array $options = []): ProcessResult
    {
        $command = ['check'];
        $expectsJson = (bool) ($options['json'] ?? false);

        unset($options['json']);

        if ($expectsJson) {
            $command[] = '--json';
        }

        if (isset($options['read_data_subset'])) {
            $command[] = '--read-data-subset';
            $command[] = (string) $options['read_data_subset'];
        }

        return $this->run($command, options: $options, expectsJson: $expectsJson);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function restore(string $snapshotId, string $targetDir, array $options = []): ProcessResult
    {
        $this->ensureDirectory($targetDir, mustBeWritable: true, context: 'target_dir');

        $command = ['restore', $snapshotId, '--target', $targetDir];
        $expectsJson = (bool) ($options['json'] ?? false);

        unset($options['json']);

        if ($expectsJson) {
            $command[] = '--json';
        }

        $this->appendMultiOption($command, '--include', $options['include'] ?? null);
        $this->appendMultiOption($command, '--exclude', $options['exclude'] ?? null);
        $this->appendMultiOption($command, '--path', $options['path'] ?? $options['paths'] ?? null);

        return $this->run($command, options: $options, expectsJson: $expectsJson);
    }

    public function version(): ProcessResult
    {
        return $this->run(['version'], requiresRepository: false);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function statsRestoreSize(string $snapshotId, array $options = []): ProcessResult
    {
        $snapshotId = trim($snapshotId);

        if ($snapshotId === '') {
            throw new ResticConfigurationException(['snapshot_id'], 'Snapshot ID is required for stats.');
        }

        $command = ['stats', '--mode', 'restore-size', '--json', $snapshotId];

        return $this->run($command, options: $options, expectsJson: true);
    }

    /**
     * @param  array<int, string>  $arguments
     * @param  array<string, mixed>  $options
     */
    protected function run(
        array $arguments,
        array $options = [],
        bool $requiresRepository = true,
        bool $expectsJson = false,
    ): ProcessResult {
        $settings = $this->settings();

        $repository = $this->resolveRepository($settings);
        $env = $this->buildEnvironment($settings, $repository, $requiresRepository);
        $env = array_merge($env, $this->normalizeEnv($options['env'] ?? []));

        $binary = $this->normalizeScalar(config('restic-backups.restic.binary', 'restic')) ?? 'restic';
        $cacheDir = $this->normalizeScalar(config('restic-backups.restic.cache_dir'));
        $workDir = $this->normalizeScalar(config('restic-backups.paths.work_dir'));

        if ($cacheDir !== null) {
            $this->ensureDirectory($cacheDir, context: 'cache_dir');
        }

        if ($workDir !== null) {
            $this->ensureDirectory($workDir, context: 'work_dir');
        }

        $projectRoot = $this->normalizeScalar($settings->project_root)
            ?? $this->normalizeScalar(config('restic-backups.paths.project_root', base_path()))
            ?? base_path();

        $this->ensureWorkingDirectory($projectRoot);

        $command = [$binary];

        if ($cacheDir !== null) {
            $command[] = '--cache-dir';
            $command[] = $cacheDir;
        }

        foreach ($arguments as $argument) {
            $command[] = $argument;
        }

        $timeout = $options['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS;
        $captureOutput = (bool) ($options['capture_output'] ?? true);
        $maxOutputBytes = (int) ($options['max_output_bytes'] ?? self::DEFAULT_MAX_OUTPUT_BYTES);
        $throwOnError = (bool) ($options['throw'] ?? false);

        if ($expectsJson) {
            $captureOutput = true;
        }

        $startedAt = new DateTimeImmutable();
        $start = microtime(true);

        $process = new Process(
            $command,
            $projectRoot,
            $env,
            null,
            $timeout === null ? null : (float) $timeout,
        );

        if (! $captureOutput) {
            $process->disableOutput();
        }

        $exitCode = 1;
        $exceptionMessage = null;

        try {
            $exitCode = $process->run();
        } catch (Throwable $exception) {
            $exceptionMessage = $exception->getMessage();
            $exitCode = $process->getExitCode() ?? 1;
        }

        $finishedAt = new DateTimeImmutable();
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $stdout = $captureOutput ? $process->getOutput() : '';
        $stderr = $captureOutput ? $process->getErrorOutput() : '';

        if ($exceptionMessage !== null) {
            $stderr = trim($stderr . PHP_EOL . $exceptionMessage);
        }

        $secrets = $this->collectSecrets($settings);
        $stdout = $this->redactOutput($stdout, $secrets, $repository);
        $stderr = $this->redactOutput($stderr, $secrets, $repository);

        if ($exitCode !== 0) {
            $stderr = $this->appendDiagnostics($stderr, $repository);
        }

        $parsedJson = $expectsJson ? $this->parseJson($stdout) : null;

        $stdout = $this->truncateOutput($stdout, $maxOutputBytes);
        $stderr = $this->truncateOutput($stderr, $maxOutputBytes);

        $result = new ProcessResult(
            exitCode: $exitCode,
            durationMs: $durationMs,
            stdout: $stdout,
            stderr: $stderr,
            parsedJson: $parsedJson,
            command: $command,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
        );

        if ($throwOnError && $result->exitCode !== 0) {
            throw new ResticProcessException($result);
        }

        return $result;
    }

    protected function settings(): BackupSetting
    {
        return $this->settings ??= BackupSetting::singleton();
    }

    protected function resolveRepository(BackupSetting $settings): ?string
    {
        $repository = $this->normalizeScalar($settings->restic_repository);

        if ($repository !== null) {
            return $repository;
        }

        $endpoint = $this->normalizeScalar($settings->endpoint);
        $bucket = $this->normalizeScalar($settings->bucket);

        if ($endpoint === null || $bucket === null) {
            return null;
        }

        $prefix = $this->normalizeScalar($settings->prefix);
        $endpoint = rtrim($endpoint, '/');
        $bucket = trim($bucket, '/');

        $repository = 's3:' . $endpoint . '/' . $bucket;

        if ($prefix !== null) {
            $repository .= '/' . ltrim($prefix, '/');
        }

        return $repository;
    }

    protected function buildEnvironment(
        BackupSetting $settings,
        ?string $repository,
        bool $requiresRepository,
    ): array {
        $missing = [];
        $password = $this->normalizeScalar($settings->restic_password);
        $accessKey = $this->normalizeScalar($settings->access_key);
        $secretKey = $this->normalizeScalar($settings->secret_key);

        if ($requiresRepository) {
            if ($repository === null) {
                $missing[] = 'restic_repository';
            }

            if ($password === null) {
                $missing[] = 'restic_password';
            }

            if ($this->needsAwsCredentials($settings, $repository)) {
                if ($accessKey === null) {
                    $missing[] = 'access_key';
                }

                if ($secretKey === null) {
                    $missing[] = 'secret_key';
                }
            }
        }

        if ($missing !== []) {
            throw new ResticConfigurationException($missing);
        }

        $env = [];

        if ($requiresRepository && $repository !== null) {
            $env['RESTIC_REPOSITORY'] = $repository;
        }

        if ($requiresRepository) {
            if ($password !== null) {
                $env['RESTIC_PASSWORD'] = $password;
            }

            if ($accessKey !== null) {
                $env['AWS_ACCESS_KEY_ID'] = $accessKey;
            }

            if ($secretKey !== null) {
                $env['AWS_SECRET_ACCESS_KEY'] = $secretKey;
            }
        }

        return $env;
    }

    protected function needsAwsCredentials(BackupSetting $settings, ?string $repository): bool
    {
        if ($repository !== null && str_starts_with($repository, 's3:')) {
            return true;
        }

        if ($this->normalizeScalar($settings->endpoint) !== null) {
            return true;
        }

        return $this->normalizeScalar($settings->bucket) !== null;
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeArray(array | string | null $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

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

    protected function appendMultiOption(array &$command, string $option, array | string | null $value): void
    {
        foreach ($this->normalizeArray($value) as $item) {
            $command[] = $option;
            $command[] = $item;
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
    protected function collectSecrets(BackupSetting $settings): array
    {
        $secrets = [
            $this->normalizeScalar($settings->access_key),
            $this->normalizeScalar($settings->secret_key),
            $this->normalizeScalar($settings->restic_password),
        ];

        return array_values(array_filter(
            $secrets,
            fn (?string $value): bool => $value !== null && $value !== '',
        ));
    }

    protected function redactOutput(string $output, array $secrets, ?string $repository): string
    {
        if ($output === '') {
            return $output;
        }

        if ($repository !== null && str_contains($repository, '@')) {
            $redactedRepository = preg_replace('#(://)([^/]*):([^@]*)@#', '$1***:***@', $repository) ?? $repository;
            $output = str_replace($repository, $redactedRepository, $output);
        }

        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }

            $output = str_replace($secret, '***', $output);
        }

        $output = preg_replace('#(://)([^/]*):([^@]*)@#', '$1***:***@', $output) ?? $output;

        return $output;
    }

    protected function parseJson(string $output): ?array
    {
        $output = trim($output);

        if ($output === '') {
            return null;
        }

        $decoded = json_decode($output, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return is_array($decoded) ? $decoded : null;
        }

        $lines = preg_split('/\r?\n/', $output);
        $items = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decodedLine = json_decode($line, true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decodedLine)) {
                return null;
            }

            $items[] = $decodedLine;
        }

        return $items === [] ? null : $items;
    }

    protected function truncateOutput(string $output, int $maxBytes): string
    {
        if ($output === '') {
            return $output;
        }

        if ($maxBytes <= 0) {
            return '';
        }

        if (strlen($output) <= $maxBytes) {
            return $output;
        }

        return substr($output, 0, $maxBytes) . PHP_EOL . '...[truncated]';
    }

    protected function appendDiagnostics(string $stderr, ?string $repository): string
    {
        if ($stderr === '') {
            return $stderr;
        }

        if (str_contains($stderr, 'Diagnostics:')) {
            return $stderr;
        }

        $diagnostics = [];

        $proxyHint = $this->proxyDiagnostic($stderr, $repository);
        if ($proxyHint !== null) {
            $diagnostics[] = $proxyHint;
        }

        $repositoryHint = $this->repositoryDiagnostic($stderr);
        if ($repositoryHint !== null) {
            $diagnostics[] = $repositoryHint;
        }

        if ($diagnostics === []) {
            return $stderr;
        }

        return rtrim($stderr)
            . PHP_EOL
            . PHP_EOL
            . 'Diagnostics:'
            . PHP_EOL
            . '- ' . implode(PHP_EOL . '- ', $diagnostics);
    }

    protected function proxyDiagnostic(string $stderr, ?string $repository): ?string
    {
        $message = strtolower($stderr);

        if (
            ! str_contains($message, 'proxyconnect')
            && ! str_contains($message, 'socks5')
            && ! str_contains($message, 'socks5h')
            && ! (str_contains($message, 'proxy') && str_contains($message, 'dial tcp'))
        ) {
            return null;
        }

        $proxyVars = $this->collectProxyEnvKeys();
        $hint = 'Proxy error detected.';

        if ($proxyVars !== []) {
            $hint .= ' Environment proxy variables set: ' . implode(', ', $proxyVars) . '.';
        }

        $host = $this->repositoryHost($repository);
        if ($host !== null) {
            $noProxy = $this->getEnvValue(['NO_PROXY', 'no_proxy']);
            if ($noProxy === null || $noProxy === '') {
                $hint .= " Consider adding {$host} to NO_PROXY or unsetting proxy for the worker.";
            } elseif (! $this->noProxyAllowsHost($noProxy, $host)) {
                $hint .= " NO_PROXY does not include {$host}; consider adding it.";
            }
        }

        return $hint;
    }

    protected function repositoryDiagnostic(string $stderr): ?string
    {
        $message = strtolower($stderr);

        if (
            str_contains($message, 'unable to open config file')
            || str_contains($message, 'is there a repository at')
        ) {
            return 'Restic repository is not initialized or unreachable. Check repository URL, credentials, and network, or run `restic -r <repo> init` for a new repository.';
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function collectProxyEnvKeys(): array
    {
        $keys = [
            'HTTP_PROXY',
            'HTTPS_PROXY',
            'ALL_PROXY',
            'NO_PROXY',
            'http_proxy',
            'https_proxy',
            'all_proxy',
            'no_proxy',
        ];

        $present = [];

        foreach ($keys as $key) {
            $value = getenv($key);

            if ($value === false || $value === '') {
                continue;
            }

            $present[] = $key;
        }

        return array_values(array_unique($present));
    }

    /**
     * @param  array<int, string>  $keys
     */
    protected function getEnvValue(array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = getenv($key);

            if ($value === false || $value === '') {
                continue;
            }

            return $value;
        }

        return null;
    }

    protected function repositoryHost(?string $repository): ?string
    {
        if ($repository === null) {
            return null;
        }

        $value = $repository;

        if (str_starts_with($value, 's3:')) {
            $value = substr($value, 3);
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $host = $parts['host'];

        return is_string($host) && $host !== '' ? $host : null;
    }

    protected function noProxyAllowsHost(string $noProxy, string $host): bool
    {
        $items = array_filter(array_map('trim', explode(',', $noProxy)));

        foreach ($items as $item) {
            if ($item === '*') {
                return true;
            }

            if ($item === $host) {
                return true;
            }

            $suffix = ltrim($item, '.');

            if ($suffix !== '' && str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
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

    protected function ensureWorkingDirectory(string $path): void
    {
        $path = trim($path);

        if ($path === '') {
            throw new ResticConfigurationException(['project_root'], 'Project root directory is empty.');
        }

        if (! is_dir($path)) {
            throw new ResticConfigurationException(['project_root'], "Project root directory [{$path}] does not exist.");
        }
    }

    /**
     * @param  array<string, mixed>  $env
     * @return array<string, string>
     */
    protected function normalizeEnv(array $env): array
    {
        $normalized = [];

        foreach ($env as $key => $value) {
            if (! is_string($key) || $key === '' || $value === null) {
                continue;
            }

            $normalized[$key] = is_string($value) ? $value : (string) $value;
        }

        return $normalized;
    }
}
