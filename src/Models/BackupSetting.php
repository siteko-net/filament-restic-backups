<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Models;

use Illuminate\Database\Eloquent\Model;

class BackupSetting extends Model
{
    protected $table = 'backup_settings';

    protected $fillable = [
        'endpoint',
        'bucket',
        'prefix',
        'access_key',
        'secret_key',
        'restic_repository',
        'restic_password',
        'retention',
        'schedule',
        'paths',
        'project_root',
    ];

    protected $casts = [
        'access_key' => 'encrypted',
        'secret_key' => 'encrypted',
        'restic_password' => 'encrypted',
        'retention' => 'array',
        'schedule' => 'array',
        'paths' => 'array',
    ];

    protected $hidden = [
        'access_key',
        'secret_key',
        'restic_password',
    ];

    public static function singleton(): self
    {
        $instance = static::query()
            ->latest('id')
            ->first();

        if ($instance instanceof self) {
            return $instance;
        }

        return static::query()->create(static::defaultAttributes());
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultAttributes(): array
    {
        return [
            'retention' => [
                'keep_daily' => 7,
                'keep_weekly' => 4,
                'keep_monthly' => 12,
            ],
            'schedule' => [
                'enabled' => false,
                'daily_time' => '02:00',
            ],
            'paths' => [
                'include' => [],
                'exclude' => [],
            ],
            'project_root' => config('restic-backups.paths.project_root', base_path()),
        ];
    }
}
