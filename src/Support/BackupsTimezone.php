<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Siteko\FilamentResticBackups\Models\BackupSetting;
use Throwable;

class BackupsTimezone
{
    public const DEFAULT_FORMAT = 'Y-m-d H:i:s';

    public static function resolve(?BackupSetting $settings = null): string
    {
        $timezone = null;

        if (! $settings instanceof BackupSetting) {
            $settings = BackupSetting::query()->latest('id')->first();
        }

        if ($settings instanceof BackupSetting) {
            $schedule = is_array($settings->schedule) ? $settings->schedule : [];
            $timezone = $schedule['timezone'] ?? null;
        }

        $timezone = is_string($timezone) ? trim($timezone) : '';

        if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }

        $appTimezone = config('app.timezone');

        if (is_string($appTimezone) && trim($appTimezone) !== '') {
            return $appTimezone;
        }

        return 'UTC';
    }

    public static function normalize(mixed $value, ?string $timezone = null): ?Carbon
    {
        $timezone = $timezone ?? static::resolve();

        if ($value instanceof Carbon) {
            $carbon = $value->copy();
        } elseif ($value instanceof DateTimeInterface) {
            $carbon = Carbon::instance($value);
        } else {
            $value = is_string($value) ? trim($value) : $value;

            if ($value === null || $value === '') {
                return null;
            }

            try {
                $carbon = Carbon::parse($value, $timezone);
            } catch (Throwable) {
                return null;
            }
        }

        return $carbon->setTimezone($timezone);
    }

    public static function format(
        mixed $value,
        ?string $timezone = null,
        string $format = self::DEFAULT_FORMAT,
        string $fallback = '-',
    ): string {
        $carbon = static::normalize($value, $timezone);

        if (! $carbon) {
            return $fallback;
        }

        return $carbon->format($format);
    }
}
