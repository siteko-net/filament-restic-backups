<?php

return [
    'enabled' => env('RESTIC_BACKUPS_ENABLED', true),

    // Filament panel ID that should register the plugin.
    'panel' => env('RESTIC_BACKUPS_PANEL', 'admin'),

    'navigation' => [
        // Set to null to use translations.
        'group_label' => null,
        'icon' => 'heroicon-o-archive-box',
        'sort' => 30,
    ],

    'paths' => [
        'project_root' => base_path(),
        'work_dir' => storage_path('app/_backup'),
    ],

    'restic' => [
        'binary' => env('RESTIC_BINARY', 'restic'),
        'cache_dir' => storage_path('app/_restic_cache'),
        'retry_lock' => env('RESTIC_BACKUPS_RETRY_LOCK', '10m'),
        'auto_unlock_stale' => env('RESTIC_BACKUPS_AUTO_UNLOCK_STALE', false),
        'stale_lock_age_seconds' => env('RESTIC_BACKUPS_STALE_LOCK_AGE_SECONDS', 3600),
    ],

    's3' => [
        'region' => env('RESTIC_BACKUPS_S3_REGION', 'us-east-1'),
    ],

    'exports' => [
        'snapshot_mode' => env('RESTIC_BACKUPS_SNAPSHOT_EXPORT_MODE', 'auto'),
        's3_prefix' => env('RESTIC_BACKUPS_EXPORTS_S3_PREFIX'),
        'multipart_chunk_bytes' => env('RESTIC_BACKUPS_EXPORTS_MULTIPART_CHUNK_BYTES', 8 * 1024 * 1024),
    ],

    'security' => [
        // When empty, any authenticated Filament user can access the pages.
        'permissions' => [],
        'require_confirmation_phrase' => false,
    ],

    'database' => [
        // Tables that must never be dropped during restore operations.
        'preserve_tables' => [
            'backup_runs',
            'backup_settings',
        ],
        // Tables that should be excluded from database dumps.
        'exclude_from_dumps' => [
            'backup_runs',
            'backup_settings',
        ],
    ],

    'locks' => [
        // Cache store used for operation locks and heartbeats.
        'store' => env('RESTIC_BACKUPS_LOCK_STORE', 'file'),
    ],
];
