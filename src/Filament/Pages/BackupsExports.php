<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Filament\Pages;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions as ActionsComponent;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Siteko\FilamentResticBackups\Exceptions\ResticConfigurationException;
use Siteko\FilamentResticBackups\Jobs\ExportDisasterRecoveryDeltaJob;
use Siteko\FilamentResticBackups\Jobs\ExportDisasterRecoveryFullJob;
use Siteko\FilamentResticBackups\Models\BackupRun;
use Siteko\FilamentResticBackups\Models\BackupSetting;
use Siteko\FilamentResticBackups\Services\ResticRunner;
use Siteko\FilamentResticBackups\Support\BackupsTimezone;
use Siteko\FilamentResticBackups\Support\ExportDiskSpaceGuard;
use Siteko\FilamentResticBackups\Support\OperationLock;
use Throwable;

use function Filament\Support\generate_loading_indicator_html;

class BackupsExports extends BaseBackupsPage
{
    private const SNAPSHOT_CACHE_SECONDS = 10;

    private const OPERATION_POLL_SECONDS = 10;

    protected static ?string $slug = 'backups/exports';

    protected static ?string $navigationLabel = 'Disaster Recovery';

    protected static ?string $title = 'Disaster Recovery Exports';

    /**
     * @var array<int, array<string, mixed>> | null
     */
    public ?array $snapshotRecords = null;

    public ?int $snapshotFetchedAt = null;

    public ?string $snapshotError = null;

    public ?string $snapshotErrorDetails = null;

    public ?int $snapshotExitCode = null;

    public ?array $latestSnapshot = null;

    public ?array $baselineSnapshot = null;

    public ?string $baselineSnapshotId = null;

    public ?string $baselineCreatedAt = null;

    protected ?string $displayTimezone = null;

    /**
     * @var array{
     *     full: array<string, mixed>|null,
     *     delta: array<string, mixed>|null,
     *     delta_notice: string|null
     * } | null
     */
    protected ?array $readyArchivePair = null;

    public static function getNavigationSort(): ?int
    {
        return static::baseNavigationSort() + 4;
    }

    public static function getNavigationLabel(): string
    {
        return __('restic-backups::backups.pages.exports.navigation_label');
    }

    public function getTitle(): string
    {
        return __('restic-backups::backups.pages.exports.title');
    }

    public function mount(): void
    {
        $settings = BackupSetting::singleton();
        $this->baselineSnapshotId = $this->normalizeScalar($settings->baseline_snapshot_id);
        $this->baselineCreatedAt = $settings->baseline_created_at?->toIso8601String();
        $this->loadSnapshots();
    }

