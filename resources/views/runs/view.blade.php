@php
    $meta = $record->meta ?? [];
    $dump = $meta['dump'] ?? [];
    $backup = $meta['backup'] ?? [];
    $retention = $meta['retention'] ?? [];
    $tags = $meta['tags'] ?? [];
    $steps = $meta['steps'] ?? [];
    $snapshot = $meta['snapshot'] ?? [];
    $restoreMeta = $meta['restore'] ?? [];
    $rollbackPath = $restoreMeta['rollback_dir'] ?? null;
    $safetyDumpPath = $restoreMeta['safety_dump_path'] ?? null;
    $bypassPath = $restoreMeta['bypass_path'] ?? null;
    $duration = null;

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

    if ($safetyDumpPath === null && is_string($rollbackPath)) {
        $candidate = $rollbackPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . '_backup' . DIRECTORY_SEPARATOR . 'db.sql.gz';
        if (is_file($candidate)) {
            $safetyDumpPath = $candidate;
        }
    }
@endphp

<div class="rb-run-details">
    <div class="rb-grid rb-grid-2">
        <div class="rb-stack">
            <div class="rb-label">Status</div>
            <div class="rb-value">{{ $record->status }}</div>
        </div>
        <div class="rb-stack">
            <div class="rb-label">Type</div>
            <div class="rb-value">{{ $record->type }}</div>
        </div>
        <div class="rb-stack">
            <div class="rb-label">Started</div>
            <div class="rb-value">{{ $record->started_at }}</div>
        </div>
        <div class="rb-stack">
            <div class="rb-label">Finished</div>
            <div class="rb-value">{{ $record->finished_at }}</div>
        </div>
        <div class="rb-stack">
            <div class="rb-label">Duration</div>
            <div class="rb-value">
                {{ $duration !== null ? $duration . 's' : 'n/a' }}
            </div>
        </div>
        <div class="rb-stack">
            <div class="rb-label">Trigger</div>
            <div class="rb-value">{{ $meta['trigger'] ?? 'n/a' }}</div>
        </div>
        <div class="rb-stack">
            <div class="rb-label">Tags</div>
            <div class="rb-value">
                {{ is_array($tags) ? implode(', ', $tags) : '' }}
            </div>
        </div>
    </div>

    @if (! empty($meta['error_message']))
        <div class="rb-card rb-card--error">
            <div class="rb-label">Error</div>
            <div class="rb-text">{{ $meta['error_message'] }}</div>
            @if (! empty($meta['step']))
                <div class="rb-text rb-text--muted">Step: {{ $meta['step'] }}</div>
            @endif
        </div>
    @endif

    @if ($record->type === 'restore')
        <div class="rb-card">
            <div class="rb-label">Restore</div>
            <div class="rb-text">Snapshot: {{ $snapshot['short_id'] ?? $meta['snapshot_id'] ?? 'n/a' }}</div>
            <div class="rb-text">Scope: {{ $meta['scope'] ?? 'n/a' }}</div>
            <div class="rb-text">Mode: {{ $meta['mode'] ?? 'n/a' }}</div>
            <div class="rb-text">Safety backup: {{ ($meta['safety_backup'] ?? false) ? 'yes' : 'no' }}</div>
            @if (! empty($rollbackPath))
                <div class="rb-inline">
                    <span>Rollback path:</span>
                    <span class="rb-mono">{{ $rollbackPath }}</span>
                    <button type="button" class="rb-link" x-data @click="navigator.clipboard.writeText(@js($rollbackPath))">Copy</button>
                </div>
            @endif
            @if (! empty($safetyDumpPath))
                <div class="rb-inline">
                    <span>Safety DB dump:</span>
                    <span class="rb-mono">{{ $safetyDumpPath }}</span>
                    <button type="button" class="rb-link" x-data @click="navigator.clipboard.writeText(@js($safetyDumpPath))">Copy</button>
                </div>
            @endif
            @if (! empty($bypassPath))
                <div class="rb-inline">
                    <span>Bypass path:</span>
                    <span class="rb-mono">{{ $bypassPath }}</span>
                    <button type="button" class="rb-link" x-data @click="navigator.clipboard.writeText(@js($bypassPath))">Copy</button>
                </div>
            @endif
        </div>
    @endif

    <div class="rb-grid rb-grid-3">
        <div class="rb-card">
            <div class="rb-label">DB Dump</div>
            <div class="rb-text">Duration: {{ $dump['duration_ms'] ?? 'n/a' }} ms</div>
            <div class="rb-text">Size: {{ isset($dump['size_bytes']) ? $formatBytes((int) $dump['size_bytes']) : 'n/a' }}</div>
        </div>
        <div class="rb-card">
            <div class="rb-label">Backup</div>
            <div class="rb-text">Exit: {{ $backup['exit_code'] ?? 'n/a' }}</div>
            <div class="rb-text">Duration: {{ $backup['duration_ms'] ?? 'n/a' }} ms</div>
        </div>
        <div class="rb-card">
            <div class="rb-label">Retention</div>
            <div class="rb-text">
                @if (! empty($retention['skipped']))
                    Skipped ({{ $retention['reason'] ?? 'n/a' }})
                @else
                    Exit: {{ $retention['exit_code'] ?? 'n/a' }}
                @endif
            </div>
            <div class="rb-text">Duration: {{ $retention['duration_ms'] ?? 'n/a' }} ms</div>
        </div>
    </div>

    @if (! empty($dump['stderr']))
        <details class="rb-details">
            <summary class="rb-summary">Dump stderr</summary>
            <pre class="rb-pre">{{ $dump['stderr'] }}</pre>
        </details>
    @endif

    @if (! empty($backup['stdout']))
        <details class="rb-details">
            <summary class="rb-summary">Backup stdout</summary>
            <pre class="rb-pre">{{ $backup['stdout'] }}</pre>
        </details>
    @endif

    @if (! empty($backup['stderr']))
        <details class="rb-details">
            <summary class="rb-summary">Backup stderr</summary>
            <pre class="rb-pre">{{ $backup['stderr'] }}</pre>
        </details>
    @endif

    @if (! empty($retention['stdout']))
        <details class="rb-details">
            <summary class="rb-summary">Retention stdout</summary>
            <pre class="rb-pre">{{ $retention['stdout'] }}</pre>
        </details>
    @endif

    @if (! empty($retention['stderr']))
        <details class="rb-details">
            <summary class="rb-summary">Retention stderr</summary>
            <pre class="rb-pre">{{ $retention['stderr'] }}</pre>
        </details>
    @endif

    @if ($record->type === 'restore' && ! empty($steps))
        @php
            $stepsJson = json_encode($steps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        @endphp
        <details class="rb-details">
            <summary class="rb-summary">Restore steps (raw)</summary>
            <pre class="rb-pre">{{ $stepsJson }}</pre>
        </details>
    @endif
</div>
