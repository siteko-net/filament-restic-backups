@php
    $archive = is_array($record['archive'] ?? null) ? $record['archive'] : [];
    $status = $archive['status'] ?? 'none';
    $downloadUrl = $archive['download_url'] ?? null;
    $deleteUrl = $archive['delete_url'] ?? null;
    $archiveSize = isset($archive['size_bytes']) && is_numeric($archive['size_bytes'])
        ? (int) $archive['size_bytes']
        : null;
    $notAvailable = __('restic-backups::backups.pages.snapshots.placeholders.not_available');

    $statusMap = [
        'ready' => [__('restic-backups::backups.pages.snapshots.archive.status.ready'), 'rb-badge rb-badge--ok'],
        'queue' => [__('restic-backups::backups.pages.snapshots.archive.status.queue'), 'rb-badge rb-badge--warn'],
        'failed' => [__('restic-backups::backups.pages.snapshots.archive.status.failed'), 'rb-badge rb-badge--error'],
        'expired' => [__('restic-backups::backups.pages.snapshots.archive.status.expired'), 'rb-badge rb-badge--warn'],
        'deleted' => [__('restic-backups::backups.pages.snapshots.archive.status.deleted'), 'rb-badge rb-badge--warn'],
        'none' => [__('restic-backups::backups.pages.snapshots.archive.status.none'), 'rb-badge rb-badge--warn'],
    ];

    [$label, $class] = $statusMap[$status] ?? $statusMap['none'];

    $formatBytes = function (?int $bytes) use ($notAvailable): string {
        if ($bytes === null) {
            return $notAvailable;
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

    $archiveSizeLabel = $formatBytes($archiveSize);
    $archiveSizeHelp = __('restic-backups::backups.pages.snapshots.archive.size_help');
@endphp

<div class="rb-stack">
    <span class="{{ $class }}">{{ $label }}</span>
    @if ($archiveSize !== null)
        <span class="rb-text rb-text--muted rb-text--sm rb-tooltip" title="{{ $archiveSizeHelp }}" aria-label="{{ $archiveSizeHelp }}">
            {{ __('restic-backups::backups.pages.snapshots.archive.size', ['size' => $archiveSizeLabel]) }}
        </span>
    @endif
    @if (
        (is_string($downloadUrl) && $downloadUrl !== '')
        || (is_string($deleteUrl) && $deleteUrl !== '')
    )
        <div class="rb-inline">
            @if (is_string($downloadUrl) && $downloadUrl !== '')
                <a class="rb-link" href="{{ $downloadUrl }}" target="_blank" rel="noopener noreferrer">
                    {{ __('restic-backups::backups.pages.snapshots.archive.download') }}
                </a>
            @endif
            @if (is_string($deleteUrl) && $deleteUrl !== '')
                <form method="POST" action="{{ $deleteUrl }}" onsubmit="return confirm(@js(__('restic-backups::backups.pages.snapshots.archive.delete_confirm')));">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rb-link">
                        {{ __('restic-backups::backups.pages.snapshots.archive.delete') }}
                    </button>
                </form>
            @endif
        </div>
    @endif
</div>
