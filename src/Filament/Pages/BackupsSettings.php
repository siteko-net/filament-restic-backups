<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\CanUseDatabaseTransactions;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form as FormComponent;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Text as SchemaText;
use Filament\Schemas\Components\Utilities\Set;
use Livewire\Attributes\Locked;
use Illuminate\Support\Facades\Cache;
use Siteko\FilamentResticBackups\Jobs\RunBackupJob;
use Siteko\FilamentResticBackups\Models\BackupSetting;
use Siteko\FilamentResticBackups\Services\ResticRunner;
use Siteko\FilamentResticBackups\Services\S3BucketsService;
use Siteko\FilamentResticBackups\Support\OperationLock;
use Throwable;

class BackupsSettings extends BaseBackupsPage
{
    use CanUseDatabaseTransactions;

    private const REPO_STATUS_CACHE_KEY = 'restic-backups:settings:repo-status';
    private const REPO_STATUS_CACHE_TTL = 30;

    /**
     * @var array<int, string>
     */
    private const DEFAULT_EXCLUDE_PATHS = [
        'vendor',
        'node_modules',
        '.git',
        'storage/framework',
        'storage/logs',
        'bootstrap/cache',
        'public/build',
        'public/hot',
    ];

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    /**
     * @var array<int, string>
     */
    public array $bucketOptions = [];

    public ?string $bucketLoadError = null;

    public bool $bucketManual = true;

    /**
     * @var array<string, mixed> | null
     */
    public ?array $repositoryStatus = null;

    #[Locked]
    public ?BackupSetting $record = null;

    protected static ?string $slug = 'backups/settings';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Backup Settings';

    public static function getNavigationSort(): ?int
    {
        return static::baseNavigationSort() + 1;
    }

    public static function getNavigationLabel(): string
    {
        return __('restic-backups::backups.pages.settings.navigation_label');
    }

    public function getTitle(): string
    {
        return __('restic-backups::backups.pages.settings.title');
    }

    public function mount(): void
    {
        $this->record = BackupSetting::singleton();
        $this->fillForm();
        $this->loadRepositoryStatus();
    }

    protected function fillForm(): void
    {
        $data = $this->record?->attributesToArray() ?? [];

        $this->callHook('beforeFill');

        $data = $this->mutateFormDataBeforeFill($data);

        $this->form->fill($data);

        $this->callHook('afterFill');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['access_key'], $data['secret_key'], $data['restic_password']);

        $data['project_root'] = base_path();

        $repositoryPrefix = $this->resolveRepositoryPrefix($data);
        $data['repository_prefix'] = $repositoryPrefix;

        $endpoint = $this->normalizeEndpoint($this->normalizeScalar($data['endpoint'] ?? null));
        if ($endpoint !== null) {
            $data['endpoint'] = $endpoint;
        }

        $bucket = $this->normalizeScalar($data['bucket'] ?? null);
        if ($endpoint !== null && $bucket !== null) {
            $data['restic_repository'] = $this->buildRepositoryUri($endpoint, $bucket, $repositoryPrefix);
        }

        $data['retention'] = is_array($data['retention'] ?? null) ? $data['retention'] : [];
        $data['schedule'] = is_array($data['schedule'] ?? null) ? $data['schedule'] : [];
        $data['paths'] = is_array($data['paths'] ?? null) ? $data['paths'] : [];

        $data['paths']['include'] = is_array($data['paths']['include'] ?? null) ? $data['paths']['include'] : [];
        $data['paths']['exclude'] = is_array($data['paths']['exclude'] ?? null) ? $data['paths']['exclude'] : [];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (['access_key', 'secret_key', 'restic_password'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            if ($this->normalizeScalar($data[$key]) === null) {
                unset($data[$key]);
            }
        }

        $endpoint = $this->normalizeEndpoint($this->normalizeScalar($data['endpoint'] ?? null));
        if ($endpoint !== null) {
            $data['endpoint'] = $endpoint;
        }

        $repositoryPrefix = $this->resolveRepositoryPrefix($data);
        $data['repository_prefix'] = $repositoryPrefix;
        $data['prefix'] = $repositoryPrefix;

        $bucket = $this->normalizeScalar($data['bucket'] ?? null);

        if ($endpoint !== null && $bucket !== null) {
            $data['restic_repository'] = $this->buildRepositoryUri($endpoint, $bucket, $repositoryPrefix);
        }

        $data['project_root'] = base_path();

        if (isset($data['paths']) && is_array($data['paths'])) {
            $data['paths']['include'] = $this->normalizePathList($data['paths']['include'] ?? []);
            $data['paths']['exclude'] = $this->normalizePathList($data['paths']['exclude'] ?? []);
        }

        return $data;
    }

