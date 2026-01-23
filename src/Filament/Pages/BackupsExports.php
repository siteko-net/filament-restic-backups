<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions as ActionsComponent;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Str;
use Siteko\FilamentResticBackups\Exceptions\ResticConfigurationException;
use Siteko\FilamentResticBackups\Jobs\ExportDisasterRecoveryDeltaJob;
use Siteko\FilamentResticBackups\Jobs\ExportDisasterRecoveryFullJob;
use Siteko\FilamentResticBackups\Models\BackupSetting;
use Siteko\FilamentResticBackups\Services\ResticRunner;
use Siteko\FilamentResticBackups\Support\BackupsTimezone;
use Siteko\FilamentResticBackups\Support\OperationLock;
use Throwable;

class BackupsExports extends BaseBackupsPage
{
    private const SNAPSHOT_CACHE_SECONDS = 10;

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

    public static function getNavigationSort(): ?int
    {
        return static::baseNavigationSort() + 5;
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
        return $schema
            ->components([
                ActionsComponent::make([
                    Action::make('downloadFull')
                        ->label(__('restic-backups::backups.pages.exports.actions.full.label'))
                        ->icon('heroicon-o-arrow-down-tray')
                        ->disabled(fn(): bool => $this->snapshotError !== null || $this->latestSnapshotId() === null)
                        ->action(function (): void {
                            $snapshotId = $this->latestSnapshotId();

                            if ($snapshotId === null) {
                                Notification::make()
                                    ->title(__('restic-backups::backups.pages.exports.notifications.snapshot_missing'))
                                    ->danger()
                                    ->send();

                                throw new Halt();
                            }

                            $lockInfo = app(OperationLock::class)->getInfo();

                            if (is_array($lockInfo)) {
                                Notification::make()
                                    ->title(__('restic-backups::backups.pages.exports.notifications.operation_in_progress'))
                                    ->body(__('restic-backups::backups.pages.exports.notifications.operation_running'))
                                    ->warning()
                                    ->send();
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
                        ->disabled(fn(): bool => $this->snapshotError !== null || ! $this->baselineIsAvailable())
                        ->action(function (): void {
                            if (! $this->baselineIsAvailable()) {
                                Notification::make()
                                    ->title(__('restic-backups::backups.pages.exports.notifications.baseline_missing'))
                                    ->danger()
                                    ->send();

                                throw new Halt();
                            }

                            $lockInfo = app(OperationLock::class)->getInfo();

                            if (is_array($lockInfo)) {
                                Notification::make()
                                    ->title(__('restic-backups::backups.pages.exports.notifications.operation_in_progress'))
                                    ->body(__('restic-backups::backups.pages.exports.notifications.operation_running'))
                                    ->warning()
                                    ->send();
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
                ]),
                Section::make(__('restic-backups::backups.pages.exports.sections.baseline.title'))
                    ->description(__('restic-backups::backups.pages.exports.sections.baseline.description'))
                    ->columns(1)
                    ->schema([
                        Text::make(fn(): string => __('restic-backups::backups.pages.exports.sections.baseline.snapshot', [
                            'id' => $this->baselineSnapshotId ?? __('restic-backups::backups.pages.exports.placeholders.not_set'),
                        ])),
                        Text::make(fn(): string => __('restic-backups::backups.pages.exports.sections.baseline.created_at', [
                            'time' => $this->formatTimestamp($this->baselineCreatedAt, __('restic-backups::backups.pages.exports.placeholders.not_set')),
                        ])),
                        Text::make(fn(): string => __('restic-backups::backups.pages.exports.sections.baseline.status', [
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
                        Text::make(fn(): string => __('restic-backups::backups.pages.exports.sections.latest.snapshot', [
                            'id' => $this->latestSnapshotId() ?? __('restic-backups::backups.pages.exports.placeholders.not_available'),
                        ])),
                        Text::make(fn(): string => __('restic-backups::backups.pages.exports.sections.latest.time', [
                            'time' => $this->formatTimestamp($this->latestSnapshotTime(), __('restic-backups::backups.pages.exports.placeholders.not_available')),
                        ])),
                        Text::make(fn(): string => $this->latestSnapshotWarning() ?? __('restic-backups::backups.pages.exports.sections.latest.ok')),
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

        $prefix = $path . '/';

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
