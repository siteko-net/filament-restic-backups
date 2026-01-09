<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Siteko\FilamentResticBackups\Exceptions\ResticConfigurationException;
use Siteko\FilamentResticBackups\Models\BackupRun;
use Siteko\FilamentResticBackups\Models\BackupSetting;
use Siteko\FilamentResticBackups\Services\ResticRunner;
use Throwable;

class BackupsOverview
{
    private const CACHE_KEY = 'restic-backups:overview:repo';
    private const CACHE_TTL_SECONDS = 45;
    private const LOCK_KEY = 'restic-backups:operation';
    private const ERROR_SNIPPET_LIMIT = 600;

    /**
     * @return array<string, mixed>
     */
    public function get(bool $force = false): array
    {
        $settings = $this->getSettings();
        $projectRoot = $this->resolveProjectRoot($settings);

        $repo = $this->getRepositoryOverview($settings, $force);
        $runs = $this->getRuns();
        $system = $this->getSystemDiagnostics($projectRoot, $runs);

        return [
            'settings' => [
                'configured' => $settings instanceof BackupSetting,
                'schedule_enabled' => $this->normalizeScheduleEnabled($settings),
                'project_root' => $projectRoot,
            ],
            'repo' => $repo,
            'runs' => $runs,
            'system' => $system,
            'fetched_at' => now()->toDateTimeString(),
        ];
    }

