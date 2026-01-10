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
                        ->label('Run backup now')
                        ->icon('heroicon-o-play')
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
                    Action::make('openRuns')
                        ->label('Open Runs')
                        ->icon('heroicon-o-list-bullet')
                        ->url(BackupsRuns::getUrl()),
                    Action::make('openSnapshots')
                        ->label('Open Snapshots')
                        ->icon('heroicon-o-rectangle-stack')
                        ->url(BackupsSnapshots::getUrl()),
                    Action::make('openSettings')
                        ->label('Open Settings')
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
                ->label('Refresh')
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
