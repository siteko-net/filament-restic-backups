<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Siteko\FilamentResticBackups\Models\BackupRun;
use Siteko\FilamentResticBackups\Models\BackupSetting;
use Siteko\FilamentResticBackups\Support\S3ExportStorage;

class DownloadExportArchiveController
{
    public function __invoke(Request $request, BackupRun $run, S3ExportStorage $storage): BinaryFileResponse|RedirectResponse
    {
        if (! in_array($run->type, ['export_snapshot', 'export_full', 'export_delta', 'export_snapshot_stream'], true)) {
            abort(404);
        }

        $meta = is_array($run->meta) ? $run->meta : [];
        $export = is_array($meta['export'] ?? null) ? $meta['export'] : [];

        if (! empty($export['expires_at'])) {
            $expiresAt = Carbon::parse((string) $export['expires_at']);

            if (now()->greaterThan($expiresAt)) {
                abort(410, 'Archive has expired.');
            }
        } else {
            $expiresAt = now()->addMinutes(60);
        }

        if (($export['storage'] ?? null) === 's3') {
            $bucket = (string) ($export['bucket'] ?? '');
            $key = (string) ($export['object_key'] ?? '');
            $name = (string) ($export['archive_name'] ?? basename($key));

            if ($bucket === '' || $key === '') {
                abort(404, 'Archive object not found.');
            }

            $url = $storage->presignedDownloadUrl(
                $storage->client(BackupSetting::singleton()),
                $bucket,
                $key,
                $name,
                $expiresAt->lessThan(now()->addMinutes(60)) ? $expiresAt : now()->addMinutes(60),
            );

            return redirect()->away($url);
        }

        $path = (string) ($export['archive_path'] ?? '');
        $name = (string) ($export['archive_name'] ?? '');

        if ($path === '' || ! is_file($path)) {
            abort(404, 'Archive file not found.');
        }

        if ($name === '') {
            $name = basename($path);
        }

        return response()->download($path, $name);
    }
}
