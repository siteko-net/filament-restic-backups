<?php

namespace Siteko\FilamentResticBackups\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Page;

abstract class BaseBackupsPage extends Page
{
    public static function getNavigationGroup(): ?string
    {
        return config('restic-backups.navigation.group_label', 'Backups');
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
}
