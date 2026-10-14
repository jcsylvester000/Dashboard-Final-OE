<?php

namespace App\Jobs;

use App\Domain\Billing\InvoicePdf;
use App\Models\Invoice;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * E-128: render and keep a finalized invoice's PDF in the background, on its own queue
 * (config billing.pdf_queue) so PDFs never hold up alerts. Downloads still work before it
 * runs: the first download renders and stores the PDF itself.
 */
class StoreInvoicePdf implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $invoiceId)
    {
        $this->onQueue((string) config('billing.pdf_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->invoiceId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(InvoicePdf $pdf): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);
        if ($invoice === null || $invoice->isDraft()) {
            return;
        }
        if ($invoice->pdf_path !== null && Storage::disk((string) config('billing.disk'))->exists($invoice->pdf_path)) {
            return;
        }
        $pdf->store($invoice);
    }
}
