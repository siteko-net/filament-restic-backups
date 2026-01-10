<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Support;

use Illuminate\Contracts\Cache\Repository;
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
        $store = $this->repository();
        $lock = $store->lock(self::LOCK_KEY, $ttlSeconds);

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

        try {
            $store->put(self::INFO_KEY, $info, $ttlSeconds);
        } catch (Throwable) {
            // Ignore cache store failures; lock ownership still stands.
        }

        return new OperationLockHandle($lock, $info, $ttlSeconds, self::INFO_KEY, $store);
    }

    /**
     * @return array<string, mixed> | null
     */
    public function getInfo(): ?array
    {
        try {
            $info = $this->repository()->get(self::INFO_KEY);
        } catch (Throwable) {
            return null;
        }

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
            $store = $this->repository();
            $store->lock(self::LOCK_KEY, 1)->forceRelease();
            $store->forget(self::INFO_KEY);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function repository(): Repository
    {
        $storeName = config('restic-backups.locks.store');

        if (is_string($storeName) && $storeName !== '') {
            try {
                return Cache::store($storeName);
            } catch (Throwable) {
                // Fall back to default store.
            }
        }

        $defaultStore = config('cache.default');

        if ($defaultStore === 'database') {
            try {
                return Cache::store('file');
            } catch (Throwable) {
                // Fall back to default store.
            }
        }

        return Cache::store();
    }
}
