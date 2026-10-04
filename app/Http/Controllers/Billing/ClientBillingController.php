<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\BillingException;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\Money;
use App\Domain\Billing\PeriodService;
use App\Http\Controllers\Controller;
use App\Models\BillingPeriod;
use App\Models\BillingProfile;
use App\Models\Department;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\RateCard;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance: per-client billing setup, periods (lock / invoice), expenses, fixed fees.
 */
class ClientBillingController extends Controller
{
    public function index(): Response
    {
        $profiles = BillingProfile::query()->get()->keyBy('workspace_id');

        $unbilled = DB::table('time_entries')
            ->whereNull('deleted_at')
            ->whereNotNull('approved_at')
            ->where('is_billable', true)
            ->whereNull('locked_at')
            ->where(fn ($q) => $q->whereNull('started_at')->orWhereNotNull('ended_at'))
            ->groupBy('workspace_id')
            ->selectRaw('workspace_id, sum(minutes) as m')
            ->pluck('m', 'workspace_id');

        $open = Invoice::query()
            ->where('kind', 'invoice')
            ->whereIn('status', [Invoice::FINALIZED, Invoice::PARTIALLY_PAID])
            ->get(['workspace_id', 'total_minor', 'paid_minor', 'credited_minor', 'currency'])
            ->groupBy('workspace_id');

        return Inertia::render('billing/Clients', [
            'clients' => Workspace::query()->orderBy('name')->get(['id', 'name', 'slug', 'status', 'color'])
                ->map(function (Workspace $w) use ($profiles, $unbilled, $open): array {
                    $profile = $profiles->get($w->id);

                    return [
                        'id' => $w->id,
                        'name' => $w->name,
                        'slug' => $w->slug,
                        'status' => $w->status,
                        'color' => $w->color,
                        'configured' => $profile !== null,
                        'currency' => $profile->currency ?? 'PHP',
                        'retainer_minor' => $profile->retainer_minor ?? 0,
                        'unbilled_minutes' => (int) ($unbilled[$w->id] ?? 0),
                        'balance_minor' => (int) collect($open->get($w->id) ?? [])->sum(fn (Invoice $i) => $i->balanceMinor()),
                    ];
                })->values(),
        ]);
    }

