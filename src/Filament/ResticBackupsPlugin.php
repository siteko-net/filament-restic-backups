<?php

namespace Siteko\FilamentResticBackups\Filament;

use Filament\Contracts\Plugin;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Siteko\FilamentResticBackups\Filament\Pages\BackupsRuns;
use Siteko\FilamentResticBackups\Filament\Pages\BackupsSnapshots;
use Siteko\FilamentResticBackups\Filament\Pages\BackupsSettings;

class ResticBackupsPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'restic-backups';
    }

    public function register(Panel $panel): void
    {
        if (! $this->shouldRegisterOnPanel($panel)) {
            return;
        }

        $panel->pages([
            BackupsSettings::class,
            BackupsSnapshots::class,
            BackupsRuns::class,
        ]);

        $panel->navigationGroups([
            NavigationGroup::make($this->getNavigationGroupLabel())
                ->icon($this->getNavigationGroupIcon()),
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }

    protected function shouldRegisterOnPanel(Panel $panel): bool
    {
        if (! config('restic-backups.enabled', true)) {
            return false;
        }

        $targetPanel = config('restic-backups.panel', 'admin');

        return $panel->getId() === $targetPanel;
    }

    protected function getNavigationGroupLabel(): string
    {
        return config('restic-backups.navigation.group_label', 'Backups');
    }

    protected function getNavigationGroupIcon(): string
    {
        return config('restic-backups.navigation.icon', 'heroicon-o-archive-box');
    }
}
