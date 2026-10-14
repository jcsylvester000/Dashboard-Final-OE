<?php

namespace App\Domain\Billing;

use App\Domain\Identity\ActivityLogger;
use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSource;
use App\Models\PrepaidHours;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * E-74: hour balances a client draws on before hours are billed at rates: unused retainer
 * hours rolled over (with a cap and an expiry) and prepaid blocks. What is left is always
 * minutes minus the minutes drawn by live (non-void) invoices, so it cannot go below zero;
 * voiding an invoice gives its hours back.
 */
class PrepaidHoursService
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * Minutes drawn per balance by live invoices (drafts included, so two drafts never share hours).
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    public function drawn(array $ids, ?int $exceptInvoiceId = null): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('invoice_items as i')->join('invoices as v', 'v.id', '=', 'i.invoice_id')
            ->whereIn('i.prepaid_hours_id', $ids)
            ->where('v.status', '!=', Invoice::VOID)
            ->when($exceptInvoiceId !== null, fn ($q) => $q->where('v.id', '!=', $exceptInvoiceId))
            ->groupBy('i.prepaid_hours_id')
            ->selectRaw('i.prepaid_hours_id as id, sum(i.minutes) as m')
            ->pluck('m', 'id')
            ->mapWithKeys(fn ($m, $id) => [(int) $id => (int) $m])->all();
    }

    /**
     * Balances with hours left, in the order they are used: rolled-over hours first (soonest
     * to expire first), then blocks (soonest to expire first, open-ended last).
     *
     * @return list<array{row: PrepaidHours, left: int}>
     */
    public function available(int $workspaceId, ?int $exceptInvoiceId = null): array
    {
        $rows = PrepaidHours::query()->where('workspace_id', $workspaceId)->get();
        $drawn = $this->drawn($rows->pluck('id')->map(fn ($id) => (int) $id)->values()->all(), $exceptInvoiceId);

        $out = [];
        foreach ($rows as $row) {
            $left = $row->minutes - ($drawn[$row->id] ?? 0);
            if ($left > 0) {
                $out[] = ['row' => $row, 'left' => $left];
            }
        }
        usort($out, fn (array $a, array $b) => [
            $a['row']->kind === 'rollover' ? 0 : 1, $a['row']->expires_on === null ? 1 : 0, $a['row']->expires_on?->toDateString() ?? '', $a['row']->id,
        ] <=> [
            $b['row']->kind === 'rollover' ? 0 : 1, $b['row']->expires_on === null ? 1 : 0, $b['row']->expires_on?->toDateString() ?? '', $b['row']->id,
        ]);

        return $out;
    }

    /**
     * Every balance with what is used and left, newest first. Amounts only when asked (Finance).
     *
     * @return list<array<string, mixed>>
     */
    public function summary(int $workspaceId, bool $withMoney): array
    {
        $rows = PrepaidHours::query()->with('invoice:id,series,number,status')->where('workspace_id', $workspaceId)
            ->latest('starts_on')->latest('id')->limit(50)->get();
        $drawn = $this->drawn($rows->pluck('id')->map(fn ($id) => (int) $id)->values()->all());
        $today = now()->toDateString();

        return $rows->map(function (PrepaidHours $p) use ($drawn, $today, $withMoney): array {
            $used = $drawn[$p->id] ?? 0;
            $row = [
                'id' => $p->id,
                'kind' => $p->kind,
                'label' => $p->label(),
                'minutes' => $p->minutes,
                'used_minutes' => $used,
                'left_minutes' => max(0, $p->minutes - $used),
                'starts_on' => $p->starts_on->toDateString(),
                'expires_on' => $p->expires_on?->toDateString(),
                'expired' => $p->expires_on !== null && $p->expires_on->toDateString() < $today,
            ];
            if ($withMoney) {
                $row += [
                    'amount_minor' => $p->amount_minor,
                    'currency' => $p->currency,
                    'invoice' => $p->invoice !== null ? ['id' => $p->invoice->id, 'number' => $p->invoice->displayNumber(), 'status' => $p->invoice->status] : null,
                    'can_delete' => $used === 0 && ($p->invoice === null || in_array($p->invoice->status, [Invoice::DRAFT, Invoice::VOID], true)),
                ];
            }

            return $row;
        })->values()->all();
    }

    /**
     * At finalize: unused included hours of the retainer roll over (up to the cap) and stay
     * usable for the set number of months after the period.
     */
    public function rolloverFrom(Invoice $invoice, BillingProfile $profile, User $actor): ?PrepaidHours
    {
        if (! $profile->rollover_enabled || $profile->included_minutes <= 0 || $invoice->period_end === null) {
            return null;
        }
        $retainer = InvoiceItem::query()->where('invoice_id', $invoice->id)->where('type', 'retainer')->first();
        if ($retainer === null) {
            return null;
        }
        $used = (int) InvoiceItemSource::query()->where('invoice_item_id', $retainer->id)->sum('minutes');
        $unused = $profile->included_minutes - min($profile->included_minutes, $used);
        if ($profile->rollover_cap_minutes !== null) {
            $unused = min($unused, $profile->rollover_cap_minutes);
        }
        if ($unused <= 0) {
            return null;
        }

        $end = $invoice->period_end->toImmutable();
        $row = PrepaidHours::create([
            'workspace_id' => $invoice->workspace_id,
            'kind' => 'rollover',
            'minutes' => $unused,
            'currency' => $invoice->currency,
            'starts_on' => $end->addDay()->toDateString(),
            'expires_on' => $end->addMonthsNoOverflow(max(1, $profile->rollover_months))->endOfMonth()->toDateString(),
            'source_invoice_id' => $invoice->id,
            'created_by' => $actor->id,
        ]);
        $this->activity->log('billing.hours-rolled-over', $invoice, ['minutes' => $unused, 'expires_on' => $row->expires_on?->toDateString()], $actor);

        return $row;
    }

    /**
     * Voiding the invoice takes back the hours it rolled over, unless another invoice already used them.
     */
    public function dropRollover(Invoice $invoice): void
    {
        $rows = PrepaidHours::query()->where('source_invoice_id', $invoice->id)->get();
        $drawn = $this->drawn($rows->pluck('id')->map(fn ($id) => (int) $id)->values()->all());
        foreach ($rows as $row) {
            if (($drawn[$row->id] ?? 0) === 0) {
                $row->delete();
            }
        }
    }
}
