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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Siteko\FilamentResticBackups\Exceptions\ResticConfigurationException;
use Siteko\FilamentResticBackups\Jobs\RunRestoreJob;
use Siteko\FilamentResticBackups\Models\BackupSetting;
use Siteko\FilamentResticBackups\Services\ResticRunner;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class BackupsSnapshots extends BaseBackupsPage implements HasTable
{
    use InteractsWithTable;

    private const SNAPSHOT_CACHE_SECONDS = 30;
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
        return static::baseNavigationSort() + 1;
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
            ->records(fn (
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
                    ->label('ID')
                    ->copyable()
                    ->copyableState(fn (array $record): string => (string) ($record['id'] ?? $record['short_id'] ?? ''))
                    ->tooltip(fn (array $record): ?string => $record['id'] ?? null)
                    ->toggleable(),
                TextColumn::make('time')
                    ->label('Time')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('hostname')
                    ->label('Host')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('tags')
                    ->label('Tags')
                    ->state(fn (array $record): array => $record['tags'] ?? [])
                    ->badge()
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->toggleable(),
                TextColumn::make('paths_summary')
                    ->label('Paths')
                    ->state(fn (array $record): string => $this->formatPathsSummary($record['paths'] ?? []))
                    ->tooltip(fn (array $record): ?string => $this->formatPathsTooltip($record['paths'] ?? []))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('tag')
                    ->label('Tag')
                    ->options(fn (): array => $this->getTagOptions())
                    ->searchable(),
                SelectFilter::make('host')
                    ->label('Host')
                    ->options(fn (): array => $this->getHostOptions())
                    ->searchable(),
                Filter::make('time')
                    ->label('Date range')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ]),
            ])
            ->actions([
                Action::make('restore')
                    ->label('Restore...')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->modalHeading('Restore snapshot')
                    ->modalSubmitActionLabel('Queue restore')
                    ->modalSubmitAction(function (Action $action): Action {
                        return $action->disabled(fn (): bool => ! $this->restorePreflightOk);
                    })
                    ->steps(fn (array $record): array => $this->buildRestoreSteps($record))
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
                                ->title('Confirmation phrase mismatch')
                                ->body('Type the exact phrase shown in the wizard to confirm the restore.')
                                ->danger()
                                ->send();

                            throw new Halt();
                        }

                        if (! $this->preflightOkFromData($preflightBase, $data)) {
                            Notification::make()
                                ->title('Preflight failed')
                                ->body('Insufficient disk space or filesystem mismatch for atomic restore.')
                                ->danger()
                                ->send();

                            throw new Halt();
                        }

                        $snapshotId = (string) ($record['id'] ?? $record['short_id'] ?? '');

                        if ($snapshotId === '') {
                            Notification::make()
                                ->title('Snapshot ID missing')
                                ->body('Unable to determine snapshot ID for restore.')
                                ->danger()
                                ->send();

                            throw new Halt();
                        }

                        RunRestoreJob::dispatch(
                            $snapshotId,
                            $scope,
                            $mode,
                            $safetyBackup,
                            'manual',
                        );

                        Notification::make()
                            ->title('Restore queued')
                            ->body('Restore job has been queued. During cutover the site will briefly enter maintenance. Use the bypass path from Runs if needed.')
                            ->success()
                            ->send();
                    }),
                Action::make('details')
                    ->label('Details')
                    ->icon('heroicon-o-document-text')
                    ->modalHeading('Snapshot details')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (array $record) => view('restic-backups::snapshots.details', [
                        'record' => $record,
                    ])),
            ])
            ->paginationPageOptions([25, 50, 100]);
    }

    public function content(Schema $schema): Schema
    {
        $components = [];

        if ($this->snapshotError !== null) {
            $components[] = Section::make('Repository issue')
                ->description('Unable to load snapshots from restic.')
                ->schema([
                    Text::make(fn (): string => $this->snapshotError ?? 'Unknown error')
                        ->color('danger'),
                    Text::make(fn (): string => $this->snapshotErrorDetails ?? '')
                        ->color('gray'),
                    ActionsComponent::make([
                        Action::make('openSettings')
                            ->label('Open Settings')
                            ->icon('heroicon-o-cog-6-tooth')
                            ->url(BackupsSettings::getUrl()),
                    ]),
                ]);
        }

        $components[] = EmbeddedTable::make();

        return $schema
            ->components($components);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->action('refreshSnapshots'),
            Action::make('settings')
                ->label('Settings')
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
            $records = $records->filter(fn (array $record): bool => in_array($tag, $record['tags'] ?? [], true));
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
                $records = $records->filter(fn (array $record): bool => ($record['time_unix'] ?? 0) >= $fromTimestamp);
            }
        }

        if ($until) {
            $untilTimestamp = $this->parseDateToTimestamp($until, 'end');
            if ($untilTimestamp !== null) {
                $records = $records->filter(fn (array $record): bool => ($record['time_unix'] ?? 0) <= $untilTimestamp);
            }
        }

        $sortColumn = $sortColumn ?: 'time';
        $descending = $sortDirection !== 'asc';

        $records = $records->sortBy(
            fn (array $record) => $this->resolveSortValue($record, $sortColumn),
            SORT_REGULAR,
            $descending,
        );

        $page = is_numeric($page) ? (int) $page : 1;
        $recordsPerPage = is_numeric($recordsPerPage) ? (int) $recordsPerPage : 25;
        $recordsPerPage = $recordsPerPage > 0 ? $recordsPerPage : 25;

        $total = $records->count();
        $items = $records->slice(($page - 1) * $recordsPerPage, $recordsPerPage)->values();

        return new LengthAwarePaginator($items, $total, $recordsPerPage, $page);
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
            $this->setSnapshotError('Restic repository is not configured.', $exception->getMessage(), null, $notify);

            return;
        } catch (Throwable $exception) {
            $this->snapshotRecords = [];
            $this->setSnapshotError('Unable to load snapshots.', $exception->getMessage(), null, $notify);

            return;
        }

        if ($result->exitCode !== 0 || ! is_array($result->parsedJson)) {
            $this->snapshotRecords = [];
            $details = $this->formatSnapshotErrorDetails($result->stderr, $result->exitCode);
            $this->setSnapshotError('Unable to load snapshots from restic.', $details, $result->exitCode, $notify);

            return;
        }

        $this->snapshotRecords = $this->normalizeSnapshots($result->parsedJson);

        if ($notify) {
            Notification::make()
                ->title('Snapshots refreshed')
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
            $prefix = "Exit code: {$exitCode}.";
            $body = $body !== '' ? $prefix . ' ' . $body : $prefix;
        }

        Notification::make()
            ->title($message)
            ->danger()
            ->body($body)
            ->send();
    }

    protected function formatSnapshotErrorDetails(string $stderr, int $exitCode): string
    {
        $stderr = $this->sanitizeErrorDetails($stderr);

        if ($stderr !== '') {
            return $stderr;
        }

        return "Restic exited with code {$exitCode}.";
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

        return substr($value, 0, self::ERROR_SNIPPET_LIMIT) . PHP_EOL . '...[truncated]';
    }

    protected function formatPathsSummary(array $paths): string
    {
        $paths = array_values(array_filter($paths, fn (string $path): bool => trim($path) !== ''));

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
        $paths = array_values(array_filter($paths, fn (string $path): bool => trim($path) !== ''));

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
        $shortId = (string) ($record['short_id'] ?? $record['id'] ?? 'unknown');
        $time = $this->formatSnapshotTime($record['time'] ?? null);
        $host = (string) ($record['hostname'] ?? 'unknown');
        $tags = $this->formatTags($record['tags'] ?? []);
        $paths = $this->formatPathsSummary($record['paths'] ?? []);
        $preflightBase = $this->computePreflightBase($record);
        $this->restorePreflightBase = $preflightBase;
        $this->restorePreflightOk = $this->preflightOkForValues($preflightBase, 'files', 'atomic');

        return [
            Step::make('Snapshot')
                ->schema([
                    Text::make('Snapshot: ' . $shortId),
                    Text::make('Time: ' . $time),
                    Text::make('Host: ' . $host),
                    Text::make('Tags: ' . $tags),
                    Text::make('Paths: ' . $paths),
                    Text::make('Warning: Restore is a destructive operation.')
                        ->color('danger'),
                ]),
            Step::make('Scope')
                ->schema([
                    Radio::make('scope')
                        ->label('Restore scope')
                        ->options([
                            'files' => 'Files only',
                            'db' => 'Database only',
                            'both' => 'Files + Database',
                        ])
                        ->default('files')
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Get $get): void {
                            $this->restorePreflightOk = $this->preflightOkForState($get, $this->restorePreflightBase);
                        }),
                    Radio::make('mode')
                        ->label('Files restore mode')
                        ->options([
                            'rsync' => 'Rsync (sync into existing project)',
                            'atomic' => 'Atomic swap (new directory, then replace)',
                        ])
                        ->default('atomic')
                        ->helperText('Atomic swap minimizes downtime.')
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
                        ->label('Create safety backup before restore')
                        ->default(true),
                ]),
            Step::make('Preflight')
                ->schema([
                    Text::make(function (Get $get) use ($preflightBase): string {
                        $free = $preflightBase['free_bytes'] ?? null;

                        return 'Free: ' . $this->formatBytes($free);
                    }),
                    Text::make(function (Get $get) use ($preflightBase): string {
                        $expected = $preflightBase['expected_bytes'] ?? null;
                        $source = $preflightBase['source'] ?? 'n/a';

                        return 'Estimated restore size: ' . $this->formatBytes($expected) . " ({$source})";
                    }),
                    Text::make(function (Get $get) use ($preflightBase): string {
                        $required = $this->requiredBytesForScope($preflightBase, (string) ($get('scope') ?? 'files'));

                        return 'Required (with buffer): ' . $this->formatBytes($required);
                    }),
                    Text::make(function (Get $get) use ($preflightBase): string {
                        $ok = $this->preflightOkForState($get, $preflightBase) ? 'OK' : 'FAIL';
                        $sameFs = $preflightBase['same_filesystem'] ?? null;
                        $scope = $this->normalizeRestoreScope((string) ($get('scope') ?? 'files'));
                        $mode = $scope === 'db'
                            ? 'rsync'
                            : $this->normalizeRestoreMode((string) ($get('mode') ?? 'atomic'));
                        $suffix = '';

                        if ($mode === 'atomic' && $sameFs !== null) {
                            $suffix = $sameFs ? ' / same FS: OK' : ' / same FS: FAIL';
                        }

                        return 'Result: ' . $ok . $suffix;
                    })->color(function (Get $get) use ($preflightBase): string {
                        return $this->preflightOkForState($get, $preflightBase) ? 'success' : 'danger';
                    }),
                ]),
            Step::make('Confirmation')
                ->schema([
                    TextInput::make('confirmation_phrase')
                        ->label('Confirmation phrase')
                        ->disabled()
                        ->dehydrated(fn (): bool => true)
                        ->default(function (Get $get) use ($record): string {
                            $scope = $this->normalizeRestoreScope((string) ($get('scope') ?? 'files'));

                            return $this->buildConfirmationPhrase($record, $scope);
                        }),
                    TextInput::make('confirmation')
                        ->label('Type the phrase to confirm')
                        ->helperText('Type the exact phrase shown above.')
                        ->required()
                        ->autocomplete('off'),
                ]),
        ];
    }

    protected function buildConfirmationPhrase(array $record, string $scope): string
    {
        $appName = trim((string) config('app.name', 'app'));
        $appName = $appName === '' ? 'app' : $appName;
        $shortId = (string) ($record['short_id'] ?? $record['id'] ?? 'unknown');
        $scope = $this->normalizeRestoreScope($scope);

        return 'RESTORE ' . $appName . ' ' . $shortId . ' ' . $scope;
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
            return 'n/a';
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
            ->filter(fn (?string $tag): bool => is_string($tag) && $tag !== '')
            ->unique()
            ->sort()
            ->values();

        return $tags->mapWithKeys(fn (string $tag): array => [$tag => $tag])->all();
    }

    /**
     * @return array<string, string>
     */
    protected function getHostOptions(): array
    {
        $this->loadSnapshots();

        $hosts = collect($this->snapshotRecords ?? [])
            ->pluck('hostname')
            ->filter(fn (?string $host): bool => is_string($host) && $host !== '')
            ->unique()
            ->sort()
            ->values();

        return $hosts->mapWithKeys(fn (string $host): array => [$host => $host])->all();
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
