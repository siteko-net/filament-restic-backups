<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Jobs;

use Carbon\Carbon;
use Carbon\CarbonInterface;
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
use Siteko\FilamentResticBackups\Support\OperationLock;
use Siteko\FilamentResticBackups\Support\OperationLockHandle;
use Siteko\FilamentResticBackups\Support\ProjectRootResolver;
use Siteko\FilamentResticBackups\Support\S3ExportStorage;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

class ExportSnapshotS3StreamJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const LOCK_TTL_SECONDS = 14400;

    private const LOCK_BLOCK_SECONDS = 30;

    private const META_OUTPUT_LIMIT = 204800;

    private const REQUEUE_DELAYS = [60, 120, 300];

    public int $timeout = 14400;

    public int $tries = 1;

    public array $backoff = [60];

    public function __construct(
        public string $snapshotId,
        public int $keepHours = 24,
        public ?int $userId = null,
        public string $trigger = 'filament',
    ) {}

    public function handle(OperationLock $operationLock, S3ExportStorage $storage): void
    {
        $lockHandle = $operationLock->acquire(
            'export_snapshot_s3',
            $this->lockTtl(),
            self::LOCK_BLOCK_SECONDS,
            [
                'snapshot_id' => $this->snapshotId,
                'trigger' => $this->trigger,
            ],
        );

        if (! $lockHandle instanceof OperationLockHandle) {
            $this->requeueOrReturn();

            return;
        }

        $run = null;
        $settings = null;
        $bucket = null;
        $objectKey = null;

        $meta = [
            'snapshot_id' => $this->snapshotId,
            'snapshot_short_id' => substr($this->snapshotId, 0, 8),
            'trigger' => $this->trigger,
            'export' => [
                'format' => 'tar.gz',
                'kind' => 'snapshot_stream',
                'storage' => 's3',
                'include_env' => true,
                'keep_hours' => $this->keepHours,
                'streamed' => true,
            ],
        ];

        if ($this->userId !== null) {
            $meta['initiator_user_id'] = $this->userId;
        }

        try {
            $settings = BackupSetting::singleton();
            $client = $storage->client($settings);
            $bucket = $storage->bucket($settings);

            $run = BackupRun::query()->create([
                'type' => 'export_snapshot_stream',
                'status' => 'running',
                'started_at' => now(),
                'meta' => $meta,
            ]);
            $lockHandle->setRunId($run->id);

            $appSlug = Str::slug((string) config('app.name', 'app')) ?: 'app';
            $env = (string) (config('app.env', 'production') ?: 'production');
            $short = substr($this->snapshotId, 0, 8);
            $stamp = now()->format('YmdHis');
            $archiveName = "{$appSlug}-{$env}-snapshot-{$short}-{$stamp}-stream.tar.gz";
            $objectKey = $storage->exportKey($this->snapshotId, $archiveName);

            $meta['export']['archive_name'] = $archiveName;
            $meta['export']['bucket'] = $bucket;
            $meta['export']['object_key'] = $objectKey;
            $meta['export']['s3_prefix'] = dirname($objectKey);
            $meta['export']['warning'] = 'Stream export includes all files from the snapshot, including .env if present.';
            $run->update(['meta' => $meta]);

            $step = 'stream_restic_dump_upload';
            $lockHandle->heartbeat(['step' => $step]);

            $result = $this->streamResticDumpToS3(
                settings: $settings,
                storage: $storage,
                bucket: $bucket,
                objectKey: $objectKey,
                lockHandle: $lockHandle,
            );

            $meta['steps'][$step] = $result;
            $meta['export']['archive_size'] = $result['archive_size'] ?? null;
            $meta['export']['archive_etag'] = $result['etag'] ?? null;
            $meta['export']['object_url'] = $result['object_url'] ?? null;

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

            if ($settings instanceof BackupSetting && is_string($bucket) && is_string($objectKey)) {
                try {
                    $storage->deleteObject($storage->client($settings), $bucket, $objectKey);
                } catch (Throwable) {
                    // Best effort cleanup after failed stream export.
                }
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

        $pending = self::dispatch(
            $this->snapshotId,
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

    /**
     * @return array<string, mixed>
     */
    protected function streamResticDumpToS3(
        BackupSetting $settings,
        S3ExportStorage $storage,
        string $bucket,
        string $objectKey,
        OperationLockHandle $lockHandle,
    ): array {
        $start = microtime(true);
        $stderrPath = tempnam(sys_get_temp_dir(), 'restic-stream-stderr-');

        if ($stderrPath === false) {
            throw new \RuntimeException('Unable to allocate stderr file for stream export.');
        }

        $command = $this->buildPipelineCommand($settings);
        $env = $this->buildResticEnvironment($settings);

        $process = @proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', $stderrPath, 'w'],
            ],
            $pipes,
            ProjectRootResolver::configuredOrCurrent($settings->project_root),
            $env,
        );

        if (! is_resource($process)) {
            @unlink($stderrPath);

            throw new \RuntimeException('Failed to start restic stream export process.');
        }

        fclose($pipes[0]);

        $upload = null;
        $exitCode = 1;
        $uploadException = null;

        try {
            $upload = $storage->uploadStream(
                $storage->client($settings),
                $bucket,
                $objectKey,
                $pipes[1],
                function (array $context = []) use ($lockHandle): void {
                    $lockHandle->heartbeat(array_merge(['step' => 'stream_restic_dump_upload'], $context));
                },
            );
        } catch (Throwable $exception) {
            $uploadException = $exception;
        } finally {
            if (is_resource($pipes[1])) {
                fclose($pipes[1]);
            }

            $exitCode = proc_close($process);
        }

        $stderr = is_file($stderrPath) ? (string) file_get_contents($stderrPath) : '';
        @unlink($stderrPath);

        $stderr = $this->sanitizeErrorMessage($stderr, $settings);

        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $result = [
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
            'stderr' => $this->truncateString($stderr, self::META_OUTPUT_LIMIT),
            'stdout' => '',
            'command' => $this->safeCommandString($command),
            'bucket' => $bucket,
            'object_key' => $objectKey,
            'archive_size' => is_array($upload) ? ($upload['size_bytes'] ?? null) : null,
            'etag' => is_array($upload) ? ($upload['etag'] ?? null) : null,
            'object_url' => is_array($upload) ? ($upload['object_url'] ?? null) : null,
            'parts' => is_array($upload) ? ($upload['parts'] ?? null) : null,
        ];

        if ($uploadException instanceof Throwable) {
            throw new \RuntimeException(
                'S3 stream upload failed. '.$uploadException->getMessage().' '.$this->truncateString($stderr, 2000),
                0,
                $uploadException,
            );
        }

        if ($exitCode !== 0) {
            throw new \RuntimeException('Restic stream export failed. '.$this->truncateString($stderr, 2000));
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    protected function buildPipelineCommand(BackupSetting $settings): array
    {
        $restic = $this->findBinary((string) config('restic-backups.restic.binary', 'restic'));
        $gzip = $this->findBinary('gzip');
        $bash = $this->findBinary('bash');
        $cacheDir = $this->normalizeScalar(config('restic-backups.restic.cache_dir'));
        $retryLock = $this->normalizeScalar(config('restic-backups.restic.retry_lock'));

        $resticCommand = [$restic];

        if ($cacheDir !== null) {
            $resticCommand[] = '--cache-dir';
            $resticCommand[] = $cacheDir;
        }

        if ($retryLock !== null) {
            $resticCommand[] = '--retry-lock';
            $resticCommand[] = $retryLock;
        }

        $resticCommand = array_merge($resticCommand, [
            'dump',
            $this->snapshotId,
            '/',
            '--archive',
            'tar',
        ]);

        $pipeline = implode(' ', array_map('escapeshellarg', $resticCommand))
            .' | '.escapeshellarg($gzip).' -c';

        return [$bash, '-o', 'pipefail', '-c', $pipeline];
    }

    /**
     * @return array<string, string>
     */
    protected function buildResticEnvironment(BackupSetting $settings): array
    {
        $repository = $this->resolveRepository($settings);
        $password = $this->normalizeScalar($settings->restic_password);
        $accessKey = $this->normalizeScalar($settings->access_key);
        $secretKey = $this->normalizeScalar($settings->secret_key);

        if ($repository === null || $password === null || $accessKey === null || $secretKey === null) {
            throw new \RuntimeException('Restic repository, password, and S3 credentials are required for stream export.');
        }

        return [
            'RESTIC_REPOSITORY' => $repository,
            'RESTIC_PASSWORD' => $password,
            'AWS_ACCESS_KEY_ID' => $accessKey,
            'AWS_SECRET_ACCESS_KEY' => $secretKey,
        ];
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

        $prefix = $this->normalizeScalar($settings->repository_prefix ?? $settings->prefix);
        $repository = 's3:'.rtrim($endpoint, '/').'/'.trim($bucket, '/');

        if ($prefix !== null) {
            $repository .= '/'.ltrim($prefix, '/');
        }

        return $repository;
    }

    protected function findBinary(string $binary): string
    {
        if (str_contains($binary, DIRECTORY_SEPARATOR) && is_file($binary) && is_executable($binary)) {
            return $binary;
        }

        $finder = new ExecutableFinder;
        $path = $finder->find($binary);

        if ($path === null) {
            throw new \RuntimeException("Binary [{$binary}] not found.");
        }

        return $path;
    }

    /**
     * @param  array<int, string>  $command
     */
    protected function safeCommandString(array $command): string
    {
        return implode(' ', array_map(function (string $argument): string {
            if ($argument === '') {
                return "''";
            }

            if (preg_match('/\s|["\\\\]/', $argument) !== 1) {
                return $argument;
            }

            return '"'.addcslashes($argument, '"\\').'"';
        }, $command));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    protected function scheduleArchiveCleanup(BackupRun $run, array $meta, CarbonInterface $expiresAt): array
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

        $expiresAt = $this->parseArchiveExpiresAt(data_get($meta, 'export.expires_at'));

        if ($expiresAt instanceof CarbonInterface && now()->greaterThan($expiresAt)) {
            return;
        }

        $downloadUrl = URL::temporarySignedRoute(
            'restic-backups.exports.download',
            $this->resolveArchiveLinkExpiry($expiresAt),
            ['run' => $run->getKey()],
            absolute: false,
        );

        $snapshotLabel = $this->normalizeScalar($meta['snapshot_short_id'] ?? null)
            ?? substr($this->snapshotId, 0, 8);

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

    protected function resolveArchiveLinkExpiry(?CarbonInterface $expiresAt): CarbonInterface
    {
        $defaultExpiry = now()->addMinutes(60);

        if (! $expiresAt instanceof CarbonInterface) {
            return $defaultExpiry;
        }

        if ($expiresAt->lessThan($defaultExpiry) && $expiresAt->greaterThan(now())) {
            return $expiresAt;
        }

        return $defaultExpiry;
    }

    protected function parseArchiveExpiresAt(mixed $value): ?CarbonInterface
    {
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

    protected function truncateString(string $value, int $limit): string
    {
        if ($value === '' || $limit <= 0) {
            return $limit <= 0 ? '' : $value;
        }

        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit).PHP_EOL.'...[truncated]';
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

    protected function sanitizeErrorMessage(string $message, ?BackupSetting $settings): string
    {
        $message = $this->truncateString($message, self::META_OUTPUT_LIMIT);

        if (! $settings instanceof BackupSetting) {
            return $message;
        }

        foreach ([
            $this->normalizeScalar($settings->access_key),
            $this->normalizeScalar($settings->secret_key),
            $this->normalizeScalar($settings->restic_password),
        ] as $secret) {
            if ($secret !== null && $secret !== '') {
                $message = str_replace($secret, '***', $message);
            }
        }

        return $message;
    }
}
