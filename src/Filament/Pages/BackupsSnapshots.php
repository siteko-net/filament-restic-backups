<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions as ActionsComponent;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Siteko\FilamentResticBackups\Exceptions\ResticConfigurationException;
use Siteko\FilamentResticBackups\Jobs\ExportSnapshotArchiveJob;
use Siteko\FilamentResticBackups\Jobs\ForgetSnapshotJob;
use Siteko\FilamentResticBackups\Jobs\RunBackupJob;
use Siteko\FilamentResticBackups\Jobs\RunRestoreJob;
use Siteko\FilamentResticBackups\Models\BackupRun;
use Siteko\FilamentResticBackups\Models\BackupSetting;
use Siteko\FilamentResticBackups\Services\ResticRunner;
use Siteko\FilamentResticBackups\Support\OperationLock;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;
use function Filament\Support\generate_loading_indicator_html;

class BackupsSnapshots extends BaseBackupsPage implements HasTable
{
    use InteractsWithTable;

    private const SNAPSHOT_CACHE_SECONDS = 10;
    private const ERROR_SNIPPET_LIMIT = 1500;

    /**
     * @var array<int, array<string, mixed>> | null
     */
    public ?array $snapshotRecords = null;

    public ?string $snapshotError = null;

    public ?string $snapshotErrorDetails = null;

    public ?int $snapshotExitCode = null;

    public ?int $snapshotFetchedAt = null;

    /**
     * @var array<string, mixed> | null
     */
    public ?array $restorePreflightBase = null;

    public bool $restorePreflightOk = true;

    protected static ?string $slug = 'backups/snapshots';

    protected static ?string $navigationLabel = 'Snapshots';

    protected static ?string $title = 'Snapshots';

    public static function getNavigationSort(): ?int
    {
        return static::baseNavigationSort() + 2;
    }

    public static function getNavigationLabel(): string
    {
        return __('restic-backups::backups.pages.snapshots.navigation_label');
    }

    public function getTitle(): string
    {
        return __('restic-backups::backups.pages.snapshots.title');
    }

    public function mount(): void
    {
        $this->loadSnapshots();
    }

