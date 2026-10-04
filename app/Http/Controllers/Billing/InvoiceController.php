<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\BillingException;
use App\Domain\Billing\InvoicePdf;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\Money;
use App\Http\Controllers\Controller;
use App\Models\BillingPeriod;
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

    public function show(Invoice $invoice): Response
    {
        $invoice->load([
            'workspace:id,name,slug',
            'items.department:id,name',
            'items.sources.source' => fn (MorphTo $m) => $m->morphWith([
                TimeEntry::class => ['user:id,name', 'task:id,title,workspace_id'],
            ]),
            'history.user:id,name',
            'payments.recorder:id,name',
        ]);

        return Inertia::render('billing/Invoice', [
            'invoice' => [
                ...$this->row($invoice),
                'subtotal_minor' => $invoice->subtotal_minor,
                'tax_rate_bp' => $invoice->tax_rate_bp,
                'tax_minor' => $invoice->tax_minor,
                'paid_minor' => $invoice->paid_minor,
                'credited_minor' => $invoice->credited_minor,
                'bill_to' => $invoice->bill_to,
                'notes' => $invoice->notes,
                'kind' => $invoice->kind,
                'credited_invoice_id' => $invoice->credited_invoice_id,
                'items' => $invoice->items->map(fn (InvoiceItem $item): array => [
                    'id' => $item->id,
                    'type' => $item->type,
                    'description' => $item->description,
                    'department' => $item->department?->name,
                    'minutes' => $item->minutes,
                    'quantity' => $item->quantity,
                    'unit_minor' => $item->unit_minor,
                    'amount_minor' => $item->amount_minor,
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
                ])->values(),
                'credit_notes' => Invoice::query()->where('credited_invoice_id', $invoice->id)->get()
                    ->map(fn (Invoice $c): array => $this->row($c))->values(),
            ],
            'methods' => Payment::METHODS,
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
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        $invoice->forceFill(['notes' => $data['notes'] ?? null])->save();

        return back();
    }

    public function recalculate(Request $request, Invoice $invoice): RedirectResponse
    {
        $invoice->loadMissing('period');
        $period = $invoice->getRelation('period');
        if (! $invoice->isDraft() || ! $period instanceof BillingPeriod) {
            throw new BillingException('Only drafts built from a billing period can be recalculated.');
        }
        $this->invoices->buildDraft($period, $this->user($request));

        return back();
    }

    public function finalize(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->invoices->finalize($invoice, $this->user($request));
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
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'reference' => ['nullable', 'string', 'max:100'],
            'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->invoices->recordPayment($invoice, [
            'amount_minor' => Money::parse((string) $data['amount']),
            'method' => (string) $data['method'],
            'reference' => $data['reference'] ?? null,
            'received_on' => (string) $data['received_on'],
            'note' => $data['note'] ?? null,
        ], $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment recorded.')]);

        return back();
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $invoice->delete(); // the model refuses anything but drafts

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
