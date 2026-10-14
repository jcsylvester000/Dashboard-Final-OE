<?php

namespace App\Console\Commands;

use App\Domain\Reports\TimeAggregates;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * E-129: nightly check that the daily time aggregates equal the raw entries; any (person, day)
 * that differs is rebuilt. --all rebuilds everything (first install, restores).
 */
class AggregateTime extends Command
{
    /** @var string */
    protected $signature = 'reports:aggregate {--days=62 : How many days back to check} {--all : Rebuild every day}';

    /** @var string */
    protected $description = 'Check and repair the pre-computed daily time totals used by reports';

    public function handle(TimeAggregates $aggregates): int
    {
        if ($this->option('all')) {
            $rows = $aggregates->rebuild();
            $this->info("Rebuilt all daily time totals ({$rows} rows).");

            return self::SUCCESS;
        }

        $to = CarbonImmutable::today();
        $from = $to->subDays(max(1, (int) $this->option('days')));
        $drift = $aggregates->drift($from, $to);
        $aggregates->refresh($drift);
        $this->info(count($drift) === 0
            ? 'Daily time totals match the entries.'
            : sprintf('Repaired %d person-day total(s) that did not match the entries.', count($drift)));
        if ($drift !== []) {
            report(new \RuntimeException(sprintf('reports:aggregate repaired %d drifted person-day slices', count($drift))));
        }

        return self::SUCCESS;
    }
}
