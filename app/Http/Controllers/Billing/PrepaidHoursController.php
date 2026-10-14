<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\BillingException;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\Money;
use App\Domain\Identity\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\BillingProfile;
use App\Models\ClientCredit;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PrepaidHours;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * E-74: prepaid hour blocks (Finance: billing.draft) and deposits (billing.payments).
 * A deposit goes on the client credit ledger, which pays the next invoices at finalize.
 */
class PrepaidHoursController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function store(Request $request, Workspace $workspace, InvoiceService $invoices): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'hours' => ['required', 'regex:/^\d{1,5}(\.\d{1,2})?$/', 'not_in:0,0.0,0.00'],
            'amount' => ['required', 'regex:'.Money::INPUT_REGEX],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'note' => ['nullable', 'string', 'max:255'],
            'create_invoice' => ['boolean'],
        ]);
        $currency = BillingProfile::query()->where('workspace_id', $workspace->id)->value('currency') ?? 'PHP';

        $block = DB::transaction(function () use ($data, $workspace, $user, $currency, $invoices) {
            $block = PrepaidHours::create([
                'workspace_id' => $workspace->id,
                'kind' => 'block',
                'minutes' => intdiv(Money::parse((string) $data['hours']) * 60, 100),
                'amount_minor' => Money::parse((string) $data['amount']),
                'currency' => (string) $currency,
                'starts_on' => $data['starts_on'],
                'expires_on' => $data['expires_on'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $user->id,
            ]);
            if ((bool) ($data['create_invoice'] ?? false) && $block->amount_minor > 0) {
                $invoices->draftForPrepaidBlock($block, $user);
            }

            return $block;
        });
        $this->activity->log('billing.prepaid-block-added', $workspace, ['block_id' => $block->id, 'minutes' => $block->minutes, 'amount_minor' => $block->amount_minor, 'currency' => $block->currency]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $block->invoice_id !== null
            ? __('Prepaid block added. Its draft invoice is ready to check.')
            : __('Prepaid block added.')]);

        return back();
    }

    public function destroy(PrepaidHours $prepaidHours): RedirectResponse
    {
        $prepaidHours->loadMissing(['invoice', 'workspace']);
        if ($prepaidHours->kind !== 'block') {
            throw new BillingException('Rolled-over hours go away when their invoice is voided.');
        }
        if ($prepaidHours->draws()->whereHas('invoice', fn ($q) => $q->where('status', '!=', Invoice::VOID))->exists()) {
            throw new BillingException('Invoices already use hours from this block, so it stays.');
        }
        $invoice = $prepaidHours->invoice;
        if ($invoice !== null && ! in_array($invoice->status, [Invoice::DRAFT, Invoice::VOID], true)) {
            throw new BillingException('This block was invoiced. Void that invoice first.');
        }

        DB::transaction(function () use ($prepaidHours, $invoice) {
            if ($invoice !== null && $invoice->isDraft()) {
                $invoice->delete(); // the model refuses anything but drafts
            }
            $prepaidHours->delete();
        });
        $this->activity->log('billing.prepaid-block-removed', $prepaidHours->workspace, ['block_id' => $prepaidHours->id, 'minutes' => $prepaidHours->minutes]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Prepaid block removed.')]);

        return back();
    }

    /**
     * A deposit received ahead of work: client credit used by the next invoices.
     */
    public function deposit(Request $request, Workspace $workspace): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'amount' => ['required', 'regex:'.Money::INPUT_REGEX],
            'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);
        $amount = Money::parse((string) $data['amount']);
        if ($amount <= 0) {
            throw new BillingException('A deposit must be more than zero.');
        }
        $currency = (string) (BillingProfile::query()->where('workspace_id', $workspace->id)->value('currency') ?? 'PHP');

        ClientCredit::create([
            'workspace_id' => $workspace->id,
            'currency' => $currency,
            'amount_minor' => $amount,
            'note' => trim(sprintf('Deposit received %s via %s %s', $data['received_on'], Payment::METHODS[(string) $data['method']], $data['reference'] ?? '')),
            'created_by' => $user->id,
        ]);
        $this->activity->log('billing.deposit-recorded', $workspace, ['amount_minor' => $amount, 'currency' => $currency, 'method' => $data['method']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deposit of :a recorded. It pays the next invoices automatically.', ['a' => Money::format($amount, $currency)])]);

        return back();
    }
}
