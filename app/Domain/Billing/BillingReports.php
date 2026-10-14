<?php

namespace App\Domain\Billing;

use App\Domain\Budgets\BudgetService;
use App\Domain\People\Capacity;
use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSource;
use App\Models\Payment;
use App\Models\PrepaidHours;
use App\Models\Project;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Finance reports. All money is integer minor units; numbers come straight
 * from invoices, invoice lines and their time-entry sources so they tie out.
 */
class BillingReports
{
    public function __construct(
        private readonly RateResolver $rates,
        private readonly FxRates $fx,
    ) {}

    /**
     * Net invoiced (before tax) per client and issue month, credit notes subtracted. Void excluded.
     *
     * E-107: each row also carries its PHP equivalent at the invoices' locked rates.
     *
     * @return list<array{client: string, currency: string, month: string, net_minor: int, tax_minor: int, total_minor: int, paid_minor: int, net_php_minor: int, php_missing: bool}>
     */
    public function revenue(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];
        Invoice::query()
            ->with('workspace:id,name')
            ->whereNotIn('status', [Invoice::DRAFT, Invoice::VOID])
            ->whereDate('issue_date', '>=', $from->toDateString())
            ->whereDate('issue_date', '<=', $to->toDateString())
            ->orderBy('issue_date')
            ->get()
            ->each(function (Invoice $i) use (&$rows) {
                $month = $i->issue_date?->format('Y-m') ?? '';
                $key = $i->workspace_id.'|'.$i->currency.'|'.$month;
                $rows[$key] ??= ['client' => $i->workspace->name ?? 'Deleted client', 'currency' => $i->currency, 'month' => $month,
                    'net_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0, 'paid_minor' => 0, 'net_php_minor' => 0, 'php_missing' => false];
                $php = FxRates::toPhp($i->subtotal_minor, $i->php_rate !== null ? (string) $i->php_rate : ($i->currency === FxRates::BASE ? '1' : null));
                $rows[$key]['net_php_minor'] += $php ?? 0;
                $rows[$key]['php_missing'] = $rows[$key]['php_missing'] || $php === null;
                $rows[$key]['net_minor'] += $i->subtotal_minor;
                $rows[$key]['tax_minor'] += $i->tax_minor;
                $rows[$key]['total_minor'] += $i->total_minor;
                $rows[$key]['paid_minor'] += $i->paid_minor;
            });

        $out = array_values($rows);
        usort($out, fn (array $a, array $b) => [$a['month'], $a['client']] <=> [$b['month'], $b['client']]);

        return $out;
    }

    /**
     * Approved billable time in the month against the hours each retainer includes.
     *
     * @return list<array{client: string, included_minutes: int, used_minutes: int, utilisation_pct: int}>
     */
    public function retainerUtilisation(CarbonImmutable $month): array
    {
        $start = $month->startOfMonth()->toDateString();
        $end = $month->endOfMonth()->toDateString();

        $used = DB::table('time_entries')
            ->whereNull('deleted_at')
            ->whereNotNull('approved_at')
            ->where('is_billable', true)
            ->whereDate('entry_date', '>=', $start)
            ->whereDate('entry_date', '<=', $end)
            ->groupBy('workspace_id')
            ->selectRaw('workspace_id, sum(minutes) as m')
            ->pluck('m', 'workspace_id');

        return BillingProfile::query()
            ->with('workspace:id,name')
            ->where('included_minutes', '>', 0)
            ->whereHas('workspace')
            ->get()
            ->map(function (BillingProfile $p) use ($used): array {
                $m = (int) ($used[$p->workspace_id] ?? 0);

                return [
                    'client' => $p->workspace->name,
                    'included_minutes' => $p->included_minutes,
                    'used_minutes' => $m,
                    // Whole percent, integer maths.
                    'utilisation_pct' => intdiv($m * 100 + intdiv($p->included_minutes, 2), $p->included_minutes),
                ];
            })
            ->sortBy('client')
            ->values()
            ->all();
    }

