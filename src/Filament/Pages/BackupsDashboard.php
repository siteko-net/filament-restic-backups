<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions as ActionsComponent;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\View as ViewComponent;
use Siteko\FilamentResticBackups\Jobs\RunBackupJob;
use Siteko\FilamentResticBackups\Support\BackupsOverview;
use Siteko\FilamentResticBackups\Support\OperationLock;

class BackupsDashboard extends BaseBackupsPage
{
    /**
     * @var array<string, mixed> | null
     */
    public ?array $overview = null;

    protected static ?string $slug = 'backups';

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Backups';


    public static function getNavigationSort(): ?int
    {
        return static::baseNavigationSort();
    }
    public static function getNavigationLabel(): string
    {
        return __('restic-backups::backups.pages.dashboard.navigation_label');
    }

    public function getTitle(): string
    {
        return __('restic-backups::backups.pages.dashboard.title');
    }

    public function mount(): void
    {
        $this->loadOverview();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                ActionsComponent::make([
                    Action::make('runBackup')
                        ->label(__('restic-backups::backups.pages.dashboard.actions.run_backup.label'))
                        ->icon('heroicon-o-play')
                        ->requiresConfirmation()
                        ->modalHeading(__('restic-backups::backups.pages.dashboard.actions.run_backup.modal_heading'))
                        ->modalDescription(__('restic-backups::backups.pages.dashboard.actions.run_backup.modal_description'))
                        ->action(function (): void {
                            $lockInfo = app(OperationLock::class)->getInfo();

                            if (is_array($lockInfo)) {
                                $message = __('restic-backups::backups.pages.dashboard.notifications.operation_running');

                                if (! empty($lockInfo['type'])) {
                                    $message .= ' ' . __('restic-backups::backups.pages.dashboard.notifications.operation_running_type', [
                                        'type' => $lockInfo['type'],
                                    ]);
                                }

                                if (! empty($lockInfo['run_id'])) {
                                    $message .= ' ' . __('restic-backups::backups.pages.dashboard.notifications.operation_running_run_id', [
                                        'run_id' => $lockInfo['run_id'],
                                    ]);
                                }

                                Notification::make()
                                    ->title(__('restic-backups::backups.pages.dashboard.notifications.operation_in_progress'))
                                    ->body($message . ' ' . __('restic-backups::backups.pages.dashboard.notifications.backup_waits'))
                                    ->warning()
                                    ->send();
                            }

                            RunBackupJob::dispatch([], 'manual', null, true, auth()->id());

                            Notification::make()
                                ->success()
                                ->title(__('restic-backups::backups.pages.dashboard.notifications.backup_queued'))
                                ->body(__('restic-backups::backups.pages.dashboard.notifications.backup_queued_body'))
                                ->send();
                        }),
                    Action::make('openRuns')
                        ->label(__('restic-backups::backups.pages.dashboard.actions.open_runs'))
                        ->icon('heroicon-o-list-bullet')
                        ->url(BackupsRuns::getUrl()),
                    Action::make('openSnapshots')
                        ->label(__('restic-backups::backups.pages.dashboard.actions.open_snapshots'))
                        ->icon('heroicon-o-rectangle-stack')
                        ->url(BackupsSnapshots::getUrl()),
                    Action::make('openSettings')
                        ->label(__('restic-backups::backups.pages.dashboard.actions.open_settings'))
                        ->icon('heroicon-o-cog-6-tooth')
                        ->url(BackupsSettings::getUrl()),
                ]),
                ViewComponent::make('restic-backups::overview')
                    ->viewData([
                        'overview' => $this->overview ?? [],
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
                ->label(__('restic-backups::backups.pages.dashboard.header_actions.refresh'))
                ->icon('heroicon-o-arrow-path')
                ->action('refreshOverview'),
        ];
    }

    public function refreshOverview(): void
    {
        $this->loadOverview(force: true);
    }

    protected function loadOverview(bool $force = false): void
    {
        $this->overview = app(BackupsOverview::class)->get($force);
    }
}