    public function save(): void
    {
        try {
            $this->beginDatabaseTransaction();

            $this->callHook('beforeValidate');

            $data = $this->form->getState();

            $this->callHook('afterValidate');

            $data = $this->mutateFormDataBeforeSave($data);

            $this->callHook('beforeSave');

            $record = $this->record ??= BackupSetting::singleton();
            $record->fill($data)->save();

            $this->callHook('afterSave');
        } catch (Halt $exception) {
            $exception->shouldRollbackDatabaseTransaction() ?
                $this->rollBackDatabaseTransaction() :
                $this->commitDatabaseTransaction();

            return;
        } catch (Throwable $exception) {
            $this->rollBackDatabaseTransaction();

            throw $exception;
        }

        $this->commitDatabaseTransaction();

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->send();

        $this->fillForm();
        $this->loadRepositoryStatus(force: true);
    }

    public function defaultForm(Schema $schema): Schema
    {
        $this->record ??= BackupSetting::singleton();

        return $schema
            ->operation('edit')
            ->model($this->record)
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Repository status')
                    ->columns(1)
                    ->schema([
                        SchemaText::make(fn(): string => 'Status: ' . $this->repositoryStatusLabel())
                            ->color(fn(): string => $this->repositoryStatusColor()),
                        SchemaText::make(fn(): string => $this->repositoryStatusMessage())
                            ->color(fn(): string => $this->repositoryStatusColor())
                            ->visible(fn(): bool => $this->repositoryStatusMessage() !== ''),
                        SchemaText::make(fn(): string => 'Snapshots: ' . (string) ($this->repositoryStatus['snapshots_count'] ?? 'n/a'))
                            ->visible(fn(): bool => ($this->repositoryStatus['status'] ?? null) === 'ok'),
                        Actions::make([
                            Action::make('refreshRepositoryStatus')
                                ->label('Refresh status')
                                ->icon('heroicon-o-arrow-path')
                                ->action('refreshRepositoryStatus'),
                            Action::make('initRepository')
                                ->label('Init repository')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->modalHeading('Init repository')
                                ->modalDescription('This will initialize a new restic repository at the computed path.')
                                ->form([
                                    TextInput::make('confirm')
                                        ->label('Type INIT to confirm')
                                        ->required(),
                                ])
                                ->visible(fn(): bool => ($this->repositoryStatus['status'] ?? null) === 'not_initialized')
                                ->disabled(fn(): bool => ! $this->hasRepositoryConfig($this->record))
                                ->action(fn(array $data) => $this->initRepository($data)),
                        ]),
                    ]),
                Section::make('Storage (S3)')
                    ->description('Configure S3-compatible storage for restic.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('endpoint')
                            ->label('Endpoint')
                            ->placeholder('https://s3.example.com')
                            ->helperText('Scheme is optional; https:// will be used by default.')
                            ->nullable(),
                        Select::make('bucket_select')
                            ->label('Bucket')
                            ->options(fn(): array => array_combine($this->bucketOptions, $this->bucketOptions))
                            ->searchable()
                            ->placeholder('Refresh to load buckets')
                            ->default(fn(): ?string => $this->data['bucket'] ?? null)
                            ->visible(fn(): bool => $this->bucketOptions !== [])
                            ->dehydrated(false)
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if (is_string($state) && $state !== '') {
                                    $set('bucket', $state);
                                }
                            }),
                        TextInput::make('bucket')
                            ->label('Bucket')
                            ->rule('regex:/^\\S+$/')
                            ->placeholder('Enter bucket name')
                            ->helperText('Enter manually if listing is not available.')
                            ->visible(fn(): bool => $this->bucketManual || $this->bucketOptions === [])
                            ->nullable(),
                        Actions::make([
                            Action::make('refreshBuckets')
                                ->label('Refresh buckets')
                                ->icon('heroicon-o-arrow-path')
                                ->action('refreshBuckets'),
                        ]),
                        SchemaText::make(fn(): string => (string) ($this->bucketLoadError ?? ''))
                            ->color('danger')
                            ->visible(fn(): bool => $this->bucketLoadError !== null),
                        TextInput::make('access_key')
                            ->label('Access key')
                            ->password()
                            ->placeholder('******')
                            ->dehydrated(fn(string | null $state): bool => filled($state))
                            ->afterStateHydrated(function (TextInput $component): void {
                                $component->state(null);
                            }),
                        TextInput::make('secret_key')
                            ->label('Secret key')
                            ->password()
                            ->placeholder('******')
                            ->dehydrated(fn(string | null $state): bool => filled($state))
                            ->afterStateHydrated(function (TextInput $component): void {
                                $component->state(null);
                            }),
                    ]),
                Section::make('Repository')
                    ->columns(1)
                    ->schema([
                        TextInput::make('repository_prefix')
                            ->label('Repository path')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Auto-generated: restic/<app>/<env>.'),
                        Textarea::make('restic_repository')
                            ->label('Repository URI')
                            ->rows(2)
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('restic_password')
                            ->label('Repository password')
                            ->password()
                            ->placeholder('******')
                            ->dehydrated(fn(string | null $state): bool => filled($state))
                            ->afterStateHydrated(function (TextInput $component): void {
                                $component->state(null);
                            }),
                    ]),
                Section::make('Retention policy')
                    ->description('If empty or 0, retention may be skipped.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('retention.keep_last')
                            ->label('Keep last')
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('retention.keep_daily')
                            ->label('Keep daily')
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('retention.keep_weekly')
                            ->label('Keep weekly')
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('retention.keep_monthly')
                            ->label('Keep monthly')
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('retention.keep_yearly')
                            ->label('Keep yearly')
                            ->numeric()
                            ->minValue(0),
                    ]),
                Section::make('Schedule')
                    ->columns(2)
                    ->schema([
                        Toggle::make('schedule.enabled')
                            ->label('Enable schedule')
                            ->default(false),
                        TimePicker::make('schedule.daily_time')
                            ->label('Daily time')
                            ->seconds(false),
                        TextInput::make('schedule.timezone')
                            ->label('Timezone')
                            ->placeholder(config('app.timezone')),
                    ]),
                Section::make('Paths')
                    ->description('If include is empty, restic backs up the entire project root, excluding the paths below.')
                    ->columns(1)
                    ->schema([
                        TextInput::make('project_root')
                            ->label('Project root')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Always uses base_path().'),
                        TagsInput::make('paths.include')
                            ->label('Include paths')
                            ->helperText('Leave empty to back up the whole project root. Use relative paths.')
                            ->placeholder('storage/app')
                            ->suggestions([
                                'storage/app',
                                'public',
                            ]),
                        TagsInput::make('paths.exclude')
                            ->label('Exclude paths')
                            ->helperText('Do not exclude storage/app/_backup, иначе не будет дампа БД.')
                            ->placeholder('vendor')
                            ->suggestions([
                                'vendor',
                                'node_modules',
                                'storage/logs',
                            ]),
                        Actions::make([
                            Action::make('restoreExcludeDefaults')
                                ->label('Restore default excludes')
                                ->action('restoreExcludeDefaults'),
                        ]),
                    ]),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return Action::make('save')
            ->label('Save')
            ->submit('save')
            ->keyBindings(['mod+s']);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('runBackup')
                ->label('Run backup now')
                ->requiresConfirmation()
                ->modalHeading('Run backup now')
                ->modalDescription('This will start a backup job in the queue.')
                ->action(function (): void {
                    $lockInfo = app(OperationLock::class)->getInfo();

                    if (is_array($lockInfo)) {
                        $message = 'Another operation is running.';

                        if (! empty($lockInfo['type'])) {
                            $message .= ' Type: ' . $lockInfo['type'] . '.';
                        }

                        if (! empty($lockInfo['run_id'])) {
                            $message .= ' Run ID: ' . $lockInfo['run_id'] . '.';
                        }

                        Notification::make()
                            ->title('Operation in progress')
                            ->body($message . ' Backup will wait in queue.')
                            ->warning()
                            ->send();
                    }

                    RunBackupJob::dispatch([], 'manual', null, true);

                    Notification::make()
                        ->success()
                        ->title('Backup queued')
                        ->body('Backup job has been queued and will run in background.')
                        ->send();
                }),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return FormComponent::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())
                    ->alignment($this->getFormActionsAlignment())
                    ->sticky($this->areFormActionsSticky())
                    ->key('form-actions'),
            ]);
    }

    public function refreshBuckets(): void
    {
        $state = $this->form->getState();

        $endpoint = $this->normalizeEndpoint($this->normalizeScalar($state['endpoint'] ?? $this->record?->endpoint));
        $accessKey = $this->normalizeScalar($state['access_key'] ?? null) ?? $this->normalizeScalar($this->record?->access_key);
        $secretKey = $this->normalizeScalar($state['secret_key'] ?? null) ?? $this->normalizeScalar($this->record?->secret_key);

        if ($endpoint === null || $accessKey === null || $secretKey === null) {
            Notification::make()
                ->title('Missing credentials')
                ->body('Endpoint, access key, and secret key are required to list buckets.')
                ->warning()
                ->send();

            return;
        }

        $result = app(S3BucketsService::class)->listBuckets($endpoint, $accessKey, $secretKey);

        if (! $result['ok']) {
            $this->bucketOptions = [];
            $this->bucketManual = true;
            $this->bucketLoadError = $result['message'] ?? 'Failed to list buckets.';

            Notification::make()
                ->title('Bucket listing failed')
                ->body($this->bucketLoadError)
                ->warning()
                ->send();

            return;
        }

        $buckets = $result['buckets'] ?? [];
        $this->bucketOptions = array_values(array_unique($buckets));
        $this->bucketManual = $this->bucketOptions === [];
        $this->bucketLoadError = null;

        Notification::make()
            ->title('Buckets refreshed')
            ->success()
            ->send();
    }

    public function restoreExcludeDefaults(): void
    {
        $this->data ??= [];
        $this->data['paths'] = is_array($this->data['paths'] ?? null) ? $this->data['paths'] : [];
        $this->data['paths']['exclude'] = $this->defaultExcludePaths();

        $this->form->fill($this->data);
    }

    public function refreshRepositoryStatus(): void
    {
        $this->loadRepositoryStatus(force: true);
    }

    public function initRepository(array $data): void
    {
        $confirmation = strtolower(trim((string) ($data['confirm'] ?? '')));

        if ($confirmation !== 'init') {
            Notification::make()
                ->title('Confirmation mismatch')
                ->body('Type INIT to confirm repository initialization.')
                ->danger()
                ->send();

            return;
        }

        $settings = $this->record ??= BackupSetting::singleton();

        if (! $this->hasRepositoryConfig($settings)) {
            Notification::make()
                ->title('Repository not configured')
                ->body('Fill endpoint, bucket, keys, and repository password first.')
                ->danger()
                ->send();

            return;
        }

        try {
            $runner = new ResticRunner($settings);
            $result = $runner->init([
                'timeout' => 300,
                'capture_output' => true,
                'max_output_bytes' => 4096,
            ]);

            if ($result->exitCode !== 0 && ! $this->resticAlreadyInitialized($result->stderr . ' ' . $result->stdout)) {
                Notification::make()
                    ->title('Init failed')
                    ->body($this->sanitizeRepositoryMessage($result->stderr ?: $result->stdout, $settings))
                    ->danger()
                    ->send();

                return;
            }

            Notification::make()
                ->title('Repository initialized')
                ->success()
                ->send();

            $this->loadRepositoryStatus(force: true);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Init failed')
                ->body($this->sanitizeRepositoryMessage($exception->getMessage(), $settings))
                ->danger()
                ->send();
        }
    }

    protected function loadRepositoryStatus(bool $force = false): void
    {
        if ($force) {
            Cache::forget(self::REPO_STATUS_CACHE_KEY);
        }

        $this->repositoryStatus = Cache::remember(
            self::REPO_STATUS_CACHE_KEY,
            self::REPO_STATUS_CACHE_TTL,
            fn(): array => $this->determineRepositoryStatus(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function determineRepositoryStatus(): array
    {
        $settings = $this->record ?? BackupSetting::singleton();

        if (! $this->hasRepositoryConfig($settings)) {
            return [
                'status' => 'not_configured',
                'message' => 'Repository is not configured yet.',
                'snapshots_count' => null,
            ];
        }

        try {
            $runner = new ResticRunner($settings);
            $result = $runner->snapshots([
                'timeout' => 30,
                'capture_output' => true,
                'max_output_bytes' => 2048,
            ]);

            if ($result->exitCode === 0 && is_array($result->parsedJson)) {
                return [
                    'status' => 'ok',
                    'message' => 'Repository доступен.',
                    'snapshots_count' => count($result->parsedJson),
                ];
            }

            $message = $this->sanitizeRepositoryMessage($result->stderr ?: $result->stdout, $settings);

            if ($this->resticNotInitialized($message)) {
                return [
                    'status' => 'not_initialized',
                    'message' => 'Repository not initialized.',
                    'snapshots_count' => null,
                ];
            }

            return [
                'status' => 'error',
                'message' => $message,
                'snapshots_count' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'error',
                'message' => $this->sanitizeRepositoryMessage($exception->getMessage(), $settings),
                'snapshots_count' => null,
            ];
        }
    }

    protected function repositoryStatusLabel(): string
    {
        return match ($this->repositoryStatus['status'] ?? null) {
            'ok' => 'Available',
            'not_initialized' => 'Not initialized',
            'not_configured' => 'Not configured',
            default => 'Error',
        };
    }

    protected function repositoryStatusColor(): string
    {
        return match ($this->repositoryStatus['status'] ?? null) {
            'ok' => 'success',
            'not_initialized', 'not_configured' => 'warning',
            default => 'danger',
        };
    }

    protected function repositoryStatusMessage(): string
    {
        return (string) ($this->repositoryStatus['message'] ?? '');
    }

    protected function hasRepositoryConfig(?BackupSetting $settings): bool
    {
        if (! $settings instanceof BackupSetting) {
            return false;
        }

        $repository = $this->normalizeScalar($settings->restic_repository);
        $password = $this->normalizeScalar($settings->restic_password);

        if ($repository !== null && $password !== null) {
            return true;
        }

        $endpoint = $this->normalizeEndpoint($this->normalizeScalar($settings->endpoint));
        $bucket = $this->normalizeScalar($settings->bucket);
        $accessKey = $this->normalizeScalar($settings->access_key);
        $secretKey = $this->normalizeScalar($settings->secret_key);

        return $endpoint !== null && $bucket !== null && $password !== null && $accessKey !== null && $secretKey !== null;
    }

    protected function resolveRepositoryPrefix(array $data): string
    {
        $prefix = $this->normalizeScalar($data['repository_prefix'] ?? null)
            ?? $this->normalizeScalar($this->record?->repository_prefix)
            ?? $this->normalizeScalar($this->record?->prefix);

        if ($prefix === null) {
            return BackupSetting::computeRepositoryPrefix();
        }

        return trim($prefix, '/');
    }

    protected function buildRepositoryUri(string $endpoint, string $bucket, string $prefix): string
    {
        $endpoint = rtrim($endpoint, '/');
        $bucket = trim($bucket, '/');
        $prefix = trim($prefix, '/');

        $repository = 's3:' . $endpoint . '/' . $bucket;

        if ($prefix !== '') {
            $repository .= '/' . $prefix;
        }

        return $repository;
    }

    protected function normalizeEndpoint(?string $value): ?string
    {
        $value = $this->normalizeScalar($value);

        if ($value === null) {
            return null;
        }

        if (! preg_match('#^https?://#i', $value)) {
            $value = 'https://' . $value;
        }

        return rtrim($value, '/');
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    protected function normalizePathList(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            if (! is_string($path) && ! is_numeric($path)) {
                continue;
            }

            $path = trim((string) $path);

            if ($path === '') {
                continue;
            }

            $path = ltrim($path, "/\\");

            if ($path === '') {
                continue;
            }

            $normalized[] = $path;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<int, string>
     */
    protected function defaultExcludePaths(): array
    {
        return self::DEFAULT_EXCLUDE_PATHS;
    }

    protected function resticNotInitialized(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'not a repository')
            || str_contains($message, 'unable to open config file')
            || str_contains($message, 'is there a repository at')
            || str_contains($message, 'does not exist');
    }

    protected function resticAlreadyInitialized(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'already exists')
            || str_contains($message, 'already initialized')
            || str_contains($message, 'config already exists');
    }

    protected function sanitizeRepositoryMessage(string $message, ?BackupSetting $settings): string
    {
        $message = trim($message);

        if ($message === '') {
            return 'Unknown error.';
        }

        if ($settings instanceof BackupSetting) {
            foreach (
                [
                    $this->normalizeScalar($settings->access_key),
                    $this->normalizeScalar($settings->secret_key),
                    $this->normalizeScalar($settings->restic_password),
                ] as $secret
            ) {
                if ($secret === null || $secret === '') {
                    continue;
                }

                $message = str_replace($secret, '***', $message);
            }

            $repository = $this->normalizeScalar($settings->restic_repository);
            if ($repository !== null && str_contains($repository, '@')) {
                $redacted = preg_replace('#(://)([^/]*):([^@]*)@#', '$1***:***@', $repository) ?? $repository;
                $message = str_replace($repository, $redacted, $message);
            }
        }

        if (mb_strlen($message) > 600) {
            $message = mb_substr($message, 0, 600) . '…';
        }

        return $message;
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
}
