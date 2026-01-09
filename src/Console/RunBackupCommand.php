<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Console;

use Illuminate\Console\Command;
use Siteko\FilamentResticBackups\Jobs\RunBackupJob;

class RunBackupCommand extends Command
{
    protected $signature = 'restic-backups:run
        {--tags= : Comma-separated tags to attach to the snapshot}
        {--trigger=manual : Trigger type (manual|schedule|system)}
        {--connection= : Database connection name}
        {--sync : Run synchronously instead of dispatching to the queue}';

    protected $description = 'Run a restic backup with database dump and retention policy.';

    public function handle(): int
    {
        $tags = $this->parseTags($this->option('tags'));
        $trigger = $this->option('trigger');
        $connection = $this->option('connection');
        $sync = (bool) $this->option('sync');

        if ($sync) {
            RunBackupJob::dispatchSync($tags, $trigger, $connection, true);
            $this->info('Backup job executed synchronously.');

            return self::SUCCESS;
        }

        RunBackupJob::dispatch($tags, $trigger, $connection, true);
        $this->info('Backup job dispatched to queue.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function parseTags(mixed $tagsOption): array
    {
        if (! is_string($tagsOption) || trim($tagsOption) === '') {
            return [];
        }

        $tags = array_map('trim', explode(',', $tagsOption));

        return array_values(array_filter($tags, static fn (string $tag): bool => $tag !== ''));
    }
}
