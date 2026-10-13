<?php

namespace App\Console\Commands;

use App\Domain\Files\DriveService;
use Illuminate\Console\Command;

/**
 * Hourly (routes/console.php): uploads that were started but never finished
 * free their reserved quota and are removed from UploadThing.
 */
class PruneDriveUploads extends Command
{
    /** @var string */
    protected $signature = 'drive:prune';

    /** @var string */
    protected $description = 'Remove abandoned drive uploads and free their quota';

    public function handle(DriveService $drive): int
    {
        $this->info($drive->pruneStale().' abandoned upload(s) removed.');

        return self::SUCCESS;
    }
}
