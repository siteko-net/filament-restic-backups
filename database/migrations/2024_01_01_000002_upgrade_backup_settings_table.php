<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('backup_settings')) {
            return;
        }

        $this->addMissingColumns();
        $this->migrateLegacyData();
    }

    public function down(): void
    {
        if (! Schema::hasTable('backup_settings')) {
            return;
        }

        $columns = [
            'endpoint',
            'bucket',
            'prefix',
            'access_key',
            'secret_key',
            'restic_repository',
            'restic_password',
            'retention',
            'schedule',
            'paths',
            'project_root',
        ];

        $columnsToDrop = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn('backup_settings', $column),
        ));

        if ($columnsToDrop === []) {
            return;
        }

        Schema::table('backup_settings', function (Blueprint $table) use ($columnsToDrop): void {
            $table->dropColumn($columnsToDrop);
        });
    }

    protected function addMissingColumns(): void
    {
        $columns = [
            'endpoint' => function (Blueprint $table): void {
                $table->string('endpoint')->nullable();
            },
            'bucket' => function (Blueprint $table): void {
                $table->string('bucket')->nullable();
            },
            'prefix' => function (Blueprint $table): void {
                $table->string('prefix')->nullable();
            },
            'access_key' => function (Blueprint $table): void {
                $table->text('access_key')->nullable();
            },
            'secret_key' => function (Blueprint $table): void {
                $table->text('secret_key')->nullable();
            },
            'restic_repository' => function (Blueprint $table): void {
                $table->text('restic_repository')->nullable();
            },
            'restic_password' => function (Blueprint $table): void {
                $table->text('restic_password')->nullable();
            },
            'retention' => function (Blueprint $table): void {
                $table->json('retention')->nullable();
            },
            'schedule' => function (Blueprint $table): void {
                $table->json('schedule')->nullable();
            },
            'paths' => function (Blueprint $table): void {
                $table->json('paths')->nullable();
            },
            'project_root' => function (Blueprint $table): void {
                $table->string('project_root')->nullable();
            },
        ];

        foreach ($columns as $name => $definition) {
            if (Schema::hasColumn('backup_settings', $name)) {
                continue;
            }

            Schema::table('backup_settings', function (Blueprint $table) use ($definition): void {
                $definition($table);
            });
        }
    }

    protected function migrateLegacyData(): void
    {
        if (! Schema::hasColumn('backup_settings', 'data')) {
            return;
        }

        $rows = DB::table('backup_settings')
            ->select(
                'id',
                'data',
                'endpoint',
                'bucket',
                'prefix',
                'access_key',
                'secret_key',
                'restic_repository',
                'restic_password',
                'retention',
                'schedule',
                'paths',
                'project_root',
            )
            ->whereNotNull('data')
            ->get();

        foreach ($rows as $row) {
            $data = json_decode($row->data, true);

            if (! is_array($data)) {
                continue;
            }

            $paths = $data['paths'] ?? data_get($data, 'restic.paths');

            if ($paths === null && (array_key_exists('include', $data) || array_key_exists('exclude', $data))) {
                $paths = [
                    'include' => $data['include'] ?? [],
                    'exclude' => $data['exclude'] ?? [],
                ];
            }

            $update = [];

            if ($this->isEmptyValue($row->endpoint)) {
                $value = $this->normalizeScalar($data['endpoint'] ?? data_get($data, 's3.endpoint'));
                if ($value !== null) {
                    $update['endpoint'] = $value;
                }
            }

            if ($this->isEmptyValue($row->bucket)) {
                $value = $this->normalizeScalar($data['bucket'] ?? data_get($data, 's3.bucket'));
                if ($value !== null) {
                    $update['bucket'] = $value;
                }
            }

            if ($this->isEmptyValue($row->prefix)) {
                $value = $this->normalizeScalar($data['prefix'] ?? data_get($data, 's3.prefix'));
                if ($value !== null) {
                    $update['prefix'] = $value;
                }
            }

            if ($this->isEmptyValue($row->access_key)) {
                $value = $this->normalizeSecret($data['access_key'] ?? data_get($data, 's3.access_key'));
                if ($value !== null) {
                    $update['access_key'] = $value;
                }
            }

            if ($this->isEmptyValue($row->secret_key)) {
                $value = $this->normalizeSecret($data['secret_key'] ?? data_get($data, 's3.secret_key'));
                if ($value !== null) {
                    $update['secret_key'] = $value;
                }
            }

            if ($this->isEmptyValue($row->restic_repository)) {
                $value = $this->normalizeScalar(
                    $data['restic_repository']
                        ?? data_get($data, 'restic.repository')
                        ?? $data['repository']
                        ?? null,
                );
                if ($value !== null) {
                    $update['restic_repository'] = $value;
                }
            }

            if ($this->isEmptyValue($row->restic_password)) {
                $value = $this->normalizeSecret($data['restic_password'] ?? data_get($data, 'restic.password'));
                if ($value !== null) {
                    $update['restic_password'] = $value;
                }
            }

            if ($row->retention === null) {
                $value = $this->normalizeJsonValue($data['retention'] ?? data_get($data, 'restic.retention'));
                if ($value !== null) {
                    $update['retention'] = $value;
                }
            }

            if ($row->schedule === null) {
                $value = $this->normalizeJsonValue($data['schedule'] ?? data_get($data, 'restic.schedule'));
                if ($value !== null) {
                    $update['schedule'] = $value;
                }
            }

            if ($row->paths === null) {
                $value = $this->normalizeJsonValue($paths);
                if ($value !== null) {
                    $update['paths'] = $value;
                }
            }

            if ($this->isEmptyValue($row->project_root)) {
                $value = $this->normalizeScalar($data['project_root'] ?? data_get($data, 'paths.project_root'));
                if ($value !== null) {
                    $update['project_root'] = $value;
                }
            }

            if ($update === []) {
                continue;
            }

            DB::table('backup_settings')
                ->where('id', $row->id)
                ->update($update);
        }
    }

    protected function normalizeScalar(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return null;
    }

    protected function normalizeSecret(mixed $value): ?string
    {
        $value = $this->normalizeScalar($value);

        if ($value === null) {
            return null;
        }

        return Crypt::encryptString($value);
    }

    protected function normalizeJsonValue(mixed $value): ?string
    {
        if (is_array($value)) {
            $encoded = json_encode($value);

            return $encoded === false ? null : $encoded;
        }

        if (! is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            return null;
        }

        $encoded = json_encode($decoded);

        return $encoded === false ? null : $encoded;
    }

    protected function isEmptyValue(mixed $value): bool
    {
        return $value === null || $value === '';
    }
};
