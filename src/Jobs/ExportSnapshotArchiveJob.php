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
use Siteko\FilamentResticBackups\Support\OperationLock;
use Siteko\FilamentResticBackups\Support\OperationLockHandle;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class ExportSnapshotArchiveJob implements ShouldQueue
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
        public string $snapshotId,
        public bool $includeEnv = false,
        public int $keepHours = 24,
        public ?int $userId = null,
        public string $trigger = 'filament',
    ) {
    }

    public function handle(OperationLock $operationLock, ResticRunner $runner): void
    {
        $lockHandle = $operationLock->acquire(
            'export_snapshot',
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

        $meta = [
            'snapshot_id' => $this->snapshotId,
            'snapshot_short_id' => substr($this->snapshotId, 0, 8),
            'trigger' => $this->trigger,
            'export' => [
                'format' => 'tar.gz',
                'include_env' => $this->includeEnv,
                'keep_hours' => $this->keepHours,
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

            $run = BackupRun::query()->create([
                'type' => 'export_snapshot',
                'status' => 'running',
                'started_at' => now(),
                'meta' => $meta,
            ]);
            $lockHandle->setRunId($run->id);

            $this->ensureDirectory($baseDir);

            $projectRoot = $this->resolveProjectRoot($settings);

            $appSlug = Str::slug((string) config('app.name', 'app')) ?: 'app';
            $env = (string) (config('app.env', 'production') ?: 'production');
            $short = substr($this->snapshotId, 0, 8);
            $stamp = now()->format('YmdHis');

            $topFolder = "{$appSlug}-{$env}-snapshot-{$short}-{$stamp}";
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

            $step = 'restic_restore';
            $lockHandle->heartbeat(['step' => $step]);

            $restoreResult = $runner->restore(
                $this->snapshotId,
                $restoreDir,
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

            $meta['steps'][$step] = $this->formatProcessResult($restoreResult);
            $run->update(['meta' => $meta]);

            if ($restoreResult->exitCode !== 0) {
                throw new \RuntimeException('Restic restore failed.');
            }

            $restoredProjectPath = $this->resolveRestoredProjectPath($restoreDir, $projectRoot);

            if (! is_dir($restoredProjectPath)) {
                throw new \RuntimeException('Restored project path was not found in the snapshot.');
            }

            // Переименовываем восстановленный проект в “красивую” корневую папку архива (без /var/www/...)
            $targetProjectDir = $bundleDir . DIRECTORY_SEPARATOR . $topFolder;

            if (! @rename($restoredProjectPath, $targetProjectDir)) {
                throw new \RuntimeException('Failed to move restored project directory into bundle.');
            }

            // По умолчанию НЕ включаем .env (безопаснее для “скачать как архив”)
            if (! $this->includeEnv) {
                $envFile = $targetProjectDir . DIRECTORY_SEPARATOR . '.env';
                if (is_file($envFile)) {
                    @unlink($envFile);
                }
            }

            // Манифест (удобно понимать что внутри)
            $manifest = [
                'snapshot_id' => $this->snapshotId,
                'generated_at' => now()->toIso8601String(),
                'app' => (string) config('app.name'),
                'env' => (string) config('app.env'),
                'project_root' => $projectRoot,
                'include_env' => $this->includeEnv,
            ];
            @file_put_contents(
                $targetProjectDir . DIRECTORY_SEPARATOR . '_snapshot_export.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );

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

            // если архив успел появиться — прибьём, чтобы не оставлять мусор
            if (is_string($archivePath) && $archivePath !== '' && is_file($archivePath)) {
                @unlink($archivePath);
            }

            throw $exception;
        } finally {
            // workdir чистим всегда
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
            $this->snapshotId,
            $this->includeEnv,
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
