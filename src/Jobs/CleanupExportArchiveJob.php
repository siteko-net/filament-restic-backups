<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Siteko\FilamentResticBackups\Models\BackupRun;

class CleanupExportArchiveJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public array $backoff = [60];

    public function __construct(public int $runId)
    {
    }

    public function handle(): void
    {
        $run = BackupRun::query()->find($this->runId);

        if (! $run instanceof BackupRun || ! in_array($run->type, ['export_snapshot', 'export_full', 'export_delta'], true)) {
            return;
        }

        $meta = is_array($run->meta) ? $run->meta : [];
        $export = is_array($meta['export'] ?? null) ? $meta['export'] : [];

        if (! empty($export['deleted_at'])) {
            return;
        }

        $path = (string) ($export['archive_path'] ?? '');

        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }

        $export['deleted_at'] = now()->toIso8601String();
        $export['expires_at'] = now()->toIso8601String();

        unset(
            $export['archive_path'],
            $export['archive_name'],
            $export['archive_size'],
            $export['archive_sha256'],
        );

        $meta['export'] = $export;

        $run->update(['meta' => $meta]);
    }
}
