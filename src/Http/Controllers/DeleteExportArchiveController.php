<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Siteko\FilamentResticBackups\Models\BackupRun;

class DeleteExportArchiveController
{
    public function __invoke(Request $request, BackupRun $run): RedirectResponse
    {
        if (! in_array($run->type, ['export_snapshot', 'export_full', 'export_delta'], true)) {
            abort(404);
        }

        $meta = is_array($run->meta) ? $run->meta : [];
        $export = is_array($meta['export'] ?? null) ? $meta['export'] : [];

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

        return redirect()->back();
    }
}
