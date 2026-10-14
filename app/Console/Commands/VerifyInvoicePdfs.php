<?php

namespace App\Console\Commands;

use App\Domain\Billing\InvoicePdf;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * E-86: restore drill / backup check. Every finalized invoice and credit note must have
 * its stored PDF. --fix re-renders a missing one from the invoice's own (unchangeable)
 * data; company details come from today's settings, so restore from backup when you can.
 */
class VerifyInvoicePdfs extends Command
{
    /** @var string */
    protected $signature = 'billing:verify-pdfs {--fix : Re-render missing PDFs from the invoice data}';

    /** @var string */
    protected $description = 'Check that every finalized invoice has its stored PDF (backup / restore drill)';

    public function handle(InvoicePdf $pdf): int
    {
        $disk = Storage::disk((string) config('billing.disk'));
        $checked = 0;
        $missing = [];

        Invoice::query()->where('status', '!=', Invoice::DRAFT)->orderBy('id')
            ->lazyById()
            ->each(function (Invoice $invoice) use ($disk, &$checked, &$missing) {
                $checked++;
                if ($invoice->pdf_path === null || ! $disk->exists($invoice->pdf_path)) {
                    $missing[] = $invoice;
                }
            });

        foreach ($missing as $invoice) {
            $this->line(sprintf('Missing: %s (id %d)', $invoice->displayNumber(), $invoice->id));
            if ($this->option('fix')) {
                $pdf->store($invoice);
                $this->line('  re-rendered');
            }
        }

        $this->info(sprintf('%d finalized document(s) checked, %d missing%s. Billing records are kept %d years (config billing.retention_years).',
            $checked, count($missing), $this->option('fix') && $missing !== [] ? ' (re-rendered)' : '', (int) config('billing.retention_years')));

        return $missing === [] || $this->option('fix') ? self::SUCCESS : self::FAILURE;
    }
}