    public function content(Schema $schema): Schema
    {
        $this->readyArchivePair = $this->resolveReadyArchivePair();

        return $schema
            ->components([
                ActionsComponent::make([
                    Action::make('downloadFull')
                        ->label(__('restic-backups::backups.pages.exports.actions.full.label'))
                        ->icon('heroicon-o-arrow-down-tray')
                        ->modalHeading(__('restic-backups::backups.pages.exports.actions.full.modal_heading'))
                        ->modalDescription(__('restic-backups::backups.pages.exports.actions.full.modal_description'))
                        ->modalSubmitActionLabel(__('restic-backups::backups.pages.exports.actions.full.modal_submit_label'))
                        ->schema(fn (): array => $this->buildDiskSpaceSchema(
                            $this->computeFullExportEstimate(),
                            'restic-backups::backups.pages.exports.preflight',
                        ))
                        ->disabled(fn (): bool => $this->snapshotError !== null || $this->latestSnapshotId() === null || $this->hasRunningOperations())
                        ->action(function (): void {
                            $snapshotId = $this->latestSnapshotId();

                            if ($snapshotId === null) {
                                Notification::make()
                                    ->title(__('restic-backups::backups.pages.exports.notifications.snapshot_missing'))
                                    ->danger()
                                    ->send();

                                throw new Halt;
                            }

                            $estimate = $this->computeFullExportEstimate();

                            if (($estimate['ok'] ?? false) !== true) {
                                Notification::make()
                                    ->title(__('restic-backups::backups.pages.exports.notifications.export_disk_space_insufficient'))
                                    ->body($this->formatExportEstimateNotificationBody(
                                        $estimate,
                                        'restic-backups::backups.pages.exports.notifications',
                                    ))
                                    ->danger()
                                    ->send();

                                throw new Halt;
                            }

                            if ($this->hasRunningOperations()) {
                                Notification::make()
                                    ->title(__('restic-backups::backups.pages.exports.notifications.operation_in_progress'))
                                    ->body(__('restic-backups::backups.pages.exports.notifications.operation_running'))
                                    ->warning()
                                    ->send();

                                throw new Halt;
                            }

                            ExportDisasterRecoveryFullJob::dispatch(
                                $snapshotId,
                                24,
                                auth()->id(),
                                'filament',
                            );

                            Notification::make()
                                ->title(__('restic-backups::backups.pages.exports.notifications.full_queued'))
                                ->body(__('restic-backups::backups.pages.exports.notifications.full_queued_body'))
                                ->success()
                                ->send();
                        }),
                    Action::make('downloadDelta')
                        ->label(__('restic-backups::backups.pages.exports.actions.delta.label'))
                        ->icon('heroicon-o-arrow-down-tray')
                        ->modalHeading(__('restic-backups::backups.pages.exports.actions.delta.modal_heading'))
                        ->modalDescription(__('restic-backups::backups.pages.exports.actions.delta.modal_description'))
                        ->modalSubmitActionLabel(__('restic-backups::backups.pages.exports.actions.delta.modal_submit_label'))
                        ->schema(fn (): array => $this->buildDiskSpaceSchema(
                            $this->computeDeltaExportEstimate(),
                            'restic-backups::backups.pages.exports.preflight',
                        ))
                        ->disabled(fn (): bool => $this->snapshotError !== null || ! $this->baselineIsAvailable() || $this->hasRunningOperations())
                        ->action(function (): void {
                            if (! $this->baselineIsAvailable()) {
                                Notification::make()
                                    ->title(__('restic-backups::backups.pages.exports.notifications.baseline_missing'))
                                    ->danger()
                                    ->send();

                                throw new Halt;
                            }

                            $estimate = $this->computeDeltaExportEstimate();

                            if (($estimate['ok'] ?? false) !== true) {
                                Notification::make()
                                    ->title(__('restic-backups::backups.pages.exports.notifications.export_disk_space_insufficient'))
                                    ->body($this->formatExportEstimateNotificationBody(
                                        $estimate,
                                        'restic-backups::backups.pages.exports.notifications',
                                    ))
                                    ->danger()
                                    ->send();

                                throw new Halt;
                            }

                            if ($this->hasRunningOperations()) {
                                Notification::make()
                                    ->title(__('restic-backups::backups.pages.exports.notifications.operation_in_progress'))
                                    ->body(__('restic-backups::backups.pages.exports.notifications.operation_running'))
                                    ->warning()
                                    ->send();

                                throw new Halt;
                            }

                            ExportDisasterRecoveryDeltaJob::dispatch(
                                24,
                                auth()->id(),
                                'filament',
                            );

                            Notification::make()
                                ->title(__('restic-backups::backups.pages.exports.notifications.delta_queued'))
                                ->body(__('restic-backups::backups.pages.exports.notifications.delta_queued_body'))
                                ->success()
                                ->send();
                        }),
                ])
                    ->poll(self::OPERATION_POLL_SECONDS.'s'),
                Section::make(__('restic-backups::backups.pages.exports.sections.downloads.title'))
                    ->description(__('restic-backups::backups.pages.exports.sections.downloads.description'))
                    ->columns(1)
                    ->schema([
                        Text::make(fn (): HtmlString => $this->renderReadyArchiveLine('export_full')),
                        Text::make(fn (): HtmlString => $this->renderReadyArchiveLine('export_delta')),
                    ])
                    ->poll(self::OPERATION_POLL_SECONDS.'s'),
                Section::make(fn (): string => __('restic-backups::backups.pages.snapshots.sections.operation_in_progress.title', [
                    'type' => $this->resolveOperationTypeLabel(
                        $this->operationType(),
                        __('restic-backups::backups.pages.exports.placeholders.not_available'),
                    ),
                ]))
                    ->description(__('restic-backups::backups.pages.snapshots.sections.operation_in_progress.description'))
                    ->schema([
                        Text::make(fn (): string => __('restic-backups::backups.pages.snapshots.sections.operation_in_progress.started_at', [
                            'started_at' => $this->formatLockTimestamp(
                                $this->operationStartedAt(),
                                __('restic-backups::backups.pages.exports.placeholders.not_available'),
                            ),
                        ])),
                        Text::make(fn (): string => __('restic-backups::backups.pages.snapshots.sections.operation_in_progress.step', [
                            'step' => $this->resolveOperationStepLabel(
                                $this->operationStep(),
                                __('restic-backups::backups.pages.exports.placeholders.not_available'),
                            ),
                        ])),
                        Text::make(fn (): HtmlString => $this->formatActivityLine(
                            $this->formatRelativeTime(
                                $this->operationLastActivityAt(),
                                __('restic-backups::backups.pages.exports.placeholders.not_available'),
                            ),
                            ! $this->operationIsStale(),
                        )),
                        Text::make(__('restic-backups::backups.pages.snapshots.sections.operation_in_progress.stale_warning'))
                            ->color('danger')
                            ->visible(fn (): bool => $this->operationIsStale()),
                    ])
                    ->visible(fn (): bool => $this->isOperationRunning())
                    ->poll(self::OPERATION_POLL_SECONDS.'s'),
                Section::make(__('restic-backups::backups.pages.exports.sections.baseline.title'))
                    ->description(__('restic-backups::backups.pages.exports.sections.baseline.description'))
                    ->columns(1)
                    ->schema([
                        Text::make(fn (): string => __('restic-backups::backups.pages.exports.sections.baseline.snapshot', [
                            'id' => $this->baselineSnapshotId ?? __('restic-backups::backups.pages.exports.placeholders.not_set'),
                        ])),
                        Text::make(fn (): string => __('restic-backups::backups.pages.exports.sections.baseline.created_at', [
                            'time' => $this->formatTimestamp($this->baselineCreatedAt, __('restic-backups::backups.pages.exports.placeholders.not_set')),
                        ])),
                        Text::make(fn (): string => __('restic-backups::backups.pages.exports.sections.baseline.status', [
                            'status' => $this->baselineSnapshotId === null
                                ? __('restic-backups::backups.pages.exports.placeholders.not_set')
                                : ($this->baselineIsAvailable()
                                    ? __('restic-backups::backups.pages.exports.status.available')
                                    : __('restic-backups::backups.pages.exports.status.missing')),
                        ])),
                    ]),
                Section::make(__('restic-backups::backups.pages.exports.sections.latest.title'))
                    ->description(__('restic-backups::backups.pages.exports.sections.latest.description'))
                    ->columns(1)
                    ->schema([
                        Text::make(fn (): string => __('restic-backups::backups.pages.exports.sections.latest.snapshot', [
                            'id' => $this->latestSnapshotId() ?? __('restic-backups::backups.pages.exports.placeholders.not_available'),
                        ])),
                        Text::make(fn (): string => __('restic-backups::backups.pages.exports.sections.latest.time', [
                            'time' => $this->formatTimestamp($this->latestSnapshotTime(), __('restic-backups::backups.pages.exports.placeholders.not_available')),
                        ])),
                        Text::make(fn (): string => $this->latestSnapshotWarning() ?? __('restic-backups::backups.pages.exports.sections.latest.ok')),
                    ]),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(__('restic-backups::backups.pages.exports.header_actions.refresh'))
                ->icon('heroicon-o-arrow-path')
                ->action('refreshSnapshots'),
        ];
    }

    public function refreshSnapshots(): void
    {
        $this->loadSnapshots(force: true);
    }

    protected function loadSnapshots(bool $force = false): void
    {
        if (! $force && $this->snapshotFetchedAt !== null) {
            if ((time() - $this->snapshotFetchedAt) < self::SNAPSHOT_CACHE_SECONDS) {
                return;
            }
        }

        $this->snapshotFetchedAt = time();
        $this->snapshotError = null;
        $this->snapshotErrorDetails = null;
        $this->snapshotExitCode = null;
        $this->latestSnapshot = null;
        $this->baselineSnapshot = null;

        $settings = BackupSetting::singleton();
        $this->baselineSnapshotId = $this->normalizeScalar($settings->baseline_snapshot_id);
        $this->baselineCreatedAt = $settings->baseline_created_at?->toIso8601String();

        try {
            $result = app(ResticRunner::class)->snapshots();
        } catch (ResticConfigurationException $exception) {
            $this->snapshotRecords = [];
            $this->snapshotError = __('restic-backups::backups.pages.exports.errors.restic_not_configured');
            $this->snapshotErrorDetails = $exception->getMessage();

            return;
        } catch (Throwable $exception) {
            $this->snapshotRecords = [];
            $this->snapshotError = __('restic-backups::backups.pages.exports.errors.unable_to_load');
            $this->snapshotErrorDetails = $exception->getMessage();

            return;
        }

        if ($result->exitCode !== 0 || ! is_array($result->parsedJson)) {
            $this->snapshotRecords = [];
            $this->snapshotExitCode = $result->exitCode;
            $this->snapshotError = __('restic-backups::backups.pages.exports.errors.unable_to_load_from_restic');
            $this->snapshotErrorDetails = $result->stderr;

            return;
        }

        $this->snapshotRecords = $this->normalizeSnapshots($result->parsedJson);
        $this->latestSnapshot = $this->resolveLatestSnapshot($this->snapshotRecords);
        $this->baselineSnapshot = $this->resolveBaselineSnapshot($this->snapshotRecords);
    }

    /**
     * @param  array<int, mixed>  $snapshots
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeSnapshots(array $snapshots): array
    {
        $normalized = [];

        foreach ($snapshots as $index => $snapshot) {
            if (! is_array($snapshot)) {
                continue;
            }

            $id = $this->normalizeScalar($snapshot['id'] ?? null);
            $shortId = $this->normalizeScalar($snapshot['short_id'] ?? null);

            if ($shortId === null && $id !== null) {
                $shortId = Str::substr($id, 0, 8);
            }

            $time = $this->normalizeScalar($snapshot['time'] ?? null);
            $timestamp = $this->parseTimeToTimestamp($time);

            $paths = $this->normalizeArray($snapshot['paths'] ?? []);
            $excludes = $this->normalizeArray($snapshot['excludes'] ?? []);

            $normalized[] = [
                'id' => $id,
                'short_id' => $shortId ?? $id ?? (string) $index,
                'time' => $time,
                'time_unix' => $timestamp,
                'paths' => $paths,
                'excludes' => $excludes,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshots
     */
    protected function resolveLatestSnapshot(array $snapshots): ?array
    {
        $latest = null;
        $latestTimestamp = 0;

        foreach ($snapshots as $snapshot) {
            $timestamp = (int) ($snapshot['time_unix'] ?? 0);

            if ($timestamp <= 0) {
                continue;
            }

            if ($latest === null || $timestamp >= $latestTimestamp) {
                $latest = $snapshot;
                $latestTimestamp = $timestamp;
            }
        }

        if ($latest === null && $snapshots !== []) {
            return $snapshots[0];
        }

        return $latest;
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshots
     */
    protected function resolveBaselineSnapshot(array $snapshots): ?array
    {
        if ($this->baselineSnapshotId === null) {
            return null;
        }

        foreach ($snapshots as $snapshot) {
            $id = $this->normalizeScalar($snapshot['id'] ?? null);
            $shortId = $this->normalizeScalar($snapshot['short_id'] ?? null);

            if ($id === $this->baselineSnapshotId || $shortId === $this->baselineSnapshotId) {
                return $snapshot;
            }

            if ($id !== null && str_starts_with($id, $this->baselineSnapshotId)) {
                return $snapshot;
            }
        }

        return null;
    }

    protected function baselineIsAvailable(): bool
    {
        return $this->baselineSnapshotId !== null && $this->baselineSnapshot !== null;
    }

    protected function latestSnapshotId(): ?string
    {
        return $this->normalizeScalar($this->latestSnapshot['id'] ?? null);
    }

    protected function latestSnapshotTime(): ?string
    {
        return $this->normalizeScalar($this->latestSnapshot['time'] ?? null);
    }

    protected function latestSnapshotWarning(): ?string
    {
        $excludes = $this->normalizeArray($this->latestSnapshot['excludes'] ?? []);

        if ($this->pathIsExcluded('vendor', $excludes) || $this->pathIsExcluded('public/build', $excludes)) {
            return __('restic-backups::backups.pages.exports.sections.latest.warn_excludes');
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function computeFullExportEstimate(): array
    {
        $snapshotId = $this->latestSnapshotId();

        if ($snapshotId === null) {
            return $this->emptyExportEstimate();
        }

        return app(ExportDiskSpaceGuard::class)->estimateSnapshot(
            app(ResticRunner::class),
            $snapshotId,
            storage_path('app/_backup/exports'),
            300,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function computeDeltaExportEstimate(): array
    {
        $baselineSnapshotId = $this->normalizeScalar($this->baselineSnapshot['id'] ?? null)
            ?? $this->baselineSnapshotId;
        $targetSnapshotId = $this->latestSnapshotId();

        if ($baselineSnapshotId === null || $targetSnapshotId === null) {
            return $this->emptyExportEstimate();
        }

        return app(ExportDiskSpaceGuard::class)->estimateDelta(
            app(ResticRunner::class),
            $baselineSnapshotId,
            $targetSnapshotId,
            storage_path('app/_backup/exports'),
            300,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyExportEstimate(): array
    {
        return [
            'ok' => false,
            'free_bytes' => null,
            'restore_size_bytes' => null,
            'required_bytes' => null,
            'missing_bytes' => null,
            'source' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $estimate
     * @return array<int, Section|Text>
     */
    protected function buildDiskSpaceSchema(array $estimate, string $prefix): array
    {
        return [
            Section::make(__($prefix.'.title'))
                ->schema([
                    Text::make(fn (): string => __($prefix.'.available', [
                        'bytes' => $this->formatBytes($estimate['free_bytes'] ?? null),
                    ])),
                    Text::make(fn (): string => __($prefix.'.restore_size', [
                        'bytes' => $this->formatBytes($estimate['restore_size_bytes'] ?? null),
                    ])),
                    Text::make(fn (): string => __($prefix.'.required', [
                        'bytes' => $this->formatBytes($estimate['required_bytes'] ?? null),
                    ])),
                    Text::make(fn (): string => __($prefix.'.missing', [
                        'bytes' => $this->formatBytes($estimate['missing_bytes'] ?? null),
                    ])),
                    Text::make(fn (): string => __($prefix.'.source', [
                        'source' => $this->exportEstimateSourceLabel($estimate['source'] ?? null),
                    ])),
                    Text::make(fn (): string => $this->exportEstimateStatusMessage($estimate, $prefix))
                        ->color(($estimate['ok'] ?? false) === true ? 'success' : 'danger'),
                ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $estimate
     */
    protected function exportEstimateStatusMessage(array $estimate, string $prefix): string
    {
        if (($estimate['required_bytes'] ?? null) === null) {
            return __($prefix.'.estimate_unavailable');
        }

        return ($estimate['ok'] ?? false) === true
            ? __($prefix.'.result_ok')
            : __($prefix.'.result_fail');
    }

    protected function exportEstimateSourceLabel(mixed $source): string
    {
        $source = is_string($source) ? trim($source) : '';

        if ($source === '') {
            return __('restic-backups::backups.pages.exports.placeholders.not_available');
        }

        $key = 'restic-backups::backups.export_space_sources.'.$source;
        $translated = __($key);

        return $translated !== $key
            ? $translated
            : $source;
    }

    /**
     * @param  array<string, mixed>  $estimate
     */
    protected function formatExportEstimateNotificationBody(array $estimate, string $prefix): string
    {
        if (($estimate['required_bytes'] ?? null) === null) {
            return __($prefix.'.export_disk_space_unknown_body');
        }

        return __($prefix.'.export_disk_space_insufficient_body', [
            'available' => $this->formatBytes($estimate['free_bytes'] ?? null),
            'required' => $this->formatBytes($estimate['required_bytes'] ?? null),
            'missing' => $this->formatBytes($estimate['missing_bytes'] ?? 0),
        ]);
    }

    protected function formatBytes(int|float|null $bytes): string
    {
        if ($bytes === null) {
            return __('restic-backups::backups.pages.exports.placeholders.not_available');
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

    protected function isOperationRunning(): bool
    {
        return $this->operationInfo() !== null;
    }

    protected function operationIsStale(): bool
    {
        if (! $this->isOperationRunning()) {
            return false;
        }

        return app(OperationLock::class)->isStale();
    }

    /**
     * @return array<string, mixed> | null
     */
    protected function operationInfo(): ?array
    {
        $info = app(OperationLock::class)->getInfo();

        return is_array($info) ? $info : null;
    }

    protected function operationType(): ?string
    {
        $info = $this->operationInfo();

        return $this->normalizeScalar($info['type'] ?? null);
    }

    protected function operationStartedAt(): ?string
    {
        $info = $this->operationInfo();

        return $this->normalizeScalar($info['started_at'] ?? null);
    }

    protected function operationLastActivityAt(): ?string
    {
        $info = $this->operationInfo();

        return $this->normalizeScalar($info['last_heartbeat_at'] ?? null);
    }

    protected function operationStep(): ?string
    {
        $info = $this->operationInfo();
        $context = is_array($info['context'] ?? null) ? $info['context'] : [];

        return $this->normalizeScalar($context['step'] ?? null);
    }

    protected function resolveOperationTypeLabel(mixed $value, string $fallback): string
    {
        $type = $this->normalizeScalar($value);

        if ($type === null) {
            return $fallback;
        }

        $key = "restic-backups::backups.pages.snapshots.operation_types.{$type}";
        $translated = __($key);

        if ($translated !== $key) {
            return $translated;
        }

        return Str::of($type)
            ->replace(['_', '-'], ' ')
            ->headline()
            ->toString();
    }

    protected function resolveOperationStepLabel(mixed $value, string $fallback): string
    {
        $step = $this->normalizeScalar($value);

        if ($step === null) {
            return $fallback;
        }

        $key = "restic-backups::backups.pages.snapshots.operation_steps.{$step}";
        $translated = __($key);

        if ($translated !== $key) {
            return $translated;
        }

        return Str::of($step)
            ->replace(['_', '-'], ' ')
            ->headline()
            ->toString();
    }

    protected function formatLockTimestamp(mixed $value, string $fallback): string
    {
        $value = $this->normalizeScalar($value);

        if ($value === null) {
            return $fallback;
        }

        $timestamp = BackupsTimezone::normalize($value, $this->displayTimezone());

        if (! $timestamp) {
            return $value;
        }

        return $timestamp
            ->locale(app()->getLocale())
            ->translatedFormat('M d, Y H:i:s');
    }

    protected function formatRelativeTime(mixed $value, string $fallback): string
    {
        $value = $this->normalizeScalar($value);

        if ($value === null) {
            return $fallback;
        }

        $timestamp = BackupsTimezone::normalize($value, $this->displayTimezone());

        if (! $timestamp) {
            return $value;
        }

        return $timestamp
            ->locale(app()->getLocale())
            ->diffForHumans();
    }

    protected function formatActivityLine(string $activity, bool $showSpinner): HtmlString
    {
        $label = __('restic-backups::backups.pages.snapshots.sections.operation_in_progress.last_activity', [
            'activity' => $activity,
        ]);

        if (! $showSpinner) {
            return new HtmlString(e($label));
        }

        $spinner = generate_loading_indicator_html()->toHtml();

        return new HtmlString(
            '<span class="rb-inline">'.$spinner.'<span>'.e($label).'</span></span>',
        );
    }

    protected function renderReadyArchiveLine(string $type): HtmlString
    {
        $kindLabel = $this->readyArchiveKindLabel($type);
        $pair = is_array($this->readyArchivePair) ? $this->readyArchivePair : $this->resolveReadyArchivePair();
        $archive = match ($type) {
            'export_full' => $pair['full'] ?? null,
            'export_delta' => $pair['delta'] ?? null,
            default => null,
        };

        if ($type === 'export_delta' && ! is_array($archive)) {
            $deltaNotice = $this->normalizeScalar($pair['delta_notice'] ?? null);

            if ($deltaNotice === 'no_changes') {
                return new HtmlString(e(__('restic-backups::backups.pages.exports.sections.downloads.line_no_changes', [
                    'kind' => $kindLabel,
                ])));
            }
        }

        if (! is_array($archive)) {
            return new HtmlString(e(__('restic-backups::backups.pages.exports.sections.downloads.line_not_ready', [
                'kind' => $kindLabel,
            ])));
        }

        $downloadUrl = $this->normalizeScalar($archive['download_url'] ?? null);
        $runId = isset($archive['run_id']) && is_numeric($archive['run_id']) ? (int) $archive['run_id'] : null;

        if ($downloadUrl === null || $runId === null) {
            return new HtmlString(e(__('restic-backups::backups.pages.exports.sections.downloads.line_not_ready', [
                'kind' => $kindLabel,
            ])));
        }

        $downloadLabel = __('restic-backups::backups.pages.exports.sections.downloads.download');
        $runLabel = __('restic-backups::backups.pages.exports.sections.downloads.run', ['run_id' => $runId]);

        return new HtmlString(
            e($kindLabel)
            .': '
            .'<a class="rb-link" href="'.e($downloadUrl).'" target="_blank" rel="noopener noreferrer">'
            .e($downloadLabel)
            .'</a>'
            .' <span class="rb-text rb-text--muted rb-text--sm">('.e($runLabel).')</span>',
        );
    }

    /**
     * @return array{
     *     full: array<string, mixed>|null,
     *     delta: array<string, mixed>|null,
     *     delta_notice: string|null
     * }
     */
    protected function resolveReadyArchivePair(): array
    {
        $full = $this->latestReadyArchiveByType('export_full');

        if (! is_array($full)) {
            return [
                'full' => null,
                'delta' => null,
                'delta_notice' => null,
            ];
        }

        $fullSnapshotId = $this->normalizeScalar($full['snapshot_id'] ?? null);
        $deltaState = $fullSnapshotId !== null
            ? $this->latestReadyCompatibleDeltaArchiveState($fullSnapshotId)
            : ['archive' => null, 'notice' => null];

        return [
            'full' => $full,
            'delta' => $deltaState['archive'] ?? null,
            'delta_notice' => $deltaState['notice'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed> | null
     */
    protected function latestReadyArchiveByType(string $type): ?array
    {
        if (! in_array($type, ['export_full', 'export_delta'], true)) {
            return null;
        }

        $runs = BackupRun::query()
            ->where('type', $type)
            ->where('status', 'success')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        foreach ($runs as $run) {
            $archive = $this->buildReadyArchiveFromRun($run);

            if (is_array($archive)) {
                return $archive;
            }
        }

        return null;
    }

    /**
     * @return array{archive: array<string, mixed>|null, notice: string|null}
     */
    protected function latestReadyCompatibleDeltaArchiveState(string $fullSnapshotId): array
    {
        $runs = BackupRun::query()
            ->where('type', 'export_delta')
            ->where('status', 'success')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        foreach ($runs as $run) {
            $archive = $this->buildReadyArchiveFromRun($run);

            if (! is_array($archive)) {
                continue;
            }

            $baselineSnapshotId = $this->normalizeScalar($archive['baseline_snapshot_id'] ?? null);

            if ($this->snapshotIdsMatch($baselineSnapshotId, $fullSnapshotId)) {
                $targetSnapshotId = $this->normalizeScalar($archive['snapshot_id'] ?? null);

                if ($this->snapshotIdsMatch($baselineSnapshotId, $targetSnapshotId)) {
                    return [
                        'archive' => null,
                        'notice' => 'no_changes',
                    ];
                }

                return [
                    'archive' => $archive,
                    'notice' => null,
                ];
            }
        }

        return [
            'archive' => null,
            'notice' => null,
        ];
    }

    /**
     * @return array<string, mixed> | null
     */
    protected function buildReadyArchiveFromRun(BackupRun $run): ?array
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        $export = is_array($meta['export'] ?? null) ? $meta['export'] : [];

        $archivePath = $this->normalizeScalar($export['archive_path'] ?? null);
        $archiveName = $this->normalizeScalar($export['archive_name'] ?? null);

        if ($archivePath === null || $archiveName === null || ! is_file($archivePath)) {
            return null;
        }

        if ($this->normalizeScalar($export['deleted_at'] ?? null) !== null) {
            return null;
        }

        $expiresAt = $this->parseArchiveExpiresAt($export['expires_at'] ?? null);
        if ($expiresAt instanceof CarbonInterface && now()->greaterThan($expiresAt)) {
            return null;
        }

        $downloadUrl = URL::temporarySignedRoute(
            'restic-backups.exports.download',
            $this->resolveArchiveLinkExpiry($expiresAt),
            ['run' => $run->getKey()],
            absolute: false,
        );

        $snapshotId = $this->normalizeScalar($meta['snapshot_id'] ?? null)
            ?? $this->normalizeScalar($export['to_snapshot_id'] ?? null);
        $baselineSnapshotId = $this->normalizeScalar($meta['baseline_snapshot_id'] ?? null)
            ?? $this->normalizeScalar($export['baseline_snapshot_id'] ?? null);

        return [
            'run_id' => $run->getKey(),
            'download_url' => $downloadUrl,
            'snapshot_id' => $snapshotId,
            'baseline_snapshot_id' => $baselineSnapshotId,
        ];
    }

    protected function snapshotIdsMatch(?string $left, ?string $right): bool
    {
        $left = $this->normalizeScalar($left);
        $right = $this->normalizeScalar($right);

        if ($left === null || $right === null) {
            return false;
        }

        if ($left === $right) {
            return true;
        }

        return str_starts_with($left, $right) || str_starts_with($right, $left);
    }

    protected function readyArchiveKindLabel(string $type): string
    {
        return match ($type) {
            'export_full' => __('restic-backups::backups.pages.exports.sections.downloads.kind.full'),
            'export_delta' => __('restic-backups::backups.pages.exports.sections.downloads.kind.delta'),
            default => Str::of($type)->replace('_', ' ')->upper()->toString(),
        };
    }

    protected function resolveArchiveLinkExpiry(?CarbonInterface $expiresAt): CarbonInterface
    {
        $defaultExpiry = now()->addMinutes(60);

        if (! $expiresAt instanceof CarbonInterface) {
            return $defaultExpiry;
        }

        if ($expiresAt->lessThan($defaultExpiry) && $expiresAt->greaterThan(now())) {
            return $expiresAt;
        }

        return $defaultExpiry;
    }

    protected function parseArchiveExpiresAt(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        $value = $this->normalizeScalar($value);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    protected function formatTimestamp(?string $value, string $fallback): string
    {
        $value = $this->normalizeScalar($value);

        if ($value === null) {
            return $fallback;
        }

        $timestamp = BackupsTimezone::normalize($value, $this->displayTimezone());

        if (! $timestamp) {
            return $fallback;
        }

        return $timestamp
            ->locale(app()->getLocale())
            ->translatedFormat('M d, Y H:i:s');
    }

    protected function displayTimezone(): string
    {
        if ($this->displayTimezone !== null) {
            return $this->displayTimezone;
        }

        $this->displayTimezone = BackupsTimezone::resolve();

        return $this->displayTimezone;
    }

    protected function parseTimeToTimestamp(?string $value): int
    {
        if ($value === null) {
            return 0;
        }

        $timestamp = BackupsTimezone::normalize($value, $this->displayTimezone());

        return $timestamp?->getTimestamp() ?? 0;
    }

    /**
     * @param  array<int, string>  $excludes
     */
    protected function pathIsExcluded(string $path, array $excludes): bool
    {
        $path = $this->normalizeExcludePattern($path);

        if ($path === '') {
            return false;
        }

        $prefix = $path.'/';

        foreach ($excludes as $exclude) {
            $pattern = $this->normalizeExcludePattern($exclude);

            if ($pattern === '') {
                continue;
            }

            if ($pattern === $path || str_starts_with($pattern, $prefix)) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeExcludePattern(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = str_replace('\\', '/', $value);

        while (str_starts_with($value, './')) {
            $value = substr($value, 2);
        }

        $value = ltrim($value, '/');

        return rtrim($value, '/');
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

    /**
     * @return array<int, string>
     */
    protected function normalizeArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                continue;
            }

            $stringValue = trim((string) $item);

            if ($stringValue === '') {
                continue;
            }

            $normalized[] = $stringValue;
        }

        return $normalized;
    }
}
