<?php

use Illuminate\Support\Facades\Route;
use Siteko\FilamentResticBackups\Http\Controllers\DeleteExportArchiveController;
use Siteko\FilamentResticBackups\Http\Controllers\DownloadExportArchiveController;

Route::middleware(['web', 'signed'])->group(function (): void {
    Route::get('/restic-backups/exports/{run}/download', DownloadExportArchiveController::class)
        ->name('restic-backups.exports.download');
    Route::delete('/restic-backups/exports/{run}/delete', DeleteExportArchiveController::class)
        ->name('restic-backups.exports.delete');
});
