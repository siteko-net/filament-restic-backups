<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Siteko\FilamentResticBackups\Models\BackupRun;
use Siteko\FilamentResticBackups\Models\BackupSetting;
use Throwable;

class CleanupRollbackDirJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(
        public string $path,
        public int $restoreRunId,
        public int $notBeforeTimestamp,
    ) {
    }

    public function handle(Filesystem $filesystem): void
    {
        $run = BackupRun::query()->find($this->restoreRunId);
        $now = Carbon::now();
        $notBefore = Carbon::createFromTimestamp($this->notBeforeTimestamp);

        if ($now->lt($notBefore)) {
            $this->updateCleanupMeta($run, [
                'scheduled' => true,
                'not_before' => $notBefore->toIso8601String(),
                'skipped' => 'not_due',
            ]);

            return;
        }

        $projectRoot = $this->resolveProjectRoot();

        if (! $this->isSafeRollbackPath($this->path, $projectRoot)) {
            $this->updateCleanupMeta($run, [
                'attempted_at' => $now->toIso8601String(),
                'path' => $this->path,
                'done' => false,
                'error' => 'Unsafe rollback path. Cleanup skipped.',
            ]);

            return;
        }

        if (! is_dir($this->path)) {
            $this->updateCleanupMeta($run, [
                'attempted_at' => $now->toIso8601String(),
                'path' => $this->path,
                'done' => true,
                'note' => 'Rollback directory was already removed.',
            ]);

            return;
        }

        try {
            $deleted = $filesystem->deleteDirectory($this->path);
        } catch (Throwable $exception) {
            $this->updateCleanupMeta($run, [
                'attempted_at' => $now->toIso8601String(),
                'path' => $this->path,
                'done' => false,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if (! $deleted && is_dir($this->path)) {
            $this->updateCleanupMeta($run, [
                'attempted_at' => $now->toIso8601String(),
                'path' => $this->path,
                'done' => false,
                'error' => 'Failed to delete rollback directory.',
            ]);

            return;
        }

        $this->updateCleanupMeta($run, [
            'attempted_at' => $now->toIso8601String(),
            'path' => $this->path,
            'done' => true,
        ]);
    }

    protected function resolveProjectRoot(): string
    {
        try {
            $settings = BackupSetting::singleton();
            $root = $this->normalizeScalar($settings->project_root);
        } catch (Throwable) {
            $root = null;
        }

        return $root
            ?? (string) config('restic-backups.paths.project_root', base_path());
    }

    protected function isSafeRollbackPath(string $path, string $projectRoot): bool
    {
        $projectRootReal = realpath($projectRoot) ?: $projectRoot;
        $parentReal = realpath(dirname($projectRootReal)) ?: dirname($projectRootReal);
        $pathReal = realpath($path) ?: $path;

        if ($pathReal === $projectRootReal) {
            return false;
        }

        $prefix = basename($projectRootReal) . '.__before_restore_';

        if (! str_starts_with(basename($pathReal), $prefix)) {
            return false;
        }

        return str_starts_with($pathReal, $parentReal . DIRECTORY_SEPARATOR);
    }

    protected function updateCleanupMeta(?BackupRun $run, array $data): void
    {
        if (! $run instanceof BackupRun) {
            return;
        }

        $meta = $run->meta ?? [];
        $cleanup = $meta['cleanup'] ?? [];
        $meta['cleanup'] = array_merge($cleanup, $data);

        $run->update(['meta' => $meta]);
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
}
