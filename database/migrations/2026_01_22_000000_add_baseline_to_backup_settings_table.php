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

        $needsSnapshot = ! Schema::hasColumn('backup_settings', 'baseline_snapshot_id');
        $needsCreatedAt = ! Schema::hasColumn('backup_settings', 'baseline_created_at');

        if (! $needsSnapshot && ! $needsCreatedAt) {
            return;
        }

        Schema::table('backup_settings', function (Blueprint $table) use ($needsSnapshot, $needsCreatedAt): void {
            if ($needsSnapshot) {
                $table->string('baseline_snapshot_id')->nullable()->after('project_root');
            }

            if ($needsCreatedAt) {
                $table->timestamp('baseline_created_at')->nullable()->after('baseline_snapshot_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('backup_settings')) {
            return;
        }

        Schema::table('backup_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('backup_settings', 'baseline_created_at')) {
                $table->dropColumn('baseline_created_at');
            }

            if (Schema::hasColumn('backup_settings', 'baseline_snapshot_id')) {
                $table->dropColumn('baseline_snapshot_id');
            }
        });
    }
};
