<?php

namespace Siteko\FilamentResticBackups\Filament\Pages;

use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

class BackupsDashboard extends BaseBackupsPage
{
    protected static ?string $slug = 'backups';

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Backups';

    public static function getNavigationSort(): ?int
    {
        return static::baseNavigationSort();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Text::make('TODO'),
            ]);
    }
}
