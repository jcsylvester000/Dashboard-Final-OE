<?php

namespace App\Domain\Billing;

use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\InvoiceItemSource;
use App\Models\Payment;
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

    /**
     * Billed hours per project (invoices issued in the range) against internal cost (rate card cost per hour).
     * Hours covered by a retainer carry no line amount, so retainer clients show revenue on the retainer line instead.
     *
     * E-108: revenue and cost are also given in PHP at the invoice's locked rate (costs on
     * PHP rate cards are already PHP), so margins compare like with like.
     *
     * @return list<array{client: string, project: string, currency: string, minutes: int, revenue_minor: int, cost_minor: int, margin_minor: int, uncosted_minutes: int, revenue_php_minor: int, cost_php_minor: int, margin_php_minor: int, php_missing: bool}>
     */
    public function profitability(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sources = InvoiceItemSource::query()
            ->join('invoice_items as i', 'i.id', '=', 'invoice_item_sources.invoice_item_id')
            ->join('invoices as v', 'v.id', '=', 'i.invoice_id')
            ->where('invoice_item_sources.source_type', (new TimeEntry)->getMorphClass())
            ->whereNotIn('v.status', [Invoice::DRAFT, Invoice::VOID])
            ->where('v.kind', 'invoice')
            ->whereDate('v.issue_date', '>=', $from->toDateString())
            ->whereDate('v.issue_date', '<=', $to->toDateString())
            ->get(['invoice_item_sources.source_id', 'invoice_item_sources.minutes', 'invoice_item_sources.amount_minor', 'v.currency', 'v.php_rate']);

        $entries = TimeEntry::query()->withTrashed()
            ->with(['workspace:id,name', 'project:id,name'])
            ->whereIn('id', $sources->pluck('source_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $rows = [];
        foreach ($sources as $s) {
            $e = $entries->get($s->source_id);
            if ($e === null) {
                continue;
            }
            $minutes = (int) $s->minutes;
            $key = $e->workspace_id.'|'.($e->project_id ?? 0);
            $rows[$key] ??= ['client' => $e->workspace->name ?? 'Deleted client', 'project' => $e->project->name ?? 'No project',
                'currency' => (string) $s->getAttribute('currency'), 'minutes' => 0, 'revenue_minor' => 0, 'cost_minor' => 0, 'margin_minor' => 0, 'uncosted_minutes' => 0,
                'revenue_php_minor' => 0, 'cost_php_minor' => 0, 'margin_php_minor' => 0, 'php_missing' => false];
            $currency = (string) $s->getAttribute('currency');
            $invRate = $s->getAttribute('php_rate') !== null ? (string) $s->getAttribute('php_rate') : ($currency === FxRates::BASE ? '1' : null);
            $rows[$key]['minutes'] += $minutes;
            $rows[$key]['revenue_minor'] += (int) $s->amount_minor;
            $revPhp = FxRates::toPhp((int) $s->amount_minor, $invRate);
            $rows[$key]['revenue_php_minor'] += $revPhp ?? 0;
            $rows[$key]['php_missing'] = $rows[$key]['php_missing'] || $revPhp === null;

            $card = $this->rates->resolve($e->workspace_id, $e->department_id, $e->user_id, $e->entry_date->toDateString());
            if ($card === null || $card->cost_minor === null) {
                $rows[$key]['uncosted_minutes'] += $minutes;
            } else {
                $cost = Money::mulDiv($minutes, $card->cost_minor, 60);
                $rows[$key]['cost_minor'] += $cost;
                // Costs on a PHP card are PHP already; a client-currency card converts at the invoice rate.
                $rows[$key]['cost_php_minor'] += ($card->currency === FxRates::BASE ? $cost : FxRates::toPhp($cost, $invRate)) ?? 0;
            }
        }

        foreach ($rows as &$r) {
            $r['margin_minor'] = $r['revenue_minor'] - $r['cost_minor'];
            $r['margin_php_minor'] = $r['revenue_php_minor'] - $r['cost_php_minor'];
        }
        unset($r);

        $out = array_values($rows);
        usort($out, fn (array $a, array $b) => $b['revenue_minor'] <=> $a['revenue_minor']);

        return $out;
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
}