    public function show(Workspace $workspace): Response
    {
        $profile = BillingProfile::query()->firstOrNew(['workspace_id' => $workspace->id]);

        return Inertia::render('billing/Client', [
            'workspace' => $workspace->only(['id', 'name', 'slug', 'color']),
            'profile' => [
                'configured' => $profile->exists,
                'currency' => $profile->currency,
                'cycle' => $profile->cycle,
                'retainer' => Money::toDecimal($profile->retainer_minor),
                'included_hours' => sprintf('%d.%02d', intdiv($profile->included_minutes, 60), intdiv(($profile->included_minutes % 60) * 100 + 30, 60)),
                'overage_rule' => $profile->overage_rule,
                'terms_days' => $profile->terms_days,
                'tax_rate' => sprintf('%d.%02d', intdiv($profile->tax_rate_bp, 100), $profile->tax_rate_bp % 100),
                'invoice_series' => $profile->invoice_series,
                'bill_to' => $profile->bill_to ?? ['name' => $workspace->name, 'address' => null, 'tax_id' => null, 'contact' => $workspace->primary_contact_name],
                'payment_instructions' => $profile->payment_instructions,
            ],
            'periods' => BillingPeriod::query()->with('invoices:id,billing_period_id,status,series,number,total_minor,currency')
                ->where('workspace_id', $workspace->id)->latest('starts_on')->limit(24)->get()
                ->map(fn (BillingPeriod $p): array => [
                    'id' => $p->id,
                    'starts_on' => $p->starts_on->toDateString(),
                    'ends_on' => $p->ends_on->toDateString(),
                    'status' => $p->status,
                    'invoices' => $p->invoices->map(fn (Invoice $i): array => [
                        'id' => $i->id, 'number' => $i->displayNumber(), 'status' => $i->status,
                        'total_minor' => $i->total_minor, 'currency' => $i->currency,
                    ])->values(),
                ])->values(),
            'rates' => RateCard::query()->with(['department:id,name', 'user:id,name'])
                ->where('workspace_id', $workspace->id)->latest('effective_from')->get()
                ->map(fn (RateCard $r): array => self::rateRow($r))->values(),
            'expenses' => Expense::query()->with('project:id,name')
                ->where('workspace_id', $workspace->id)->latest('incurred_on')->limit(50)->get()
                ->map(fn (Expense $e): array => [
                    'id' => $e->id, 'description' => $e->description, 'amount_minor' => $e->amount_minor,
                    'incurred_on' => $e->incurred_on->toDateString(), 'is_billable' => $e->is_billable,
                    'project' => $e->project?->name, 'billed' => $e->invoice_item_id !== null,
                ])->values(),
            'projects' => Project::query()->where('workspace_id', $workspace->id)->orderBy('name')
                ->get(['id', 'name', 'status', 'due_on', 'fixed_fee_minor', 'fixed_fee_billed_at'])
                ->map(fn (Project $p): array => [
                    'id' => $p->id, 'name' => $p->name, 'status' => $p->status,
                    'fixed_fee' => $p->fixed_fee_minor !== null ? Money::toDecimal($p->fixed_fee_minor) : '',
                    'billed' => $p->fixed_fee_billed_at !== null,
                ])->values(),
            'departments' => Department::query()->orderBy('position')->get(['id', 'name']),
            'members' => $workspace->members()->orderBy('name')->get(['users.id', 'users.name'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'options' => [
                'currencies' => BillingProfile::CURRENCIES,
                'cycles' => BillingProfile::CYCLES,
                'overageRules' => BillingProfile::OVERAGE_RULES,
            ],
        ]);
    }

    public function updateProfile(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate([
            'currency' => ['required', Rule::in(BillingProfile::CURRENCIES)],
            'cycle' => ['required', Rule::in(array_keys(BillingProfile::CYCLES))],
            'retainer' => ['required', 'regex:'.Money::INPUT_REGEX],
            'included_hours' => ['required', 'regex:/^\d{1,5}(\.\d{1,2})?$/'],
            'overage_rule' => ['required', Rule::in(array_keys(BillingProfile::OVERAGE_RULES))],
            'terms_days' => ['required', 'integer', 'min:0', 'max:180'],
            'tax_rate' => ['required', 'regex:/^\d{1,2}(\.\d{1,2})?$/'],
            'invoice_series' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/', Rule::notIn(['CN'])], // CN = credit notes
            'bill_to.name' => ['required', 'string', 'max:255'],
            'bill_to.address' => ['nullable', 'string', 'max:500'],
            'bill_to.tax_id' => ['nullable', 'string', 'max:50'],
            'bill_to.contact' => ['nullable', 'string', 'max:255'],
            'payment_instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        BillingProfile::query()->updateOrCreate(['workspace_id' => $workspace->id], [
            'currency' => $data['currency'],
            'cycle' => $data['cycle'],
            'retainer_minor' => Money::parse((string) $data['retainer']),
            // Hours -> minutes without floats: "12.5" -> 750.
            'included_minutes' => intdiv(Money::parse((string) $data['included_hours']) * 60, 100),
            'overage_rule' => $data['overage_rule'],
            'terms_days' => (int) $data['terms_days'],
            'tax_rate_bp' => Money::parse((string) $data['tax_rate']),
            'invoice_series' => $data['invoice_series'],
            'bill_to' => $data['bill_to'],
            'payment_instructions' => $data['payment_instructions'] ?? null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Billing profile saved.')]);

        return back();
    }

    public function storePeriod(Request $request, Workspace $workspace, PeriodService $periods): RedirectResponse
    {
        $data = $request->validate([
            'starts_on' => ['nullable', 'date_format:Y-m-d', 'required_with:ends_on'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on', 'required_with:starts_on'],
        ]);

        isset($data['starts_on'], $data['ends_on'])
            ? $periods->create($workspace, CarbonImmutable::parse($data['starts_on']), CarbonImmutable::parse($data['ends_on']))
            : $periods->next($workspace);

        return back();
    }

    public function lockPeriod(Request $request, BillingPeriod $period, PeriodService $periods): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $periods->lock($period, $user);

        return back();
    }

    public function unlockPeriod(BillingPeriod $period, PeriodService $periods): RedirectResponse
    {
        $periods->unlock($period);

        return back();
    }

    public function invoicePeriod(Request $request, BillingPeriod $period, InvoiceService $invoices): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $invoice = $invoices->buildDraft($period, $user);

        return to_route('billing.invoices.show', $invoice);
    }

    public function storeExpense(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'regex:'.Money::INPUT_REGEX],
            'incurred_on' => ['required', 'date_format:Y-m-d'],
            'is_billable' => ['boolean'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('workspace_id', $workspace->id)],
        ]);

        Expense::create([
            'workspace_id' => $workspace->id,
            'project_id' => $data['project_id'] ?? null,
            'description' => $data['description'],
            'amount_minor' => Money::parse((string) $data['amount']),
            'incurred_on' => $data['incurred_on'],
            'is_billable' => (bool) ($data['is_billable'] ?? true),
            'created_by' => $request->user()?->id,
        ]);

        return back();
    }

    public function destroyExpense(Expense $expense): RedirectResponse
    {
        $onInvoice = DB::table('invoice_item_sources as s')
            ->join('invoice_items as i', 'i.id', '=', 's.invoice_item_id')
            ->join('invoices as v', 'v.id', '=', 'i.invoice_id')
            ->where('s.source_type', $expense->getMorphClass())
            ->where('s.source_id', $expense->id)
            ->where('v.status', '!=', Invoice::VOID)
            ->exists();
        if ($expense->invoice_item_id !== null || $onInvoice) {
            throw new BillingException('This expense is on an invoice (or draft) and cannot be removed.');
        }
        $expense->delete();

        return back();
    }

    public function updateFixedFee(Request $request, Project $project): RedirectResponse
    {
        if ($project->fixed_fee_billed_at !== null) {
            throw new BillingException('This fixed fee is already billed.');
        }
        $data = $request->validate(['fixed_fee' => ['nullable', 'regex:'.Money::INPUT_REGEX]]);

        $fee = isset($data['fixed_fee']) && $data['fixed_fee'] !== '' ? Money::parse((string) $data['fixed_fee']) : null;
        $project->forceFill(['fixed_fee_minor' => $fee ?: null])->save();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    public static function rateRow(RateCard $r): array
    {
        return [
            'id' => $r->id,
            'workspace_id' => $r->workspace_id,
            'department' => $r->department?->name,
            'person' => $r->user?->name,
            'rate_minor' => $r->rate_minor,
            'cost_minor' => $r->cost_minor,
            'effective_from' => $r->effective_from->toDateString(),
        ];
    }
}
