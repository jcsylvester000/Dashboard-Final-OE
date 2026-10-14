<?php

namespace App\Console\Commands;

use App\Domain\Work\PreviewChecks;
use Illuminate\Console\Command;

/**
 * E-26: refresh the up/down status of preview links on open tasks.
 */
class CheckPreviewLinks extends Command
{
    /** @var string */
    protected $signature = 'previews:check';

    /** @var string */
    protected $description = 'Check preview URLs on open tasks and alert assignees when one breaks';

    public function handle(PreviewChecks $checks): int
    {
        $this->info('Checked '.$checks->checkAll().' preview link(s).');

        return self::SUCCESS;
    }
}
