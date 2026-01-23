<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Jobs;

use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Siteko\FilamentResticBackups\Models\BackupRun;
use Siteko\FilamentResticBackups\Models\BackupSetting;
use Siteko\FilamentResticBackups\Services\ResticRunner;
use Siteko\FilamentResticBackups\Support\DisasterRecoveryExport;
use Siteko\FilamentResticBackups\Support\OperationLock;
use Siteko\FilamentResticBackups\Support\OperationLockHandle;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class ExportDisasterRecoveryDeltaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const LOCK_TTL_SECONDS = 14400; // 4h
    private const LOCK_BLOCK_SECONDS = 30;
    private const META_OUTPUT_LIMIT = 204800;
    private const REQUEUE_DELAYS = [60, 120, 300];

    public int $timeout = 14400;
    public int $tries = 1;
    public array $backoff = [60];

    public function __construct(
        public int $keepHours = 24,
        public ?int $userId = null,
        public string $trigger = 'filament',
    ) {
    }

    public function handle(OperationLock $operationLock, ResticRunner $runner): void
    {
        $lockHandle = $operationLock->acquire(
            'export_delta',
            $this->lockTtl(),
            self::LOCK_BLOCK_SECONDS,
            [
                'trigger' => $this->trigger,
            ],
        );

        if (! $lockHandle instanceof OperationLockHandle) {
            $this->requeueOrReturn();
            return;
        }

        $run = null;
        $settings = null;

        $meta = [
            'trigger' => $this->trigger,
            'export' => [
                'format' => 'tar.gz',
                'include_env' => true,
                'keep_hours' => $this->keepHours,
                'kind' => 'delta',
            ],
        ];

        if ($this->userId !== null) {
            $meta['initiator_user_id'] = $this->userId;
        }

        $baseDir = storage_path('app/_backup/exports');
        $workDir = null;
        $archivePath = null;

        try {
            $settings = BackupSetting::singleton();
            $excludePaths = $this->resolveExcludePaths($settings);
            $baselineSnapshotId = $this->normalizeScalar($settings->baseline_snapshot_id);

            if ($baselineSnapshotId === null) {
                throw new \RuntimeException('Baseline snapshot is not configured.');
            }

            $meta['export']['exclude_paths'] = $excludePaths;

            $run = BackupRun::query()->create([
                'type' => 'export_delta',
                'status' => 'running',
                'started_at' => now(),
                'meta' => $meta,
            ]);
            $lockHandle->setRunId($run->id);

            $this->ensureDirectory($baseDir);

            $projectRoot = $this->resolveProjectRoot($settings);

            $appSlug = Str::slug((string) config('app.name', 'app')) ?: 'app';
            $env = (string) (config('app.env', 'production') ?: 'production');
            $stamp = now()->format('YmdHis');

            $step = 'restic_snapshots';
            $lockHandle->heartbeat(['step' => $step]);

            $snapshotsResult = $runner->snapshots();
            $meta['steps'][$step] = $this->formatProcessResult($snapshotsResult);
            $run->update(['meta' => $meta]);

            if ($snapshotsResult->exitCode !== 0 || ! is_array($snapshotsResult->parsedJson)) {
                throw new \RuntimeException('Unable to load snapshots from restic.');
            }

            $snapshots = $this->normalizeSnapshots($snapshotsResult->parsedJson);
            $latestSnapshot = $this->resolveLatestSnapshot($snapshots);

            if ($latestSnapshot === null) {
                throw new \RuntimeException('No snapshots found in the repository.');
            }

            $baselineSnapshot = $this->findSnapshotById($snapshots, $baselineSnapshotId);

            if ($baselineSnapshot === null) {
                throw new \RuntimeException('Baseline snapshot was not found in the repository.');
            }

            $targetSnapshotId = $baselineSnapshot['id'] !== ($latestSnapshot['id'] ?? null)
                ? (string) ($latestSnapshot['id'] ?? $baselineSnapshot['id'])
                : $baselineSnapshot['id'];
            $targetShortId = $latestSnapshot['short_id'] ?? substr($targetSnapshotId, 0, 8);

            $meta['snapshot_id'] = $targetSnapshotId;
            $meta['snapshot_short_id'] = $targetShortId;
            $meta['baseline_snapshot_id'] = $baselineSnapshot['id'];
            $meta['baseline_snapshot_short_id'] = $baselineSnapshot['short_id'] ?? substr($baselineSnapshot['id'], 0, 8);
            $meta['export']['baseline_snapshot_id'] = $baselineSnapshot['id'];
            $meta['export']['to_snapshot_id'] = $targetSnapshotId;
            $run->update(['meta' => $meta]);

            $short = substr($targetSnapshotId, 0, 8);
            $topFolder = "{$appSlug}-{$env}-dr-delta-{$short}-{$stamp}";
            $archiveName = "{$topFolder}.tar.gz";
            $archivePath = $baseDir . DIRECTORY_SEPARATOR . $archiveName;

            $workDir = $baseDir . DIRECTORY_SEPARATOR . "work-run-{$run->id}-{$stamp}";
            $restoreDir = $workDir . DIRECTORY_SEPARATOR . 'restore';
            $bundleDir = $workDir . DIRECTORY_SEPARATOR . 'bundle';

            $this->ensureDirectory($workDir);
            $this->ensureDirectory($restoreDir);
            $this->ensureDirectory($bundleDir);

            $meta['export']['archive_name'] = $archiveName;
            $meta['export']['archive_path'] = $archivePath;
            $meta['export']['work_dir'] = $workDir;
            $run->update(['meta' => $meta]);

            $step = 'restic_diff';
            $lockHandle->heartbeat(['step' => $step]);

            $diffResult = $runner->diff(
                $baselineSnapshot['id'],
                $targetSnapshotId,
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

            $meta['steps'][$step] = $this->formatProcessResult($diffResult);
            $run->update(['meta' => $meta]);

            if ($diffResult->exitCode !== 0) {
                throw new \RuntimeException('Restic diff failed.');
            }

            $diff = $this->parseDiffOutput($diffResult->stdout);
            $meta['export']['diff'] = [
                'added' => count($diff['added']),
                'modified' => count($diff['modified']),
                'deleted' => count($diff['deleted']),
            ];
            $run->update(['meta' => $meta]);

            $basePaths = $this->buildBasePaths(
                $projectRoot,
                $this->normalizeArray($baselineSnapshot['paths'] ?? []),
                $this->normalizeArray($latestSnapshot['paths'] ?? []),
            );

            $changedEntries = $this->mapDiffPaths(
                array_merge($diff['added'], $diff['modified']),
                $basePaths,
            );
            $deletedEntries = $this->mapDiffPaths($diff['deleted'], $basePaths);

            if ($excludePaths !== []) {
                $changedEntries = $this->filterEntriesByExcludes($changedEntries, $excludePaths);
                $deletedEntries = $this->filterEntriesByExcludes($deletedEntries, $excludePaths);
            }

            $changedRelative = $this->uniqueRelativePaths($changedEntries);
            $deletedRelative = $this->uniqueRelativePaths($deletedEntries);

            $meta['export']['changed_files'] = count($changedRelative);
            $meta['export']['deleted_files'] = count($deletedRelative);
            $run->update(['meta' => $meta]);

            $includePaths = $this->buildIncludePathsFromEntries($changedEntries);

            if ($includePaths !== []) {
                $chunked = array_chunk($includePaths, 200);
                $restoreChunks = [];
                $overallExit = 0;

                foreach ($chunked as $index => $chunk) {
                    $step = 'restic_restore';
                    $lockHandle->heartbeat(['step' => $step]);

                    $restoreResult = $runner->restore(
                        $targetSnapshotId,
                        $restoreDir,
                        [
                            'timeout' => $this->timeout,
                            'capture_output' => true,
                            'max_output_bytes' => self::META_OUTPUT_LIMIT,
                            'include' => $chunk,
                            'exclude' => $excludePaths,
                            'heartbeat' => function (array $context = []) use ($lockHandle, $step): void {
                                $lockHandle->heartbeat(array_merge(['step' => $step], $context));
                            },
                            'heartbeat_every' => 20,
                        ],
                    );

                    $chunkResult = $this->formatProcessResult($restoreResult);
                    $chunkResult['chunk_index'] = $index + 1;
                    $chunkResult['chunk_size'] = count($chunk);
                    $restoreChunks[] = $chunkResult;

                    if ($restoreResult->exitCode !== 0) {
                        $overallExit = $restoreResult->exitCode;
                        break;
                    }
                }

                $meta['steps']['restic_restore'] = [
                    'exit_code' => $overallExit,
                    'chunks' => $restoreChunks,
                ];
                $run->update(['meta' => $meta]);

                if ($overallExit !== 0) {
                    throw new \RuntimeException('Restic restore failed.');
                }
            }

            $targetProjectDir = $bundleDir . DIRECTORY_SEPARATOR . $topFolder;
            $filesDir = $targetProjectDir . DIRECTORY_SEPARATOR . 'files';
            $this->ensureDirectory($targetProjectDir);
            $this->ensureDirectory($filesDir);

            if ($changedEntries !== []) {
                $restoredBaseMap = $this->buildRestoredBaseMap($restoreDir, $basePaths);
                $restoredBaseMap[''] = $restoreDir;

                $missing = $this->copyDeltaEntries($changedEntries, $restoredBaseMap, $filesDir);

                if ($missing !== []) {
                    $meta['export']['missing_files'] = $missing;
                    $run->update(['meta' => $meta]);
                    throw new \RuntimeException('Some delta files were not found in the restored snapshot.');
                }
            }

            $generatedAt = now()->toIso8601String();
            $meta['export']['generated_at'] = $generatedAt;
            $run->update(['meta' => $meta]);

            $manifest = [
                'baseline_snapshot_id' => $baselineSnapshot['id'],
                'to_snapshot_id' => $targetSnapshotId,
                'generated_at' => $generatedAt,
                'deleted' => $deletedRelative,
            ];

            @file_put_contents(
                $targetProjectDir . DIRECTORY_SEPARATOR . DisasterRecoveryExport::MANIFEST_NAME,
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );

            DisasterRecoveryExport::writeReadmeDelta($targetProjectDir, [
                'baseline_snapshot_id' => $baselineSnapshot['id'],
                'to_snapshot_id' => $targetSnapshotId,
                'generated_at' => $generatedAt,
            ]);
            DisasterRecoveryExport::writeTools($targetProjectDir);

            $step = 'pack_tar_gz';
            $lockHandle->heartbeat(['step' => $step]);

            $tar = $this->findBinary('tar');

            $pack = $this->runProcess(
                [$tar, '-C', $bundleDir, '-czf', $archivePath, $topFolder],
                cwd: null,
            );

            $meta['steps'][$step] = $pack;
            $run->update(['meta' => $meta]);

            if (($pack['exit_code'] ?? 1) !== 0 || ! is_file($archivePath)) {
                throw new \RuntimeException('Archive packing failed.');
            }

            @chmod($archivePath, 0640);

            $meta['export']['archive_size'] = @filesize($archivePath) ?: null;
            $meta['export']['archive_sha256'] = @hash_file('sha256', $archivePath) ?: null;

            $expiresAt = now()->addHours(max(1, (int) $this->keepHours));
            $meta['export']['expires_at'] = $expiresAt->toIso8601String();

            $run->update([
                'status' => 'success',
                'finished_at' => now(),
                'meta' => $meta,
            ]);

            $meta = $this->scheduleArchiveCleanup($run, $meta, $expiresAt);

            $this->notifyArchiveReady($run, $meta);
        } catch (Throwable $exception) {
            if ($run instanceof BackupRun) {
                $meta['error_class'] = $exception::class;
                $meta['error_message'] = $this->sanitizeErrorMessage($exception->getMessage(), $settings);

                $run->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'meta' => $meta,
                ]);
            }

            // Remove any partially created archive to avoid leftovers.
            if (is_string($archivePath) && $archivePath !== '' && is_file($archivePath)) {
                @unlink($archivePath);
            }

            throw $exception;
        } finally {
            // Always clean the work directory.
            if (is_string($workDir) && $workDir !== '') {
                $this->removeDirectoryRecursive($workDir);
            }

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

        $pending = self::dispatch(
            $this->keepHours,
            $this->userId,
            $this->trigger,
        )->delay($delay);

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

    protected function resolveProjectRoot(BackupSetting $settings): string
    {
        $projectRoot = $this->normalizeScalar($settings->project_root)
            ?? $this->normalizeScalar(config('restic-backups.paths.project_root', base_path()))
            ?? base_path();

        return $projectRoot;
    }

    protected function resolveRestoredProjectPath(string $restoreDir, string $projectRoot): string
    {
        $relative = ltrim($projectRoot, DIRECTORY_SEPARATOR);

        return rtrim($restoreDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
    }

    /**
     * @param  array<int, mixed>  $snapshots
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeSnapshots(array $snapshots): array
    {
        $normalized = [];

        foreach ($snapshots as $index => $snapshot) {
            if (! is_array($snapshot)) {
                continue;
            }

            $id = $this->normalizeScalar($snapshot['id'] ?? null);
            $shortId = $this->normalizeScalar($snapshot['short_id'] ?? null);

            if ($shortId === null && $id !== null) {
                $shortId = Str::substr($id, 0, 8);
            }

            $time = $this->normalizeScalar($snapshot['time'] ?? null);

            $normalized[] = [
                'id' => $id,
                'short_id' => $shortId ?? $id ?? (string) $index,
                'time' => $time,
                'time_unix' => $this->parseTimeToTimestamp($time),
                'paths' => $this->normalizeArray($snapshot['paths'] ?? []),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshots
     */
    protected function resolveLatestSnapshot(array $snapshots): ?array
    {
        $latest = null;
        $latestTimestamp = 0;

        foreach ($snapshots as $snapshot) {
            $timestamp = (int) ($snapshot['time_unix'] ?? 0);

            if ($timestamp <= 0) {
                continue;
            }

            if ($latest === null || $timestamp >= $latestTimestamp) {
                $latest = $snapshot;
                $latestTimestamp = $timestamp;
            }
        }

        if ($latest === null && $snapshots !== []) {
            return $snapshots[0];
        }

        return $latest;
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshots
     */
    protected function findSnapshotById(array $snapshots, string $snapshotId): ?array
    {
        $snapshotId = trim($snapshotId);

        foreach ($snapshots as $snapshot) {
            $id = $this->normalizeScalar($snapshot['id'] ?? null);
            $shortId = $this->normalizeScalar($snapshot['short_id'] ?? null);

            if ($id === null && $shortId === null) {
                continue;
            }

            if ($id === $snapshotId || $shortId === $snapshotId) {
                return $snapshot;
            }

            if ($id !== null && str_starts_with($id, $snapshotId)) {
                return $snapshot;
            }
        }

        return null;
    }

    /**
     * @return array{added: array<int, string>, modified: array<int, string>, deleted: array<int, string>}
     */
    protected function parseDiffOutput(string $output): array
    {
        $changes = [
            'added' => [],
            'modified' => [],
            'deleted' => [],
        ];

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (! preg_match('/^([AMD])\\s+(.+)$/', $line, $matches)) {
                continue;
            }

            $type = $matches[1] ?? '';
            $path = trim($matches[2] ?? '');

            if ($path === '') {
                continue;
            }

            switch ($type) {
                case 'A':
                    $changes['added'][] = $path;
                    break;
                case 'M':
                    $changes['modified'][] = $path;
                    break;
                case 'D':
                    $changes['deleted'][] = $path;
                    break;
                default:
                    break;
            }
        }

        return $changes;
    }

    /**
     * @param  array<int, string>  $baselinePaths
     * @param  array<int, string>  $latestPaths
     * @return array<int, string>
     */
    protected function buildBasePaths(string $projectRoot, array $baselinePaths, array $latestPaths): array
    {
        $paths = [$projectRoot];

        foreach (array_merge($baselinePaths, $latestPaths) as $path) {
            if (! is_string($path) && ! is_numeric($path)) {
                continue;
            }

            $paths[] = (string) $path;
        }

        $normalized = [];

        foreach ($paths as $path) {
            $path = $this->normalizePath((string) $path);

            if ($path === '') {
                continue;
            }

            $normalized[$path] = true;
        }

        return array_keys($normalized);
    }

    /**
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $basePaths
     * @return array<int, array{relative: string, base: string}>
     */
    protected function mapDiffPaths(array $paths, array $basePaths): array
    {
        $entries = [];

        foreach ($paths as $path) {
            if (! is_string($path) && ! is_numeric($path)) {
                continue;
            }

            $resolved = $this->resolveRelativePath((string) $path, $basePaths);

            if ($resolved === null) {
                continue;
            }

            $entries[] = $resolved;
        }

        return $entries;
    }

    /**
     * @param  array<int, array{relative: string, base: string}>  $entries
     * @return array<int, string>
     */
    protected function uniqueRelativePaths(array $entries): array
    {
        $unique = [];

        foreach ($entries as $entry) {
            $relative = $entry['relative'] ?? null;

            if (! is_string($relative) || $relative === '') {
                continue;
            }

            $unique[$relative] = true;
        }

        return array_values(array_keys($unique));
    }

    /**
     * @param  array<int, array{relative: string, base: string}>  $entries
     * @param  array<int, string>  $excludePaths
     * @return array<int, array{relative: string, base: string}>
     */
    protected function filterEntriesByExcludes(array $entries, array $excludePaths): array
    {
        if ($entries === [] || $excludePaths === []) {
            return $entries;
        }

        $filtered = [];

        foreach ($entries as $entry) {
            $relative = $entry['relative'] ?? null;

            if (! is_string($relative) || $relative === '') {
                continue;
            }

            if ($this->isExcludedRelativePath($relative, $excludePaths)) {
                continue;
            }

            $filtered[] = $entry;
        }

        return $filtered;
    }

    /**
     * @param  array<int, array{relative: string, base: string}>  $entries
     * @return array<int, string>
     */
    protected function buildIncludePathsFromEntries(array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        $paths = [];

        foreach ($entries as $entry) {
            $relative = $entry['relative'] ?? null;
            $base = $entry['base'] ?? null;

            if (! is_string($relative) || $relative === '') {
                continue;
            }

            $base = is_string($base) ? $this->normalizePath($base) : '';
            $relative = $this->normalizePath($relative);

            if ($relative === '') {
                continue;
            }

            $path = $base === '' ? $relative : $base . '/' . $relative;
            $paths[$path] = true;
        }

        return array_values(array_keys($paths));
    }

    /**
     * @param  array<int, string>  $basePaths
     * @return array<string, string>
     */
    protected function buildRestoredBaseMap(string $restoreDir, array $basePaths): array
    {
        $map = [];
        $restoreDir = rtrim($restoreDir, DIRECTORY_SEPARATOR);

        foreach ($basePaths as $basePath) {
            $basePath = $this->normalizePath($basePath);

            if ($basePath === '') {
                continue;
            }

            $relative = ltrim($basePath, '/');
            $relative = str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $candidate = $restoreDir . DIRECTORY_SEPARATOR . $relative;

            if (is_dir($candidate)) {
                $map[$basePath] = $candidate;
            }
        }

        return $map;
    }

    /**
     * @param  array<int, array{relative: string, base: string}>  $entries
     * @param  array<string, string>  $restoredBaseMap
     * @return array<int, string>
     */
    protected function copyDeltaEntries(array $entries, array $restoredBaseMap, string $filesDir): array
    {
        $missing = [];
        $filesDir = rtrim($filesDir, DIRECTORY_SEPARATOR);

        foreach ($entries as $entry) {
            $relative = $entry['relative'] ?? null;
            $base = $entry['base'] ?? '';

            if (! is_string($relative) || $relative === '') {
                continue;
            }

            $sourceBase = $restoredBaseMap[$base] ?? $restoredBaseMap[''] ?? null;

            if ($sourceBase === null) {
                $missing[] = $relative;
                continue;
            }

            $relativeFs = str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $source = rtrim($sourceBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativeFs;
            $destination = $filesDir . DIRECTORY_SEPARATOR . $relativeFs;

            if (is_dir($source)) {
                $this->ensureDirectory($destination);
                continue;
            }

            if (is_link($source)) {
                $target = readlink($source);
                if ($target === false) {
                    $missing[] = $relative;
                    continue;
                }

                $this->ensureDirectory(dirname($destination));
                @symlink($target, $destination);
                continue;
            }

            if (! is_file($source)) {
                $missing[] = $relative;
                continue;
            }

            $this->ensureDirectory(dirname($destination));

            if (! @copy($source, $destination)) {
                $missing[] = $relative;
            }
        }

        return $missing;
    }

    /**
     * @param  array<int, string>  $basePaths
     * @return array{relative: string, base: string} | null
     */
    protected function resolveRelativePath(string $path, array $basePaths): ?array
    {
        $path = $this->normalizePath($path);

        if ($path === '') {
            return null;
        }

        foreach ($basePaths as $basePath) {
            $basePath = $this->normalizePath($basePath);

            if ($basePath === '') {
                continue;
            }

            if ($path === $basePath) {
                return null;
            }

            $prefix = $basePath . '/';

            if (str_starts_with($path, $prefix)) {
                $relative = substr($path, strlen($prefix));
                $relative = $this->sanitizeRelativePath($relative);

                if ($relative === null) {
                    return null;
                }

                return [
                    'relative' => $relative,
                    'base' => $basePath,
                ];
            }
        }

        if (! str_starts_with($path, '/')) {
            $relative = $this->sanitizeRelativePath($path);

            if ($relative === null) {
                return null;
            }

            return [
                'relative' => $relative,
                'base' => '',
            ];
        }

        return null;
    }

    protected function normalizePath(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = str_replace('\\', '/', $value);

        return rtrim($value, '/');
    }

    /**
     * @param  array<int, string>  $excludePaths
     */
    protected function isExcludedRelativePath(string $relative, array $excludePaths): bool
    {
        $relative = $this->normalizePath($relative);
        $relative = ltrim($relative, '/');

        if ($relative === '') {
            return false;
        }

        foreach ($excludePaths as $exclude) {
            if (! is_string($exclude) && ! is_numeric($exclude)) {
                continue;
            }

            $exclude = $this->normalizePath((string) $exclude);
            $exclude = ltrim($exclude, '/');

            if ($exclude === '') {
                continue;
            }

            if ($relative === $exclude || str_starts_with($relative, $exclude . '/')) {
                return true;
            }
        }

        return false;
    }

    protected function sanitizeRelativePath(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = str_replace('\\', '/', $value);
        $value = ltrim($value, '/');

        while (str_starts_with($value, './')) {
            $value = substr($value, 2);
        }

        if ($value === '') {
            return null;
        }

        $parts = explode('/', $value);

        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return null;
            }
        }

        return implode('/', $parts);
    }

    /**
     * @return array<int, string>
     */
    protected function resolveExcludePaths(BackupSetting $settings): array
    {
        $paths = is_array($settings->paths) ? $settings->paths : [];

        return $this->normalizePathList($paths['exclude'] ?? []);
    }

    /**
     * @param  array<int, mixed>  $paths
     * @return array<int, string>
     */
    protected function normalizePathList(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            if (! is_string($path) && ! is_numeric($path)) {
                continue;
            }

            $path = trim((string) $path);

            if ($path === '') {
                continue;
            }

            $path = str_replace('\\', '/', $path);
            $path = ltrim($path, '/');
            $path = rtrim($path, '/');

            if ($path === '') {
                continue;
            }

            $normalized[$path] = true;
        }

        return array_values(array_keys($normalized));
    }

    protected function parseTimeToTimestamp(?string $value): int
    {
        if ($value === null) {
            return 0;
        }

        try {
            return Carbon::parse($value)->getTimestamp();
        } catch (Throwable) {
            return 0;
        }
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

    protected function removeDirectoryRecursive(string $path): void
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);

        if ($path === '' || ! is_dir($path)) {
            return;
        }

        $it = new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS);
        $ri = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($ri as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }

    /**
     * @param  array<int, string>  $command
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

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    protected function scheduleArchiveCleanup(BackupRun $run, array $meta, Carbon $expiresAt): array
    {
        if (! $expiresAt->greaterThan(now())) {
            return $meta;
        }

        $export = is_array($meta['export'] ?? null) ? $meta['export'] : [];

        if (! $this->shouldScheduleCleanup()) {
            $export['cleanup_scheduled'] = false;
            $meta['export'] = $export;
            $run->update(['meta' => $meta]);

            return $meta;
        }

        $pending = CleanupExportArchiveJob::dispatch((int) $run->getKey())
            ->delay($expiresAt);

        if ($this->queue) {
            $pending->onQueue($this->queue);
        }

        if ($this->connection) {
            $pending->onConnection($this->connection);
        }

        $export['cleanup_scheduled'] = true;
        $export['cleanup_scheduled_at'] = $expiresAt->toIso8601String();
        $meta['export'] = $export;

        $run->update(['meta' => $meta]);

        return $meta;
    }

    protected function shouldScheduleCleanup(): bool
    {
        $connection = $this->connection ?? config('queue.default');

        return $connection !== 'sync';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function notifyArchiveReady(BackupRun $run, array $meta): void
    {
        if ($this->userId === null) {
            return;
        }

        $user = $this->resolveNotificationUser($this->userId);

        if (! $user || ! method_exists($user, 'notify')) {
            return;
        }

        $export = is_array($meta['export'] ?? null) ? $meta['export'] : [];
        $archivePath = $this->normalizeScalar($export['archive_path'] ?? null);
        $archiveName = $this->normalizeScalar($export['archive_name'] ?? null);

        if ($archivePath === null || $archiveName === null) {
            return;
        }

        $expiresAt = $this->parseArchiveExpiresAt($export['expires_at'] ?? null);

        if ($expiresAt instanceof Carbon && now()->greaterThan($expiresAt)) {
            return;
        }

        $downloadUrl = URL::temporarySignedRoute(
            'restic-backups.exports.download',
            $this->resolveArchiveLinkExpiry($expiresAt),
            ['run' => $run->getKey()],
            absolute: false,
        );

        $snapshotLabel = $this->normalizeScalar($meta['snapshot_short_id'] ?? null);
        if ($snapshotLabel === null) {
            $fallbackId = $this->normalizeScalar($export['to_snapshot_id'] ?? null);
            $snapshotLabel = $fallbackId !== null ? substr($fallbackId, 0, 8) : 'unknown';
        }

        try {
            Notification::make()
                ->title(__('restic-backups::backups.pages.snapshots.notifications.export_ready'))
                ->body(__('restic-backups::backups.pages.snapshots.notifications.export_ready_body', [
                    'snapshot' => $snapshotLabel,
                ]))
                ->success()
                ->actions([
                    Action::make('download')
                        ->label(__('restic-backups::backups.pages.snapshots.archive.download'))
                        ->url($downloadUrl, shouldOpenInNewTab: true),
                ])
                ->sendToDatabase($user, isEventDispatched: true);
        } catch (Throwable) {
            // Notification failure should not fail the export job.
        }
    }

    protected function resolveNotificationUser(int $userId): ?Authenticatable
    {
        $model = config('auth.providers.users.model');

        if (! is_string($model) || $model === '' || ! is_subclass_of($model, Authenticatable::class)) {
            return null;
        }

        $user = $model::query()->find($userId);

        return $user instanceof Authenticatable ? $user : null;
    }

    protected function resolveArchiveLinkExpiry(?Carbon $expiresAt): Carbon
    {
        $defaultExpiry = now()->addMinutes(60);

        if (! $expiresAt instanceof Carbon) {
            return $defaultExpiry;
        }

        if ($expiresAt->lessThan($defaultExpiry) && $expiresAt->greaterThan(now())) {
            return $expiresAt;
        }

        return $defaultExpiry;
    }

    protected function parseArchiveExpiresAt(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        $value = $this->normalizeScalar($value);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
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
