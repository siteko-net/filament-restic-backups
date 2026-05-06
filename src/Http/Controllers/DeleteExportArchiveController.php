<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Siteko\FilamentResticBackups\Models\BackupRun;
use Siteko\FilamentResticBackups\Models\BackupSetting;
use Siteko\FilamentResticBackups\Support\S3ExportStorage;

class DeleteExportArchiveController
{
    public function __invoke(Request $request, BackupRun $run, S3ExportStorage $storage): RedirectResponse
    {
        if (! in_array($run->type, ['export_snapshot', 'export_full', 'export_delta', 'export_snapshot_stream'], true)) {
            abort(404);
        }

        $meta = is_array($run->meta) ? $run->meta : [];
        $export = is_array($meta['export'] ?? null) ? $meta['export'] : [];

        $path = (string) ($export['archive_path'] ?? '');

        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }

        if (($export['storage'] ?? null) === 's3') {
            $bucket = (string) ($export['bucket'] ?? '');
            $key = (string) ($export['object_key'] ?? '');

            if ($bucket !== '' && $key !== '') {
                $storage->deleteObject($storage->client(BackupSetting::singleton()), $bucket, $key);
            }
        }

        $export['deleted_at'] = now()->toIso8601String();
        $export['expires_at'] = now()->toIso8601String();

        unset(
            $export['archive_path'],
            $export['archive_name'],
            $export['archive_size'],
            $export['archive_sha256'],
            $export['archive_etag'],
            $export['object_url'],
        );

        $meta['export'] = $export;

        $run->update(['meta' => $meta]);

        return redirect()->back();
    }
}