    /**
     * Approved, billable time not yet on a finalized invoice, valued at today's rates.
     *
     * E-108: value_php_minor is an estimate at today's rate (nothing is locked yet).
     *
     * @return list<array{client: string, currency: string, minutes: int, value_minor: int, unpriced_minutes: int, oldest: string|null, value_php_minor: int|null}>
     */
    public function unbilled(): array
    {
        $currencies = BillingProfile::query()->pluck('currency', 'workspace_id');
        $rows = [];

        TimeEntry::query()
            ->with('workspace:id,name')
            ->whereNotNull('approved_at')
            ->where('is_billable', true)
            ->whereNull('locked_at')
            ->where(fn ($q) => $q->whereNull('started_at')->orWhereNotNull('ended_at'))
            ->whereHas('workspace')
            ->orderBy('entry_date')
            ->get()
            ->each(function (TimeEntry $e) use (&$rows, $currencies) {
                $rate = $this->rates->resolve($e->workspace_id, $e->department_id, $e->user_id, $e->entry_date->toDateString());
                $rows[$e->workspace_id] ??= ['client' => $e->workspace->name, 'currency' => (string) ($currencies[$e->workspace_id] ?? 'PHP'),
                    'minutes' => 0, 'value_minor' => 0, 'unpriced_minutes' => 0, 'oldest' => $e->entry_date->toDateString()];
                $rows[$e->workspace_id]['minutes'] += $e->minutes;
                if ($rate === null) {
                    $rows[$e->workspace_id]['unpriced_minutes'] += $e->minutes;
                } else {
                    $rows[$e->workspace_id]['value_minor'] += Money::mulDiv($e->minutes, $rate->rate_minor, 60);
                }
            });

        $today = now()->toImmutable();
        foreach ($rows as &$r) {
            $r['value_php_minor'] = FxRates::toPhp($r['value_minor'], $this->fx->rate($r['currency'], $today)['rate'] ?? null);
        }
        unset($r);

        $out = array_values($rows);
        usort($out, fn (array $a, array $b) => $b['minutes'] <=> $a['minutes']);

        return $out;
    }

    /** profitability grouping => label (E-77) */
    public const MARGIN_GROUPS = ['project' => 'Project', 'client' => 'Client', 'department' => 'Department', 'person' => 'Person'];

