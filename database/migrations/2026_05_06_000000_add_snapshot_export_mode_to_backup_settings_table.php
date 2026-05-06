<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('backup_settings') || Schema::hasColumn('backup_settings', 'snapshot_export_mode')) {
            return;
        }

        Schema::table('backup_settings', function (Blueprint $table): void {
            $table->string('snapshot_export_mode')->default('auto')->after('paths');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('backup_settings') || ! Schema::hasColumn('backup_settings', 'snapshot_export_mode')) {
            return;
        }

        Schema::table('backup_settings', function (Blueprint $table): void {
            $table->dropColumn('snapshot_export_mode');
        });
    }
};
