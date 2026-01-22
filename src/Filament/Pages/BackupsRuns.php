<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\URL;
use Siteko\FilamentResticBackups\Jobs\RunBackupJob;
use Siteko\FilamentResticBackups\Models\BackupRun;
use Siteko\FilamentResticBackups\Support\BackupsTimezone;
use Siteko\FilamentResticBackups\Support\OperationLock;

class BackupsRuns extends BaseBackupsPage implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'backups/runs';

    protected static ?string $navigationLabel = 'Runs';

    protected static ?string $title = 'Backup Runs';

    protected ?string $displayTimezone = null;

    public static function getNavigationSort(): ?int
    {
        return static::baseNavigationSort() + 3;
    }

    public static function getNavigationLabel(): string
    {
        return __('restic-backups::backups.pages.runs.navigation_label');
    }

    public function getTitle(): string
    {
        return __('restic-backups::backups.pages.runs.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn(): Builder => BackupRun::query())
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('started_at')
                    ->label(__('restic-backups::backups.pages.runs.table.columns.started'))
                    ->dateTime(timezone: fn(): string => $this->displayTimezone())
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->label(__('restic-backups::backups.pages.runs.table.columns.finished'))
                    ->dateTime(timezone: fn(): string => $this->displayTimezone())
                    ->toggleable(),
                TextColumn::make('type')
                    ->label(__('restic-backups::backups.pages.runs.table.columns.type'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('restic-backups::backups.pages.runs.table.columns.status'))
                    ->badge()
                    ->color(fn(?string $state): string => match ($state) {
                        'success' => 'success',
                        'failed' => 'danger',
                        'running' => 'warning',
                        'skipped' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('duration')
                    ->label(__('restic-backups::backups.pages.runs.table.columns.duration'))
                    ->state(fn(BackupRun $record): ?string => $this->formatDuration($record))
                    ->toggleable(),
                TextColumn::make('meta.trigger')
                    ->label(__('restic-backups::backups.pages.runs.table.columns.trigger'))
                    ->toggleable(),
                TextColumn::make('meta.tags')
                    ->label(__('restic-backups::backups.pages.runs.table.columns.tags'))
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->toggleable(),
                TextColumn::make('meta.backup.exit_code')
                    ->label(__('restic-backups::backups.pages.runs.table.columns.exit_code'))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('restic-backups::backups.pages.runs.table.filters.status'))
                    ->options([
                        'running' => __('restic-backups::backups.pages.runs.table.filters.status_options.running'),
                        'success' => __('restic-backups::backups.pages.runs.table.filters.status_options.success'),
                        'failed' => __('restic-backups::backups.pages.runs.table.filters.status_options.failed'),
                        'skipped' => __('restic-backups::backups.pages.runs.table.filters.status_options.skipped'),
                    ]),
                SelectFilter::make('type')
                    ->label(__('restic-backups::backups.pages.runs.table.filters.type'))
                    ->options([
                        'backup' => __('restic-backups::backups.pages.runs.table.filters.type_options.backup'),
                        'check' => __('restic-backups::backups.pages.runs.table.filters.type_options.check'),
                        'forget' => __('restic-backups::backups.pages.runs.table.filters.type_options.forget'),
                        'forget_snapshot' => __('restic-backups::backups.pages.runs.table.filters.type_options.forget_snapshot'),
                        'restore' => __('restic-backups::backups.pages.runs.table.filters.type_options.restore'),
                        'export_snapshot' => __('restic-backups::backups.pages.runs.table.filters.type_options.export_snapshot'),
                    ]),
                Filter::make('started_at')
                    ->label(__('restic-backups::backups.pages.runs.table.filters.started_at'))
                    ->schema(schema: [
                        DatePicker::make('from')->label(__('restic-backups::backups.pages.runs.table.filters.from')),
                        DatePicker::make('until')->label(__('restic-backups::backups.pages.runs.table.filters.until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn(Builder $query, string $date): Builder => $query->whereDate('started_at', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn(Builder $query, string $date): Builder => $query->whereDate('started_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                Action::make('view')
                    ->label(__('restic-backups::backups.pages.runs.actions.view.label'))
                    ->icon('heroicon-o-eye')
                    ->modalHeading(__('restic-backups::backups.pages.runs.actions.view.modal_heading'))
                    ->modalSubmitAction(false)
                    ->modalContent(fn(BackupRun $record) => view('restic-backups::runs.view', [
                        'record' => $record,
                        'timezone' => $this->displayTimezone(),
                    ])),
                Action::make('download')
                    ->label(__('restic-backups::backups.pages.runs.actions.download.label'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(function (BackupRun $record): bool {
                        if ($record->type !== 'export_snapshot' || $record->status !== 'success') {
                            return false;
                        }

                        $meta = is_array($record->meta) ? $record->meta : [];
                        $export = is_array($meta['export'] ?? null) ? $meta['export'] : [];

                        return ! empty($export['archive_path']) && ! empty($export['archive_name']);
                    })
                    ->url(function (BackupRun $record): string {
                        return URL::temporarySignedRoute(
                            'restic-backups.exports.download',
                            now()->addMinutes(60),
                            ['run' => $record->getKey()],
                        );
                    })
                    ->openUrlInNewTab(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create_snapshot')
                ->label(__('restic-backups::backups.pages.runs.header_actions.create_snapshot'))
                ->icon('heroicon-o-play')
                ->requiresConfirmation()
                ->modalHeading(__('restic-backups::backups.pages.runs.header_actions.create_snapshot_modal_heading'))
                ->modalDescription(__('restic-backups::backups.pages.runs.header_actions.create_snapshot_modal_description'))
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
        ];
    }

    protected function formatDuration(BackupRun $record): ?string
    {
        if (! $record->started_at || ! $record->finished_at) {
            return null;
        }

        $seconds = $record->started_at->diffInSeconds($record->finished_at);

        return $this->formatDurationSeconds($seconds);
    }

    protected function formatDurationSeconds(int | float $seconds): string
    {
        $seconds = (int) round($seconds);

        if ($seconds < 60) {
            return __('restic-backups::backups.pages.runs.duration.seconds', [
                'seconds' => $seconds,
            ]);
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
    }

    protected function displayTimezone(): string
    {
        return $this->displayTimezone ??= BackupsTimezone::resolve();
    }
}
