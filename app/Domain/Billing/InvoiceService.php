<?php

namespace App\Domain\Billing;

use App\Models\BillingPeriod;
use App\Models\BillingProfile;
use App\Models\Department;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceHistory;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSource;
use App\Models\Payment;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Invoice lifecycle: build draft from a period -> finalize (numbered, immutable)
 * -> payments / void / credit note. Every status change is written to invoice_history.
 */
class InvoiceService
{
    public function __construct(
        private readonly RateResolver $rates,
        private readonly PeriodService $periods,
    ) {}

    /**
     * Build (or rebuild) the draft invoice for a period. Locks the period first so
     * time can't change underneath the invoice.
     *
     * Lines:
     *  - retainer (if any); approved billable minutes up to the included hours are attached to it
     *  - hours over the retainer, grouped by department and rate (unless the overage rule is "absorb")
     *  - fixed-fee projects that are completed or due by the period end, not yet billed
     *  - billable expenses dated up to the period end, not yet billed
     */
    public function buildDraft(BillingPeriod $period, User $actor): Invoice
    {
        $period->loadMissing('workspace');
        $workspace = $period->workspace;

        $billed = $period->invoices()->whereNotIn('status', [Invoice::DRAFT, Invoice::VOID])->exists();
        if ($billed) {
            throw new BillingException('This period already has a finalized invoice.');
        }

        $profile = BillingProfile::query()->firstOrNew(['workspace_id' => $workspace->id]);

        return DB::transaction(function () use ($period, $workspace, $profile, $actor) {
            // Inside the transaction: a failed build (e.g. missing rate) leaves the period as it was.
            $this->periods->lock($period, $actor);
            $invoice = $period->invoices()->where('status', Invoice::DRAFT)->first();
            $isNew = $invoice === null;
            if ($invoice === null) {
                $invoice = new Invoice(['workspace_id' => $workspace->id, 'billing_period_id' => $period->id, 'created_by' => $actor->id]);
            } else {
                $invoice->items()->get()->each->delete();
            }

            $invoice->fill([
                'series' => $profile->invoice_series,
                'currency' => $profile->currency,
                'period_start' => $period->starts_on->toDateString(),
                'period_end' => $period->ends_on->toDateString(),
                'tax_rate_bp' => $profile->tax_rate_bp,
            ])->save();

            $from = $period->starts_on->toDateString();
            $to = $period->ends_on->toDateString();
            $position = 0;

            // Retainer and hours.
            $retainer = null;
            if ($profile->retainer_minor > 0) {
                $retainer = $this->item($invoice, $position++, [
                    'type' => 'retainer',
                    'description' => sprintf('Retainer %s – %s%s', $period->starts_on->format('M j'), $period->ends_on->format('M j, Y'),
                        $profile->included_minutes > 0 ? sprintf(' (includes %s h)', $this->hours($profile->included_minutes)) : ''),
                    'quantity' => '1.00',
                    'unit_minor' => $profile->retainer_minor,
                    'amount_minor' => $profile->retainer_minor,
                ]);
            }

            $entries = TimeEntry::query()
                ->with('user:id,name')
                ->where('workspace_id', $workspace->id)
                ->whereNotNull('approved_at')
                ->where('is_billable', true)
                ->whereNull('locked_at')
                ->where(fn ($q) => $q->whereNull('started_at')->orWhereNotNull('ended_at'))
                ->whereDate('entry_date', '>=', $from)
                ->whereDate('entry_date', '<=', $to)
                ->whereNotIn('id', $this->alreadyOnInvoices('time_entry', $invoice->id))
                ->orderBy('entry_date')
                ->orderBy('id')
                ->get();

            $included = $retainer !== null ? $profile->included_minutes : 0;
            $absorb = $retainer !== null && $profile->overage_rule === 'absorb';
            /** @var array<string, array{department_id: int|null, rate: int, minutes: int, sources: list<array{0: TimeEntry, 1: int}>}> $groups */
            $groups = [];

            foreach ($entries as $entry) {
                $covered = min($included, $entry->minutes);
                $included -= $covered;
                $over = $entry->minutes - $covered;

                if ($retainer !== null && ($covered > 0 || ($absorb && $over > 0))) {
                    $this->source($retainer, $entry, $covered + ($absorb ? $over : 0), 0);
                }
                if ($over === 0 || $absorb) {
                    continue;
                }

                $rate = $this->rates->resolve($workspace->id, $entry->department_id, $entry->user_id, $entry->entry_date->toDateString());
                if ($rate === null) {
                    throw new BillingException(sprintf(
                        'No hourly rate for %s on %s. Add a rate card in Billing > Rates.',
                        $entry->user->name ?? 'a team member',
                        $entry->entry_date->toDateString(),
                    ));
                }

                $key = ($entry->department_id ?? 0).'|'.$rate->rate_minor;
                $groups[$key] ??= ['department_id' => $entry->department_id, 'rate' => $rate->rate_minor, 'minutes' => 0, 'sources' => []];
                $groups[$key]['minutes'] += $over;
                $groups[$key]['sources'][] = [$entry, $over];
            }

            $departments = Department::query()->pluck('name', 'id');
            ksort($groups);
            foreach ($groups as $g) {
                $line = $this->item($invoice, $position++, [
                    'type' => 'hours',
                    'description' => ($g['department_id'] !== null ? ($departments[$g['department_id']] ?? 'Team') : 'Team')
                        .($retainer !== null ? ' – hours over retainer' : ' – hours'),
                    'department_id' => $g['department_id'],
                    'minutes' => $g['minutes'],
                    'quantity' => $this->hours($g['minutes']),
                    'unit_minor' => $g['rate'],
                    'amount_minor' => Money::mulDiv($g['minutes'], $g['rate'], 60),
                ]);
                foreach ($g['sources'] as [$entry, $minutes]) {
                    $this->source($line, $entry, $minutes, Money::mulDiv($minutes, $g['rate'], 60));
                }
            }

            // Fixed fees.
            $projects = Project::query()
                ->where('workspace_id', $workspace->id)
                ->where('fixed_fee_minor', '>', 0)
                ->whereNull('fixed_fee_billed_at')
                ->where(fn ($q) => $q->where('status', 'completed')->orWhereDate('due_on', '<=', $to))
                ->whereNotIn('id', $this->alreadyOnInvoices('project', $invoice->id))
                ->orderBy('id')
                ->get();
            foreach ($projects as $project) {
                $line = $this->item($invoice, $position++, [
                    'type' => 'fixed',
                    'description' => 'Fixed fee – '.$project->name,
                    'quantity' => '1.00',
                    'unit_minor' => (int) $project->fixed_fee_minor,
                    'amount_minor' => (int) $project->fixed_fee_minor,
                ]);
                $this->source($line, $project, null, (int) $project->fixed_fee_minor);
            }

            // Expenses.
            $expenses = Expense::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_billable', true)
                ->whereNull('invoice_item_id')
                ->whereDate('incurred_on', '<=', $to)
                ->whereNotIn('id', $this->alreadyOnInvoices('expense', $invoice->id))
                ->orderBy('incurred_on')
                ->orderBy('id')
                ->get();
            foreach ($expenses as $expense) {
                $line = $this->item($invoice, $position++, [
                    'type' => 'expense',
                    'description' => sprintf('Expense (%s) – %s', $expense->incurred_on->format('M j'), $expense->description),
                    'quantity' => '1.00',
                    'unit_minor' => $expense->amount_minor,
                    'amount_minor' => $expense->amount_minor,
                ]);
                $this->source($line, $expense, null, $expense->amount_minor);
            }

            $this->recalculate($invoice);

            if ($isNew) {
                $this->history($invoice, null, Invoice::DRAFT, 'Draft created', $actor);
            }

            return $invoice;
        });
    }