    /**
     * Margin on billed hours (invoices issued in the range), by project, client, department or person.
     *
     * E-77: revenue is what the hours earned. Hours billed at rates carry their line amount;
     * hours covered by a retainer share the retainer amount in proportion to their minutes (a
     * retainer with no hours shows as "Retainer – no hours used", so totals tie to the invoices);
     * hours from a prepaid block carry the block's price per hour; rolled-over hours were earned
     * by the earlier retainer. Cost is the person's internal cost rate (Finance-only cost table,
     * PHP), falling back to the cost on the rate card.
     * E-108: revenue and cost also in PHP at the invoice's locked rate.
     *
     * @return list<array{name: string, client: string, project: string, currency: string, minutes: int, revenue_minor: int, cost_minor: int, margin_minor: int, uncosted_minutes: int, revenue_php_minor: int, cost_php_minor: int, margin_php_minor: int, php_missing: bool, retainer_minor: int, capacity_minutes: int|null, billable_minutes: int|null, utilisation_pct: int|null}>
     */
    public function profitability(CarbonImmutable $from, CarbonImmutable $to, string $by = 'project'): array
    {
        $by = array_key_exists($by, self::MARGIN_GROUPS) ? $by : 'project';
        $costs = app(CostRates::class);
        $live = fn ($q) => $q->whereNotIn('v.status', [Invoice::DRAFT, Invoice::VOID])->where('v.kind', 'invoice')
            ->whereDate('v.issue_date', '>=', $from->toDateString())->whereDate('v.issue_date', '<=', $to->toDateString());

        $sources = InvoiceItemSource::query()
            ->join('invoice_items as i', 'i.id', '=', 'invoice_item_sources.invoice_item_id')
            ->join('invoices as v', 'v.id', '=', 'i.invoice_id')
            ->where('invoice_item_sources.source_type', (new TimeEntry)->getMorphClass())
            ->where($live)
            ->orderBy('invoice_item_sources.id')
            ->get(['invoice_item_sources.source_id', 'invoice_item_sources.minutes', 'invoice_item_sources.amount_minor', 'v.currency', 'v.php_rate',
                'i.id as item_id', 'i.type as item_type', 'i.amount_minor as item_amount', 'i.prepaid_hours_id']);

        // Revenue per source: retainer amounts shared by minutes; prepaid blocks at their price per hour.
        $earned = [];
        foreach ($sources->where('item_type', 'retainer')->groupBy('item_id') as $group) {
            $shares = Money::allocate((int) $group->first()?->getAttribute('item_amount'), $group->map(fn ($s) => (int) $s->minutes)->values()->all());
            foreach ($group->values() as $n => $s) {
                $earned[spl_object_id($s)] = $shares[$n] ?? 0;
            }
        }
        $blocks = PrepaidHours::query()->whereIn('id', $sources->pluck('prepaid_hours_id')->filter()->unique()->all())->get()->keyBy('id');
        foreach ($sources->where('item_type', 'prepaid') as $s) {
            $block = $blocks->get((int) $s->getAttribute('prepaid_hours_id'));
            $earned[spl_object_id($s)] = $block !== null && $block->kind === 'block' && $block->minutes > 0
                ? Money::mulDiv((int) $s->minutes, $block->amount_minor, $block->minutes) : 0;
        }

        $entries = TimeEntry::query()->withTrashed()
            ->with(['workspace:id,name', 'project:id,name', 'department:id,name', 'user:id,name'])
            ->whereIn('id', $sources->pluck('source_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $rows = [];
        $blank = fn (string $name, string $client, string $project, string $currency) => ['name' => $name, 'client' => $client, 'project' => $project, 'currency' => $currency,
            'minutes' => 0, 'revenue_minor' => 0, 'cost_minor' => 0, 'margin_minor' => 0, 'uncosted_minutes' => 0,
            'revenue_php_minor' => 0, 'cost_php_minor' => 0, 'margin_php_minor' => 0, 'php_missing' => false, 'retainer_minor' => 0,
            'capacity_minutes' => null, 'billable_minutes' => null, 'utilisation_pct' => null, 'user_id' => null];

        foreach ($sources as $s) {
            $e = $entries->get($s->source_id);
            if ($e === null) {
                continue;
            }
            $currency = (string) $s->getAttribute('currency');
            $client = $e->workspace->name ?? 'Deleted client';
            $project = $e->project->name ?? 'No project';
            [$key, $name] = match ($by) {
                'client' => [(string) $e->workspace_id, $client],
                'department' => ['d'.($e->department_id ?? 0), $e->department->name ?? 'No department'],
                'person' => ['u'.($e->user_id ?? 0), $e->user->name ?? 'Former member'],
                default => [$e->workspace_id.'|'.($e->project_id ?? 0), $project],
            };
            $key .= '|'.$currency;
            $rows[$key] ??= [...$blank($name, $by === 'project' || $by === 'client' ? $client : '', $by === 'project' ? $project : '', $currency), 'user_id' => $by === 'person' ? $e->user_id : null];

            $minutes = (int) $s->minutes;
            $revenue = $earned[spl_object_id($s)] ?? (int) $s->amount_minor;
            $invRate = $s->getAttribute('php_rate') !== null ? (string) $s->getAttribute('php_rate') : ($currency === FxRates::BASE ? '1' : null);
            $rows[$key]['minutes'] += $minutes;
            $rows[$key]['revenue_minor'] += $revenue;
            if ($s->getAttribute('item_type') === 'retainer') {
                $rows[$key]['retainer_minor'] += $revenue;
            }
            $revPhp = FxRates::toPhp($revenue, $invRate);
            $rows[$key]['revenue_php_minor'] += $revPhp ?? 0;
            $rows[$key]['php_missing'] = $rows[$key]['php_missing'] || $revPhp === null;

            $date = $e->entry_date->toDateString();
            $personal = $costs->perHour($e->user_id, $date);
            if ($personal !== null) {
                // Cost table rates are PHP; shown in the invoice currency at the invoice's rate.
                $costPhp = Money::mulDiv($minutes, $personal, 60);
                $rows[$key]['cost_php_minor'] += $costPhp;
                $rows[$key]['cost_minor'] += $currency === FxRates::BASE ? $costPhp : (self::fromPhp($costPhp, $invRate) ?? 0);
            } else {
                $card = $this->rates->resolve($e->workspace_id, $e->department_id, $e->user_id, $date);
                if ($card === null || $card->cost_minor === null) {
                    $rows[$key]['uncosted_minutes'] += $minutes;
                } else {
                    $cost = Money::mulDiv($minutes, $card->cost_minor, 60);
                    $rows[$key]['cost_minor'] += $cost;
                    // Costs on a PHP card are PHP already; a client-currency card converts at the invoice rate.
                    $rows[$key]['cost_php_minor'] += ($card->currency === FxRates::BASE ? $cost : FxRates::toPhp($cost, $invRate)) ?? 0;
                }
            }
        }

        // Retainers with no hours behind them still count, so the totals tie to the invoices.
        $idle = InvoiceItem::query()->from('invoice_items as i')->join('invoices as v', 'v.id', '=', 'i.invoice_id')
            ->leftJoin('workspaces as w', 'w.id', '=', 'v.workspace_id')
            ->where('i.type', 'retainer')->where($live)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('invoice_item_sources as s')->whereColumn('s.invoice_item_id', 'i.id'))
            ->get(['i.amount_minor', 'v.currency', 'v.php_rate', 'v.workspace_id', 'w.name as client_name']);
        foreach ($idle as $r) {
            $currency = (string) $r->getAttribute('currency');
            $client = (string) ($r->getAttribute('client_name') ?? 'Deleted client');
            $label = 'Retainer – no hours used';
            $key = ($by === 'project' || $by === 'client' ? 'idle'.$r->getAttribute('workspace_id') : 'idle').'|'.$currency;
            $rows[$key] ??= $blank($by === 'client' ? $client : $label, $by === 'project' || $by === 'client' ? $client : '', $by === 'project' ? $label : '', $currency);
            $amount = (int) $r->amount_minor;
            $rate = $r->getAttribute('php_rate') !== null ? (string) $r->getAttribute('php_rate') : ($currency === FxRates::BASE ? '1' : null);
            $rows[$key]['revenue_minor'] += $amount;
            $rows[$key]['retainer_minor'] += $amount;
            $php = FxRates::toPhp($amount, $rate);
            $rows[$key]['revenue_php_minor'] += $php ?? 0;
            $rows[$key]['php_missing'] = $rows[$key]['php_missing'] || $php === null;
        }

        // By person: utilisation = billable hours logged in the range / capacity (8 h working days less leave).
        if ($by === 'person') {
            $ids = array_values(array_filter(array_map(fn (array $r) => $r['user_id'], $rows)));
            $capacity = app(Capacity::class)->load($from->toDateString(), $to->toDateString(), $ids);
            $billable = TimeEntry::query()->whereIn('user_id', $ids)->where('is_billable', true)
                ->whereDate('entry_date', '>=', $from->toDateString())->whereDate('entry_date', '<=', $to->toDateString())
                ->where(fn ($q) => $q->whereNull('started_at')->orWhereNotNull('ended_at'))
                ->groupBy('user_id')->selectRaw('user_id, sum(minutes) as m')->pluck('m', 'user_id');
            foreach ($rows as &$r) {
                if ($r['user_id'] !== null) {
                    $cap = $capacity->minutes((int) $r['user_id']);
                    $r['capacity_minutes'] = $cap;
                    $r['billable_minutes'] = (int) ($billable[$r['user_id']] ?? 0);
                    $r['utilisation_pct'] = $cap > 0 ? intdiv($r['billable_minutes'] * 100 + intdiv($cap, 2), $cap) : null;
                }
            }
            unset($r);
        }

        $out = [];
        foreach ($rows as $r) {
            unset($r['user_id']);
            $r['margin_minor'] = $r['revenue_minor'] - $r['cost_minor'];
            $r['margin_php_minor'] = $r['revenue_php_minor'] - $r['cost_php_minor'];
            $out[] = $r;
        }
        usort($out, fn (array $a, array $b) => $b['revenue_php_minor'] <=> $a['revenue_php_minor'] ?: $b['revenue_minor'] <=> $a['revenue_minor']);

        return $out;
    }

    /** PHP minor units -> a currency's minor units at a rate (PHP per unit). */
    private static function fromPhp(int $php, ?string $rate): ?int
    {
        if ($rate === null) {
            return null;
        }
        $micros = FxRates::micros($rate);

        return $micros > 0 ? Money::mulDiv($php, 1_000_000, $micros) : null;
    }

    /**
     * E-107: realised exchange gains/losses on payments received in the range, and the
     * unrealised gain/loss on open foreign invoices at today's rate.
     *
     * @return array{realised_php_minor: int, unrealised_php_minor: int, open: list<array{id: int, number: string, client: string, currency: string, balance_minor: int, invoice_rate: string|null, today_rate: string|null, unrealised_php_minor: int|null}>, missing_rates: list<string>}
     */
    public function foreignExchange(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $realised = 0;
        Payment::query()->with('invoice')
            ->whereNull('reversed_at')->where('method', '!=', 'credit')
            ->whereDate('received_on', '>=', $from->toDateString())->whereDate('received_on', '<=', $to->toDateString())
            ->whereHas('invoice', fn ($q) => $q->where('currency', '!=', FxRates::BASE))
            ->get()
            ->each(function (Payment $p) use (&$realised) {
                $inv = $p->invoice;
                if ($inv->php_rate !== null && $p->php_rate !== null) {
                    $realised += (int) FxRates::toPhp($p->amount_minor, (string) $p->php_rate) - (int) FxRates::toPhp($p->amount_minor, (string) $inv->php_rate);
                }
            });

        $today = now()->toImmutable();
        $open = [];
        $unrealised = 0;
        $missing = [];
        Invoice::query()->with('workspace:id,name')
            ->where('kind', 'invoice')->where('currency', '!=', FxRates::BASE)
            ->whereIn('status', [Invoice::FINALIZED, Invoice::PARTIALLY_PAID])
            ->orderBy('due_date')->get()
            ->each(function (Invoice $i) use (&$open, &$unrealised, &$missing, $today) {
                $todayRate = $this->fx->rate($i->currency, $today)['rate'] ?? null;
                $invRate = $i->php_rate !== null ? (string) $i->php_rate : null;
                $gain = $todayRate !== null && $invRate !== null
                    ? (int) FxRates::toPhp($i->balanceMinor(), $todayRate) - (int) FxRates::toPhp($i->balanceMinor(), $invRate) : null;
                if ($todayRate === null) {
                    $missing[$i->currency] = $i->currency;
                }
                $unrealised += $gain ?? 0;
                $open[] = ['id' => $i->id, 'number' => $i->displayNumber(), 'client' => $i->workspace->name ?? 'Deleted client', 'currency' => $i->currency,
                    'balance_minor' => $i->balanceMinor(), 'invoice_rate' => $invRate, 'today_rate' => $todayRate, 'unrealised_php_minor' => $gain];
            });

        return ['realised_php_minor' => $realised, 'unrealised_php_minor' => $unrealised, 'open' => $open, 'missing_rates' => array_values($missing)];
    }

    /**
     * E-107: open balances of every currency, in PHP at the invoices' locked rates.
     *
     * @return array{current: int, '1_30': int, '31_60': int, '61_90': int, '90_plus': int, total: int, missing: int}
     */
    public function agingInPhp(): array
    {
        $out = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, '90_plus' => 0, 'total' => 0, 'missing' => 0];
        $today = now()->toImmutable()->startOfDay();
        Invoice::query()->where('kind', 'invoice')->whereIn('status', [Invoice::FINALIZED, Invoice::PARTIALLY_PAID])->whereHas('workspace')->get()
            ->each(function (Invoice $i) use (&$out, $today) {
                $php = FxRates::toPhp($i->balanceMinor(), $i->php_rate !== null ? (string) $i->php_rate : ($i->currency === FxRates::BASE ? '1' : null));
                if ($php === null) {
                    $out['missing']++;

                    return;
                }
                if ($php <= 0) {
                    return;
                }
                $days = $i->due_date !== null ? (int) $i->due_date->startOfDay()->diffInDays($today, false) : 0;
                $bucket = match (true) {
                    $days <= 0 => 'current',
                    $days <= 30 => '1_30',
                    $days <= 60 => '31_60',
                    $days <= 90 => '61_90',
                    default => '90_plus',
                };
                $out[$bucket] += $php;
                $out['total'] += $php;
            });

        return $out;
    }

