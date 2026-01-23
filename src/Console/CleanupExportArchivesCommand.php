<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Siteko\FilamentResticBackups\Models\BackupRun;

class CleanupExportArchivesCommand extends Command
{
    protected $signature = 'restic-backups:cleanup-exports {--hours=24 : Remove export work dirs older than this} {--dry-run : Show what would be removed}';

    protected $description = 'Remove expired export archives and stale export work directories.';

    public function handle(Filesystem $filesystem): int
    {
        $hours = (int) $this->option('hours');
        $dryRun = (bool) $this->option('dry-run');
        $hours = $hours > 0 ? $hours : 24;

        $now = Carbon::now();

        $archivesRemoved = $this->cleanupExpiredArchives($filesystem, $now, $dryRun);
        $workDirsRemoved = $this->cleanupWorkDirs($filesystem, $now->copy()->subHours($hours), $dryRun);

        $this->info("Cleanup completed. Removed {$archivesRemoved} archives and {$workDirsRemoved} work directories.");

        return self::SUCCESS;
    }

    protected function cleanupExpiredArchives(Filesystem $filesystem, Carbon $now, bool $dryRun): int
    {
        $removed = 0;

        $runs = BackupRun::query()
            ->whereIn('type', ['export_snapshot', 'export_full', 'export_delta'])
            ->orderBy('id')
            ->get();

        foreach ($runs as $run) {
            $meta = is_array($run->meta) ? $run->meta : [];
            $export = is_array($meta['export'] ?? null) ? $meta['export'] : [];

            if (! empty($export['deleted_at'])) {
                continue;
            }

            $expiresAt = $this->parseExpiresAt($export['expires_at'] ?? null);

            if (! $expiresAt instanceof Carbon || $expiresAt->greaterThan($now)) {
                continue;
            }

            $archivePath = $this->normalizeScalar($export['archive_path'] ?? null);
            $suffix = $archivePath ? " ({$archivePath})" : '';

            if ($dryRun) {
                $this->info("Would remove expired archive for run {$run->getKey()}{$suffix}");
                $removed++;
                continue;
            }

            if ($archivePath !== null && is_file($archivePath)) {
                $filesystem->delete($archivePath);
            }

            $this->markExportDeleted($run, $meta, $export, $now);
            $removed++;
        }

        return $removed;
    }

    protected function cleanupWorkDirs(Filesystem $filesystem, Carbon $cutoff, bool $dryRun): int
    {
        $baseDir = storage_path('app/_backup/exports');

        if (! is_dir($baseDir)) {
            $this->warn("Exports directory not found: {$baseDir}");
            return 0;
        }

        $entries = scandir($baseDir) ?: [];
        $removed = 0;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (! str_starts_with($entry, 'work-run-')) {
                continue;
            }

            $path = $baseDir . DIRECTORY_SEPARATOR . $entry;

            if (! is_dir($path)) {
                continue;
            }

            if (! $this->isSafeWorkDir($path, $baseDir)) {
                $this->warn("Skip unsafe path: {$path}");
                continue;
            }

            $referenceTime = $this->extractWorkDirTimestamp($entry);

            if (! $referenceTime instanceof Carbon) {
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
                $removed++;
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

        return $removed;
    }

    protected function extractWorkDirTimestamp(string $entry): ?Carbon
    {
        if (! preg_match('/^work-run-\\d+-(\\d{14})$/', $entry, $matches)) {
            return null;
        }

        $parsed = Carbon::createFromFormat('YmdHis', $matches[1]);

        return $parsed instanceof Carbon ? $parsed : null;
    }

    protected function isSafeWorkDir(string $path, string $baseDir): bool
    {
        $baseReal = realpath($baseDir) ?: $baseDir;
        $pathReal = realpath($path) ?: $path;

        if (! str_starts_with($pathReal, $baseReal . DIRECTORY_SEPARATOR)) {
            return false;
        }

        if (! str_starts_with(basename($pathReal), 'work-run-')) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $export
     */
    protected function markExportDeleted(BackupRun $run, array $meta, array $export, Carbon $now): void
    {
        $export['deleted_at'] = $now->toIso8601String();
        $export['expires_at'] = $now->toIso8601String();

        unset(
            $export['archive_path'],
            $export['archive_name'],
            $export['archive_size'],
            $export['archive_sha256'],
        );

        $meta['export'] = $export;

        $run->update(['meta' => $meta]);
    }

    protected function parseExpiresAt(mixed $value): ?Carbon
    {
        $value = $this->normalizeScalar($value);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
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
}
