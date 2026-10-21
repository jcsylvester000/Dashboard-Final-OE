<?php

namespace App\Console\Commands;

use App\Jobs\GenerateReportPack;
use App\Models\ReportPack;
use Illuminate\Console\Command;

/**
 * E-51: queue every enabled report pack that is due today (or overdue).
 */
class RunReportPacks extends Command
{
    /** @var string */
    protected $signature = 'reports:packs';

    /** @var string */
    protected $description = 'Generate scheduled client report packs that are due';

    public function handle(): int
    {
        $count = 0;
        ReportPack::query()->where('enabled', true)->whereDate('next_run_on', '<=', now()->toDateString())->orderBy('id')
            ->each(function (ReportPack $pack) use (&$count) {
                GenerateReportPack::dispatch($pack->id);
                $count++;
            });
        $this->info("Queued {$count} report pack(s).");

        return self::SUCCESS;
    }
}