    /**
     * Number the invoice, freeze it and mark everything it bills as billed.
     */
    public function finalize(Invoice $invoice, User $actor): Invoice
    {
        if (! $invoice->isDraft()) {
            throw new BillingException('Only drafts can be finalized.');
        }
        if (! $invoice->items()->exists()) {
            throw new BillingException('There is nothing to bill on this invoice.');
        }

        return DB::transaction(function () use ($invoice, $actor) {
            $profile = BillingProfile::query()->where('workspace_id', $invoice->workspace_id)->first();

            // Postgres rejects FOR UPDATE with aggregates, so lock the highest numbered row instead.
            $last = Invoice::query()->where('series', $invoice->series)->whereNotNull('number')
                ->orderByDesc('number')->lockForUpdate()->value('number');
            $now = now();

            $invoice->forceFill([
                'number' => (int) $last + 1,
                'status' => Invoice::FINALIZED,
                'issue_date' => $now->toDateString(),
                'due_date' => $now->addDays($profile->terms_days ?? 15)->toDateString(),
                'bill_to' => $invoice->bill_to ?? $profile?->bill_to,
                'finalized_at' => $now,
                'finalized_by' => $actor->id,
            ])->save();

            $this->markBilled($invoice, true);

            if ($invoice->billing_period_id !== null && $invoice->kind === 'invoice') {
                BillingPeriod::query()->whereKey($invoice->billing_period_id)->update(['status' => BillingPeriod::INVOICED]);
            }

            $this->history($invoice, Invoice::DRAFT, Invoice::FINALIZED, null, $actor);

            return $invoice;
        });
    }

