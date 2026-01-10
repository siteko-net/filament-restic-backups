<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

class OperationLockHandle
{
    /**
     * @param  array<string, mixed>  $info
     */
    public function __construct(
        private Lock $lock,
        private array $info,
        private int $ttlSeconds,
        private string $infoKey,
        private Repository $store,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function info(): array
    {
        return $this->info;
    }

    public function setRunId(int $runId): void
    {
        $this->updateInfo(['run_id' => $runId]);
    }

    /**
     * @param  array<string, mixed>  $contextPatch
     */
    public function heartbeat(array $contextPatch = []): void
    {
        $this->updateInfo(['context' => $contextPatch], heartbeat: true);
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function updateInfo(array $patch = [], bool $heartbeat = false): void
    {
        if (isset($patch['context']) && is_array($patch['context'])) {
            $this->info['context'] = array_merge(
                $this->info['context'] ?? [],
                $patch['context'],
            );

            unset($patch['context']);
        }

        foreach ($patch as $key => $value) {
            $this->info[$key] = $value;
        }

        if ($heartbeat) {
            $this->info['last_heartbeat_at'] = now()->toIso8601String();
        }

        try {
            $this->store->put($this->infoKey, $this->info, $this->ttlSeconds);
        } catch (Throwable) {
            // Ignore cache store failures; do not block main flow.
        }
    }

    public function release(): void
    {
        try {
            $this->lock->release();
        } catch (Throwable) {
            // Best-effort only.
        }

        try {
            $this->store->forget($this->infoKey);
        } catch (Throwable) {
            // Best-effort only.
        }
    }
}
