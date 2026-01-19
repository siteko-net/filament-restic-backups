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
    $notAvailable = __('restic-backups::backups.views.placeholders.not_available');
    $dash = __('restic-backups::backups.views.placeholders.dash');
    $yesLabel = __('restic-backups::backups.views.values.yes');
    $noLabel = __('restic-backups::backups.views.values.no');
    $enabledLabel = __('restic-backups::backups.views.values.enabled');
    $disabledLabel = __('restic-backups::backups.views.values.disabled');

    $formatBytes = function (?int $bytes): string {
        if ($bytes === null) {
            return __('restic-backups::backups.views.placeholders.not_available');
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
            return __('restic-backups::backups.views.placeholders.not_available');
        }

        $seconds = $run->started_at->diffInSeconds($run->finished_at);

        if ($seconds < 60) {
            return __('restic-backups::backups.pages.runs.duration.seconds', ['seconds' => $seconds]);
        }

        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return __('restic-backups::backups.pages.runs.duration.minutes', [
                'minutes' => $minutes,
                'seconds' => $remainingSeconds,
            ]);
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return __('restic-backups::backups.pages.runs.duration.hours', [
            'hours' => $hours,
            'minutes' => $remainingMinutes,
        ]);
    };

    $statusBadge = function (?string $status): array {
        return match ($status) {
            'success' => [__('restic-backups::backups.views.overview.status.success'), 'rb-badge rb-badge--ok'],
            'failed' => [__('restic-backups::backups.views.overview.status.failed'), 'rb-badge rb-badge--error'],
            'running' => [__('restic-backups::backups.views.overview.status.running'), 'rb-badge rb-badge--warn'],
            'skipped' => [__('restic-backups::backups.views.overview.status.skipped'), 'rb-badge rb-badge--warn'],
            default => [__('restic-backups::backups.views.overview.status.unknown'), 'rb-badge rb-badge--warn'],
        };
    };

    $repoStatus = (string) ($repo['status'] ?? 'error');
    $repoBadge = match ($repoStatus) {
        'ok' => [__('restic-backups::backups.views.overview.status.repo_available'), 'rb-badge rb-badge--ok'],
        'uninitialized' => [__('restic-backups::backups.views.overview.status.repo_uninitialized'), 'rb-badge rb-badge--warn'],
        default => [__('restic-backups::backups.views.overview.status.repo_error'), 'rb-badge rb-badge--error'],
    };

    $lock = $system['lock'] ?? [];
    $lockStatus = $lock['likely_locked'] ?? null;
    $lockInfo = is_array($lock['info'] ?? null) ? $lock['info'] : null;
    $lockContext = is_array($lockInfo['context'] ?? null) ? $lockInfo['context'] : [];
    $lockStale = $lock['stale'] ?? null;
    $lockLabel = $lockStatus === true
        ? [__('restic-backups::backups.views.overview.status.lock_likely'), 'rb-badge rb-badge--warn']
        : ($lockStatus === false
            ? [__('restic-backups::backups.views.overview.status.lock_not'), 'rb-badge rb-badge--ok']
            : [__('restic-backups::backups.views.overview.status.lock_unknown'), 'rb-badge rb-badge--warn']);
@endphp

