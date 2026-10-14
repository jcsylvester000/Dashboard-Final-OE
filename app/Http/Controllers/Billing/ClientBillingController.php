<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\BillingException;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\Money;
use App\Domain\Billing\PeriodService;
use App\Domain\Billing\PrepaidHoursService;
use App\Domain\Billing\RateTemplateService;
use App\Domain\Identity\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\BillingPeriod;
use App\Models\BillingProfile;
use App\Models\ClientCredit;
use App\Models\Department;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\RateCard;
use App\Models\RateTemplate;
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
    public function __construct(private readonly ActivityLogger $activity) {}

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

    public function show(Request $request, Workspace $workspace): Response
    {
        $profile = BillingProfile::query()->firstOrNew(['workspace_id' => $workspace->id]);

        return Inertia::render('billing/Client', [
            'workspace' => $workspace->only(['id', 'name', 'slug', 'color']),
            // E-68: overpayments kept for the next invoice, per currency.
            'credits' => ClientCredit::balances($workspace->id),
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
                // E-74
                'rollover_enabled' => $profile->rollover_enabled,
                'rollover_cap_hours' => $profile->rollover_cap_minutes !== null ? sprintf('%d.%02d', intdiv($profile->rollover_cap_minutes, 60), intdiv(($profile->rollover_cap_minutes % 60) * 100 + 30, 60)) : '',
                'rollover_months' => $profile->rollover_months,
            ],
            // E-74: rolled-over hours and prepaid blocks, with what is used and left.
            'prepaid' => app(PrepaidHoursService::class)->summary($workspace->id, true),
            'paymentMethods' => Payment::METHODS,
            'periods' => BillingPeriod::query()->with(['invoices:id,billing_period_id,project_id,status,series,number,total_minor,currency', 'invoices.project:id,name'])
                ->where('workspace_id', $workspace->id)->latest('starts_on')->limit(24)->get()
                ->map(fn (BillingPeriod $p): array => [
                    'id' => $p->id,
                    'starts_on' => $p->starts_on->toDateString(),
                    'ends_on' => $p->ends_on->toDateString(),
                    'status' => $p->status,
                    'invoices' => $p->invoices->map(fn (Invoice $i): array => [
                        'id' => $i->id, 'number' => $i->displayNumber(), 'status' => $i->status,
                        'total_minor' => $i->total_minor, 'currency' => $i->currency,
                        'project' => $i->project?->name,
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
                    'project' => $e->project?->name, 'project_id' => $e->project_id, 'billed' => $e->invoice_item_id !== null,
                    // E-75
                    'category' => $e->category, 'markup_bp' => $e->markup_bp, 'taxable' => $e->taxable,
                    'billed_minor' => $e->billedMinor(), 'has_receipt' => $e->receipt_path !== null, 'receipt_name' => $e->receipt_name,
                    'approved' => $e->isApproved(),
                    'can_approve' => ! $e->isApproved() && $e->created_by !== $request->user()?->id,
                ])->values(),
            'expenseCategories' => Expense::CATEGORIES,
            // E-69: the client's statement link.
            'statementLink' => $profile->statement_token !== null ? [
                'url' => route('statement-link.show', $profile->statement_token),
                'expires_at' => $profile->statement_token_expires_at?->toIso8601String(),
            ] : null,
            'projects' => Project::query()->where('workspace_id', $workspace->id)->orderBy('name')
                ->get(['id', 'name', 'status', 'due_on', 'fixed_fee_minor', 'fixed_fee_billed_at'])
                ->map(fn (Project $p): array => [
                    'id' => $p->id, 'name' => $p->name, 'status' => $p->status,
                    'fixed_fee' => $p->fixed_fee_minor !== null ? Money::toDecimal($p->fixed_fee_minor) : '',
                    'billed' => $p->fixed_fee_billed_at !== null,
                ])->values(),
            'teamRates' => app(RateTemplateService::class)->teamRates($workspace),
            'templates' => RateTemplate::query()->orderBy('name')->get(['id', 'name']),
            'appliedTemplate' => $profile->rate_template_id !== null ? [
                'name' => RateTemplate::query()->whereKey($profile->rate_template_id)->value('name'),
                'applied_at' => $profile->rate_template_applied_at?->toDateString(),
            ] : null,
            'departments' => Department::query()->active()->orderBy('position')->get(['id', 'name']),
            'members' => $workspace->members()->orderBy('name')->get(['users.id', 'users.name'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'options' => [
                'currencies' => BillingProfile::currencies(),
                'cycles' => BillingProfile::CYCLES,
                'overageRules' => BillingProfile::OVERAGE_RULES,
            ],
        ]);
    }

    public function updateProfile(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate([
            'currency' => ['required', Rule::in(BillingProfile::currencies())],
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
            // E-74 (optional: older forms leave the rollover settings as they are)
            'rollover_enabled' => ['sometimes', 'boolean'],
            'rollover_cap_hours' => ['sometimes', 'nullable', 'regex:/^\d{1,5}(\.\d{1,2})?$/'],
            'rollover_months' => ['sometimes', 'integer', 'min:1', 'max:12'],
        ]);

        $profile = BillingProfile::query()->firstOrNew(['workspace_id' => $workspace->id]);
        $before = $profile->exists ? $profile->only(['currency', 'cycle', 'retainer_minor', 'included_minutes', 'overage_rule', 'terms_days', 'tax_rate_bp', 'invoice_series', 'bill_to', 'payment_instructions', 'rollover_enabled', 'rollover_cap_minutes', 'rollover_months']) : [];
        $currencyChanged = $profile->exists && $profile->currency !== $data['currency'];

        // E-111: change currency only between billing rounds; history keeps its own currency.
        if ($currencyChanged && Invoice::query()->where('workspace_id', $workspace->id)->where('status', Invoice::DRAFT)->exists()) {
            throw new BillingException(__('Finalize or delete this client\'s draft invoices before changing its currency.'));
        }

        $profile->fill([
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
        if (array_key_exists('rollover_enabled', $data)) {
            $profile->rollover_enabled = (bool) $data['rollover_enabled'];
        }
        if (array_key_exists('rollover_cap_hours', $data)) {
            $cap = $data['rollover_cap_hours'];
            $profile->rollover_cap_minutes = $cap !== null && $cap !== '' ? intdiv(Money::parse((string) $cap) * 60, 100) : null;
        }
        if (array_key_exists('rollover_months', $data)) {
            $profile->rollover_months = (int) $data['rollover_months'];
        }
        $profile->save();

        // E-62: log what changed (before -> after) on the client.
        $after = $profile->only(array_keys($before) ?: ['currency']);
        $changes = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) != $value) {
                $changes[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
            }
        }
        if ($changes !== []) {
            $this->activity->log('billing.profile-updated', $workspace, ['changes' => $changes]);
        }

        $message = __('Billing profile saved.');
        if ($currencyChanged) {
            $old = (string) ($before['currency'] ?? '');
            $stale = RateCard::query()->where('workspace_id', $workspace->id)->where('currency', $old)->count();
            $message = __('Billing currency is now :c from the next draft. Finalized invoices stay in :o.', ['c' => $profile->currency, 'o' => $old])
                .($stale > 0 ? ' '.__(':n client rate(s) in :o no longer apply; add :c rates.', ['n' => $stale, 'o' => $old, 'c' => $profile->currency]) : '');
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

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
        $this->activity->log('billing.period-locked', $period, ['workspace_id' => $period->workspace_id, 'starts_on' => $period->starts_on->toDateString(), 'ends_on' => $period->ends_on->toDateString()]);

        return back();
    }

    public function unlockPeriod(Request $request, BillingPeriod $period, PeriodService $periods): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;
        $periods->unlock($period);
        $this->activity->log('billing.period-unlocked', $period, ['workspace_id' => $period->workspace_id, 'starts_on' => $period->starts_on->toDateString(), 'ends_on' => $period->ends_on->toDateString(), 'reason' => $reason]);

        return back();
    }

    public function invoicePeriod(Request $request, BillingPeriod $period, InvoiceService $invoices): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $projectId = $request->validate([
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('workspace_id', $period->workspace_id)],
        ])['project_id'] ?? null;
        $invoice = $invoices->buildDraft($period, $user, null, $projectId !== null ? Project::query()->findOrFail($projectId) : null);

        return to_route('billing.invoices.show', $invoice);
    }

    public function updateFixedFee(Request $request, Project $project): RedirectResponse
    {
        if ($project->fixed_fee_billed_at !== null) {
            throw new BillingException('This fixed fee is already billed.');
        }
        $data = $request->validate(['fixed_fee' => ['nullable', 'regex:'.Money::INPUT_REGEX]]);

        $fee = isset($data['fixed_fee']) && $data['fixed_fee'] !== '' ? Money::parse((string) $data['fixed_fee']) : null;
        $was = $project->fixed_fee_minor;
        $project->forceFill(['fixed_fee_minor' => $fee ?: null])->save();
        if ($was !== $project->fixed_fee_minor) {
            $this->activity->log('billing.fixed-fee-changed', $project, ['from_minor' => $was, 'to_minor' => $project->fixed_fee_minor]);
        }

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
            'currency' => $r->currency,
            'effective_from' => $r->effective_from->toDateString(),
        ];
    }
}
