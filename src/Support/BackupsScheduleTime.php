<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Support;

use DateTimeInterface;

class BackupsScheduleTime
{
    public static function normalize(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i');
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(?<hour>[01]\d|2[0-3]):(?<minute>[0-5]\d)(?::[0-5]\d)?$/', $value, $matches) !== 1) {
            return null;
        }

        return $matches['hour'] . ':' . $matches['minute'];
    }
}