<div class="rb-run-details">
    <div class="rb-grid rb-grid-2">
        <div class="rb-card">
            <div class="rb-inline">
                <div class="rb-label">{{ __('restic-backups::backups.views.overview.labels.repository') }}</div>
                <span class="{{ $repoBadge[1] }}">{{ $repoBadge[0] }}</span>
            </div>
            <div class="rb-text">{{ $repo['message'] ?? $dash }}</div>
            <div class="rb-text">
                {{ __('restic-backups::backups.views.overview.labels.snapshots') }}: {{ $repo['snapshots_count'] ?? $notAvailable }}
            </div>
            @if (! empty($lastSnapshot))
                <div class="rb-text">
                    {{ __('restic-backups::backups.views.overview.labels.last') }}: {{ $lastSnapshot['time'] ?? $notAvailable }}
                    ({{ $lastSnapshot['short_id'] ?? $notAvailable }})
                </div>
                @if (! empty($lastSnapshot['tags']))
                    <div class="rb-text">
                        {{ __('restic-backups::backups.views.overview.labels.tags') }}: {{ implode(', ', $lastSnapshot['tags']) }}
                    </div>
                @endif
            @endif
        </div>

        <div class="rb-card">
            <div class="rb-inline">
                <div class="rb-label">{{ __('restic-backups::backups.views.overview.labels.system') }}</div>
                <span class="{{ $lockLabel[1] }}">{{ $lockLabel[0] }}</span>
            </div>
            <div class="rb-text">
                {{ __('restic-backups::backups.views.overview.labels.free_disk') }}: {{ $formatBytes($system['disk_free_bytes'] ?? null) }}
            </div>
            <div class="rb-text">
                {{ __('restic-backups::backups.views.overview.labels.queue') }}: {{ $queue['connection'] ?? $notAvailable }}
            </div>
            @if (($queue['is_sync'] ?? false) === true)
                <div class="rb-text rb-muted">{{ __('restic-backups::backups.views.overview.messages.queue_sync') }}</div>
            @endif
            <div class="rb-text">
                {{ __('restic-backups::backups.views.overview.labels.settings_configured') }}:
                {{ ($settings['configured'] ?? false) ? $yesLabel : $noLabel }}
            </div>
            @if (array_key_exists('schedule_enabled', $settings))
                <div class="rb-text">
                    {{ __('restic-backups::backups.views.overview.labels.schedule') }}:
                    {{ ($settings['schedule_enabled'] ?? false) ? $enabledLabel : $disabledLabel }}
                </div>
            @endif
            @if (is_array($lockInfo))
                <div class="rb-text">
                    {{ __('restic-backups::backups.views.overview.labels.operation') }}: {{ $lockInfo['type'] ?? $notAvailable }}
                </div>
                <div class="rb-text">
                    {{ __('restic-backups::backups.views.overview.labels.run_id') }}: {{ $lockInfo['run_id'] ?? $notAvailable }}
                </div>
                <div class="rb-text">
                    {{ __('restic-backups::backups.views.overview.labels.started') }}: {{ $lockInfo['started_at'] ?? $notAvailable }}
                </div>
                <div class="rb-text">
                    {{ __('restic-backups::backups.views.overview.labels.heartbeat') }}: {{ $lockInfo['last_heartbeat_at'] ?? $notAvailable }}
                </div>
                <div class="rb-text">
                    {{ __('restic-backups::backups.views.overview.labels.host') }}: {{ $lockInfo['hostname'] ?? $notAvailable }}
                </div>
                @if (! empty($lockContext['step']))
                    <div class="rb-text">
                        {{ __('restic-backups::backups.views.overview.labels.step') }}: {{ $lockContext['step'] }}
                    </div>
                @endif
                @if ($lockStale === true)
                    <div class="rb-text rb-muted">{{ __('restic-backups::backups.views.overview.messages.lock_stale') }}</div>
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
                <div class="rb-label">{{ __('restic-backups::backups.views.overview.labels.last_backup') }}</div>
                @php([$label, $class] = $statusBadge($lastBackup?->status ?? null))
                <span class="{{ $class }}">{{ $label }}</span>
            </div>
            <div class="rb-text">{{ __('restic-backups::backups.views.overview.labels.started') }}: {{ $lastBackup?->started_at ?? $notAvailable }}</div>
            <div class="rb-text">{{ __('restic-backups::backups.views.overview.labels.duration') }}: {{ $formatDuration($lastBackup) }}</div>
            <div class="rb-text rb-muted">
                {{ __('restic-backups::backups.views.overview.labels.trigger') }}:
                {{ data_get($lastBackup?->meta, 'trigger', $notAvailable) }}
            </div>
        </div>

        <div class="rb-card">
            <div class="rb-inline">
                <div class="rb-label">{{ __('restic-backups::backups.views.overview.labels.last_restore') }}</div>
                @php([$label, $class] = $statusBadge($lastRestore?->status ?? null))
                <span class="{{ $class }}">{{ $label }}</span>
            </div>
            <div class="rb-text">{{ __('restic-backups::backups.views.overview.labels.started') }}: {{ $lastRestore?->started_at ?? $notAvailable }}</div>
            <div class="rb-text">{{ __('restic-backups::backups.views.overview.labels.duration') }}: {{ $formatDuration($lastRestore) }}</div>
            <div class="rb-text rb-muted">
                {{ __('restic-backups::backups.views.overview.labels.scope') }}:
                {{ data_get($lastRestore?->meta, 'scope', $notAvailable) }}
            </div>
        </div>

        <div class="rb-card">
            <div class="rb-inline">
                <div class="rb-label">{{ __('restic-backups::backups.views.overview.labels.last_failed') }}</div>
                @php([$label, $class] = $statusBadge($lastFailed?->status ?? null))
                <span class="{{ $class }}">{{ $label }}</span>
            </div>
            <div class="rb-text">{{ __('restic-backups::backups.views.overview.labels.started') }}: {{ $lastFailed?->started_at ?? $notAvailable }}</div>
            <div class="rb-text">{{ __('restic-backups::backups.views.overview.labels.duration') }}: {{ $formatDuration($lastFailed) }}</div>
            <div class="rb-text rb-muted">
                {{ data_get($lastFailed?->meta, 'error_message', $dash) }}
            </div>
        </div>
    </div>
</div>
