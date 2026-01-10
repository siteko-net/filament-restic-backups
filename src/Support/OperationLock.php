<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

class OperationLock
{
    public const LOCK_KEY = 'restic-backups:operation';
    public const INFO_KEY = 'restic-backups:operation:info';

    /**
     * @param  array<string, mixed>  $context
     */
    public function acquire(
        string $type,
        int $ttlSeconds,
        int $blockSeconds = 30,
        array $context = [],
    ): ?OperationLockHandle {
        $lock = Cache::lock(self::LOCK_KEY, $ttlSeconds);

        try {
            $acquired = $blockSeconds > 0
                ? $lock->block($blockSeconds)
                : $lock->get();
        } catch (Throwable) {
            return null;
        }

        if (! $acquired) {
            return null;
        }

        $now = now();

        $info = [
            'type' => $type,
            'run_id' => null,
            'started_at' => $now->toIso8601String(),
            'hostname' => gethostname() ?: php_uname('n'),
            'pid' => getmypid(),
            'ttl_seconds' => $ttlSeconds,
            'expires_at' => $now->copy()->addSeconds($ttlSeconds)->toIso8601String(),
            'last_heartbeat_at' => $now->toIso8601String(),
            'context' => $context,
        ];

        Cache::put(self::INFO_KEY, $info, $ttlSeconds);

        return new OperationLockHandle($lock, $info, $ttlSeconds, self::INFO_KEY);
    }

    /**
     * @return array<string, mixed> | null
     */
    public function getInfo(): ?array
    {
        $info = Cache::get(self::INFO_KEY);

        return is_array($info) ? $info : null;
    }

    public function isStale(int $seconds = 900): bool
    {
        $info = $this->getInfo();

        if (! is_array($info)) {
            return false;
        }

        $lastHeartbeat = $info['last_heartbeat_at'] ?? null;

        if (! is_string($lastHeartbeat) || $lastHeartbeat === '') {
            return true;
        }

        $timestamp = strtotime($lastHeartbeat);

        if ($timestamp === false) {
            return true;
        }

        return (time() - $timestamp) >= $seconds;
    }

    public function forceRelease(): bool
    {
        try {
            Cache::lock(self::LOCK_KEY, 1)->forceRelease();
            Cache::forget(self::INFO_KEY);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
