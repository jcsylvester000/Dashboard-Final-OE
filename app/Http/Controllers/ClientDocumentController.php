<?php

namespace App\Http\Controllers;

use App\Domain\Billing\InvoicePdf;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\StatementService;
use App\Domain\Settings\CompanyProfile;
use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\Workspace;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Client links, no login (E-70 invoice, E-69 statement). Token-only, throttled, noindex,
 * no-referrer. Unknown, revoked or expired links and void invoices are a plain 404 so
 * they can't be probed. Shows only that one invoice or that client's statement.
 */
class ClientDocumentController extends Controller
{
    public function __construct(private readonly CompanyProfile $company) {}

    public function invoice(string $token, InvoiceService $invoices): View
    {
        $invoice = $this->invoiceFor($token);

        // First view goes on the invoice history: proof the client opened it.
        if ($invoice->share_first_viewed_at === null) {
            $invoice->forceFill(['share_first_viewed_at' => now()])->save();
            $invoices->history($invoice, $invoice->status, $invoice->status, 'Opened by the client (link)', null);
        }
        Invoice::query()->whereKey($invoice->id)->update(['share_views' => DB::raw('share_views + 1')]);

        $profile = $invoice->workspace->loadMissing('billingProfile')->billingProfile;
        $company = $this->company->get();

        return view('client.invoice', [
            'invoice' => $invoice,
            'agency' => $company['name'],
            'brand' => $company['brand_color'],
            'logo' => $this->company->logoDataUri(),
            'taxLabel' => sprintf('%s (%d.%02d%%)', $company['vat_registered'] ? 'VAT' : 'Tax', intdiv($invoice->tax_rate_bp, 100), $invoice->tax_rate_bp % 100),
            'paymentInstructions' => $profile?->payment_instructions,
        ]);
    }

    public function invoicePdf(string $token, InvoicePdf $pdf): Response
    {
        $invoice = $this->invoiceFor($token);

        return response($pdf->download($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$invoice->displayNumber().'.pdf"',
        ]);
    }

    public function statement(string $token, StatementService $statements): View
    {
        [$workspace, $profile] = $this->statementFor($token);
        $company = $this->company->get();

        return view('client.statement', [
            's' => $statements->build($workspace, $profile->currency, now()->toImmutable()->subMonthsNoOverflow(6)->startOfMonth(), now()->toImmutable()),
            'client' => $profile->bill_to['name'] ?? $workspace->name,
            'token' => $token,
            'agency' => $company['name'],
            'brand' => $company['brand_color'],
            'logo' => $this->company->logoDataUri(),
            'paymentInstructions' => $profile->payment_instructions,
        ]);
    }

    public function statementPdf(string $token, StatementService $statements, InvoicePdf $pdf): Response
    {
        [$workspace, $profile] = $this->statementFor($token);
        $statement = $statements->build($workspace, $profile->currency, now()->toImmutable()->subMonthsNoOverflow(6)->startOfMonth(), now()->toImmutable());

        return response($pdf->statement($workspace, $statement), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Statement '.now()->toDateString().'.pdf"',
        ]);
    }

    private function invoiceFor(string $token): Invoice
    {
        abort_unless(strlen($token) === 40 && ctype_alnum($token), 404);
        $invoice = Invoice::query()->with(['items', 'workspace'])->where('share_token', $token)->first();
        abort_if($invoice === null || $invoice->workspace === null || $invoice->kind !== 'invoice', 404);
        abort_if(in_array($invoice->status, [Invoice::DRAFT, Invoice::VOID], true), 404);
        abort_if($invoice->share_expires_at !== null && $invoice->share_expires_at->isPast(), 404);

        return $invoice;
    }

    /**
     * @return array{0: Workspace, 1: BillingProfile}
     */
    private function statementFor(string $token): array
    {
        abort_unless(strlen($token) === 40 && ctype_alnum($token), 404);
        $profile = BillingProfile::query()->with('workspace')->where('statement_token', $token)->first();
        abort_if($profile === null || $profile->workspace === null, 404);
        abort_if($profile->statement_token_expires_at !== null && $profile->statement_token_expires_at->isPast(), 404);

        return [$profile->workspace, $profile];
    }
}
