<?php

namespace App\Domain\Billing;

use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\InvoiceItemSource;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Finance reports. All money is integer minor units; numbers come straight
 * from invoices, invoice lines and their time-entry sources so they tie out.
 */
class BillingReports
{
    public function __construct(private readonly RateResolver $rates) {}

    /**
     * Net invoiced (before tax) per client and issue month, credit notes subtracted. Void excluded.
     *
     * @return list<array{client: string, currency: string, month: string, net_minor: int, tax_minor: int, total_minor: int, paid_minor: int}>
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
                    'net_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0, 'paid_minor' => 0];
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
     * @return list<array{client: string, currency: string, minutes: int, value_minor: int, unpriced_minutes: int, oldest: string|null}>
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

        $out = array_values($rows);
        usort($out, fn (array $a, array $b) => $b['minutes'] <=> $a['minutes']);

        return $out;
    }

    /**
     * Billed hours per project (invoices issued in the range) against internal cost (rate card cost per hour).
     * Hours covered by a retainer carry no line amount, so retainer clients show revenue on the retainer line instead.
     *
     * @return list<array{client: string, project: string, currency: string, minutes: int, revenue_minor: int, cost_minor: int, margin_minor: int, uncosted_minutes: int}>
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
            ->get(['invoice_item_sources.source_id', 'invoice_item_sources.minutes', 'invoice_item_sources.amount_minor', 'v.currency']);

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
                'currency' => (string) $s->getAttribute('currency'), 'minutes' => 0, 'revenue_minor' => 0, 'cost_minor' => 0, 'margin_minor' => 0, 'uncosted_minutes' => 0];
            $rows[$key]['minutes'] += $minutes;
            $rows[$key]['revenue_minor'] += (int) $s->amount_minor;

            $card = $this->rates->resolve($e->workspace_id, $e->department_id, $e->user_id, $e->entry_date->toDateString());
            if ($card === null || $card->cost_minor === null) {
                $rows[$key]['uncosted_minutes'] += $minutes;
            } else {
                $rows[$key]['cost_minor'] += Money::mulDiv($minutes, $card->cost_minor, 60);
            }
        }

        foreach ($rows as &$r) {
            $r['margin_minor'] = $r['revenue_minor'] - $r['cost_minor'];
        }
        unset($r);

        $out = array_values($rows);
        usort($out, fn (array $a, array $b) => $b['revenue_minor'] <=> $a['revenue_minor']);

        return $out;
    }
}
