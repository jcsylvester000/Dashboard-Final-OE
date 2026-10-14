<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\BillingException;
use App\Domain\Billing\FinanceSettings;
use App\Domain\Billing\InvoicePdf;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\Money;
use App\Domain\Identity\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\BillingPeriod;
use App\Models\BillingProfile;
use App\Models\ClientCredit;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceHistory;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSource;
use App\Models\Payment;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance: invoice list with aging, invoice detail with drill-down to the
 * time entries behind each line, finalize / void / credit note / payments.
 */
class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([Invoice::DRAFT, Invoice::FINALIZED, Invoice::PARTIALLY_PAID, Invoice::PAID, Invoice::VOID, 'open'])],
            'workspace' => ['nullable', 'integer'],
        ]);

        $page = Invoice::query()
            ->with('workspace:id,name,slug')
            ->when($filters['status'] ?? null, fn ($q, $s) => $s === 'open'
                ? $q->whereIn('status', [Invoice::FINALIZED, Invoice::PARTIALLY_PAID])
                : $q->where('status', $s))
            ->when($filters['workspace'] ?? null, fn ($q, $w) => $q->where('workspace_id', $w))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('billing/Invoices', [
            'invoices' => $page->through(fn (Invoice $i): array => $this->row($i)),
            'filters' => $filters,
            'aging' => $this->invoices->aging(),
            'workspaces' => Workspace::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, Invoice $invoice, FinanceSettings $finance): Response
    {
        $invoice->load([
            'workspace:id,name,slug',
            'project:id,name',
            'items.department:id,name',
            'items.sources.source' => fn (MorphTo $m) => $m->morphWith([
                TimeEntry::class => ['user:id,name', 'task:id,title,workspace_id'],
            ]),
            'history.user:id,name',
            'payments.recorder:id,name',
            'payments.reverser:id,name',
        ]);
        $user = $this->user($request);
        $needsReview = $invoice->isDraft() && $finance->needsReview($invoice);

        return Inertia::render('billing/Invoice', [
            'invoice' => [
                ...$this->row($invoice),
                'subtotal_minor' => $invoice->subtotal_minor,
                'tax_rate_bp' => $invoice->tax_rate_bp,
                'line_grouping' => $invoice->line_grouping,
                'project' => $invoice->project?->only(['id', 'name']),
                'tax_minor' => $invoice->tax_minor,
                'paid_minor' => $invoice->paid_minor,
                'credited_minor' => $invoice->credited_minor,
                'bill_to' => $invoice->bill_to,
                'notes' => $invoice->notes,
                'kind' => $invoice->kind,
                'credited_invoice_id' => $invoice->credited_invoice_id,
                'service_label' => $invoice->service_label,
                // E-66: who may finalize. A draft sent for review waits for a second person.
                'review' => [
                    'required' => $needsReview,
                    'requested_at' => $invoice->review_requested_at?->toIso8601String(),
                    'requested_by' => $invoice->review_requested_by !== null ? User::query()->whereKey($invoice->review_requested_by)->value('name') : null,
                    'mine' => $invoice->review_requested_by === $user->id,
                    'note' => $invoice->review_note,
                    'can_finalize' => ! $needsReview || ($invoice->review_requested_at !== null && $invoice->review_requested_by !== $user->id),
                ],
                // E-68: credit the client holds in this currency (applied automatically at finalize).
                'client_credit_minor' => ClientCredit::balance($invoice->workspace_id, $invoice->currency),
                // E-64: work this draft could not price yet (Finalize stays blocked).
                'missing_rates' => $invoice->isDraft() ? ($invoice->missing_rates ?? []) : [],
                'items' => $invoice->items->map(fn (InvoiceItem $item): array => [
                    'id' => $item->id,
                    'type' => $item->type,
                    'description' => $item->description,
                    'department' => $item->department?->name,
                    'minutes' => $item->minutes,
                    'quantity' => $item->quantity,
                    'unit_minor' => $item->unit_minor,
                    'amount_minor' => $item->amount_minor,
                    'manual' => $item->manual,
                    'taxable' => $item->taxable,
                    'discount_bp' => $item->discount_bp,
                    'reason' => $item->reason,
                    'sources' => $item->sources->map(fn (InvoiceItemSource $s): array => $this->source($s, $invoice))->values(),
                ])->values(),
                'history' => $invoice->history->map(fn (InvoiceHistory $h): array => [
                    'id' => $h->id,
                    'from' => $h->from_status,
                    'to' => $h->to_status,
                    'note' => $h->note,
                    'by' => $h->user?->name,
                    'at' => $h->created_at->toIso8601String(),
                ])->values(),
                'payments' => $invoice->payments->map(fn (Payment $p): array => [
                    'id' => $p->id,
                    'amount_minor' => $p->amount_minor,
                    'method' => Payment::METHODS[$p->method] ?? $p->method,
                    'reference' => $p->reference,
                    'received_on' => $p->received_on->toDateString(),
                    'by' => $p->recorder?->name,
                    'note' => $p->note,
                    'is_credit' => $p->method === 'credit',
                    'fee_minor' => $p->fee_minor,
                    'withholding_minor' => $p->withholding_minor,
                    'received_minor' => $p->received_minor,
                    'received_currency' => $p->received_currency,
                    'credit_minor' => $p->credit_minor,
                    'reversed_at' => $p->reversed_at?->toIso8601String(),
                    'reversed_by' => $p->reverser?->name,
                    'reversal_reason' => $p->reversal_reason,
                ])->values(),
                'credit_notes' => Invoice::query()->where('credited_invoice_id', $invoice->id)->get()
                    ->map(fn (Invoice $c): array => $this->row($c))->values(),
            ],
            'methods' => Payment::manualMethods(),
            'groupings' => Invoice::GROUPINGS,
            'currencies' => BillingProfile::currencies(),
            'backdateDays' => $finance->get()['backdate_days'],
        ]);
    }

    /**
     * PDF invoice: the copy stored at finalize (live render with a DRAFT mark for drafts).
     */
    public function pdf(Invoice $invoice, InvoicePdf $pdf): HttpResponse
    {
        return $this->file($pdf->download($invoice), $invoice->displayNumber().'.pdf');
    }

    /**
     * PDF work report: tasks, people and hours behind the invoice.
     */
    public function workReport(Invoice $invoice, InvoicePdf $pdf): HttpResponse
    {
        return $this->file($pdf->workReport($invoice), $invoice->displayNumber().' work report.pdf');
    }

    private function file(string $bytes, string $name): HttpResponse
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $name).'"',
        ]);
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        if (! $invoice->isDraft()) {
            throw new BillingException('Finalized invoices cannot be edited. Issue a credit note instead.');
        }
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'service_label' => ['nullable', 'string', 'max:120'],
        ]);
        $invoice->forceFill(['notes' => $data['notes'] ?? null, 'service_label' => $data['service_label'] ?? null])->save();

        return back();
    }

    public function recalculate(Request $request, Invoice $invoice): RedirectResponse
    {
        $invoice->loadMissing('period');
        $period = $invoice->getRelation('period');
        if (! $invoice->isDraft() || ! $period instanceof BillingPeriod) {
            throw new BillingException('Only drafts built from a billing period can be recalculated.');
        }
        $grouping = $request->validate(['grouping' => ['nullable', Rule::in(array_keys(Invoice::GROUPINGS))]])['grouping'] ?? null;
        $invoice->loadMissing('project');
        $this->invoices->buildDraft($period, $this->user($request), $grouping, $invoice->project);

        return back();
    }

    public function finalize(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate(['issue_date' => ['nullable', 'date_format:Y-m-d']]);
        $this->invoices->finalize($invoice, $this->user($request), $data['issue_date'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice :n finalized.', ['n' => $invoice->displayNumber()])]);

        return back();
    }

    public function cancel(Request $request, Invoice $invoice): RedirectResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $this->invoices->void($invoice, $this->user($request), $reason);

        return back();
    }

    public function credit(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'regex:'.Money::INPUT_REGEX],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $credit = $this->invoices->creditNote($invoice, Money::parse((string) $data['amount']), (string) $data['reason'], $this->user($request));

        return to_route('billing.invoices.show', $credit);
    }

    public function payment(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'regex:'.Money::INPUT_REGEX],
            'method' => ['required', Rule::in(array_keys(Payment::manualMethods()))],
            'reference' => ['nullable', 'string', 'max:100'],
            'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
            // E-105: what the bank actually credited, and what came off on the way.
            'fee' => ['nullable', 'regex:'.Money::INPUT_REGEX],
            'withholding' => ['nullable', 'regex:'.Money::INPUT_REGEX],
            'received_amount' => ['nullable', 'regex:'.Money::INPUT_REGEX],
            'received_currency' => ['nullable', 'required_with:received_amount', Rule::in(BillingProfile::currencies())],
        ]);

        $amount = Money::parse((string) $data['amount']);
        $received = isset($data['received_amount']) ? Money::parse((string) $data['received_amount']) : null;
        $receivedCurrency = $received !== null ? (string) $data['received_currency'] : null;
        $payment = $this->invoices->recordPayment($invoice, [
            'amount_minor' => $amount,
            'method' => (string) $data['method'],
            'reference' => $data['reference'] ?? null,
            'received_on' => (string) $data['received_on'],
            'note' => $data['note'] ?? null,
            'fee_minor' => isset($data['fee']) ? Money::parse((string) $data['fee']) : 0,
            'withholding_minor' => isset($data['withholding']) ? Money::parse((string) $data['withholding']) : 0,
            'received_minor' => $received,
            'received_currency' => $receivedCurrency,
            // Rate = received / (applied - fee - withholding), only across currencies.
            'fx_rate' => $this->fxRate($invoice->currency, $receivedCurrency, $received, $amount, $data),
        ], $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => $payment->credit_minor > 0
            ? __('Payment recorded. :x extra is kept as client credit.', ['x' => Money::format($payment->credit_minor, $invoice->currency)])
            : __('Payment recorded.')]);

        return back();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fxRate(string $invoiceCurrency, ?string $receivedCurrency, ?int $received, int $amount, array $data): ?string
    {
        if ($received === null || $receivedCurrency === null || $receivedCurrency === $invoiceCurrency) {
            return null;
        }
        $net = $amount - (isset($data['fee']) ? Money::parse((string) $data['fee']) : 0) - (isset($data['withholding']) ? Money::parse((string) $data['withholding']) : 0);

        if ($net <= 0) {
            return null;
        }
        $scaled = Money::mulDiv($received, 100_000_000, $net); // 8 decimals, integer math (no bcmath needed)

        return sprintf('%d.%08d', intdiv($scaled, 100_000_000), $scaled % 100_000_000);
    }

    /** E-68: undo a payment recorded by mistake, keeping it in the history. */
    public function reversePayment(Request $request, Payment $payment): RedirectResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $this->invoices->reversePayment($payment, $this->user($request), $reason);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment reversed.')]);

        return back();
    }

    /** E-63: a line Finance adds by hand (one-off charge, goodwill credit). */
    public function storeLine(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'unit_price' => ['required', 'regex:/^-?\d{1,12}(\.\d{1,2})?$/'],
            'taxable' => ['boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $this->invoices->addLine($invoice, [
            'description' => (string) $data['description'],
            'quantity' => (string) $data['quantity'],
            'unit_minor' => Money::parse((string) $data['unit_price']),
            'taxable' => (bool) ($data['taxable'] ?? true),
            'reason' => $data['reason'] ?? null,
        ], $this->user($request));

        return back();
    }

    public function storeDiscount(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['percent', 'amount'])],
            'value' => ['required', 'regex:'.Money::INPUT_REGEX],
            'applies_to_item_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:200'],
        ]);
        $value = Money::parse((string) $data['value']); // "12.5" -> 1250 = basis points for a percentage
        $this->invoices->addDiscount($invoice, [
            'percent_bp' => $data['kind'] === 'percent' ? $value : null,
            'amount_minor' => $data['kind'] === 'amount' ? $value : null,
            'applies_to_item_id' => isset($data['applies_to_item_id']) ? (int) $data['applies_to_item_id'] : null,
            'reason' => (string) $data['reason'],
        ], $this->user($request));

        return back();
    }

    public function destroyLine(Request $request, InvoiceItem $invoiceItem): RedirectResponse
    {
        $this->invoices->removeLine($invoiceItem, $this->user($request));

        return back();
    }

    /** E-66: maker marks the draft ready; a second Finance user finalizes or sends it back. */
    public function requestReview(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->invoices->requestReview($invoice, $this->user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sent for review. Someone else in Finance finalizes it.')]);

        return back();
    }

    public function sendBack(Request $request, Invoice $invoice): RedirectResponse
    {
        $note = (string) $request->validate(['note' => ['required', 'string', 'max:500']])['note'];
        $this->invoices->sendBack($invoice, $this->user($request), $note);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sent back with your note.')]);

        return back();
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $invoice->delete(); // the model refuses anything but drafts
        app(ActivityLogger::class)->log('billing.invoice-draft-deleted', null, ['invoice_id' => $invoice->id, 'workspace_id' => $invoice->workspace_id, 'total_minor' => $invoice->total_minor, 'currency' => $invoice->currency]);

        return to_route('billing.invoices.index');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Invoice $i): array
    {
        $i->loadMissing('workspace:id,name,slug');
        $workspace = $i->getRelation('workspace');

        return [
            'id' => $i->id,
            'number' => $i->displayNumber(),
            'kind' => $i->kind,
            'status' => $i->status,
            'workspace' => $workspace instanceof Workspace ? $workspace->only(['id', 'name', 'slug']) : null,
            'currency' => $i->currency,
            'period_start' => $i->period_start?->toDateString(),
            'period_end' => $i->period_end?->toDateString(),
            'issue_date' => $i->issue_date?->toDateString(),
            'due_date' => $i->due_date?->toDateString(),
            'total_minor' => $i->total_minor,
            'balance_minor' => $i->kind === 'invoice' && in_array($i->status, [Invoice::FINALIZED, Invoice::PARTIALLY_PAID], true) ? $i->balanceMinor() : 0,
        ];
    }

    /**
     * Drill-down row for one source of a line.
     *
     * @return array<string, mixed>
     */
    private function source(InvoiceItemSource $s, Invoice $invoice): array
    {
        $src = $s->source;
        $workspace = $invoice->getRelation('workspace');
        $base = ['id' => $s->id, 'type' => $s->source_type, 'minutes' => $s->minutes, 'amount_minor' => $s->amount_minor];

        return match (true) {
            $src instanceof TimeEntry => [...$base,
                'date' => $src->entry_date->toDateString(),
                'person' => $src->user->name ?? null,
                'task' => $src->task?->title,
                'task_url' => $src->task !== null && $workspace instanceof Workspace
                    ? route('workspaces.tasks.show', [$workspace, $src->task], false) : null,
                'note' => $src->note,
            ],
            $src instanceof Expense => [...$base, 'date' => $src->incurred_on->toDateString(), 'note' => $src->description],
            $src instanceof Project => [...$base, 'note' => $src->name],
            default => [...$base, 'note' => 'Removed record'],
        };
    }
}
