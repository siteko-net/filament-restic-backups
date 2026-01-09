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
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Livewire\Attributes\Locked;
use Siteko\FilamentResticBackups\Jobs\RunBackupJob;
use Siteko\FilamentResticBackups\Models\BackupSetting;
use Throwable;

class BackupsSettings extends BaseBackupsPage
{
    use CanUseDatabaseTransactions;

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    #[Locked]
    public ?BackupSetting $record = null;

    protected static ?string $slug = 'backups/settings';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Backup Settings';

    public static function getNavigationSort(): ?int
    {
        return static::baseNavigationSort() + 1;
    }

    public function mount(): void
    {
        $this->record = BackupSetting::singleton();
        $this->fillForm();
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

        $data['project_root'] = $this->normalizeScalar($data['project_root'] ?? null)
            ?? $this->normalizeScalar(config('restic-backups.paths.project_root', base_path()))
            ?? base_path();

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
                Section::make('Storage (S3)')
                    ->description('Configure S3-compatible storage for restic.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('endpoint')
                            ->label('Endpoint')
                            ->placeholder('https://s3.example.com')
                            ->url()
                            ->nullable(),
                        TextInput::make('bucket')
                            ->label('Bucket')
                            ->rule('regex:/^\\S+$/')
                            ->nullable(),
                        TextInput::make('prefix')
                            ->label('Prefix')
                            ->helperText('Optional folder/prefix inside the bucket.')
                            ->nullable(),
                        TextInput::make('access_key')
                            ->label('Access key')
                            ->password()
                            ->placeholder('******')
                            ->dehydrated(fn (string | null $state): bool => filled($state))
                            ->afterStateHydrated(function (TextInput $component): void {
                                $component->state(null);
                            }),
                        TextInput::make('secret_key')
                            ->label('Secret key')
                            ->password()
                            ->placeholder('******')
                            ->dehydrated(fn (string | null $state): bool => filled($state))
                            ->afterStateHydrated(function (TextInput $component): void {
                                $component->state(null);
                            }),
                    ]),
                Section::make('Restic repository')
                    ->columns(1)
                    ->schema([
                        Textarea::make('restic_repository')
                            ->label('Repository')
                            ->rows(2)
                            ->nullable(),
                        TextInput::make('restic_password')
                            ->label('Repository password')
                            ->password()
                            ->placeholder('******')
                            ->dehydrated(fn (string | null $state): bool => filled($state))
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
                    ->description('If include is empty, restic backs up the entire project_root.')
                    ->columns(1)
                    ->schema([
                        TextInput::make('project_root')
                            ->label('Project root')
                            ->helperText('Base path for backup.')
                            ->nullable(),
                        TagsInput::make('paths.include')
                            ->label('Include paths')
                            ->placeholder('/var/www/project')
                            ->suggestions([
                                'storage/app',
                                'public',
                            ]),
                        TagsInput::make('paths.exclude')
                            ->label('Exclude paths')
                            ->placeholder('vendor')
                            ->suggestions([
                                'vendor',
                                'node_modules',
                                'storage/logs',
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
