<?php

namespace App\Jobs;

use App\Domain\Documents\ReportPacks;
use App\Models\ReportPack;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * E-51: build one scheduled report pack on the PDF queue. A failure is listed under
 * Admin > Failed jobs and the pack stays due, so it is tried again the next day.
 */
class GenerateReportPack implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public readonly int $packId)
    {
        $this->onQueue((string) config('billing.pdf_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->packId;
    }

    public function handle(ReportPacks $packs): void
    {
        $pack = ReportPack::query()->find($this->packId);
        if ($pack !== null && $pack->enabled) {
            $packs->run($pack);
        }
    }
}
