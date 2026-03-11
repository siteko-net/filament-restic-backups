<?php

namespace Siteko\FilamentResticBackups;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Siteko\FilamentResticBackups\Support\BackupsScheduleRegistrar;

class ResticBackupsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/restic-backups.php', 'restic-backups');
        $this->registerTranslations();
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerViews();
        $this->registerCommands();
        $this->registerRoutes();
        $this->registerScheduler();
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/restic-backups.php' => config_path('restic-backups.php'),
        ], 'restic-backups-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'restic-backups-migrations');

        $this->publishes([
            __DIR__ . '/../database/seeders' => database_path('seeders'),
        ], 'restic-backups-seeders');
        $this->publishes([
            __DIR__ . '/../resources/lang' => lang_path('vendor/restic-backups'),
        ], 'restic-backups-translations');
    }

    protected function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'restic-backups');
    }

    protected function registerTranslations(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'restic-backups');
    }

    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            \Siteko\FilamentResticBackups\Console\RunBackupCommand::class,
            \Siteko\FilamentResticBackups\Console\CleanupExportArchivesCommand::class,
            \Siteko\FilamentResticBackups\Console\CleanupRollbackDirsCommand::class,
            \Siteko\FilamentResticBackups\Console\UnlockOperationCommand::class,
        ]);
    }
    protected function registerRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    }

    protected function registerScheduler(): void
    {
        $this->app->booted(function (): void {
            if (! $this->app->runningInConsole()) {
                return;
            }

            $schedule = $this->app->make(Schedule::class);
            $this->app->make(BackupsScheduleRegistrar::class)->register($schedule);
        });
    }
}
