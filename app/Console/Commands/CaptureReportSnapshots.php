<?php

namespace App\Console\Commands;

use App\Domain\Reports\SnapshotService;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Mondays (routes/console.php): save last week's report snapshot for every active workspace.
 */
class CaptureReportSnapshots extends Command
{
    /** @var string */
    protected $signature = 'reports:snapshot {--week= : Any date in the week to capture (default: last week)}';

    /** @var string */
    protected $description = 'Save the weekly report snapshot for each active workspace';

    public function handle(SnapshotService $snapshots): int
    {
        $week = $this->option('week');
        $start = is_string($week) && $week !== '' ? CarbonImmutable::parse($week) : SnapshotService::lastWeek();

        $count = 0;
        Workspace::query()->where('status', '!=', 'archived')->orderBy('id')->each(function (Workspace $workspace) use ($snapshots, $start, &$count) {
            $snapshots->capture($workspace, $start);
            $count++;
        });

        $this->info("Saved {$count} snapshot(s) for the week of {$start->startOfWeek(CarbonImmutable::MONDAY)->toDateString()}.");

        return self::SUCCESS;
    }
}
