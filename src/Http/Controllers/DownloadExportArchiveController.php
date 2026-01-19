<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Siteko\FilamentResticBackups\Models\BackupRun;

class DownloadExportArchiveController
{
    public function __invoke(Request $request, BackupRun $run): BinaryFileResponse
    {
        if ($run->type !== 'export_snapshot') {
            abort(404);
        }

        $meta = is_array($run->meta) ? $run->meta : [];
        $export = is_array($meta['export'] ?? null) ? $meta['export'] : [];

        $path = (string) ($export['archive_path'] ?? '');
        $name = (string) ($export['archive_name'] ?? '');

        if ($path === '' || ! is_file($path)) {
            abort(404, 'Archive file not found.');
        }

        if (! empty($export['expires_at'])) {
            $expiresAt = Carbon::parse((string) $export['expires_at']);

            if (now()->greaterThan($expiresAt)) {
                abort(410, 'Archive has expired.');
            }
        }

        if ($name === '') {
            $name = basename($path);
        }

        return response()->download($path, $name);
    }
}
