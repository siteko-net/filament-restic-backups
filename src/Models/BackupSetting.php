<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BackupSetting extends Model
{
    protected $table = 'backup_settings';

    protected $fillable = [
        'endpoint',
        'bucket',
        'prefix',
        'repository_prefix',
        'access_key',
        'secret_key',
        'restic_repository',
        'restic_password',
        'retention',
        'schedule',
        'paths',
        'project_root',
        'baseline_snapshot_id',
        'baseline_created_at',
    ];

    protected $casts = [
        'access_key' => 'encrypted',
        'secret_key' => 'encrypted',
        'restic_password' => 'encrypted',
        'retention' => 'array',
        'schedule' => 'array',
        'paths' => 'array',
        'baseline_created_at' => 'datetime',
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

    public function getAccessKeyAttribute(mixed $value): ?string
    {
        return $this->decryptSecretAttribute('access_key', $value);
    }

    public function getSecretKeyAttribute(mixed $value): ?string
    {
        return $this->decryptSecretAttribute('secret_key', $value);
    }

    public function getResticPasswordAttribute(mixed $value): ?string
    {
        return $this->decryptSecretAttribute('restic_password', $value);
    }

    public function resolveRepositoryPrefix(): string
    {
        $prefix = $this->repository_prefix ?: $this->prefix;

        if (is_string($prefix) && trim($prefix) !== '') {
            return trim($prefix, '/');
        }

        return static::computeRepositoryPrefix();
    }

    public static function computeRepositoryPrefix(): string
    {
        $appSlug = Str::slug((string) config('app.name', ''));

        if ($appSlug === '') {
            $appSlug = Str::slug((string) basename(base_path()));
        }

        if ($appSlug === '') {
            $appSlug = 'project-' . substr(sha1(base_path()), 0, 8);
        }

        $env = trim((string) config('app.env', 'production'));
        $env = $env === '' ? 'production' : $env;

        return 'restic/' . $appSlug . '/' . $env;
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
                'exclude' => [
                    'node_modules',
                    '.git',
                    'storage/framework',
                    'storage/logs',
                    'bootstrap/cache',
                    'public/hot',
                ],
            ],
            'project_root' => config('restic-backups.paths.project_root', base_path()),
            'repository_prefix' => static::computeRepositoryPrefix(),
            'baseline_snapshot_id' => null,
            'baseline_created_at' => null,
        ];
    }

    protected function decryptSecretAttribute(string $attribute, mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($value);

            return trim($decrypted) === '' ? null : trim($decrypted);
        } catch (DecryptException $exception) {
            if (! $this->looksLikeEncryptedPayload($value)) {
                return $value;
            }

            Log::warning('Restic backup secret could not be decrypted.', [
                'attribute' => $attribute,
                'backup_setting_id' => $this->getKey(),
            ]);

            return null;
        }
    }

    protected function looksLikeEncryptedPayload(string $value): bool
    {
        $decoded = base64_decode($value, true);

        if (! is_string($decoded) || $decoded === '') {
            return false;
        }

        $payload = json_decode($decoded, true);

        if (! is_array($payload)) {
            return false;
        }

        foreach (['iv', 'value', 'mac'] as $key) {
            if (! array_key_exists($key, $payload) || ! is_string($payload[$key])) {
                return false;
            }
        }

        return true;
    }
}
