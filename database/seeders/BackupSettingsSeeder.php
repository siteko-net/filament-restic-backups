<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Siteko\FilamentResticBackups\Models\BackupSetting;

class BackupSettingsSeeder extends Seeder
{
    public function run(): void
    {
        if (BackupSetting::query()->exists()) {
            return;
        }

        BackupSetting::query()->create(BackupSetting::defaultAttributes());
    }
}