    /**
     * Cancel a finalized, unpaid invoice. Its time, expenses and fixed fees become billable again.
     */
    public function void(Invoice $invoice, User $actor, string $reason): Invoice
    {
        if ($invoice->isDraft() || $invoice->status === Invoice::VOID) {
            throw new BillingException('Only finalized invoices can be voided. Delete a draft instead.');
        }
        if ($invoice->paid_minor > 0 || $invoice->credited_minor > 0) {
            throw new BillingException('This invoice has payments or credits. Issue a credit note instead.');
        }

        return DB::transaction(function () use ($invoice, $actor, $reason) {
            $from = $invoice->status;
            $invoice->forceFill(['status' => Invoice::VOID])->save();
            $this->markBilled($invoice, false);

            if ($invoice->billing_period_id !== null && $invoice->kind === 'invoice') {
                BillingPeriod::query()->whereKey($invoice->billing_period_id)->update(['status' => BillingPeriod::LOCKED]);
            }
            if ($invoice->kind === 'credit_note' && $invoice->credited_invoice_id !== null) {
                $original = Invoice::query()->findOrFail($invoice->credited_invoice_id);
                $original->forceFill(['credited_minor' => $original->credited_minor + $invoice->total_minor])->save();
                $this->settle($original, $actor, 'Credit note '.$invoice->displayNumber().' voided');
            }

            $this->history($invoice, $from, Invoice::VOID, $reason, $actor);

            return $invoice;
        });
    }

    /**
     * Correct a finalized invoice: a numbered credit note (series CN) for part or all of it.
     * $amountMinor is before tax; the same tax rate applies.
     */
    public function creditNote(Invoice $invoice, int $amountMinor, string $reason, User $actor): Invoice
    {
        if (! in_array($invoice->status, [Invoice::FINALIZED, Invoice::PARTIALLY_PAID, Invoice::PAID], true) || $invoice->kind !== 'invoice') {
            throw new BillingException('Credit notes can only be issued against finalized invoices.');
        }
        $total = $amountMinor + Money::tax($amountMinor, $invoice->tax_rate_bp);
        if ($amountMinor <= 0 || $total > $invoice->total_minor - $invoice->credited_minor) {
            throw new BillingException('The credit is more than what is left on the invoice.');
        }

        return DB::transaction(function () use ($invoice, $amountMinor, $reason, $actor, $total) {
            $credit = Invoice::create([
                'workspace_id' => $invoice->workspace_id,
                'kind' => 'credit_note',
                'credited_invoice_id' => $invoice->id,
                'series' => 'CN',
                'currency' => $invoice->currency,
                'period_start' => $invoice->period_start?->toDateString(),
                'period_end' => $invoice->period_end?->toDateString(),
                'tax_rate_bp' => $invoice->tax_rate_bp,
                'bill_to' => $invoice->bill_to,
                'notes' => $reason,
                'created_by' => $actor->id,
            ]);
            $this->item($credit, 0, [
                'type' => 'credit',
                'description' => 'Credit for '.$invoice->displayNumber().' – '.$reason,
                'quantity' => '1.00',
                'unit_minor' => -$amountMinor,
                'amount_minor' => -$amountMinor,
            ]);
            $this->recalculate($credit);
            $this->history($credit, null, Invoice::DRAFT, null, $actor);
            $this->finalize($credit, $actor);

            $invoice->forceFill(['credited_minor' => $invoice->credited_minor + $total])->save();
            $this->settle($invoice, $actor, 'Credit note '.$credit->displayNumber());

            return $credit;
        });
    }

    /**
     * @param  array{amount_minor: int, method: string, reference?: string|null, received_on: string, note?: string|null}  $data
     */
    public function recordPayment(Invoice $invoice, array $data, User $actor): Payment
    {
        if (! in_array($invoice->status, [Invoice::FINALIZED, Invoice::PARTIALLY_PAID], true) || $invoice->kind !== 'invoice') {
            throw new BillingException('Payments can only be recorded on open, finalized invoices.');
        }
        if ($data['amount_minor'] <= 0 || $data['amount_minor'] > $invoice->balanceMinor()) {
            throw new BillingException('The payment is more than the balance due.');
        }

        return DB::transaction(function () use ($invoice, $data, $actor) {
            $payment = Payment::create([...$data, 'invoice_id' => $invoice->id, 'recorded_by' => $actor->id]);
            $invoice->forceFill(['paid_minor' => $invoice->paid_minor + $data['amount_minor']])->save();
            $this->settle($invoice, $actor, sprintf('Payment %s via %s', Money::format($data['amount_minor'], $invoice->currency), Payment::METHODS[$data['method']] ?? $data['method']));

            return $payment;
        });
    }

