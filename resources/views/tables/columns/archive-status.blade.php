@php
    $archive = is_array($record['archive'] ?? null) ? $record['archive'] : [];
    $status = $archive['status'] ?? 'none';
    $downloadUrl = $archive['download_url'] ?? null;

    $statusMap = [
        'ready' => [__('restic-backups::backups.pages.snapshots.archive.status.ready'), 'rb-badge rb-badge--ok'],
        'queue' => [__('restic-backups::backups.pages.snapshots.archive.status.queue'), 'rb-badge rb-badge--warn'],
        'failed' => [__('restic-backups::backups.pages.snapshots.archive.status.failed'), 'rb-badge rb-badge--error'],
        'expired' => [__('restic-backups::backups.pages.snapshots.archive.status.expired'), 'rb-badge rb-badge--warn'],
        'none' => [__('restic-backups::backups.pages.snapshots.archive.status.none'), 'rb-badge rb-badge--warn'],
    ];

    [$label, $class] = $statusMap[$status] ?? $statusMap['none'];
@endphp

<div class="rb-stack">
    <span class="{{ $class }}">{{ $label }}</span>
    @if (is_string($downloadUrl) && $downloadUrl !== '')
        <a class="rb-link" href="{{ $downloadUrl }}" target="_blank" rel="noopener noreferrer">
            {{ __('restic-backups::backups.pages.snapshots.archive.download') }}
        </a>
    @endif
</div>
