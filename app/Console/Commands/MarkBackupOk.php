<?php

namespace App\Console\Commands;

use App\Domain\System\HealthChecks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * E-96: the backup script calls this after a successful backup, so System health can
 * show when the last one ran.
 */
class MarkBackupOk extends Command
{
    /** @var string */
    protected $signature = 'health:backup-ok';

    /** @var string */
    protected $description = 'Record that a backup just finished successfully';

    public function handle(): int
    {
        Cache::forever(HealthChecks::BACKUP_KEY, now()->toIso8601String());
        $this->info('Backup time recorded.');

        return self::SUCCESS;
    }
}
