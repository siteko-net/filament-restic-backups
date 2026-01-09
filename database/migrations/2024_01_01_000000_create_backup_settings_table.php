<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('endpoint')->nullable();
            $table->string('bucket')->nullable();
            $table->string('prefix')->nullable();
            $table->text('access_key')->nullable();
            $table->text('secret_key')->nullable();
            $table->text('restic_repository')->nullable();
            $table->text('restic_password')->nullable();
            $table->json('retention')->nullable();
            $table->json('schedule')->nullable();
            $table->json('paths')->nullable();
            $table->string('project_root')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_settings');
    }
};
