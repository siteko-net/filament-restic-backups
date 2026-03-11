<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Support;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;
use Siteko\FilamentResticBackups\Models\BackupSetting;
use Throwable;

class BackupsScheduleRegistrar
{
    private const DEFAULT_DAILY_TIME = '02:00';

    public function register(Schedule $schedule): void
    {
        if (! config('restic-backups.enabled', true)) {
            return;
        }

        $config = $this->resolveConfig();

        if ($config['enabled']) {
            $schedule->command('restic-backups:run --trigger=schedule')
                ->dailyAt($config['daily_time'])
                ->timezone($config['timezone'])
                ->withoutOverlapping(120);
        }

        $schedule->command('restic-backups:cleanup-exports --hours=24')
            ->dailyAt($config['daily_time'])
            ->timezone($config['timezone'])
            ->withoutOverlapping(120);

        $schedule->command('restic-backups:cleanup-rollbacks --hours=24')
            ->dailyAt($config['daily_time'])
            ->timezone($config['timezone'])
            ->withoutOverlapping(120);
    }

    /**
     * @return array{enabled: bool, daily_time: string, timezone: string}
     */
    protected function resolveConfig(): array
    {
        $timezone = BackupsTimezone::resolve();
        $dailyTime = self::DEFAULT_DAILY_TIME;
        $enabled = false;

        try {
            if (! Schema::hasTable('backup_settings')) {
                return [
                    'enabled' => $enabled,
                    'daily_time' => $dailyTime,
                    'timezone' => $timezone,
                ];
            }

            $settings = BackupSetting::query()->latest('id')->first();

            if (! $settings instanceof BackupSetting) {
                return [
                    'enabled' => $enabled,
                    'daily_time' => $dailyTime,
                    'timezone' => $timezone,
                ];
            }

            $schedule = is_array($settings->schedule) ? $settings->schedule : [];
            $enabled = (bool) ($schedule['enabled'] ?? false);
            $timezone = BackupsTimezone::resolve($settings);
            $dailyTime = $this->normalizeDailyTime($schedule['daily_time'] ?? null);
        } catch (Throwable) {
            // Keep fallback defaults when schedule settings cannot be loaded.
        }

        return [
            'enabled' => $enabled,
            'daily_time' => $dailyTime,
            'timezone' => $timezone,
        ];
    }

    protected function normalizeDailyTime(mixed $value): string
    {
        if (! is_string($value)) {
            return self::DEFAULT_DAILY_TIME;
        }

        $value = trim($value);

        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1) {
            return $value;
        }

        return self::DEFAULT_DAILY_TIME;
    }
}
