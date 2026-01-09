<?php

namespace Siteko\FilamentResticBackups\Models;

use Illuminate\Database\Eloquent\Model;

class BackupRun extends Model
{
    protected $table = 'backup_runs';

    protected $fillable = [
        'type',
        'status',
        'started_at',
        'finished_at',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
