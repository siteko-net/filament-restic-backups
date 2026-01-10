<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Console;

use Illuminate\Console\Command;
use Siteko\FilamentResticBackups\Support\OperationLock;

class UnlockOperationCommand extends Command
{
    protected $signature = 'restic-backups:unlock
        {--force : Skip confirmation}
        {--stale : Only unlock if the heartbeat looks stale}
        {--stale-seconds=900 : Stale threshold in seconds}';

    protected $description = 'Force release the restic-backups operation lock.';

    public function handle(OperationLock $operationLock): int
    {
        $info = $operationLock->getInfo();
        $staleSeconds = (int) $this->option('stale-seconds');
        $isStale = $info !== null ? $operationLock->isStale($staleSeconds) : null;

        if (is_array($info)) {
            $this->line('Current lock info:');
            $this->line('  Type: ' . ($info['type'] ?? 'n/a'));
            $this->line('  Run ID: ' . ($info['run_id'] ?? 'n/a'));
            $this->line('  Started: ' . ($info['started_at'] ?? 'n/a'));
            $this->line('  Heartbeat: ' . ($info['last_heartbeat_at'] ?? 'n/a'));
            $this->line('  Host: ' . ($info['hostname'] ?? 'n/a'));
            $this->line('  PID: ' . ($info['pid'] ?? 'n/a'));
            $this->line('  Expires: ' . ($info['expires_at'] ?? 'n/a'));
            if ($isStale === true) {
                $this->warn('Heartbeat looks stale.');
            }
        } else {
            $this->info('No active lock info found.');
        }

        if ($this->option('stale') && $isStale !== true) {
            $this->warn('Lock is not stale. Skipping unlock.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $confirm = trim((string) $this->ask('Type UNLOCK to confirm'));

            if (strtoupper($confirm) !== 'UNLOCK' && strtolower($confirm) !== 'yes') {
                $this->info('Aborted.');

                return self::SUCCESS;
            }
        }

        if ($operationLock->forceRelease()) {
            $this->info('Lock released.');

            return self::SUCCESS;
        }

        $this->error('Failed to release lock.');

        return self::FAILURE;
    }
}
