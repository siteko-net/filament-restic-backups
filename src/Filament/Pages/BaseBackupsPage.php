<?php

namespace Siteko\FilamentResticBackups\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Page;
use Siteko\FilamentResticBackups\Models\BackupRun;
use Siteko\FilamentResticBackups\Support\OperationLock;
use Throwable;

abstract class BaseBackupsPage extends Page
{
    private const RUNNING_FALLBACK_SECONDS = 21600; // 6h

    public static function getNavigationGroup(): ?string
    {
        $label = config('restic-backups.navigation.group_label');

        if (is_string($label) && trim($label) !== '') {
            return $label;
        }

        return __('restic-backups::backups.navigation.group');
    }

    public static function canAccess(): bool
    {
        if (! config('restic-backups.enabled', true)) {
            return false;
        }

        $permissions = config('restic-backups.security.permissions', []);
        $permissions = is_array($permissions) ? $permissions : [];

        $guard = Filament::auth();
        $user = $guard->user();

        if (empty($permissions)) {
            return $guard->check();
        }

        if (! $user) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    protected static function baseNavigationSort(): int
    {
        return (int) config('restic-backups.navigation.sort', 30);
    }

    protected function hasRunningOperations(): bool
    {
        $lockInfo = app(OperationLock::class)->getInfo();

        if (is_array($lockInfo)) {
            return true;
        }

        try {
            $threshold = now()->subSeconds(self::RUNNING_FALLBACK_SECONDS);

            return BackupRun::query()
                ->where('status', 'running')
                ->whereNull('finished_at')
                ->where(function ($query) use ($threshold): void {
                    $query
                        ->where('started_at', '>=', $threshold)
                        ->orWhere('updated_at', '>=', $threshold);
                })
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
