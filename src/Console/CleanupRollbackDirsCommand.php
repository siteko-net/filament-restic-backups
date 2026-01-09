<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Siteko\FilamentResticBackups\Models\BackupSetting;
use Throwable;

class CleanupRollbackDirsCommand extends Command
{
    protected $signature = 'restic-backups:cleanup-rollbacks {--hours=24 : Remove rollback dirs older than this} {--dry-run : Show what would be removed}';

    protected $description = 'Remove stale __before_restore_ rollback directories.';

    public function handle(Filesystem $filesystem): int
    {
        $hours = (int) $this->option('hours');
        $dryRun = (bool) $this->option('dry-run');
        $hours = $hours > 0 ? $hours : 24;

        $projectRoot = $this->resolveProjectRoot();
        $parent = dirname($projectRoot);

        if (! is_dir($parent)) {
            $this->error("Parent directory not found: {$parent}");

            return self::FAILURE;
        }

        $prefix = basename($projectRoot) . '.__before_restore_';
        $cutoff = Carbon::now()->subHours($hours);
        $removed = 0;

        $entries = scandir($parent) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (! str_starts_with($entry, $prefix)) {
                continue;
            }

            $path = $parent . DIRECTORY_SEPARATOR . $entry;

            if (! is_dir($path)) {
                continue;
            }

            if (! $this->isSafeRollbackPath($path, $projectRoot)) {
                $this->warn("Skip unsafe path: {$path}");
                continue;
            }

            $timestamp = $this->extractTimestamp($entry, $prefix);
            $referenceTime = $timestamp;

            if ($referenceTime === null) {
                $mtime = filemtime($path);

                if ($mtime === false) {
                    $this->warn("Skip: unable to read mtime for {$path}");
                    continue;
                }

                $referenceTime = Carbon::createFromTimestamp((int) $mtime);
            }

            if ($referenceTime->greaterThan($cutoff)) {
                continue;
            }

            if ($dryRun) {
                $this->info("Would remove: {$path}");
                continue;
            }

            $deleted = $filesystem->deleteDirectory($path);

            if ($deleted) {
                $removed++;
                $this->info("Removed: {$path}");
            } else {
                $this->warn("Failed to remove: {$path}");
            }
        }

        $this->info("Cleanup completed. Removed {$removed} rollback directories.");

        return self::SUCCESS;
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

    protected function extractTimestamp(string $entry, string $prefix): ?Carbon
    {
        $suffix = substr($entry, strlen($prefix));

        if (! preg_match('/^(\\d{14})/', $suffix, $matches)) {
            return null;
        }

        $parsed = Carbon::createFromFormat('YmdHis', $matches[1]);

        return $parsed instanceof Carbon ? $parsed : null;
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