    /**
     * Move an open invoice to paid / partially paid from its balance.
     */
    private function settle(Invoice $invoice, User $actor, string $note): void
    {
        $to = match (true) {
            $invoice->balanceMinor() <= 0 => Invoice::PAID,
            $invoice->paid_minor > 0 || $invoice->credited_minor > 0 => Invoice::PARTIALLY_PAID,
            default => Invoice::FINALIZED,
        };
        $from = $invoice->status;
        if ($from !== $to) {
            $invoice->forceFill(['status' => $to])->save();
        }
        $this->history($invoice, $from, $to, $note, $actor);
    }

    /**
     * Subtotal, tax and total from the lines.
     */
    private function recalculate(Invoice $invoice): void
    {
        $subtotal = (int) $invoice->items()->sum('amount_minor');
        $tax = Money::tax($subtotal, $invoice->tax_rate_bp);
        $invoice->forceFill(['subtotal_minor' => $subtotal, 'tax_minor' => $tax, 'total_minor' => $subtotal + $tax])->save();
    }

    /**
     * Mark (or un-mark) the time entries, expenses and fixed fees this invoice bills.
     */
    private function markBilled(Invoice $invoice, bool $billed): void
    {
        $sources = InvoiceItemSource::query()
            ->whereIn('invoice_item_id', $invoice->items()->select('id'))
            ->get(['invoice_item_id', 'source_type', 'source_id']);

        $ids = fn (string $type) => $sources->where('source_type', $type)->pluck('source_id')->all();

        TimeEntry::query()->whereIn('id', $ids('time_entry'))->update(['locked_at' => $billed ? now() : null]);
        Project::query()->whereIn('id', $ids('project'))->update(['fixed_fee_billed_at' => $billed ? now() : null]);

        foreach ($sources->where('source_type', 'expense') as $s) {
            Expense::query()->whereKey($s->source_id)->update(['invoice_item_id' => $billed ? $s->invoice_item_id : null]);
        }
    }

    /**
     * Ids of a source type already on another live (non-void) invoice.
     */
    private function alreadyOnInvoices(string $type, int $exceptInvoiceId): QueryBuilder
    {
        return DB::table('invoice_item_sources as s')
            ->join('invoice_items as i', 'i.id', '=', 's.invoice_item_id')
            ->join('invoices as v', 'v.id', '=', 'i.invoice_id')
            ->where('s.source_type', $type)
            ->where('v.status', '!=', Invoice::VOID)
            ->where('v.id', '!=', $exceptInvoiceId)
            ->select('s.source_id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function item(Invoice $invoice, int $position, array $attributes): InvoiceItem
    {
        return InvoiceItem::create([...$attributes, 'invoice_id' => $invoice->id, 'position' => $position]);
    }

    private function source(InvoiceItem $item, TimeEntry|Expense|Project $source, ?int $minutes, int $amountMinor): void
    {
        InvoiceItemSource::create([
            'invoice_item_id' => $item->id,
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'minutes' => $minutes,
            'amount_minor' => $amountMinor,
        ]);
    }

    public function history(Invoice $invoice, ?string $from, string $to, ?string $note, ?User $actor): void
    {
        InvoiceHistory::create([
            'invoice_id' => $invoice->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'user_id' => $actor?->id,
        ]);
    }

    /** 90 -> "1.50" */
    private function hours(int $minutes): string
    {
        return sprintf('%d.%02d', intdiv($minutes, 60), intdiv(($minutes % 60) * 100 + 30, 60));
    }

    /**
     * Open invoices bucketed by days past due (current, 1-30, 31-60, 61-90, 90+), per currency.
     *
     * @return array<string, array<string, int>>
     */
    public function aging(?CarbonImmutable $asOf = null): array
    {
        $today = ($asOf ?? now()->toImmutable())->startOfDay();
        $buckets = [];

        Invoice::query()
            ->where('kind', 'invoice')
            ->whereIn('status', [Invoice::FINALIZED, Invoice::PARTIALLY_PAID])
            ->whereHas('workspace')
            ->get()
            ->each(function (Invoice $inv) use (&$buckets, $today) {
                $balance = $inv->balanceMinor();
                if ($balance <= 0) {
                    return;
                }
                $days = $inv->due_date !== null ? (int) $inv->due_date->startOfDay()->diffInDays($today, false) : 0;
                $bucket = match (true) {
                    $days <= 0 => 'current',
                    $days <= 30 => '1_30',
                    $days <= 60 => '31_60',
                    $days <= 90 => '61_90',
                    default => '90_plus',
                };
                $buckets[$inv->currency] ??= ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, '90_plus' => 0, 'total' => 0];
                $buckets[$inv->currency][$bucket] += $balance;
                $buckets[$inv->currency]['total'] += $balance;
            });

        return $buckets;
    }
}
