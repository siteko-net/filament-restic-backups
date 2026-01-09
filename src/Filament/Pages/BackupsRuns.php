<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Siteko\FilamentResticBackups\Models\BackupRun;

class BackupsRuns extends BaseBackupsPage implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'backups/runs';

    protected static ?string $navigationLabel = 'Runs';

    protected static ?string $title = 'Backup Runs';

    public static function getNavigationSort(): ?int
    {
        return static::baseNavigationSort() + 3;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => BackupRun::query())
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->label('Finished')
                    ->dateTime()
                    ->toggleable(),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'success' => 'success',
                        'failed' => 'danger',
                        'running' => 'warning',
                        'skipped' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('duration')
                    ->label('Duration')
                    ->state(fn (BackupRun $record): ?string => $this->formatDuration($record))
                    ->toggleable(),
                TextColumn::make('meta.trigger')
                    ->label('Trigger')
                    ->toggleable(),
                TextColumn::make('meta.tags')
                    ->label('Tags')
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->toggleable(),
                TextColumn::make('meta.backup.exit_code')
                    ->label('Exit')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'running' => 'Running',
                        'success' => 'Success',
                        'failed' => 'Failed',
                        'skipped' => 'Skipped',
                    ]),
                SelectFilter::make('type')
                    ->options([
                        'backup' => 'Backup',
                        'check' => 'Check',
                        'forget' => 'Retention',
                        'restore' => 'Restore',
                    ]),
                Filter::make('started_at')
                    ->label('Started date')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('started_at', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('started_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->modalHeading('Backup run details')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (BackupRun $record) => view('restic-backups::runs.view', [
                        'record' => $record,
                    ])),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
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
            return $seconds . 's';
        }

        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return sprintf('%dm %ds', $minutes, $remainingSeconds);
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return sprintf('%dh %dm', $hours, $remainingMinutes);
    }
}