    /**
     * E-49 / E-81: open invoices one per row, with days overdue, bucket and PHP equivalent.
     *
     * @return list<array{client: string, number: string, currency: string, issue_date: string|null, due_date: string|null, days_overdue: int, bucket: string, balance_minor: int, balance_php_minor: int|null}>
     */
    public function agingRows(): array
    {
        $labels = ['current' => 'Current', '1_30' => '1-30 days', '31_60' => '31-60 days', '61_90' => '61-90 days', '90_plus' => '90+ days'];
        $today = now()->toImmutable()->startOfDay();
        $rows = [];
        Invoice::query()->with('workspace:id,name')->where('kind', 'invoice')
            ->whereIn('status', [Invoice::FINALIZED, Invoice::PARTIALLY_PAID])->whereHas('workspace')
            ->orderBy('due_date')->get()
            ->each(function (Invoice $i) use (&$rows, $today, $labels) {
                $balance = $i->balanceMinor();
                if ($balance <= 0) {
                    return;
                }
                $days = $i->due_date !== null ? (int) $i->due_date->startOfDay()->diffInDays($today, false) : 0;
                $bucket = match (true) {
                    $days <= 0 => 'current',
                    $days <= 30 => '1_30',
                    $days <= 60 => '31_60',
                    $days <= 90 => '61_90',
                    default => '90_plus',
                };
                $rows[] = ['client' => $i->workspace->name ?? 'Deleted client', 'number' => $i->displayNumber(), 'currency' => $i->currency,
                    'issue_date' => $i->issue_date?->toDateString(), 'due_date' => $i->due_date?->toDateString(),
                    'days_overdue' => max(0, $days), 'bucket' => $labels[$bucket], 'balance_minor' => $balance,
                    'balance_php_minor' => FxRates::toPhp($balance, $i->php_rate !== null ? (string) $i->php_rate : ($i->currency === FxRates::BASE ? '1' : null))];
            });

        return $rows;
    }

