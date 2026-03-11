@php
    $meta = $record->meta ?? [];
    $dump = $meta['dump'] ?? [];
    $backup = $meta['backup'] ?? [];
    $retention = $meta['retention'] ?? [];
    $tags = $meta['tags'] ?? [];
    $steps = $meta['steps'] ?? [];
    $snapshot = $meta['snapshot'] ?? [];
    $restoreMeta = $meta['restore'] ?? [];
    $rollback = $meta['rollback'] ?? [];
    $rollbackPath = $restoreMeta['rollback_dir'] ?? null;
    $rollbackPathOriginal = $restoreMeta['rollback_dir_original'] ?? $rollbackPath;
    $safetyDumpPath = $restoreMeta['safety_dump_path'] ?? null;
    $bypassPath = $restoreMeta['bypass_path'] ?? null;
    $projectRoot = $meta['project_root'] ?? null;
    $duration = null;
    $notAvailable = __('restic-backups::backups.views.placeholders.not_available');
    $yesLabel = __('restic-backups::backups.views.values.yes');
    $noLabel = __('restic-backups::backups.views.values.no');
    $timezone = $timezone ?? \Siteko\FilamentResticBackups\Support\BackupsTimezone::resolve();
    $formatDateTime = function ($value) use ($timezone, $notAvailable): string {
        return \Siteko\FilamentResticBackups\Support\BackupsTimezone::format(
            $value,
            $timezone,
            \Siteko\FilamentResticBackups\Support\BackupsTimezone::DEFAULT_FORMAT,
            $notAvailable,
        );
    };

    if ($record->started_at && $record->finished_at) {
        $duration = $record->started_at->diffInSeconds($record->finished_at);
    }

    $formatBytes = function (int $bytes): string {
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

    $formatScope = function ($scope) use ($notAvailable): string {
        if (! is_string($scope) || trim($scope) === '') {
            return $notAvailable;
        }

        $scope = strtolower(trim($scope));
        $key = 'restic-backups::backups.views.scope_values.' . $scope;
        $translated = __($key);

        return $translated === $key ? $scope : $translated;
    };

    if ($safetyDumpPath === null && is_string($rollbackPath)) {
        $candidate = $rollbackPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . '_backup' . DIRECTORY_SEPARATOR . 'db.sql.gz';
        if (is_file($candidate)) {
            $safetyDumpPath = $candidate;
        }
    }

    if (! is_string($rollbackPath) || ! is_dir($rollbackPath)) {
        $rollbackPath = null;
    }

    if (is_string($safetyDumpPath) && ! is_file($safetyDumpPath)) {
        $safetyDumpPath = null;
    }

    if ($safetyDumpPath === null && is_string($projectRoot)) {
        $candidate = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . '_backup' . DIRECTORY_SEPARATOR . 'db.sql.gz';
        if (is_file($candidate)) {
            $safetyDumpPath = $candidate;
        }
    }

    $showBypassPath = $record->type === 'restore'
        && in_array((string) $record->status, ['running', 'failed'], true)
        && is_string($bypassPath)
        && trim($bypassPath) !== '';
    $bypassUrl = null;

    if ($showBypassPath) {
        $bypassUrl = str_starts_with($bypassPath, 'http://') || str_starts_with($bypassPath, 'https://')
            ? $bypassPath
            : url($bypassPath);
    }
@endphp

<div class="rb-run-details">
    <div class="rb-grid rb-grid-2">
        <div class="rb-stack">
            <div class="rb-label">{{ __('restic-backups::backups.views.runs.labels.status') }}</div>
            <div class="rb-value">{{ $record->status }}</div>
        </div>
        <div class="rb-stack">
            <div class="rb-label">{{ __('restic-backups::backups.views.runs.labels.type') }}</div>
            <div class="rb-value">{{ $record->type }}</div>
        </div>
        <div class="rb-stack">
            <div class="rb-label">{{ __('restic-backups::backups.views.runs.labels.started') }}</div>
            <div class="rb-value">{{ $formatDateTime($record->started_at) }}</div>
        </div>
        <div class="rb-stack">
            <div class="rb-label">{{ __('restic-backups::backups.views.runs.labels.finished') }}</div>
            <div class="rb-value">{{ $formatDateTime($record->finished_at) }}</div>
        </div>
        <div class="rb-stack">
            <div class="rb-label">{{ __('restic-backups::backups.views.runs.labels.duration') }}</div>
            <div class="rb-value">
                {{ $duration !== null
                    ? __('restic-backups::backups.pages.runs.duration.seconds', ['seconds' => $duration])
                    : $notAvailable }}
            </div>
        </div>
        <div class="rb-stack">
            <div class="rb-label">{{ __('restic-backups::backups.views.runs.labels.trigger') }}</div>
            <div class="rb-value">{{ $meta['trigger'] ?? $notAvailable }}</div>
        </div>
        <div class="rb-stack">
            <div class="rb-label">{{ __('restic-backups::backups.views.runs.labels.tags') }}</div>
            <div class="rb-value">
                {{ is_array($tags) ? implode(', ', $tags) : '' }}
            </div>
        </div>
    </div>

    @if (! empty($meta['error_message']))
        <div class="rb-card rb-card--error">
            <div class="rb-label">{{ __('restic-backups::backups.views.runs.labels.error') }}</div>
            <div class="rb-text">{{ $meta['error_message'] }}</div>
            @if (! empty($meta['step']))
                <div class="rb-text rb-text--muted">
                    {{ __('restic-backups::backups.views.runs.labels.step') }}: {{ $meta['step'] }}
                </div>
            @endif
        </div>
    @endif

    @if ($record->type === 'restore')
        <div class="rb-card">
            <div class="rb-label">{{ __('restic-backups::backups.views.runs.labels.restore') }}</div>
            <div class="rb-text">
                {{ __('restic-backups::backups.views.runs.labels.snapshot') }}:
                {{ $snapshot['short_id'] ?? $meta['snapshot_id'] ?? $notAvailable }}
            </div>
            <div class="rb-text">{{ __('restic-backups::backups.views.runs.labels.scope') }}: {{ $formatScope($meta['scope'] ?? null) }}</div>
            <div class="rb-text">{{ __('restic-backups::backups.views.runs.labels.mode') }}: {{ $meta['mode'] ?? $notAvailable }}</div>
            <div class="rb-text">
                {{ __('restic-backups::backups.views.runs.labels.safety_backup') }}:
                {{ ($meta['safety_backup'] ?? false) ? $yesLabel : $noLabel }}
            </div>
            @if (! empty($rollbackPath))
                <div class="rb-inline">
                    <span>{{ __('restic-backups::backups.views.runs.labels.rollback_path') }}:</span>
                    <span class="rb-mono">{{ $rollbackPath }}</span>
                    <button type="button" class="rb-link" x-data @click="navigator.clipboard.writeText(@js($rollbackPath))">
                        {{ __('restic-backups::backups.views.runs.labels.copy') }}
                    </button>
                </div>
            @elseif (
                ! empty($rollbackPathOriginal)
                && ($rollback['attempted'] ?? false)
                && ($rollback['success'] ?? false)
            )
                <div class="rb-text rb-text--muted">
                    {{ __('restic-backups::backups.views.runs.labels.rollback_path_moved_back', [
                        'path' => is_string($projectRoot) ? $projectRoot : $notAvailable,
                    ]) }}
                </div>
            @endif
            @if (! empty($safetyDumpPath))
                <div class="rb-inline">
                    <span>{{ __('restic-backups::backups.views.runs.labels.safety_dump') }}:</span>
                    <span class="rb-mono">{{ $safetyDumpPath }}</span>
                    <button type="button" class="rb-link" x-data @click="navigator.clipboard.writeText(@js($safetyDumpPath))">
                        {{ __('restic-backups::backups.views.runs.labels.copy') }}
                    </button>
                </div>
            @endif
            @if ($showBypassPath && is_string($bypassUrl))
                <div class="rb-text rb-text--muted">
                    {{ __('restic-backups::backups.views.runs.labels.bypass_notice') }}
                </div>
                <div class="rb-inline">
                    <a href="{{ $bypassUrl }}" class="rb-link rb-mono" target="_blank" rel="noopener noreferrer">{{ $bypassUrl }}</a>
                    <button type="button" class="rb-link" x-data @click="navigator.clipboard.writeText(@js($bypassUrl))">
                        {{ __('restic-backups::backups.views.runs.labels.copy') }}
                    </button>
                </div>
            @endif
        </div>
    @endif

    <div class="rb-grid rb-grid-3">
        <div class="rb-card">
            <div class="rb-label">{{ __('restic-backups::backups.views.runs.labels.db_dump') }}</div>
            <div class="rb-text">
                {{ __('restic-backups::backups.views.runs.labels.duration') }}: {{ $dump['duration_ms'] ?? $notAvailable }} ms
            </div>
            <div class="rb-text">
                {{ __('restic-backups::backups.views.runs.labels.size') }}:
                {{ isset($dump['size_bytes']) ? $formatBytes((int) $dump['size_bytes']) : $notAvailable }}
            </div>
        </div>
        <div class="rb-card">
            <div class="rb-label">{{ __('restic-backups::backups.views.runs.labels.backup') }}</div>
            <div class="rb-text">{{ __('restic-backups::backups.views.runs.labels.exit') }}: {{ $backup['exit_code'] ?? $notAvailable }}</div>
            <div class="rb-text">
                {{ __('restic-backups::backups.views.runs.labels.duration') }}: {{ $backup['duration_ms'] ?? $notAvailable }} ms
            </div>
        </div>
        <div class="rb-card">
            <div class="rb-label">{{ __('restic-backups::backups.views.runs.labels.retention') }}</div>
            <div class="rb-text">
                @if (! empty($retention['skipped']))
                    {{ __('restic-backups::backups.views.runs.labels.skipped', ['reason' => $retention['reason'] ?? $notAvailable]) }}
                @else
                    {{ __('restic-backups::backups.views.runs.labels.exit') }}: {{ $retention['exit_code'] ?? $notAvailable }}
                @endif
            </div>
            <div class="rb-text">
                {{ __('restic-backups::backups.views.runs.labels.duration') }}: {{ $retention['duration_ms'] ?? $notAvailable }} ms
            </div>
        </div>
    </div>

    @if (! empty($dump['stderr']))
        <details class="rb-details">
            <summary class="rb-summary">{{ __('restic-backups::backups.views.runs.details.dump_stderr') }}</summary>
            <pre class="rb-pre">{{ $dump['stderr'] }}</pre>
        </details>
    @endif

    @if (! empty($backup['stdout']))
        <details class="rb-details">
            <summary class="rb-summary">{{ __('restic-backups::backups.views.runs.details.backup_stdout') }}</summary>
            <pre class="rb-pre">{{ $backup['stdout'] }}</pre>
        </details>
    @endif

    @if (! empty($backup['stderr']))
        <details class="rb-details">
            <summary class="rb-summary">{{ __('restic-backups::backups.views.runs.details.backup_stderr') }}</summary>
            <pre class="rb-pre">{{ $backup['stderr'] }}</pre>
        </details>
    @endif

    @if (! empty($retention['stdout']))
        <details class="rb-details">
            <summary class="rb-summary">{{ __('restic-backups::backups.views.runs.details.retention_stdout') }}</summary>
            <pre class="rb-pre">{{ $retention['stdout'] }}</pre>
        </details>
    @endif

    @if (! empty($retention['stderr']))
        <details class="rb-details">
            <summary class="rb-summary">{{ __('restic-backups::backups.views.runs.details.retention_stderr') }}</summary>
            <pre class="rb-pre">{{ $retention['stderr'] }}</pre>
        </details>
    @endif

    @if ($record->type === 'restore' && ! empty($steps))
        @php
            $stepsJson = json_encode($steps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        @endphp
        <details class="rb-details">
            <summary class="rb-summary">{{ __('restic-backups::backups.views.runs.details.restore_steps_raw') }}</summary>
            <pre class="rb-pre">{{ $stepsJson }}</pre>
        </details>
    @endif
</div>