    public function refreshSnapshots(): void
    {
        $this->loadSnapshots(force: true, notify: true);
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn(
                array $filters = [],
                int | string $page = 1,
                int | string $recordsPerPage = 25,
                ?string $sortColumn = null,
                ?string $sortDirection = null,
            ): LengthAwarePaginator => $this->getSnapshotRecords(
                $filters,
                $page,
                $recordsPerPage,
                $sortColumn,
                $sortDirection,
            ))
            ->defaultSort('time', 'desc')
            ->columns([
                TextColumn::make('short_id')
                    ->label(__('restic-backups::backups.pages.snapshots.table.columns.id'))
                    ->copyable()
                    ->copyableState(fn(array $record): string => (string) ($record['id'] ?? $record['short_id'] ?? ''))
                    ->tooltip(fn(array $record): ?string => $record['id'] ?? null)
                    ->toggleable(),
                TextColumn::make('time')
                    ->label(__('restic-backups::backups.pages.snapshots.table.columns.time'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('size')
                    ->label(__('restic-backups::backups.pages.snapshots.table.columns.size'))
                    ->state(fn(array $record): ?int => $record['size_bytes'] ?? null)
                    ->formatStateUsing(fn($state): string => $this->formatBytes(is_numeric($state) ? (int) $state : null))
                    ->toggleable(),
                TextColumn::make('tags')
                    ->label(__('restic-backups::backups.pages.snapshots.table.columns.tags'))
                    ->state(fn(array $record): array => $record['tags'] ?? [])
                    ->badge()
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->toggleable(isToggledHiddenByDefault: true),
                ViewColumn::make('archive')
                    ->label(__('restic-backups::backups.pages.snapshots.table.columns.archive'))
                    ->view('restic-backups::tables.columns.archive-status')
                    ->toggleable(),
            ])
            ->deferColumnManager(false)
            ->filters([
                SelectFilter::make('tag')
                    ->label(__('restic-backups::backups.pages.snapshots.table.filters.tag'))
                    ->options(fn(): array => $this->getTagOptions())
                    ->searchable(),
                SelectFilter::make('host')
                    ->label(__('restic-backups::backups.pages.snapshots.table.filters.host'))
                    ->options(fn(): array => $this->getHostOptions())
                    ->searchable(),
                Filter::make('time')
                    ->label(__('restic-backups::backups.pages.snapshots.table.filters.date_range'))
                    ->schema([
                        DatePicker::make('from')->label(__('restic-backups::backups.pages.snapshots.table.filters.from')),
                        DatePicker::make('until')->label(__('restic-backups::backups.pages.snapshots.table.filters.until')),
                    ]),
            ])
            ->pushRecordActions([

                Action::make('restore')
                    ->label(__('restic-backups::backups.pages.snapshots.actions.restore.label'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->modalHeading(__('restic-backups::backups.pages.snapshots.actions.restore.modal_heading'))
                    ->modalSubmitActionLabel(__('restic-backups::backups.pages.snapshots.actions.restore.modal_submit_label'))
                    ->modalSubmitAction(function (Action $action): Action {
                        return $action->disabled(fn(): bool => ! $this->restorePreflightOk);
                    })
                    ->steps(fn(array $record): array => $this->buildRestoreSteps($record))
                    ->action(function (array $data, array $record): void {
                        $scope = $this->normalizeRestoreScope((string) ($data['scope'] ?? 'files'));
                        $mode = $scope === 'db'
                            ? null
                            : $this->normalizeRestoreMode((string) ($data['mode'] ?? 'atomic'));
                        $safetyBackup = (bool) ($data['safety_backup'] ?? true);
                        $expectedPhrase = $this->normalizeConfirmationInput(
                            (string) ($data['confirmation_phrase'] ?? $this->buildConfirmationPhrase($record, $scope)),
                        );
                        $confirmation = $this->normalizeConfirmationInput((string) ($data['confirmation'] ?? ''));
                        $preflightBase = $this->restorePreflightBase ?? $this->computePreflightBase($record);

                        if (mb_strtolower($confirmation) !== mb_strtolower($expectedPhrase)) {
                            Notification::make()
                                ->title(__('restic-backups::backups.pages.snapshots.notifications.confirmation_phrase_mismatch'))
                                ->body(__('restic-backups::backups.pages.snapshots.notifications.confirmation_phrase_mismatch_body'))
                                ->danger()
                                ->send();

                            throw new Halt();
                        }

                        if (! $this->preflightOkFromData($preflightBase, $data)) {
                            Notification::make()
                                ->title(__('restic-backups::backups.pages.snapshots.notifications.preflight_failed'))
                                ->body(__('restic-backups::backups.pages.snapshots.notifications.preflight_failed_body'))
                                ->danger()
                                ->send();

                            throw new Halt();
                        }

                        $snapshotId = (string) ($record['id'] ?? $record['short_id'] ?? '');

                        if ($snapshotId === '') {
                            Notification::make()
                                ->title(__('restic-backups::backups.pages.snapshots.notifications.snapshot_id_missing'))
                                ->body(__('restic-backups::backups.pages.snapshots.notifications.snapshot_id_missing_restore'))
                                ->danger()
                                ->send();

                            throw new Halt();
                        }

                        $lockInfo = app(OperationLock::class)->getInfo();

                        if (is_array($lockInfo)) {
                            $message = __('restic-backups::backups.pages.snapshots.notifications.operation_running');

                            if (! empty($lockInfo['type'])) {
                                $message .= ' ' . __('restic-backups::backups.pages.snapshots.notifications.operation_running_type', [
                                    'type' => $lockInfo['type'],
                                ]);
                            }

                            if (! empty($lockInfo['run_id'])) {
                                $message .= ' ' . __('restic-backups::backups.pages.snapshots.notifications.operation_running_run_id', [
                                    'run_id' => $lockInfo['run_id'],
                                ]);
                            }

                            Notification::make()
                                ->title(__('restic-backups::backups.pages.snapshots.notifications.operation_in_progress'))
                                ->body($message . ' ' . __('restic-backups::backups.pages.snapshots.notifications.restore_waits'))
                                ->warning()
                                ->send();
                        }

                        RunRestoreJob::dispatch(
                            $snapshotId,
                            $scope,
                            $mode,
                            $safetyBackup,
                            'manual',
                        );

                        Notification::make()
                            ->title(__('restic-backups::backups.pages.snapshots.notifications.restore_queued'))
                            ->body(__('restic-backups::backups.pages.snapshots.notifications.restore_queued_body'))
                            ->success()
                            ->send();
                    }),
                Action::make('export_archive')
                    ->label(__('restic-backups::backups.pages.snapshots.actions.export.label'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalHeading(__('restic-backups::backups.pages.snapshots.actions.export.modal_heading'))
                    ->modalDescription(__('restic-backups::backups.pages.snapshots.actions.export.modal_description'))
                    ->visible(fn(array $record): bool => ! $this->isArchiveReady($record))
                    ->disabled(fn(): bool => $this->snapshotError !== null)
                    ->form([
                        Toggle::make('include_env')
                            ->label(__('restic-backups::backups.pages.snapshots.actions.export.include_env_label'))
                            ->helperText(__('restic-backups::backups.pages.snapshots.actions.export.include_env_help'))
                            ->default(false),
                        TextInput::make('keep_hours')
                            ->label(__('restic-backups::backups.pages.snapshots.actions.export.keep_hours_label'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(168)
                            ->default(24)
                            ->required(),
                    ])
                    ->action(function (array $data, array $record): void {
                        $snapshotId = (string) ($record['id'] ?? $record['short_id'] ?? '');

                        if ($snapshotId === '') {
                            Notification::make()
                                ->title(__('restic-backups::backups.pages.snapshots.notifications.snapshot_id_missing'))
                                ->body(__('restic-backups::backups.pages.snapshots.notifications.snapshot_id_missing_export'))
                                ->danger()
                                ->send();

                            throw new Halt();
                        }

                        $includeEnv = (bool) ($data['include_env'] ?? false);
                        $keepHours = (int) ($data['keep_hours'] ?? 24);

                        $lockInfo = app(OperationLock::class)->getInfo();
                        if (is_array($lockInfo)) {
                            Notification::make()
                                ->title(__('restic-backups::backups.pages.snapshots.notifications.operation_in_progress'))
                                ->body(__('restic-backups::backups.pages.snapshots.notifications.operation_running') . ' ' . __('restic-backups::backups.pages.snapshots.notifications.export_waits'))
                                ->warning()
                                ->send();
                        }

                        ExportSnapshotArchiveJob::dispatch(
                            $snapshotId,
                            $includeEnv,
                            $keepHours,
                            auth()->id(),
                            'filament',
                        );

                        Notification::make()
                            ->title(__('restic-backups::backups.pages.snapshots.notifications.export_queued'))
                            ->body(__('restic-backups::backups.pages.snapshots.notifications.export_queued_body'))
                            ->success()
                            ->send();
                    }),
                Action::make('delete')
                    ->iconButton()
                    // ->label(__('restic-backups::backups.pages.snapshots.actions.delete.label'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->modalHeading(__('restic-backups::backups.pages.snapshots.actions.delete.modal_heading'))
                    ->modalDescription(__('restic-backups::backups.pages.snapshots.actions.delete.modal_description'))
                    ->disabled(fn(): bool => $this->snapshotError !== null)
                    ->schema(function (array $record): array {
                        $snapshotId = (string) ($record['id'] ?? $record['short_id'] ?? '');
                        $shortId = (string) ($record['short_id'] ?? substr($snapshotId, 0, 8));

                        return [
                            TextInput::make('confirmation')
                                ->label(__('restic-backups::backups.pages.snapshots.actions.delete.confirm_label'))
                                ->helperText(__('restic-backups::backups.pages.snapshots.actions.delete.confirm_help', [
                                    'short_id' => $shortId,
                                ]))
                                ->required(),
                        ];
                    })
                    ->action(function (array $data, array $record): void {
                        $snapshotId = (string) ($record['id'] ?? $record['short_id'] ?? '');
                        $shortId = (string) ($record['short_id'] ?? substr($snapshotId, 0, 8));

                        if ($snapshotId === '' || $shortId === '') {
                            Notification::make()
                                ->title(__('restic-backups::backups.pages.snapshots.notifications.snapshot_id_missing'))
                                ->body(__('restic-backups::backups.pages.snapshots.notifications.snapshot_id_missing_delete'))
                                ->danger()
                                ->send();

                            throw new Halt();
                        }

                        $confirmation = trim((string) ($data['confirmation'] ?? ''));

                        if ($confirmation !== $shortId) {
                            Notification::make()
                                ->title(__('restic-backups::backups.pages.snapshots.notifications.confirmation_mismatch'))
                                ->body(__('restic-backups::backups.pages.snapshots.notifications.confirmation_mismatch_body'))
                                ->danger()
                                ->send();

                            throw new Halt();
                        }

                        $lockInfo = app(OperationLock::class)->getInfo();

                        if (is_array($lockInfo)) {
                            $message = __('restic-backups::backups.pages.snapshots.notifications.operation_running');

                            if (! empty($lockInfo['type'])) {
                                $message .= ' ' . __('restic-backups::backups.pages.snapshots.notifications.operation_running_type', [
                                    'type' => $lockInfo['type'],
                                ]);
                            }

                            if (! empty($lockInfo['run_id'])) {
                                $message .= ' ' . __('restic-backups::backups.pages.snapshots.notifications.operation_running_run_id', [
                                    'run_id' => $lockInfo['run_id'],
                                ]);
                            }

                            Notification::make()
                                ->title(__('restic-backups::backups.pages.snapshots.notifications.operation_in_progress'))
                                ->body($message . ' ' . __('restic-backups::backups.pages.snapshots.notifications.delete_waits'))
                                ->warning()
                                ->send();
                        }

                        ForgetSnapshotJob::dispatch($snapshotId, auth()->id(), 'filament');

                        Notification::make()
                            ->title(__('restic-backups::backups.pages.snapshots.notifications.snapshot_delete_queued'))
                            ->body(__('restic-backups::backups.pages.snapshots.notifications.snapshot_delete_queued_body'))
                            ->success()
                            ->send();
                    }),
                Action::make('details')
                    // ->label(__('restic-backups::backups.pages.snapshots.actions.details.label'))
                    ->iconButton()
                    ->icon('heroicon-o-document-text')
                    ->modalHeading(__('restic-backups::backups.pages.snapshots.actions.details.modal_heading'))
                    ->modalSubmitAction(false)
                    ->modalContent(fn(array $record) => view('restic-backups::snapshots.details', [
                        'record' => $record,
                    ])),
            ])
            ->paginationPageOptions([25, 50, 100])
            ->poll(self::SNAPSHOT_CACHE_SECONDS . 's');
    }

    public function content(Schema $schema): Schema
    {
        $components = [];
        $lockInfo = app(OperationLock::class)->getInfo();
        $lockStale = is_array($lockInfo) ? app(OperationLock::class)->isStale() : false;
        $notAvailable = __('restic-backups::backups.pages.snapshots.placeholders.not_available');

        if (is_array($lockInfo)) {
            $context = is_array($lockInfo['context'] ?? null) ? $lockInfo['context'] : [];
            $operationType = $this->resolveOperationTypeLabel($lockInfo['type'] ?? null, $notAvailable);
            $operationStep = $this->resolveOperationStepLabel($context['step'] ?? null, $notAvailable);
            $startedAt = $this->formatLockTimestamp($lockInfo['started_at'] ?? null, $notAvailable);
            $lastActivity = $this->formatRelativeTime($lockInfo['last_heartbeat_at'] ?? null, $notAvailable);

            $components[] = Section::make(__('restic-backups::backups.pages.snapshots.sections.operation_in_progress.title', [
                'type' => $operationType,
            ]))
                ->description(__('restic-backups::backups.pages.snapshots.sections.operation_in_progress.description'))
                ->schema([
                    Text::make(fn(): string => __('restic-backups::backups.pages.snapshots.sections.operation_in_progress.started_at', [
                        'started_at' => $startedAt,
                    ])),
                    Text::make(fn(): string => __('restic-backups::backups.pages.snapshots.sections.operation_in_progress.step', [
                        'step' => $operationStep,
                    ])),
                    Text::make(fn(): HtmlString => $this->formatActivityLine($lastActivity, ! $lockStale)),
                    Text::make(__('restic-backups::backups.pages.snapshots.sections.operation_in_progress.stale_warning'))
                        ->color('danger')
                        ->visible(fn(): bool => $lockStale),
                ]);
        }

        if ($this->snapshotError !== null) {
            $components[] = Section::make(__('restic-backups::backups.pages.snapshots.sections.repository_issue.title'))
                ->description(__('restic-backups::backups.pages.snapshots.sections.repository_issue.description'))
                ->schema([
                    Text::make(fn(): string => $this->snapshotError ?? __('restic-backups::backups.pages.snapshots.sections.repository_issue.unknown_error'))
                        ->color('danger'),
                    Text::make(fn(): string => $this->snapshotErrorDetails ?? '')
                        ->color('gray'),
                    ActionsComponent::make([
                        Action::make('openSettings')
                            ->label(__('restic-backups::backups.pages.snapshots.sections.repository_issue.open_settings'))
                            ->icon('heroicon-o-cog-6-tooth')
                            ->url(BackupsSettings::getUrl()),
                    ]),
                ]);
        }

        $components[] = EmbeddedTable::make();

        return $schema
            ->components($components);
    }

    protected function isArchiveReady(array $record): bool
    {
        $archive = is_array($record['archive'] ?? null) ? $record['archive'] : [];

        return ($archive['status'] ?? null) === 'ready';
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create_snapshot')
                ->label(__('restic-backups::backups.pages.snapshots.header_actions.create_snapshot'))
                ->icon('heroicon-o-play')
                ->requiresConfirmation()
                ->modalHeading(__('restic-backups::backups.pages.snapshots.header_actions.create_snapshot_modal_heading'))
                ->modalDescription(__('restic-backups::backups.pages.snapshots.header_actions.create_snapshot_modal_description'))
                ->action(function (): void {
                    $lockInfo = app(OperationLock::class)->getInfo();

                    if (is_array($lockInfo)) {
                        $message = __('restic-backups::backups.pages.snapshots.notifications.operation_running');

                        if (! empty($lockInfo['type'])) {
                            $message .= ' ' . __('restic-backups::backups.pages.snapshots.notifications.operation_running_type', [
                                'type' => $lockInfo['type'],
                            ]);
                        }

                        if (! empty($lockInfo['run_id'])) {
                            $message .= ' ' . __('restic-backups::backups.pages.snapshots.notifications.operation_running_run_id', [
                                'run_id' => $lockInfo['run_id'],
                            ]);
                        }

                        Notification::make()
                            ->title(__('restic-backups::backups.pages.snapshots.notifications.operation_in_progress'))
                            ->body($message . ' ' . __('restic-backups::backups.pages.snapshots.notifications.backup_waits'))
                            ->warning()
                            ->send();
                    }

                    RunBackupJob::dispatch([], 'manual', null, true, auth()->id());

                    Notification::make()
                        ->success()
                        ->title(__('restic-backups::backups.pages.snapshots.notifications.snapshot_create_queued'))
                        ->body(__('restic-backups::backups.pages.snapshots.notifications.snapshot_create_queued_body'))
                        ->send();
                }),
            Action::make('refresh')
                ->label(__('restic-backups::backups.pages.snapshots.header_actions.refresh'))
                ->icon('heroicon-o-arrow-path')
                ->action('refreshSnapshots'),
            Action::make('settings')
                ->label(__('restic-backups::backups.pages.snapshots.header_actions.settings'))
                ->icon('heroicon-o-cog-6-tooth')
                ->url(BackupsSettings::getUrl()),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function getSnapshotRecords(
        array $filters,
        int | string $page,
        int | string $recordsPerPage,
        ?string $sortColumn,
        ?string $sortDirection,
    ): LengthAwarePaginator {
        $this->loadSnapshots();

        $records = collect($this->snapshotRecords ?? []);

        $tag = $filters['tag']['value'] ?? null;
        if (is_string($tag) && $tag !== '') {
            $records = $records->filter(fn(array $record): bool => in_array($tag, $record['tags'] ?? [], true));
        }

        $host = $filters['host']['value'] ?? null;
        if (is_string($host) && $host !== '') {
            $host = strtolower($host);
            $records = $records->filter(function (array $record) use ($host): bool {
                $recordHost = strtolower((string) ($record['hostname'] ?? ''));

                return $recordHost === $host;
            });
        }

        $from = $filters['time']['from'] ?? null;
        $until = $filters['time']['until'] ?? null;

        if ($from) {
            $fromTimestamp = $this->parseDateToTimestamp($from, 'start');
            if ($fromTimestamp !== null) {
                $records = $records->filter(fn(array $record): bool => ($record['time_unix'] ?? 0) >= $fromTimestamp);
            }
        }

        if ($until) {
            $untilTimestamp = $this->parseDateToTimestamp($until, 'end');
            if ($untilTimestamp !== null) {
                $records = $records->filter(fn(array $record): bool => ($record['time_unix'] ?? 0) <= $untilTimestamp);
            }
        }

        $sortColumn = $sortColumn ?: 'time';
        $descending = $sortDirection !== 'asc';

        $records = $records->sortBy(
            fn(array $record) => $this->resolveSortValue($record, $sortColumn),
            SORT_REGULAR,
            $descending,
        );

        $page = is_numeric($page) ? (int) $page : 1;
        $recordsPerPage = is_numeric($recordsPerPage) ? (int) $recordsPerPage : 25;
        $recordsPerPage = $recordsPerPage > 0 ? $recordsPerPage : 25;

        $total = $records->count();
        $items = $records
            ->slice(($page - 1) * $recordsPerPage, $recordsPerPage)
            ->values()
            ->all();
        $items = $this->attachArchiveStatus($items);
        $items = $this->attachSnapshotSizes($items);

        return new LengthAwarePaginator($items, $total, $recordsPerPage, $page);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function attachArchiveStatus(array $items): array
    {
        if ($items === []) {
            return $items;
        }

        $snapshotIds = collect($items)
            ->map(fn(array $item): ?string => $this->normalizeScalar($item['id'] ?? null))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($snapshotIds === []) {
            foreach ($items as $index => $item) {
                $items[$index]['archive'] = $this->buildArchiveState(null);
            }

            return $items;
        }

        $runs = BackupRun::query()
            ->where('type', 'export_snapshot')
            ->whereIn('meta->snapshot_id', $snapshotIds)
            ->orderByDesc('id')
            ->get();

        $latestBySnapshot = [];

        foreach ($runs as $run) {
            $meta = is_array($run->meta) ? $run->meta : [];
            $snapshotId = $this->normalizeScalar($meta['snapshot_id'] ?? null);

            if ($snapshotId === null || isset($latestBySnapshot[$snapshotId])) {
                continue;
            }

            $latestBySnapshot[$snapshotId] = $run;
        }

        foreach ($items as $index => $item) {
            $snapshotId = $this->normalizeScalar($item['id'] ?? null);
            $run = $snapshotId !== null ? ($latestBySnapshot[$snapshotId] ?? null) : null;
            $items[$index]['archive'] = $this->buildArchiveState($run);
        }

        return $items;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function attachSnapshotSizes(array $items): array
    {
        if ($items === []) {
            return $items;
        }

        $snapshotIds = collect($items)
            ->map(fn(array $item): ?string => $this->normalizeScalar($item['id'] ?? null))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($snapshotIds === []) {
            foreach ($items as $index => $item) {
                $items[$index]['size_bytes'] = null;
            }

            return $items;
        }

        $runs = BackupRun::query()
            ->where('type', 'backup')
            ->where('status', 'success')
            ->whereIn('meta->snapshot_id', $snapshotIds)
            ->orderByDesc('id')
            ->get();

        $latestBySnapshot = [];

        foreach ($runs as $run) {
            $meta = is_array($run->meta) ? $run->meta : [];
            $snapshotId = $this->normalizeScalar($meta['snapshot_id'] ?? null);

            if ($snapshotId === null || isset($latestBySnapshot[$snapshotId])) {
                continue;
            }

            $latestBySnapshot[$snapshotId] = $run;
        }

        foreach ($items as $index => $item) {
            $snapshotId = $this->normalizeScalar($item['id'] ?? null);
            $summary = null;

            if ($snapshotId !== null) {
                $summary = $this->extractSummaryFromBackupRun($latestBySnapshot[$snapshotId] ?? null);
            }

            if ($summary === null) {
                $summary = $this->extractSummaryFromSnapshotRecord($item);
            }

            $items[$index]['size_bytes'] = $this->extractSnapshotSizeBytes($summary);
        }

        return $items;
    }

    /**
     * @return array<string, mixed> | null
     */
    protected function extractSummaryFromBackupRun(?BackupRun $run): ?array
    {
        if (! $run instanceof BackupRun) {
            return null;
        }

        $meta = is_array($run->meta) ? $run->meta : [];
        $backup = is_array($meta['backup'] ?? null) ? $meta['backup'] : [];
        $summary = $backup['summary'] ?? null;

        return is_array($summary) && $summary !== [] ? $summary : null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed> | null
     */
    protected function extractSummaryFromSnapshotRecord(array $record): ?array
    {
        $raw = is_array($record['raw'] ?? null) ? $record['raw'] : [];
        $summary = $raw['summary'] ?? null;

        return is_array($summary) && $summary !== [] ? $summary : null;
    }

    /**
     * @param  array<string, mixed> | null  $summary
     */
    protected function extractSnapshotSizeBytes(?array $summary): ?int
    {
        if (! is_array($summary)) {
            return null;
        }

        $keys = ['total_bytes_processed', 'total_size', 'total_size_bytes', 'total_size_in_bytes'];

        foreach ($keys as $key) {
            if (isset($summary[$key]) && is_numeric($summary[$key])) {
                return (int) $summary[$key];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildArchiveState(?BackupRun $run): array
    {
        if (! $run instanceof BackupRun) {
            return ['status' => 'none'];
        }

        $status = (string) ($run->status ?? '');
        $meta = is_array($run->meta) ? $run->meta : [];
        $export = is_array($meta['export'] ?? null) ? $meta['export'] : [];

        $archivePath = $this->normalizeScalar($export['archive_path'] ?? null);
        $archiveName = $this->normalizeScalar($export['archive_name'] ?? null);
        $archiveSize = $this->normalizeScalar($export['archive_size'] ?? null);
        $archiveSize = is_numeric($archiveSize) ? (int) $archiveSize : null;
        $expiresAt = $this->parseArchiveExpiresAt($export['expires_at'] ?? null);
        $deletedAt = $this->normalizeScalar($export['deleted_at'] ?? null);

        if ($deletedAt !== null) {
            return [
                'status' => 'deleted',
                'run_id' => $run->getKey(),
                'size_bytes' => null,
            ];
        }

        if ($status === 'success') {
            if ($archivePath !== null && $archiveName !== null) {
                if ($expiresAt instanceof Carbon && now()->greaterThan($expiresAt)) {
                    return [
                        'size_bytes' => $archiveSize,
                        'status' => 'expired',
                        'expires_at' => $expiresAt->toIso8601String(),
                        'delete_url' => URL::temporarySignedRoute(
                            'restic-backups.exports.delete',
                            $this->resolveArchiveLinkExpiry($expiresAt),
                            ['run' => $run->getKey()],
                        ),
                        'run_id' => $run->getKey(),
                    ];
                }

                $downloadUrl = URL::temporarySignedRoute(
                    'restic-backups.exports.download',
                    $this->resolveArchiveLinkExpiry($expiresAt),
                    ['run' => $run->getKey()],
                );
                $deleteUrl = URL::temporarySignedRoute(
                    'restic-backups.exports.delete',
                    $this->resolveArchiveLinkExpiry($expiresAt),
                    ['run' => $run->getKey()],
                );

                return [
                    'size_bytes' => $archiveSize,
                    'status' => 'ready',
                    'download_url' => $downloadUrl,
                    'delete_url' => $deleteUrl,
                    'expires_at' => $expiresAt?->toIso8601String(),
                    'run_id' => $run->getKey(),
                ];
            }

            return [
                'size_bytes' => $archiveSize,
                'status' => 'failed',
                'run_id' => $run->getKey(),
            ];
        }

        if ($status === 'failed') {
            return [
                'size_bytes' => $archiveSize,
                'status' => 'failed',
                'run_id' => $run->getKey(),
            ];
        }

        if ($status === 'running') {
            return [
                'size_bytes' => $archiveSize,
                'status' => 'queue',
                'run_id' => $run->getKey(),
            ];
        }

        return [
            'size_bytes' => $archiveSize,
            'status' => 'queue',
            'run_id' => $run->getKey(),
        ];
    }

    protected function resolveArchiveLinkExpiry(?Carbon $expiresAt): Carbon
    {
        $defaultExpiry = now()->addMinutes(60);

        if (! $expiresAt instanceof Carbon) {
            return $defaultExpiry;
        }

        if ($expiresAt->lessThan($defaultExpiry) && $expiresAt->greaterThan(now())) {
            return $expiresAt;
        }

        return $defaultExpiry;
    }

    protected function parseArchiveExpiresAt(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
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

    protected function loadSnapshots(bool $force = false, bool $notify = false): void
    {
        if (! $force && $this->snapshotRecords !== null && $this->snapshotFetchedAt !== null) {
            if ((time() - $this->snapshotFetchedAt) < self::SNAPSHOT_CACHE_SECONDS) {
                return;
            }
        }

        $this->snapshotFetchedAt = time();
        $this->snapshotExitCode = null;
        $this->snapshotError = null;
        $this->snapshotErrorDetails = null;

        try {
            $result = app(ResticRunner::class)->snapshots();
        } catch (ResticConfigurationException $exception) {
            $this->snapshotRecords = [];
            $this->setSnapshotError(
                __('restic-backups::backups.pages.snapshots.errors.restic_not_configured'),
                $exception->getMessage(),
                null,
                $notify,
            );

            return;
        } catch (Throwable $exception) {
            $this->snapshotRecords = [];
            $this->setSnapshotError(
                __('restic-backups::backups.pages.snapshots.errors.unable_to_load'),
                $exception->getMessage(),
                null,
                $notify,
            );

            return;
        }

        if ($result->exitCode !== 0 || ! is_array($result->parsedJson)) {
            $this->snapshotRecords = [];
            $details = $this->formatSnapshotErrorDetails($result->stderr, $result->exitCode);
            $this->setSnapshotError(
                __('restic-backups::backups.pages.snapshots.errors.unable_to_load_from_restic'),
                $details,
                $result->exitCode,
                $notify,
            );

            return;
        }

        $this->snapshotRecords = $this->normalizeSnapshots($result->parsedJson);

        if ($notify) {
            Notification::make()
                ->title(__('restic-backups::backups.pages.snapshots.notifications.snapshots_refreshed'))
                ->success()
                ->send();
        }
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

            $hostname = $this->normalizeScalar($snapshot['hostname'] ?? null);
            $tags = $this->normalizeArray($snapshot['tags'] ?? []);
            $paths = $this->normalizeArray($snapshot['paths'] ?? []);

            $normalized[] = [
                '__key' => $id ?? $shortId ?? (string) $index,
                'id' => $id,
                'short_id' => $shortId ?? $id ?? (string) $index,
                'time' => $time,
                'time_unix' => $timestamp,
                'hostname' => $hostname,
                'tags' => $tags,
                'paths' => $paths,
                'raw' => $snapshot,
            ];
        }

        return $normalized;
    }

    protected function setSnapshotError(string $message, ?string $details, ?int $exitCode, bool $notify): void
    {
        $this->snapshotError = $message;
        $this->snapshotErrorDetails = $this->sanitizeErrorDetails($details);
        $this->snapshotExitCode = $exitCode;

        if (! $notify) {
            return;
        }

        $body = $this->snapshotErrorDetails;

        if ($exitCode !== null) {
            $prefix = __('restic-backups::backups.pages.snapshots.errors.exit_code', [
                'code' => $exitCode,
            ]);
            $body = $body !== '' ? $prefix . ' ' . $body : $prefix;
        }

        Notification::make()
            ->title($message)
            ->danger()
            ->body($body)
            ->send();
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

        try {
            return Carbon::parse($value)
                ->locale(app()->getLocale())
                ->translatedFormat('M d, Y H:i:s');
        } catch (Throwable) {
            return $value;
        }
    }

    protected function formatRelativeTime(mixed $value, string $fallback): string
    {
        $value = $this->normalizeScalar($value);

        if ($value === null) {
            return $fallback;
        }

        try {
            return Carbon::parse($value)
                ->locale(app()->getLocale())
                ->diffForHumans();
        } catch (Throwable) {
            return $value;
        }
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
            '<span class="rb-inline">' . $spinner . '<span>' . e($label) . '</span></span>',
        );
    }

    protected function formatSnapshotErrorDetails(string $stderr, int $exitCode): string
    {
        $stderr = $this->sanitizeErrorDetails($stderr);

        if ($stderr !== '') {
            return $stderr;
        }

        return __('restic-backups::backups.pages.snapshots.errors.restic_exit_code', [
            'code' => $exitCode,
        ]);
    }

    protected function sanitizeErrorDetails(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('#(://)([^/]*):([^@]*)@#', '$1***:***@', $value) ?? $value;

        if (strlen($value) <= self::ERROR_SNIPPET_LIMIT) {
            return $value;
        }

        return substr($value, 0, self::ERROR_SNIPPET_LIMIT) . PHP_EOL . __('restic-backups::backups.pages.snapshots.errors.truncated');
    }

    protected function formatPathsSummary(array $paths): string
    {
        $paths = array_values(array_filter($paths, fn(string $path): bool => trim($path) !== ''));

        if ($paths === []) {
            return '—';
        }

        $first = $paths[0];
        $remaining = count($paths) - 1;

        if ($remaining <= 0) {
            return $first;
        }

        return $first . ' +' . $remaining;
    }

    protected function formatPathsTooltip(array $paths): ?string
    {
        $paths = array_values(array_filter($paths, fn(string $path): bool => trim($path) !== ''));

        if ($paths === []) {
            return null;
        }

        return implode(PHP_EOL, $paths);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<int, Step>
     */
    protected function buildRestoreSteps(array $record): array
    {
        $unknown = __('restic-backups::backups.pages.snapshots.placeholders.unknown');
        $shortId = (string) ($record['short_id'] ?? $record['id'] ?? $unknown);
        $time = $this->formatSnapshotTime($record['time'] ?? null);
        $host = (string) ($record['hostname'] ?? $unknown);
        $tags = $this->formatTags($record['tags'] ?? []);
        $paths = $this->formatPathsSummary($record['paths'] ?? []);
        $preflightBase = $this->computePreflightBase($record);
        $this->restorePreflightBase = $preflightBase;
        $this->restorePreflightOk = $this->preflightOkForValues($preflightBase, 'files', 'atomic');

        return [
            Step::make(__('restic-backups::backups.pages.snapshots.wizard.steps.snapshot'))
                ->schema([
                    Text::make(__('restic-backups::backups.pages.snapshots.wizard.snapshot.id', ['id' => $shortId])),
                    Text::make(__('restic-backups::backups.pages.snapshots.wizard.snapshot.time', ['time' => $time])),
                    Text::make(__('restic-backups::backups.pages.snapshots.wizard.snapshot.host', ['host' => $host])),
                    Text::make(__('restic-backups::backups.pages.snapshots.wizard.snapshot.tags', ['tags' => $tags])),
                    Text::make(__('restic-backups::backups.pages.snapshots.wizard.snapshot.paths', ['paths' => $paths])),
                    Text::make(__('restic-backups::backups.pages.snapshots.wizard.snapshot.warning'))
                        ->color('danger'),
                ]),
            Step::make(__('restic-backups::backups.pages.snapshots.wizard.steps.scope'))
                ->schema([
                    Radio::make('scope')
                        ->label(__('restic-backups::backups.pages.snapshots.wizard.scope.label'))
                        ->helperText(__('restic-backups::backups.pages.snapshots.wizard.scope.helper'))
                        ->options([
                            'files' => __('restic-backups::backups.pages.snapshots.wizard.scope.options.files'),
                            'db' => __('restic-backups::backups.pages.snapshots.wizard.scope.options.db'),
                            'both' => __('restic-backups::backups.pages.snapshots.wizard.scope.options.both'),
                        ])
                        ->default('files')
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Get $get): void {
                            $this->restorePreflightOk = $this->preflightOkForState($get, $this->restorePreflightBase);
                        }),
                    Radio::make('mode')
                        ->label(__('restic-backups::backups.pages.snapshots.wizard.scope.mode_label'))
                        ->options([
                            'rsync' => __('restic-backups::backups.pages.snapshots.wizard.scope.mode_options.rsync'),
                            'atomic' => __('restic-backups::backups.pages.snapshots.wizard.scope.mode_options.atomic'),
                        ])
                        ->default('atomic')
                        ->helperText(__('restic-backups::backups.pages.snapshots.wizard.scope.mode_helper'))
                        ->required(function (Get $get): bool {
                            $scope = $this->normalizeRestoreScope((string) ($get('scope') ?? 'files'));

                            return $scope !== 'db';
                        })
                        ->disabled(function (Get $get): bool {
                            $scope = $this->normalizeRestoreScope((string) ($get('scope') ?? 'files'));

                            return $scope === 'db';
                        })
                        ->dehydrated(function (Get $get): bool {
                            $scope = $this->normalizeRestoreScope((string) ($get('scope') ?? 'files'));

                            return $scope !== 'db';
                        })
                        ->afterStateUpdated(function (Get $get): void {
                            $this->restorePreflightOk = $this->preflightOkForState($get, $this->restorePreflightBase);
                        }),
                    Toggle::make('safety_backup')
                        ->label(__('restic-backups::backups.pages.snapshots.wizard.scope.safety_backup'))
                        ->default(true),
                ]),
            Step::make(__('restic-backups::backups.pages.snapshots.wizard.steps.preflight'))
                ->schema([
                    Text::make(function (Get $get) use ($preflightBase): string {
                        $free = $preflightBase['free_bytes'] ?? null;

                        return __('restic-backups::backups.pages.snapshots.wizard.preflight.free', [
                            'bytes' => $this->formatBytes($free),
                        ]);
                    }),
                    Text::make(function (Get $get) use ($preflightBase): string {
                        $expected = $preflightBase['expected_bytes'] ?? null;
                        $source = $preflightBase['source'] ?? null;
                        $bytes = $this->formatBytes($expected);

                        if (is_string($source) && $source !== '') {
                            return __('restic-backups::backups.pages.snapshots.wizard.preflight.estimated', [
                                'bytes' => $bytes,
                                'source' => $source,
                            ]);
                        }

                        return __('restic-backups::backups.pages.snapshots.wizard.preflight.estimated_no_source', [
                            'bytes' => $bytes,
                        ]);
                    }),
                    Text::make(function (Get $get) use ($preflightBase): string {
                        $required = $this->requiredBytesForScope($preflightBase, (string) ($get('scope') ?? 'files'));

                        return __('restic-backups::backups.pages.snapshots.wizard.preflight.required', [
                            'bytes' => $this->formatBytes($required),
                        ]);
                    }),
                    Text::make(function (Get $get) use ($preflightBase): string {
                        $ok = $this->preflightOkForState($get, $preflightBase)
                            ? __('restic-backups::backups.pages.snapshots.wizard.preflight.result_ok')
                            : __('restic-backups::backups.pages.snapshots.wizard.preflight.result_fail');
                        $sameFs = $preflightBase['same_filesystem'] ?? null;
                        $scope = $this->normalizeRestoreScope((string) ($get('scope') ?? 'files'));
                        $mode = $scope === 'db'
                            ? 'rsync'
                            : $this->normalizeRestoreMode((string) ($get('mode') ?? 'atomic'));
                        $suffix = '';

                        if ($mode === 'atomic' && $sameFs !== null) {
                            $suffix = $sameFs
                                ? __('restic-backups::backups.pages.snapshots.wizard.preflight.same_fs_ok')
                                : __('restic-backups::backups.pages.snapshots.wizard.preflight.same_fs_fail');
                        }

                        return __('restic-backups::backups.pages.snapshots.wizard.preflight.result', [
                            'result' => $ok . $suffix,
                        ]);
                    })->color(function (Get $get) use ($preflightBase): string {
                        return $this->preflightOkForState($get, $preflightBase) ? 'success' : 'danger';
                    }),
                ]),
            Step::make(__('restic-backups::backups.pages.snapshots.wizard.steps.confirmation'))
                ->schema([
                    TextInput::make('confirmation_phrase')
                        ->label(__('restic-backups::backups.pages.snapshots.wizard.confirmation.phrase_label'))
                        ->disabled()
                        ->dehydrated(fn(): bool => true)
                        ->default(function (Get $get) use ($record): string {
                            $scope = $this->normalizeRestoreScope((string) ($get('scope') ?? 'files'));

                            return $this->buildConfirmationPhrase($record, $scope);
                        }),
                    TextInput::make('confirmation')
                        ->label(__('restic-backups::backups.pages.snapshots.wizard.confirmation.input_label'))
                        ->helperText(__('restic-backups::backups.pages.snapshots.wizard.confirmation.input_helper'))
                        ->required()
                        ->autocomplete('off'),
                ]),
        ];
    }

    protected function buildConfirmationPhrase(array $record, string $scope): string
    {
        $appName = trim((string) config('app.name', 'app'));
        $appName = $appName === '' ? 'app' : $appName;
        $shortId = (string) ($record['short_id'] ?? $record['id'] ?? __('restic-backups::backups.pages.snapshots.placeholders.unknown'));
        $scopeLabel = $this->confirmationScopeLabel($scope);

        return __('restic-backups::backups.pages.snapshots.wizard.confirmation.phrase_value', [
            'app' => $appName,
            'snapshot' => $shortId,
            'scope' => $scopeLabel,
        ]);
    }

    protected function confirmationScopeLabel(string $scope): string
    {
        $scope = $this->normalizeRestoreScope($scope);

        return match ($scope) {
            'db' => __('restic-backups::backups.pages.snapshots.wizard.confirmation.scope.db'),
            'both' => __('restic-backups::backups.pages.snapshots.wizard.confirmation.scope.both'),
            default => __('restic-backups::backups.pages.snapshots.wizard.confirmation.scope.files'),
        };
    }

    protected function normalizeConfirmationInput(string $value): string
    {
        $value = str_replace("\xC2\xA0", ' ', $value);
        $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value) ?? $value;
        $value = preg_replace('/\p{Cf}+/u', '', $value) ?? $value;
        $value = trim($value);
        $normalized = preg_replace('/[\p{Z}\s]+/u', ' ', $value);

        return is_string($normalized) ? $normalized : $value;
    }

    protected function normalizeRestoreScope(string $scope): string
    {
        $scope = strtolower(trim($scope));

        return match ($scope) {
            'db', 'database' => 'db',
            'both' => 'both',
            'files', 'file' => 'files',
            default => 'files',
        };
    }

    protected function normalizeRestoreMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return in_array($mode, ['rsync', 'atomic'], true) ? $mode : 'rsync';
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function computePreflightBase(array $record): array
    {
        $snapshotId = (string) ($record['id'] ?? $record['short_id'] ?? '');
        $projectRoot = $this->resolveProjectRoot();
        $freeBytes = $this->getDiskFreeBytes($projectRoot);
        $expectedBytes = null;
        $source = null;

        if ($snapshotId !== '') {
            try {
                $result = app(ResticRunner::class)->statsRestoreSize($snapshotId, [
                    'timeout' => 300,
                    'capture_output' => true,
                ]);

                if ($result->exitCode === 0 && is_array($result->parsedJson)) {
                    $expectedBytes = $this->extractRestoreSize($result->parsedJson);
                    $source = $expectedBytes !== null ? 'restic_stats' : null;
                }
            } catch (Throwable) {
                // fallback below
            }
        }

        if ($expectedBytes === null) {
            $expectedBytes = $this->getDirectorySizeBytes($projectRoot);
            $source = $expectedBytes !== null ? 'du' : null;
        }

        return [
            'free_bytes' => $freeBytes,
            'expected_bytes' => $expectedBytes,
            'source' => $source,
            'same_filesystem' => $this->sameFilesystem($projectRoot, dirname($projectRoot)),
        ];
    }

    protected function preflightOkForState(Get $get, ?array $base): bool
    {
        $scope = $this->normalizeRestoreScope((string) ($get('scope') ?? 'files'));
        $mode = $this->normalizeRestoreMode((string) ($get('mode') ?? 'atomic'));

        return $this->preflightOkForValues($base, $scope, $mode);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function preflightOkFromData(?array $base, array $data): bool
    {
        $scope = $this->normalizeRestoreScope((string) ($data['scope'] ?? 'files'));
        $mode = $scope === 'db' ? 'rsync' : $this->normalizeRestoreMode((string) ($data['mode'] ?? 'atomic'));

        return $this->preflightOkForValues($base, $scope, $mode);
    }

    protected function preflightOkForValues(?array $base, string $scope, string $mode): bool
    {
        if (! is_array($base)) {
            return false;
        }

        $requiredBytes = $this->requiredBytesForScope($base, $scope);
        $freeBytes = $base['free_bytes'] ?? null;

        if (! is_int($requiredBytes) || ! is_int($freeBytes)) {
            return false;
        }

        if ($scope !== 'db' && $mode === 'atomic') {
            $sameFs = $base['same_filesystem'] ?? null;

            if ($sameFs !== true) {
                return false;
            }
        }

        return $freeBytes >= $requiredBytes;
    }

    /**
     * @param  array<string, mixed>  $base
     */
    protected function requiredBytesForScope(array $base, string $scope): ?int
    {
        if ($scope === 'db') {
            return 2 * 1024 * 1024 * 1024;
        }

        $expected = $base['expected_bytes'] ?? null;

        if (! is_int($expected)) {
            return null;
        }

        return (int) ceil(($expected * 1.15) + (2 * 1024 * 1024 * 1024));
    }

    protected function formatBytes(int | float | null $bytes): string
    {
        if ($bytes === null) {
            return __('restic-backups::backups.pages.snapshots.placeholders.not_available');
        }

        $bytes = (float) $bytes;

        if ($bytes < 1024) {
            return number_format($bytes, 0) . ' B';
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
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    protected function extractRestoreSize(array $stats): ?int
    {
        $keys = ['total_size', 'total_size_bytes', 'total_size_in_bytes'];

        foreach ($keys as $key) {
            if (isset($stats[$key]) && is_numeric($stats[$key])) {
                return (int) $stats[$key];
            }
        }

        return null;
    }

    protected function resolveProjectRoot(): string
    {
        $settings = BackupSetting::singleton();

        return $this->normalizeScalar($settings->project_root)
            ?? (string) config('restic-backups.paths.project_root', base_path());
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

    protected function getDirectorySizeBytes(string $path): ?int
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);

        if ($path === '' || ! is_dir($path)) {
            return null;
        }

        $finder = new ExecutableFinder();
        $du = $finder->find('du');

        if ($du === null) {
            return null;
        }

        $process = new Process([$du, '-sb', $path], dirname($path));
        $exitCode = $process->run();

        if ($exitCode !== 0) {
            return null;
        }

        $output = trim($process->getOutput());

        if ($output === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $output);
        $size = $parts[0] ?? null;

        return is_numeric($size) ? (int) $size : null;
    }

    protected function sameFilesystem(string $pathA, string $pathB): bool
    {
        $statA = @stat($pathA);
        $statB = @stat($pathB);

        if ($statA === false || $statB === false) {
            return false;
        }

        $devA = $statA['dev'] ?? null;
        $devB = $statB['dev'] ?? null;

        return $devA !== null && $devA === $devB;
    }

    protected function formatSnapshotTime(?string $value): string
    {
        $value = $this->normalizeScalar($value);

        if ($value === null) {
            return '—';
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return $value;
        }
    }

    protected function formatTags(array $tags): string
    {
        $tags = $this->normalizeArray($tags);

        return $tags === [] ? '—' : implode(', ', $tags);
    }

    protected function parseTimeToTimestamp(?string $value): int
    {
        if ($value === null) {
            return 0;
        }

        try {
            return Carbon::parse($value)->getTimestamp();
        } catch (Throwable) {
            return 0;
        }
    }

    protected function parseDateToTimestamp(string $value, string $mode): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }

        if ($mode === 'end') {
            $date = $date->endOfDay();
        } else {
            $date = $date->startOfDay();
        }

        return $date->getTimestamp();
    }

    /**
     * @return array<string, string>
     */
    protected function getTagOptions(): array
    {
        $this->loadSnapshots();

        $tags = collect($this->snapshotRecords ?? [])
            ->pluck('tags')
            ->flatten()
            ->filter(fn(?string $tag): bool => is_string($tag) && $tag !== '')
            ->unique()
            ->sort()
            ->values();

        return $tags->mapWithKeys(fn(string $tag): array => [$tag => $tag])->all();
    }

    /**
     * @return array<string, string>
     */
    protected function getHostOptions(): array
    {
        $this->loadSnapshots();

        $hosts = collect($this->snapshotRecords ?? [])
            ->pluck('hostname')
            ->filter(fn(?string $host): bool => is_string($host) && $host !== '')
            ->unique()
            ->sort()
            ->values();

        return $hosts->mapWithKeys(fn(string $host): array => [$host => $host])->all();
    }

    protected function resolveSortValue(array $record, string $column): mixed
    {
        return match ($column) {
            'hostname' => strtolower((string) ($record['hostname'] ?? '')),
            'short_id', 'id' => (string) ($record['short_id'] ?? $record['id'] ?? ''),
            default => $record['time_unix'] ?? 0,
        };
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
