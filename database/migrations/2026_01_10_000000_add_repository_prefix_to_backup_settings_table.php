<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('backup_settings')) {
            return;
        }

        if (Schema::hasColumn('backup_settings', 'repository_prefix')) {
            return;
        }

        Schema::table('backup_settings', function (Blueprint $table): void {
            $table->string('repository_prefix')->nullable()->after('prefix');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('backup_settings')) {
            return;
        }

        if (! Schema::hasColumn('backup_settings', 'repository_prefix')) {
            return;
        }

        Schema::table('backup_settings', function (Blueprint $table): void {
            $table->dropColumn('repository_prefix');
        });
    }
};
