<?php

namespace App\Domain\Billing;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * PDF invoice and PDF work report (DomPDF: pure PHP, no browser on the server).
 * The invoice PDF is rendered once at finalize and stored; downloads of a
 * finalized invoice always serve that stored copy. Drafts render live with a
 * DRAFT watermark. Clients receive these files from Finance; the app sends no email.
 */
class InvoicePdf
{
    public function __construct(private readonly WorkReport $workReport) {}

    public function invoice(Invoice $invoice): string
    {
        // withTrashed: an archived (soft-deleted) client's invoices must still render.
        $invoice->loadMissing(['items', 'workspace' => fn ($q) => $q->withTrashed()->with('billingProfile')]);
        // The workspace may already be loaded without its profile (lazy loading is off).
        $profile = $invoice->workspace->loadMissing('billingProfile')->billingProfile;

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'company' => config('billing.company'),
            'paymentInstructions' => $profile?->payment_instructions,
            'draft' => $invoice->isDraft(),
        ])->setPaper('a4')->output();
    }

    public function workReport(Invoice $invoice): string
    {
        $invoice->loadMissing(['workspace' => fn ($q) => $q->withTrashed()]);

        return $this->renderWorkReport([
            'title' => 'Work report',
            'client' => $invoice->bill_to['name'] ?? $invoice->workspace->name,
            'period' => $invoice->period_start !== null && $invoice->period_end !== null
                ? $invoice->period_start->format('M j').' – '.$invoice->period_end->format('M j, Y') : null,
            'ref' => 'For invoice '.$invoice->displayNumber(),
        ], $this->workReport->build($invoice), $invoice->isDraft());
    }

    /**
     * Same layout for the invoice work report and the Work Summary export.
     *
     * @param  array{title: string, client: string, period: string|null, ref: string}  $meta
     * @param  array<string, mixed>  $report  WorkReport::build() / forPeriod() result
     */
    public function renderWorkReport(array $meta, array $report, bool $draft = false): string
    {
        return Pdf::loadView('pdf.work-report', [
            'meta' => $meta,
            'company' => config('billing.company'),
            'report' => $report,
            'draft' => $draft,
        ])->setPaper('a4')->output();
    }

    /**
     * Render and keep the finalized invoice PDF (called at finalize).
     */
    public function store(Invoice $invoice): string
    {
        $path = sprintf('invoices/%d/%s.pdf', $invoice->workspace_id, $invoice->displayNumber());
        Storage::disk((string) config('billing.disk'))->put($path, $this->invoice($invoice));
        $invoice->forceFill(['pdf_path' => $path])->save();

        return $path;
    }

    /**
     * The bytes to download: the stored copy for finalized invoices, a live render otherwise.
     */
    public function download(Invoice $invoice): string
    {
        $disk = Storage::disk((string) config('billing.disk'));

        if (! $invoice->isDraft() && $invoice->pdf_path !== null && $disk->exists($invoice->pdf_path)) {
            return (string) $disk->get($invoice->pdf_path);
        }
        if (! $invoice->isDraft()) {
            $this->store($invoice);

            return (string) $disk->get((string) $invoice->pdf_path);
        }

        return $this->invoice($invoice);
    }
}
