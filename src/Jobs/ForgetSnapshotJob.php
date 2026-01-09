<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Siteko\FilamentResticBackups\Models\BackupRun;
use Siteko\FilamentResticBackups\Services\ResticRunner;
use Throwable;

class ForgetSnapshotJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const LOCK_KEY = 'restic-backups:operation';
    private const LOCK_TTL_SECONDS = 7200;
    private const META_OUTPUT_LIMIT = 204800;

    public int $timeout = 7200;
    public int $tries = 1;
    public array $backoff = [60];

    public function __construct(
        public string $snapshotId,
        public ?int $userId = null,
        public string $trigger = 'filament',
    ) {
    }

    public function handle(ResticRunner $runner): void
    {
        $lock = Cache::lock(self::LOCK_KEY, $this->lockTtl());

        if (! $lock->get()) {
            $this->recordSkippedRun();

            return;
        }

        $run = null;
        $meta = [
            'snapshot_id' => $this->snapshotId,
            'snapshot_short_id' => substr($this->snapshotId, 0, 8),
            'trigger' => $this->trigger,
        ];

        if ($this->userId !== null) {
            $meta['initiator_user_id'] = $this->userId;
        }

        try {
            $run = BackupRun::query()->create([
                'type' => 'forget_snapshot',
                'status' => 'running',
                'started_at' => now(),
                'meta' => $meta,
            ]);

            $result = $runner->forgetSnapshot($this->snapshotId, true, [
                'timeout' => $this->timeout,
                'capture_output' => true,
                'max_output_bytes' => self::META_OUTPUT_LIMIT,
            ]);

            $meta['steps']['forget_prune'] = $this->formatProcessResult($result);
            $run->update(['meta' => $meta]);

            if ($result->exitCode !== 0) {
                throw new \RuntimeException('Snapshot forget failed.');
            }

            $run->update([
                'status' => 'success',
                'finished_at' => now(),
                'meta' => $meta,
            ]);
        } catch (Throwable $exception) {
            if ($run instanceof BackupRun) {
                $meta['error_class'] = $exception::class;
                $meta['error_message'] = $this->truncateString($exception->getMessage(), self::META_OUTPUT_LIMIT);

                $run->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'meta' => $meta,
                ]);
            }

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    protected function recordSkippedRun(): void
    {
        $meta = [
            'snapshot_id' => $this->snapshotId,
            'snapshot_short_id' => substr($this->snapshotId, 0, 8),
            'trigger' => $this->trigger,
            'reason' => 'lock_unavailable',
        ];

        if ($this->userId !== null) {
            $meta['initiator_user_id'] = $this->userId;
        }

        BackupRun::query()->create([
            'type' => 'forget_snapshot',
            'status' => 'skipped',
            'started_at' => now(),
            'finished_at' => now(),
            'meta' => $meta,
        ]);
    }

    protected function lockTtl(): int
    {
        return max(self::LOCK_TTL_SECONDS, $this->timeout);
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatProcessResult(\Siteko\FilamentResticBackups\DTO\ProcessResult $result): array
    {
        return [
            'exit_code' => $result->exitCode,
            'duration_ms' => $result->durationMs,
            'stdout' => $this->truncateString($result->stdout, self::META_OUTPUT_LIMIT),
            'stderr' => $this->truncateString($result->stderr, self::META_OUTPUT_LIMIT),
            'command' => $result->safeCommandString(),
        ];
    }

    protected function truncateString(string $value, int $limit): string
    {
        if ($value === '') {
            return $value;
        }

        if ($limit <= 0) {
            return '';
        }

        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit) . PHP_EOL . '...[truncated]';
    }
}
