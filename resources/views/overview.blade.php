@php
    $overview = $overview ?? [];
    $settings = $overview['settings'] ?? [];
    $repo = $overview['repo'] ?? [];
    $runs = $overview['runs'] ?? [];
    $system = $overview['system'] ?? [];
    $queue = $system['queue'] ?? [];

    $lastSnapshot = $repo['last_snapshot'] ?? null;
    $lastBackup = $runs['last_backup'] ?? null;
    $lastRestore = $runs['last_restore'] ?? null;
    $lastFailed = $runs['last_failed'] ?? null;

    $formatBytes = function (?int $bytes): string {
        if ($bytes === null) {
            return 'n/a';
        }

        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $kb = $bytes / 1024;
        if ($kb < 1024) {
            return number_format($kb, 1) . ' KB';
        }

        $mb = $kb / 1024;
        if ($mb < 1024) {
            return number_format($mb, 1) . ' MB';
        }

        $gb = $mb / 1024;

        return number_format($gb, 1) . ' GB';
    };

    $formatDuration = function ($run): string {
        if (! $run || ! $run->started_at || ! $run->finished_at) {
            return 'n/a';
        }

        $seconds = $run->started_at->diffInSeconds($run->finished_at);

        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return sprintf('%dm %ds', $minutes, $remainingSeconds);
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return sprintf('%dh %dm', $hours, $remainingMinutes);
    };

    $statusBadge = function (?string $status): array {
        return match ($status) {
            'success' => ['Success', 'rb-badge rb-badge--ok'],
            'failed' => ['Failed', 'rb-badge rb-badge--error'],
            'running' => ['Running', 'rb-badge rb-badge--warn'],
            'skipped' => ['Skipped', 'rb-badge rb-badge--warn'],
            default => ['n/a', 'rb-badge rb-badge--warn'],
        };
    };

    $repoStatus = (string) ($repo['status'] ?? 'error');
    $repoBadge = match ($repoStatus) {
        'ok' => ['Available', 'rb-badge rb-badge--ok'],
        'uninitialized' => ['Not initialized', 'rb-badge rb-badge--warn'],
        default => ['Error', 'rb-badge rb-badge--error'],
    };

    $lock = $system['lock'] ?? [];
    $lockStatus = $lock['likely_locked'] ?? null;
    $lockInfo = is_array($lock['info'] ?? null) ? $lock['info'] : null;
    $lockContext = is_array($lockInfo['context'] ?? null) ? $lockInfo['context'] : [];
    $lockStale = $lock['stale'] ?? null;
    $lockLabel = $lockStatus === true
        ? ['Likely locked', 'rb-badge rb-badge--warn']
        : ($lockStatus === false ? ['Not locked', 'rb-badge rb-badge--ok'] : ['Unknown', 'rb-badge rb-badge--warn']);
@endphp

<div class="rb-run-details">
    <div class="rb-grid rb-grid-2">
        <div class="rb-card">
            <div class="rb-inline">
                <div class="rb-label">Repository</div>
                <span class="{{ $repoBadge[1] }}">{{ $repoBadge[0] }}</span>
            </div>
            <div class="rb-text">{{ $repo['message'] ?? '—' }}</div>
            <div class="rb-text">Snapshots: {{ $repo['snapshots_count'] ?? 'n/a' }}</div>
            @if (! empty($lastSnapshot))
                <div class="rb-text">
                    Last: {{ $lastSnapshot['time'] ?? 'n/a' }}
                    ({{ $lastSnapshot['short_id'] ?? 'n/a' }})
                </div>
                @if (! empty($lastSnapshot['tags']))
                    <div class="rb-text">Tags: {{ implode(', ', $lastSnapshot['tags']) }}</div>
                @endif
            @endif
        </div>

        <div class="rb-card">
            <div class="rb-inline">
                <div class="rb-label">System</div>
                <span class="{{ $lockLabel[1] }}">{{ $lockLabel[0] }}</span>
            </div>
            <div class="rb-text">Free disk: {{ $formatBytes($system['disk_free_bytes'] ?? null) }}</div>
            <div class="rb-text">Queue: {{ $queue['connection'] ?? 'n/a' }}</div>
            @if (($queue['is_sync'] ?? false) === true)
                <div class="rb-text rb-muted">Queue is sync (jobs run inline).</div>
            @endif
            <div class="rb-text">Settings configured: {{ ($settings['configured'] ?? false) ? 'yes' : 'no' }}</div>
            @if (array_key_exists('schedule_enabled', $settings))
                <div class="rb-text">Schedule: {{ ($settings['schedule_enabled'] ?? false) ? 'enabled' : 'disabled' }}</div>
            @endif
            @if (is_array($lockInfo))
                <div class="rb-text">Operation: {{ $lockInfo['type'] ?? 'n/a' }}</div>
                <div class="rb-text">Run ID: {{ $lockInfo['run_id'] ?? 'n/a' }}</div>
                <div class="rb-text">Started: {{ $lockInfo['started_at'] ?? 'n/a' }}</div>
                <div class="rb-text">Heartbeat: {{ $lockInfo['last_heartbeat_at'] ?? 'n/a' }}</div>
                <div class="rb-text">Host: {{ $lockInfo['hostname'] ?? 'n/a' }}</div>
                @if (! empty($lockContext['step']))
                    <div class="rb-text">Step: {{ $lockContext['step'] }}</div>
                @endif
                @if ($lockStale === true)
                    <div class="rb-text rb-muted">Lock looks stale (no heartbeat recently).</div>
                @endif
            @endif
            @if (! empty($lock['note']))
                <div class="rb-text rb-muted">{{ $lock['note'] }}</div>
            @endif
        </div>
    </div>

    <div class="rb-grid rb-grid-3">
        <div class="rb-card">
            <div class="rb-inline">
                <div class="rb-label">Last backup</div>
                @php([$label, $class] = $statusBadge($lastBackup?->status ?? null))
                <span class="{{ $class }}">{{ $label }}</span>
            </div>
            <div class="rb-text">Started: {{ $lastBackup?->started_at ?? 'n/a' }}</div>
            <div class="rb-text">Duration: {{ $formatDuration($lastBackup) }}</div>
            <div class="rb-text rb-muted">Trigger: {{ data_get($lastBackup?->meta, 'trigger', 'n/a') }}</div>
        </div>

        <div class="rb-card">
            <div class="rb-inline">
                <div class="rb-label">Last restore</div>
                @php([$label, $class] = $statusBadge($lastRestore?->status ?? null))
                <span class="{{ $class }}">{{ $label }}</span>
            </div>
            <div class="rb-text">Started: {{ $lastRestore?->started_at ?? 'n/a' }}</div>
            <div class="rb-text">Duration: {{ $formatDuration($lastRestore) }}</div>
            <div class="rb-text rb-muted">Scope: {{ data_get($lastRestore?->meta, 'scope', 'n/a') }}</div>
        </div>

        <div class="rb-card">
            <div class="rb-inline">
                <div class="rb-label">Last failed</div>
                @php([$label, $class] = $statusBadge($lastFailed?->status ?? null))
                <span class="{{ $class }}">{{ $label }}</span>
            </div>
            <div class="rb-text">Started: {{ $lastFailed?->started_at ?? 'n/a' }}</div>
            <div class="rb-text">Duration: {{ $formatDuration($lastFailed) }}</div>
            <div class="rb-text rb-muted">
                {{ data_get($lastFailed?->meta, 'error_message', '—') }}
            </div>
        </div>
    </div>
</div>