    protected function getSettings(): ?BackupSetting
    {
        return BackupSetting::query()->latest('id')->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getRuns(): array
    {
        $lastAny = BackupRun::query()->latest('started_at')->first();
        $lastBackup = BackupRun::query()->where('type', 'backup')->latest('started_at')->first();
        $lastRestore = BackupRun::query()->where('type', 'restore')->latest('started_at')->first();
        $lastFailed = BackupRun::query()->where('status', 'failed')->latest('started_at')->first();

        $lastSkippedLock = BackupRun::query()
            ->where('status', 'skipped')
            ->where('meta->reason', 'lock_unavailable')
            ->latest('started_at')
            ->first();

        return [
            'last_any' => $lastAny,
            'last_backup' => $lastBackup,
            'last_restore' => $lastRestore,
            'last_failed' => $lastFailed,
            'last_skipped_lock' => $lastSkippedLock,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getRepositoryOverview(?BackupSetting $settings, bool $force): array
    {
        if (! $this->hasRepositoryConfiguration($settings)) {
            return [
                'status' => 'uninitialized',
                'message' => 'Repository is not configured.',
                'snapshots_count' => null,
                'last_snapshot' => null,
            ];
        }

        if ($force) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () use ($settings): array {
            try {
                $runner = new ResticRunner($settings);
                $result = $runner->snapshots();

                if ($result->exitCode !== 0) {
                    return $this->repoErrorFromOutput($result->stderr ?: $result->stdout);
                }

                $snapshots = is_array($result->parsedJson) ? $result->parsedJson : null;
                if (! is_array($snapshots)) {
                    return [
                        'status' => 'error',
                        'message' => 'Snapshot output parsing failed.',
                        'snapshots_count' => null,
                        'last_snapshot' => null,
                    ];
                }

                $normalized = $this->normalizeSnapshots($snapshots);
                $lastSnapshot = $this->pickLatestSnapshot($normalized);

                return [
                    'status' => 'ok',
                    'message' => 'Repository доступен.',
                    'snapshots_count' => count($normalized),
                    'last_snapshot' => $lastSnapshot,
                ];
            } catch (ResticConfigurationException $exception) {
                return [
                    'status' => 'uninitialized',
                    'message' => 'Repository settings are incomplete.',
                    'snapshots_count' => null,
                    'last_snapshot' => null,
                ];
            } catch (Throwable $exception) {
                return [
                    'status' => 'error',
                    'message' => $this->truncateError($exception->getMessage()),
                    'snapshots_count' => null,
                    'last_snapshot' => null,
                ];
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function getSystemDiagnostics(string $projectRoot, array $runs): array
    {
        $lockInfo = $this->checkLock();

        $skippedLock = $runs['last_skipped_lock'] ?? null;
        if ($skippedLock instanceof BackupRun && $lockInfo['note'] === null) {
            $lockInfo['note'] = 'Recent run skipped because another operation was locked.';
        }

        return [
            'disk_free_bytes' => $this->getDiskFreeBytes($projectRoot),
            'lock' => $lockInfo,
            'queue' => [
                'connection' => (string) config('queue.default'),
                'is_sync' => (string) config('queue.default') === 'sync',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkLock(): array
    {
        try {
            $lock = Cache::lock(self::LOCK_KEY, 1);
            $acquired = $lock->get();

            if ($acquired) {
                $lock->release();

                return [
                    'likely_locked' => false,
                    'note' => null,
                ];
            }

            return [
                'likely_locked' => true,
                'note' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'likely_locked' => null,
                'note' => 'Lock driver not supported or unavailable.',
            ];
        }
    }

    protected function normalizeScheduleEnabled(?BackupSetting $settings): ?bool
    {
        if (! $settings instanceof BackupSetting) {
            return null;
        }

        $schedule = is_array($settings->schedule) ? $settings->schedule : [];

        if (! array_key_exists('enabled', $schedule)) {
            return null;
        }

        return (bool) $schedule['enabled'];
    }

    protected function resolveProjectRoot(?BackupSetting $settings): string
    {
        $projectRoot = $this->normalizeScalar($settings?->project_root);

        return $projectRoot
            ?? (string) config('restic-backups.paths.project_root', base_path());
    }

    protected function hasRepositoryConfiguration(?BackupSetting $settings): bool
    {
        if (! $settings instanceof BackupSetting) {
            return false;
        }

        $repository = $this->normalizeScalar($settings->restic_repository);
        $endpoint = $this->normalizeScalar($settings->endpoint);
        $bucket = $this->normalizeScalar($settings->bucket);
        $password = $this->normalizeScalar($settings->restic_password);

        if ($password === null) {
            return false;
        }

        if ($repository !== null) {
            return true;
        }

        return $endpoint !== null && $bucket !== null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshots
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeSnapshots(array $snapshots): array
    {
        $normalized = [];

        foreach ($snapshots as $snapshot) {
            if (! is_array($snapshot)) {
                continue;
            }

            $time = $snapshot['time'] ?? null;
            $timeParsed = $time ? Carbon::parse($time) : null;
            $id = $this->normalizeScalar($snapshot['id'] ?? null);
            $shortId = $this->normalizeScalar($snapshot['short_id'] ?? null);

            $normalized[] = [
                'id' => $id,
                'short_id' => $shortId ?? ($id ? substr($id, 0, 8) : null),
                'time' => $timeParsed?->toDateTimeString(),
                'time_unix' => $timeParsed?->getTimestamp(),
                'hostname' => $this->normalizeScalar($snapshot['hostname'] ?? null),
                'tags' => is_array($snapshot['tags'] ?? null) ? array_values($snapshot['tags']) : [],
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshots
     * @return array<string, mixed> | null
     */
    protected function pickLatestSnapshot(array $snapshots): ?array
    {
        if ($snapshots === []) {
            return null;
        }

        usort($snapshots, function (array $left, array $right): int {
            return (int) ($right['time_unix'] ?? 0) <=> (int) ($left['time_unix'] ?? 0);
        });

        return $snapshots[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function repoErrorFromOutput(string $output): array
    {
        $message = $this->truncateError($output);
        $lower = strtolower($message);
        $status = str_contains($lower, 'is there a repository at')
            || str_contains($lower, 'unable to open config file')
            || str_contains($lower, 'does not exist')
            || str_contains($lower, 'not a repository')
            ? 'uninitialized'
            : 'error';

        return [
            'status' => $status,
            'message' => $message,
            'snapshots_count' => null,
            'last_snapshot' => null,
        ];
    }

    protected function truncateError(string $message): string
    {
        $message = trim($message);

        if ($message === '') {
            return 'Unknown error.';
        }

        $message = preg_replace('#(://)([^/]*):([^@]*)@#', '$1***:***@', $message) ?? $message;

        if (mb_strlen($message) > self::ERROR_SNIPPET_LIMIT) {
            $message = mb_substr($message, 0, self::ERROR_SNIPPET_LIMIT) . '…';
        }

        return $message;
    }

    protected function normalizeScalar(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return null;
    }

    protected function getDiskFreeBytes(string $path): ?int
    {
        $bytes = @disk_free_space($path);

        return $bytes === false ? null : (int) $bytes;
    }
}
