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
        'node_modules',
        '.git',
        'storage/framework',
        'storage/logs',
        'bootstrap/cache',
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
        return static::baseNavigationSort() + 5;
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
            ->title(__('restic-backups::backups.pages.settings.notifications.settings_saved'))
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
        $notAvailable = __('restic-backups::backups.pages.settings.placeholders.not_available');

        return $schema
            ->components([
                Section::make(__('restic-backups::backups.pages.settings.sections.repository_status.title'))
                    ->columns(1)
                    ->schema([
                        SchemaText::make(fn(): string => __('restic-backups::backups.pages.settings.sections.repository_status.status', [
                            'status' => $this->repositoryStatusLabel(),
                        ]))
                            ->color(fn(): string => $this->repositoryStatusColor()),
                        SchemaText::make(fn(): string => $this->repositoryStatusMessage())
                            ->color(fn(): string => $this->repositoryStatusColor())
                            ->visible(fn(): bool => $this->repositoryStatusMessage() !== ''),
                        SchemaText::make(fn(): string => __('restic-backups::backups.pages.settings.sections.repository_status.snapshots', [
                            'count' => $this->repositoryStatus['snapshots_count'] ?? $notAvailable,
                        ]))
                            ->visible(fn(): bool => ($this->repositoryStatus['status'] ?? null) === 'ok'),
                        Actions::make([
                            Action::make('refreshRepositoryStatus')
                                ->label(__('restic-backups::backups.pages.settings.sections.repository_status.refresh'))
                                ->icon('heroicon-o-arrow-path')
                                ->action('refreshRepositoryStatus'),
                            Action::make('initRepository')
                                ->label(__('restic-backups::backups.pages.settings.sections.repository_status.init.label'))
                                ->icon('heroicon-o-arrow-down-tray')
                                ->modalHeading(__('restic-backups::backups.pages.settings.sections.repository_status.init.modal_heading'))
                                ->modalDescription(__('restic-backups::backups.pages.settings.sections.repository_status.init.modal_description'))
                                ->form([
                                    TextInput::make('confirm')
                                        ->label(__('restic-backups::backups.pages.settings.sections.repository_status.init.confirm_label'))
                                        ->required(),
                                ])
                                ->visible(fn(): bool => ($this->repositoryStatus['status'] ?? null) === 'not_initialized')
                                ->disabled(fn(): bool => ! $this->hasRepositoryConfig($this->record))
                                ->action(fn(array $data) => $this->initRepository($data)),
                        ]),
                    ]),
                Section::make(__('restic-backups::backups.pages.settings.sections.storage.title'))
                    ->description(__('restic-backups::backups.pages.settings.sections.storage.description'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('endpoint')
                            ->label(__('restic-backups::backups.pages.settings.sections.storage.endpoint.label'))
                            ->placeholder(__('restic-backups::backups.pages.settings.sections.storage.endpoint.placeholder'))
                            ->helperText(__('restic-backups::backups.pages.settings.sections.storage.endpoint.helper'))
                            ->nullable(),
                        Select::make('bucket_select')
                            ->label(__('restic-backups::backups.pages.settings.sections.storage.bucket_select.label'))
                            ->options(fn(): array => array_combine($this->bucketOptions, $this->bucketOptions))
                            ->searchable()
                            ->placeholder(__('restic-backups::backups.pages.settings.sections.storage.bucket_select.placeholder'))
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
                            ->label(__('restic-backups::backups.pages.settings.sections.storage.bucket.label'))
                            ->rule('regex:/^\\S+$/')
                            ->placeholder(__('restic-backups::backups.pages.settings.sections.storage.bucket.placeholder'))
                            ->helperText(__('restic-backups::backups.pages.settings.sections.storage.bucket.helper'))
                            ->visible(fn(): bool => $this->bucketManual || $this->bucketOptions === [])
                            ->dehydratedWhenHidden()
                            ->nullable(),
                        Actions::make([
                            Action::make('refreshBuckets')
                                ->label(__('restic-backups::backups.pages.settings.sections.storage.refresh_buckets'))
                                ->icon('heroicon-o-arrow-path')
                                ->action('refreshBuckets'),
                        ]),
                        SchemaText::make(fn(): string => (string) ($this->bucketLoadError ?? ''))
                            ->color('danger')
                            ->visible(fn(): bool => $this->bucketLoadError !== null),
                        TextInput::make('access_key')
                            ->label(__('restic-backups::backups.pages.settings.sections.storage.access_key'))
                            ->password()
                            ->placeholder('******')
                            ->dehydrated(fn(string | null $state): bool => filled($state))
                            ->afterStateHydrated(function (TextInput $component): void {
                                $component->state(null);
                            }),
                        TextInput::make('secret_key')
                            ->label(__('restic-backups::backups.pages.settings.sections.storage.secret_key'))
                            ->password()
                            ->placeholder('******')
                            ->dehydrated(fn(string | null $state): bool => filled($state))
                            ->afterStateHydrated(function (TextInput $component): void {
                                $component->state(null);
                            }),
                    ]),
                Section::make(__('restic-backups::backups.pages.settings.sections.repository.title'))
                    ->columns(1)
                    ->schema([
                        TextInput::make('repository_prefix')
                            ->label(__('restic-backups::backups.pages.settings.sections.repository.path_label'))
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText(__('restic-backups::backups.pages.settings.sections.repository.path_helper')),
                        Textarea::make('restic_repository')
                            ->label(__('restic-backups::backups.pages.settings.sections.repository.uri_label'))
                            ->rows(2)
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('restic_password')
                            ->label(__('restic-backups::backups.pages.settings.sections.repository.password_label'))
                            ->required(fn(): bool => $this->normalizeScalar($this->record?->restic_password) === null)
                            ->password()
                            ->placeholder('******')
                            ->helperText(__('restic-backups::backups.pages.settings.sections.repository.password_helper'))
                            ->dehydrated(fn(string | null $state): bool => filled($state))
                            ->afterStateHydrated(function (TextInput $component): void {
                                $component->state(null);
                            }),
                    ]),
                Section::make(__('restic-backups::backups.pages.settings.sections.retention.title'))
                    ->description(__('restic-backups::backups.pages.settings.sections.retention.description'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('retention.keep_last')
                            ->label(__('restic-backups::backups.pages.settings.sections.retention.keep_last'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('retention.keep_daily')
                            ->label(__('restic-backups::backups.pages.settings.sections.retention.keep_daily'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('retention.keep_weekly')
                            ->label(__('restic-backups::backups.pages.settings.sections.retention.keep_weekly'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('retention.keep_monthly')
                            ->label(__('restic-backups::backups.pages.settings.sections.retention.keep_monthly'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('retention.keep_yearly')
                            ->label(__('restic-backups::backups.pages.settings.sections.retention.keep_yearly'))
                            ->numeric()
                            ->minValue(0),
                    ]),
                Section::make(__('restic-backups::backups.pages.settings.sections.schedule.title'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('schedule.enabled')
                            ->label(__('restic-backups::backups.pages.settings.sections.schedule.enabled'))
                            ->default(false),
                        TimePicker::make('schedule.daily_time')
                            ->label(__('restic-backups::backups.pages.settings.sections.schedule.daily_time'))
                            ->seconds(false),
                        TextInput::make('schedule.timezone')
                            ->label(__('restic-backups::backups.pages.settings.sections.schedule.timezone'))
                            ->placeholder(config('app.timezone')),
                    ]),
                Section::make(__('restic-backups::backups.pages.settings.sections.paths.title'))
                    ->description(__('restic-backups::backups.pages.settings.sections.paths.description'))
                    ->columns(1)
                    ->schema([
                        TextInput::make('project_root')
                            ->label(__('restic-backups::backups.pages.settings.sections.paths.project_root_label'))
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText(__('restic-backups::backups.pages.settings.sections.paths.project_root_helper')),
                        TagsInput::make('paths.include')
                            ->label(__('restic-backups::backups.pages.settings.sections.paths.include_label'))
                            ->helperText(__('restic-backups::backups.pages.settings.sections.paths.include_helper'))
                            ->placeholder(__('restic-backups::backups.pages.settings.sections.paths.include_placeholder'))
                            ->suggestions([
                                'storage/app',
                                'public',
                            ]),
                        TagsInput::make('paths.exclude')
                            ->label(__('restic-backups::backups.pages.settings.sections.paths.exclude_label'))
                            ->helperText(__('restic-backups::backups.pages.settings.sections.paths.exclude_helper'))
                            ->placeholder(__('restic-backups::backups.pages.settings.sections.paths.exclude_placeholder'))
                            ->suggestions([
                                'node_modules',
                                'storage/framework',
                                'storage/logs',
                                'bootstrap/cache',
                                'public/hot',
                            ]),
                        Actions::make([
                            Action::make('restoreExcludeDefaults')
                                ->label(__('restic-backups::backups.pages.settings.sections.paths.recommended_defaults'))
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
            ->label(__('restic-backups::backups.pages.settings.actions.save'))
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
                ->label(__('restic-backups::backups.pages.settings.actions.run_backup.label'))
                ->requiresConfirmation()
                ->modalHeading(__('restic-backups::backups.pages.settings.actions.run_backup.modal_heading'))
                ->modalDescription(__('restic-backups::backups.pages.settings.actions.run_backup.modal_description'))
                ->action(function (): void {
                    $lockInfo = app(OperationLock::class)->getInfo();

                    if (is_array($lockInfo)) {
                        $message = __('restic-backups::backups.pages.settings.notifications.operation_running');

                        if (! empty($lockInfo['type'])) {
                            $message .= ' ' . __('restic-backups::backups.pages.settings.notifications.operation_running_type', [
                                'type' => $lockInfo['type'],
                            ]);
                        }

                        if (! empty($lockInfo['run_id'])) {
                            $message .= ' ' . __('restic-backups::backups.pages.settings.notifications.operation_running_run_id', [
                                'run_id' => $lockInfo['run_id'],
                            ]);
                        }

                        Notification::make()
                            ->title(__('restic-backups::backups.pages.settings.notifications.operation_in_progress'))
                            ->body($message . ' ' . __('restic-backups::backups.pages.settings.notifications.backup_waits'))
                            ->warning()
                            ->send();
                    }

                    RunBackupJob::dispatch([], 'manual', null, true, auth()->id());

                    Notification::make()
                        ->success()
                        ->title(__('restic-backups::backups.pages.settings.notifications.backup_queued'))
                        ->body(__('restic-backups::backups.pages.settings.notifications.backup_queued_body'))
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
                ->title(__('restic-backups::backups.pages.settings.notifications.missing_credentials'))
                ->body(__('restic-backups::backups.pages.settings.notifications.missing_credentials_body'))
                ->warning()
                ->send();

            return;
        }

        $result = app(S3BucketsService::class)->listBuckets($endpoint, $accessKey, $secretKey);

        if (! $result['ok']) {
            $this->bucketOptions = [];
            $this->bucketManual = true;
            $this->bucketLoadError = $result['message'] ?? __('restic-backups::backups.pages.settings.notifications.bucket_listing_failed_body');

            Notification::make()
                ->title(__('restic-backups::backups.pages.settings.notifications.bucket_listing_failed'))
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
            ->title(__('restic-backups::backups.pages.settings.notifications.buckets_refreshed'))
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
                ->title(__('restic-backups::backups.pages.settings.notifications.confirmation_mismatch'))
                ->body(__('restic-backups::backups.pages.settings.notifications.confirmation_mismatch_body'))
                ->danger()
                ->send();

            return;
        }

        $settings = $this->record ??= BackupSetting::singleton();

        if (! $this->hasRepositoryConfig($settings)) {
            Notification::make()
                ->title(__('restic-backups::backups.pages.settings.notifications.repository_not_configured'))
                ->body(__('restic-backups::backups.pages.settings.notifications.repository_not_configured_body'))
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
                    ->title(__('restic-backups::backups.pages.settings.notifications.init_failed'))
                    ->body($this->sanitizeRepositoryMessage($result->stderr ?: $result->stdout, $settings))
                    ->danger()
                    ->send();

                return;
            }

            Notification::make()
                ->title(__('restic-backups::backups.pages.settings.notifications.repository_initialized'))
                ->success()
                ->send();

            $this->loadRepositoryStatus(force: true);
        } catch (Throwable $exception) {
            Notification::make()
                ->title(__('restic-backups::backups.pages.settings.notifications.init_failed'))
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
                'message' => __('restic-backups::backups.pages.settings.repository_status.messages.not_configured'),
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
                    'message' => __('restic-backups::backups.pages.settings.repository_status.messages.available'),
                    'snapshots_count' => count($result->parsedJson),
                ];
            }

            $message = $this->sanitizeRepositoryMessage($result->stderr ?: $result->stdout, $settings);

            if ($this->resticNotInitialized($message)) {
                return [
                    'status' => 'not_initialized',
                    'message' => __('restic-backups::backups.pages.settings.repository_status.messages.not_initialized'),
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
            'ok' => __('restic-backups::backups.pages.settings.repository_status.labels.available'),
            'not_initialized' => __('restic-backups::backups.pages.settings.repository_status.labels.not_initialized'),
            'not_configured' => __('restic-backups::backups.pages.settings.repository_status.labels.not_configured'),
            default => __('restic-backups::backups.pages.settings.repository_status.labels.error'),
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
            return __('restic-backups::backups.pages.settings.errors.unknown_error');
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
