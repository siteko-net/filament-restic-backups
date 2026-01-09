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

<div class="space-y-6">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
            <div class="text-xs uppercase text-gray-500">Status</div>
            <div class="text-sm font-medium">{{ $record->status }}</div>
        </div>
        <div>
            <div class="text-xs uppercase text-gray-500">Type</div>
            <div class="text-sm font-medium">{{ $record->type }}</div>
        </div>
        <div>
            <div class="text-xs uppercase text-gray-500">Started</div>
            <div class="text-sm font-medium">{{ $record->started_at }}</div>
        </div>
        <div>
            <div class="text-xs uppercase text-gray-500">Finished</div>
            <div class="text-sm font-medium">{{ $record->finished_at }}</div>
        </div>
        <div>
            <div class="text-xs uppercase text-gray-500">Duration</div>
            <div class="text-sm font-medium">
                {{ $duration !== null ? $duration . 's' : 'n/a' }}
            </div>
        </div>
        <div>
            <div class="text-xs uppercase text-gray-500">Trigger</div>
            <div class="text-sm font-medium">{{ $meta['trigger'] ?? 'n/a' }}</div>
        </div>
        <div class="md:col-span-2">
            <div class="text-xs uppercase text-gray-500">Tags</div>
            <div class="text-sm font-medium">
                {{ is_array($tags) ? implode(', ', $tags) : '' }}
            </div>
        </div>
    </div>

    @if (! empty($meta['error_message']))
        <div class="rounded border border-red-200 bg-red-50 p-3">
            <div class="text-xs uppercase text-red-700">Error</div>
            <div class="text-sm text-red-700 break-words">{{ $meta['error_message'] }}</div>
            @if (! empty($meta['step']))
                <div class="text-xs text-red-700 mt-1">Step: {{ $meta['step'] }}</div>
            @endif
        </div>
    @endif

    @if ($record->type === 'restore')
        <div class="rounded border border-gray-200 p-3">
            <div class="text-xs uppercase text-gray-500">Restore</div>
            <div class="text-sm">Snapshot: {{ $snapshot['short_id'] ?? $meta['snapshot_id'] ?? 'n/a' }}</div>
            <div class="text-sm">Scope: {{ $meta['scope'] ?? 'n/a' }}</div>
            <div class="text-sm">Mode: {{ $meta['mode'] ?? 'n/a' }}</div>
            <div class="text-sm">Safety backup: {{ ($meta['safety_backup'] ?? false) ? 'yes' : 'no' }}</div>
            @if (! empty($rollbackPath))
                <div class="text-sm flex flex-wrap items-center gap-2">
                    <span>Rollback path:</span>
                    <span class="font-mono text-xs break-all">{{ $rollbackPath }}</span>
                    <button type="button" class="text-xs text-gray-500 underline" x-data @click="navigator.clipboard.writeText(@js($rollbackPath))">Copy</button>
                </div>
            @endif
            @if (! empty($safetyDumpPath))
                <div class="text-sm flex flex-wrap items-center gap-2">
                    <span>Safety DB dump:</span>
                    <span class="font-mono text-xs break-all">{{ $safetyDumpPath }}</span>
                    <button type="button" class="text-xs text-gray-500 underline" x-data @click="navigator.clipboard.writeText(@js($safetyDumpPath))">Copy</button>
                </div>
            @endif
            @if (! empty($bypassPath))
                <div class="text-sm flex flex-wrap items-center gap-2">
                    <span>Bypass path:</span>
                    <span class="font-mono text-xs break-all">{{ $bypassPath }}</span>
                    <button type="button" class="text-xs text-gray-500 underline" x-data @click="navigator.clipboard.writeText(@js($bypassPath))">Copy</button>
                </div>
            @endif
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <div class="rounded border border-gray-200 p-3">
            <div class="text-xs uppercase text-gray-500">DB Dump</div>
            <div class="text-sm">Duration: {{ $dump['duration_ms'] ?? 'n/a' }} ms</div>
            <div class="text-sm">Size: {{ isset($dump['size_bytes']) ? $formatBytes((int) $dump['size_bytes']) : 'n/a' }}</div>
        </div>
        <div class="rounded border border-gray-200 p-3">
            <div class="text-xs uppercase text-gray-500">Backup</div>
            <div class="text-sm">Exit: {{ $backup['exit_code'] ?? 'n/a' }}</div>
            <div class="text-sm">Duration: {{ $backup['duration_ms'] ?? 'n/a' }} ms</div>
        </div>
        <div class="rounded border border-gray-200 p-3">
            <div class="text-xs uppercase text-gray-500">Retention</div>
            <div class="text-sm">
                @if (! empty($retention['skipped']))
                    Skipped ({{ $retention['reason'] ?? 'n/a' }})
                @else
                    Exit: {{ $retention['exit_code'] ?? 'n/a' }}
                @endif
            </div>
            <div class="text-sm">Duration: {{ $retention['duration_ms'] ?? 'n/a' }} ms</div>
        </div>
    </div>

    @if (! empty($dump['stderr']))
        <details class="rounded border border-gray-200 p-3">
            <summary class="text-sm font-medium">Dump stderr</summary>
            <pre class="mt-2 text-xs whitespace-pre-wrap">{{ $dump['stderr'] }}</pre>
        </details>
    @endif

    @if (! empty($backup['stdout']))
        <details class="rounded border border-gray-200 p-3">
            <summary class="text-sm font-medium">Backup stdout</summary>
            <pre class="mt-2 text-xs whitespace-pre-wrap">{{ $backup['stdout'] }}</pre>
        </details>
    @endif

    @if (! empty($backup['stderr']))
        <details class="rounded border border-gray-200 p-3">
            <summary class="text-sm font-medium">Backup stderr</summary>
            <pre class="mt-2 text-xs whitespace-pre-wrap">{{ $backup['stderr'] }}</pre>
        </details>
    @endif

    @if (! empty($retention['stdout']))
        <details class="rounded border border-gray-200 p-3">
            <summary class="text-sm font-medium">Retention stdout</summary>
            <pre class="mt-2 text-xs whitespace-pre-wrap">{{ $retention['stdout'] }}</pre>
        </details>
    @endif

    @if (! empty($retention['stderr']))
        <details class="rounded border border-gray-200 p-3">
            <summary class="text-sm font-medium">Retention stderr</summary>
            <pre class="mt-2 text-xs whitespace-pre-wrap">{{ $retention['stderr'] }}</pre>
        </details>
    @endif

    @if ($record->type === 'restore' && ! empty($steps))
        @php
            $stepsJson = json_encode($steps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        @endphp
        <details class="rounded border border-gray-200 p-3">
            <summary class="text-sm font-medium">Restore steps (raw)</summary>
            <pre class="mt-2 text-xs whitespace-pre-wrap">{{ $stepsJson }}</pre>
        </details>
    @endif
</div>
