<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class SafeEncryptedString implements CastsAttributes
{
    /**
     * @var array<string, bool>
     */
    protected static array $reportedInvalidPayloads = [];

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (! $this->looksLikeEncryptedPayload($value)) {
            return $value;
        }

        try {
            $decrypted = trim(Crypt::decryptString($value));

            return $decrypted === '' ? null : $decrypted;
        } catch (DecryptException $exception) {
            $this->reportInvalidPayload($model, $key, $value);

            return null;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return Crypt::encryptString($value);
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

        foreach (['iv', 'value', 'mac'] as $payloadKey) {
            if (! array_key_exists($payloadKey, $payload) || ! is_string($payload[$payloadKey])) {
                return false;
            }
        }

        return true;
    }

    protected function reportInvalidPayload(Model $model, string $key, string $value): void
    {
        $payloadFingerprint = implode(':', [
            $model::class,
            (string) ($model->getKey() ?? 'new'),
            $key,
            sha1($value),
        ]);

        if (isset(static::$reportedInvalidPayloads[$payloadFingerprint])) {
            return;
        }

        static::$reportedInvalidPayloads[$payloadFingerprint] = true;

        Log::warning('Restic backup secret could not be decrypted.', [
            'attribute' => $key,
            'backup_setting_id' => $model->getKey(),
        ]);
    }
}
