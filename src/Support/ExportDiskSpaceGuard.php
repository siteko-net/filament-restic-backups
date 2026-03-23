<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Support;

use Illuminate\Support\Carbon;
use Siteko\FilamentResticBackups\DTO\ProcessResult;
use Siteko\FilamentResticBackups\Services\ResticRunner;
use Throwable;

class ExportDiskSpaceGuard
{
    private const DEFAULT_TIMEOUT_SECONDS = 600;

    private const QUEUED_PREFLIGHT_TTL_SECONDS = 300;

    private const SNAPSHOT_ARCHIVE_RATIO = 1.0;

    private const SNAPSHOT_RESERVE_RATIO = 0.15;

    private const SNAPSHOT_MIN_ARCHIVE_BYTES = 536870912; // 512 MB

    private const SNAPSHOT_MIN_RESERVE_BYTES = 1073741824; // 1 GB

    private const DELTA_ARCHIVE_RATIO = 1.0;

    private const DELTA_RESERVE_RATIO = 0.15;

    private const DELTA_MIN_ARCHIVE_BYTES = 134217728; // 128 MB

    private const DELTA_MIN_RESERVE_BYTES = 536870912; // 512 MB

    /**
     * @return array<string, mixed>
     */
    public function estimateSnapshot(
        ResticRunner $runner,
        string $snapshotId,
        ?string $workPath = null,
        ?int $timeout = null,
        array $processOptions = [],
    ): array {
        $workPath = $this->resolveWorkPath($workPath);
        $statsMeta = null;
        $restoreSizeBytes = null;

        try {
            $statsResult = $runner->statsRestoreSize($snapshotId, array_merge($processOptions, [
                'timeout' => $timeout ?? self::DEFAULT_TIMEOUT_SECONDS,
                'capture_output' => true,
            ]));
            $statsMeta = $this->formatProcessResult($statsResult);

            if ($statsResult->exitCode === 0 && is_array($statsResult->parsedJson)) {
                $restoreSizeBytes = $this->extractRestoreSize($statsResult->parsedJson);
            }
        } catch (Throwable $exception) {
            $statsMeta = $this->formatThrowable($exception);
        }

        return $this->buildEstimate(
            restoreSizeBytes: $restoreSizeBytes,
            workPath: $workPath,
            source: $restoreSizeBytes !== null ? 'restic_stats' : null,
            profile: 'snapshot',
            meta: ['stats' => $statsMeta],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function estimateDelta(
        ResticRunner $runner,
        string $baselineSnapshotId,
        string $targetSnapshotId,
        ?string $workPath = null,
        ?int $timeout = null,
        array $processOptions = [],
    ): array {
        $workPath = $this->resolveWorkPath($workPath);
        $diffMeta = null;
        $restoreSizeBytes = null;

        try {
            $diffResult = $runner->diff($baselineSnapshotId, $targetSnapshotId, array_merge($processOptions, [
                'json' => true,
                'timeout' => $timeout ?? self::DEFAULT_TIMEOUT_SECONDS,
                'capture_output' => true,
            ]));
            $diffMeta = $this->formatProcessResult($diffResult);

            if ($diffResult->exitCode === 0 && is_array($diffResult->parsedJson)) {
                $restoreSizeBytes = $this->extractDeltaRestoreSize($diffResult->parsedJson);
            }
        } catch (Throwable $exception) {
            $diffMeta = $this->formatThrowable($exception);
        }

        if ($restoreSizeBytes !== null) {
            return $this->buildEstimate(
                restoreSizeBytes: $restoreSizeBytes,
                workPath: $workPath,
                source: 'restic_diff',
                profile: 'delta',
                meta: ['diff' => $diffMeta],
            );
        }

        $estimate = $this->estimateSnapshot(
            $runner,
            $targetSnapshotId,
            $workPath,
            $timeout,
            $processOptions,
        );

        $estimate['source'] = ($estimate['restore_size_bytes'] ?? null) !== null ? 'restic_stats_fallback' : null;
        $estimate['profile'] = 'delta';
        $estimate['diff'] = $diffMeta;

        if (($estimate['restore_size_bytes'] ?? null) !== null) {
            $estimate = $this->buildEstimate(
                restoreSizeBytes: (int) $estimate['restore_size_bytes'],
                workPath: $workPath,
                source: 'restic_stats_fallback',
                profile: 'delta',
                meta: [
                    'diff' => $diffMeta,
                    'stats' => $estimate['stats'] ?? null,
                ],
            );
        }

        return $estimate;
    }

    /**
     * @param  array<string, mixed>  $estimate
     * @param  array<string, scalar|null>  $context
     * @return array<string, mixed>
     */
    public function queuePayload(array $estimate, array $context = []): array
    {
        return array_merge($context, [
            'computed_at' => now()->toIso8601String(),
            'ok' => (bool) ($estimate['ok'] ?? false),
            'profile' => is_string($estimate['profile'] ?? null) ? $estimate['profile'] : null,
            'source' => is_string($estimate['source'] ?? null) ? $estimate['source'] : null,
            'free_bytes' => $this->intFromPayload($estimate['free_bytes'] ?? null),
            'restore_size_bytes' => $this->intFromPayload($estimate['restore_size_bytes'] ?? null),
            'estimated_archive_bytes' => $this->intFromPayload($estimate['estimated_archive_bytes'] ?? null),
            'reserve_bytes' => $this->intFromPayload($estimate['reserve_bytes'] ?? null),
            'required_bytes' => $this->intFromPayload($estimate['required_bytes'] ?? null),
            'missing_bytes' => $this->intFromPayload($estimate['missing_bytes'] ?? null),
            'note' => is_string($estimate['note'] ?? null) ? $estimate['note'] : null,
            'origin' => 'ui_preflight',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, scalar|null>  $expectedContext
     * @return array<string, mixed>|null
     */
    public function hydrateQueuedEstimate(
        ?array $payload,
        array $expectedContext,
        ?string $workPath = null,
        int $maxAgeSeconds = self::QUEUED_PREFLIGHT_TTL_SECONDS,
    ): ?array {
        if (! is_array($payload)) {
            return null;
        }

        foreach ($expectedContext as $key => $value) {
            if (($payload[$key] ?? null) !== $value) {
                return null;
            }
        }

        $computedAt = $payload['computed_at'] ?? null;

        if (! is_string($computedAt) || trim($computedAt) === '') {
            return null;
        }

        try {
            $timestamp = Carbon::parse($computedAt);
        } catch (Throwable) {
            return null;
        }

        if (abs(now()->diffInSeconds($timestamp, false)) > $maxAgeSeconds) {
            return null;
        }

        $estimate = [
            'exit_code' => (bool) ($payload['ok'] ?? false) ? 0 : 1,
            'ok' => (bool) ($payload['ok'] ?? false),
            'profile' => is_string($payload['profile'] ?? null) ? $payload['profile'] : null,
            'source' => is_string($payload['source'] ?? null) ? $payload['source'] : null,
            'work_path' => $this->resolveWorkPath($workPath),
            'disk_path' => $this->resolveExistingPath($this->resolveWorkPath($workPath)),
            'free_bytes' => $this->intFromPayload($payload['free_bytes'] ?? null),
            'restore_size_bytes' => $this->intFromPayload($payload['restore_size_bytes'] ?? null),
            'estimated_archive_bytes' => $this->intFromPayload($payload['estimated_archive_bytes'] ?? null),
            'reserve_bytes' => $this->intFromPayload($payload['reserve_bytes'] ?? null),
            'required_bytes' => $this->intFromPayload($payload['required_bytes'] ?? null),
            'missing_bytes' => $this->intFromPayload($payload['missing_bytes'] ?? null),
            'note' => is_string($payload['note'] ?? null) ? $payload['note'] : null,
            'origin' => 'ui_preflight',
            'computed_at' => $timestamp->toIso8601String(),
        ];

        if (($estimate['required_bytes'] ?? null) === null || ($estimate['free_bytes'] ?? null) === null) {
            return null;
        }

        return $estimate;
    }

    /**
     * @param  array<string, mixed>  $estimate
     */
    public function failureMessage(array $estimate): string
    {
        if (($estimate['required_bytes'] ?? null) === null) {
            return 'Export was cancelled because the required disk space could not be estimated safely.';
        }

        return sprintf(
            'Insufficient disk space for export. Available: %s. Required: %s. Missing: %s.',
            $this->formatBytes($estimate['free_bytes'] ?? null),
            $this->formatBytes($estimate['required_bytes'] ?? null),
            $this->formatBytes($estimate['missing_bytes'] ?? 0),
        );
    }

    public function formatBytes(int|float|null $bytes, string $notAvailable = 'n/a'): string
    {
        if ($bytes === null) {
            return $notAvailable;
        }

        $bytes = (float) $bytes;

        if ($bytes < 1024) {
            return number_format($bytes, 0).' B';
        }

        $kb = $bytes / 1024;
        if ($kb < 1024) {
            return number_format($kb, 1).' KB';
        }

        $mb = $kb / 1024;
        if ($mb < 1024) {
            return number_format($mb, 1).' MB';
        }

        $gb = $mb / 1024;

        return number_format($gb, 1).' GB';
    }

    protected function resolveWorkPath(?string $workPath): string
    {
        $path = trim((string) $workPath);

        return $path !== '' ? $path : storage_path('app/_backup/exports');
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    protected function extractRestoreSize(array $stats): ?int
    {
        foreach (['total_size', 'total_size_bytes', 'total_size_in_bytes'] as $key) {
            if (isset($stats[$key]) && is_numeric($stats[$key])) {
                return max(0, (int) $stats[$key]);
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $events
     */
    protected function extractDeltaRestoreSize(array $events): ?int
    {
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            if (($event['message_type'] ?? null) !== 'statistics') {
                continue;
            }

            $added = $event['added'] ?? null;

            if (is_array($added) && isset($added['bytes']) && is_numeric($added['bytes'])) {
                return max(0, (int) $added['bytes']);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    protected function buildEstimate(
        ?int $restoreSizeBytes,
        string $workPath,
        ?string $source,
        string $profile,
        array $meta = [],
    ): array {
        $diskPath = $this->resolveExistingPath($workPath);
        $freeBytes = $this->getDiskFreeBytes($diskPath);
        $archiveBytes = null;
        $reserveBytes = null;
        $requiredBytes = null;
        $missingBytes = null;
        $ok = false;
        $note = null;

        if ($restoreSizeBytes !== null) {
            $archiveBytes = $this->estimatedArchiveBytes($restoreSizeBytes, $profile);
            $reserveBytes = $this->safetyReserveBytes($restoreSizeBytes, $profile);
            $requiredBytes = $restoreSizeBytes + $archiveBytes + $reserveBytes;
        }

        if ($requiredBytes === null) {
            $note = 'Unable to estimate required disk space for export.';
        } elseif ($freeBytes === null) {
            $note = 'Unable to determine free disk space for export.';
        } else {
            $missingBytes = max($requiredBytes - $freeBytes, 0);
            $ok = $missingBytes === 0;
            $note = $ok ? 'Disk space preflight passed.' : 'Insufficient disk space for export.';
        }

        return array_merge($meta, [
            'exit_code' => $ok ? 0 : 1,
            'ok' => $ok,
            'profile' => $profile,
            'source' => $source,
            'work_path' => $workPath,
            'disk_path' => $diskPath,
            'free_bytes' => $freeBytes,
            'restore_size_bytes' => $restoreSizeBytes,
            'estimated_archive_bytes' => $archiveBytes,
            'reserve_bytes' => $reserveBytes,
            'required_bytes' => $requiredBytes,
            'missing_bytes' => $missingBytes,
            'note' => $note,
        ]);
    }

    protected function estimatedArchiveBytes(int $restoreSizeBytes, string $profile): int
    {
        [$ratio, $minimum] = match ($profile) {
            'delta' => [self::DELTA_ARCHIVE_RATIO, self::DELTA_MIN_ARCHIVE_BYTES],
            default => [self::SNAPSHOT_ARCHIVE_RATIO, self::SNAPSHOT_MIN_ARCHIVE_BYTES],
        };

        return max((int) ceil($restoreSizeBytes * $ratio), $minimum);
    }

    protected function safetyReserveBytes(int $restoreSizeBytes, string $profile): int
    {
        [$ratio, $minimum] = match ($profile) {
            'delta' => [self::DELTA_RESERVE_RATIO, self::DELTA_MIN_RESERVE_BYTES],
            default => [self::SNAPSHOT_RESERVE_RATIO, self::SNAPSHOT_MIN_RESERVE_BYTES],
        };

        return max((int) ceil($restoreSizeBytes * $ratio), $minimum);
    }

    protected function resolveExistingPath(string $path): string
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);

        if ($path === '') {
            return DIRECTORY_SEPARATOR;
        }

        while (! file_exists($path) && $path !== DIRECTORY_SEPARATOR) {
            $next = dirname($path);

            if ($next === $path) {
                break;
            }

            $path = $next;
        }

        return $path !== '' ? $path : DIRECTORY_SEPARATOR;
    }

    protected function getDiskFreeBytes(string $path): ?int
    {
        if (function_exists('statvfs')) {
            $stat = @statvfs($path);

            if (is_array($stat) && isset($stat['f_bavail'], $stat['f_frsize'])) {
                return (int) ($stat['f_bavail'] * $stat['f_frsize']);
            }
        }

        $free = @disk_free_space($path);

        if ($free === false) {
            return null;
        }

        return (int) $free;
    }

    protected function intFromPayload(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatProcessResult(ProcessResult $result): array
    {
        return [
            'exit_code' => $result->exitCode,
            'duration_ms' => $result->durationMs,
            'stdout' => $result->stdout,
            'stderr' => $result->stderr,
            'command' => $result->safeCommandString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatThrowable(Throwable $exception): array
    {
        return [
            'exit_code' => 1,
            'stderr' => $exception->getMessage(),
        ];
    }
}
