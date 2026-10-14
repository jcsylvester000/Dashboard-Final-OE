<?php

namespace App\Console\Commands;

use App\Domain\Files\Video\VideoUploads;
use Illuminate\Console\Command;

/**
 * E-11: abort video uploads left unfinished (closed tab, lost connection) so they
 * stop holding the client's video quota and storage.
 */
class PruneVideoUploads extends Command
{
    /** @var string */
    protected $signature = 'video:prune-uploads';

    /** @var string */
    protected $description = 'Abort video uploads left unfinished for longer than video.abandon_hours';

    public function handle(VideoUploads $uploads): int
    {
        $count = $uploads->pruneAbandoned();
        $this->info("Aborted {$count} unfinished video upload(s).");

        return self::SUCCESS;
    }
}