    /**
     * E-76: budget vs actual for every open project with a budget (amounts in the budget's currency).
     *
     * @return list<array{client: string, project: string, currency: string, budget_hours: float|null, used_hours: float, hours_pct: int|null, budget_minor: int|null, used_minor: int, amount_pct: int|null}>
     */
    public function budgets(): array
    {
        $budgets = app(BudgetService::class);
        $rows = [];
        Project::query()->with('workspace:id,name')
            ->where(fn ($q) => $q->whereNotNull('budget_minutes')->orWhereNotNull('budget_minor'))
            ->where('status', '!=', 'archived')->whereHas('workspace')
            ->orderBy('name')->get()
            ->each(function (Project $p) use (&$rows, $budgets) {
                $b = $budgets->burn($p);
                $rows[] = ['client' => $p->workspace->name, 'project' => $p->name, 'currency' => $b['currency'],
                    'budget_hours' => $b['minutes_budget'] !== null ? round($b['minutes_budget'] / 60, 2) : null,
                    'used_hours' => round($b['minutes_used'] / 60, 2), 'hours_pct' => $b['hours_pct'],
                    'budget_minor' => $b['amount_budget'], 'used_minor' => $b['amount_used'], 'amount_pct' => $b['amount_pct']];
            });
        usort($rows, fn (array $a, array $b) => max($b['hours_pct'] ?? 0, $b['amount_pct'] ?? 0) <=> max($a['hours_pct'] ?? 0, $a['amount_pct'] ?? 0));

        return $rows;
    }
}
