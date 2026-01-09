<?php

namespace Siteko\FilamentResticBackups\Filament;

use Filament\Contracts\Plugin;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;
use Siteko\FilamentResticBackups\Filament\Pages\BackupsDashboard;
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
            BackupsDashboard::class,
            BackupsSettings::class,
            BackupsSnapshots::class,
            BackupsRuns::class,
        ]);

        $panel->assets([
            Css::make(
                'restic-backups',
                __DIR__ . '/../../resources/css/filament/restic-backups.css',
            ),
        ], 'siteko/filament-restic-backups');

        $panel->navigationGroups([
            NavigationGroup::make($this->getNavigationGroupLabel())
                ->icon($this->getNavigationGroupIcon()),
        ]);
    }

    public function boot(Panel $panel): void
    {
        if (! $this->shouldRegisterOnPanel($panel)) {
            return;
        }

        $this->registerInlineStylesWhenAssetsMissing();
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

    protected function registerInlineStylesWhenAssetsMissing(): void
    {
        $cssPath = __DIR__ . '/../../resources/css/filament/restic-backups.css';

        if (! is_file($cssPath)) {
            return;
        }

        $publicPath = $this->getPublishedCssPath();

        if ($publicPath !== null && is_file($publicPath)) {
            return;
        }

        FilamentView::registerRenderHook(
            PanelsRenderHook::STYLES_AFTER,
            function (): HtmlString {
                $cssPath = __DIR__ . '/../../resources/css/filament/restic-backups.css';
                $contents = is_file($cssPath) ? file_get_contents($cssPath) : '';

                return new HtmlString('<style>' . trim((string) $contents) . '</style>');
            },
        );
    }

    protected function getPublishedCssPath(): ?string
    {
        $assetsPath = trim((string) config('filament.assets_path', ''), '/');
        $relativePath = $assetsPath === ''
            ? 'css/siteko/filament-restic-backups/restic-backups.css'
            : $assetsPath . '/css/siteko/filament-restic-backups/restic-backups.css';

        return public_path($relativePath);
    }
}
