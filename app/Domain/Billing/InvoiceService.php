<?php

namespace App\Domain\Billing;

use App\Domain\Identity\ActivityLogger;
use App\Models\BillingPeriod;
use App\Models\BillingProfile;
use App\Models\ClientCredit;
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
        private readonly ActivityLogger $activity,
        private readonly FinanceSettings $finance,
    ) {}

    /**
     * Build (or rebuild) the draft invoice for a period. Locks the period first so
     * time can't change underneath the invoice.
     *
     * Lines:
     *  - retainer (if any); approved billable minutes up to the included hours are attached to it
     *  - hours over the retainer, grouped by department (or project / person, see Invoice::GROUPINGS)
     *    and rate (unless the overage rule is "absorb")
     *  - fixed-fee projects that are completed or due by the period end, not yet billed
     *  - billable expenses dated up to the period end, not yet billed
     */
    public function buildDraft(BillingPeriod $period, ?User $actor, ?string $grouping = null, ?Project $project = null): Invoice
    {
        if ($grouping !== null && ! array_key_exists($grouping, Invoice::GROUPINGS)) {
            throw new BillingException('Unknown line grouping.');
        }

        $period->loadMissing('workspace');
        $workspace = $period->workspace;

        if ($project !== null && $project->workspace_id !== $period->workspace_id) {
            throw new BillingException('That project belongs to another client.');
        }

        // One whole-client invoice per period; project invoices (S3-14) can sit beside each other,
        // and work already on another invoice is never billed twice (alreadyOnInvoices).
        $finalized = fn () => $period->invoices()->whereNotIn('status', [Invoice::DRAFT, Invoice::VOID]);
        if ($finalized()->whereNull('project_id')->exists()) {
            throw new BillingException('This period already has a finalized invoice.');
        }
        if ($project !== null && $finalized()->where('project_id', $project->id)->exists()) {
            throw new BillingException('This project already has a finalized invoice for this period.');
        }

        $profile = BillingProfile::query()->firstOrNew(['workspace_id' => $workspace->id]);

        return DB::transaction(function () use ($period, $workspace, $profile, $actor, $grouping, $project) {
            // Inside the transaction: a failed build (e.g. missing rate) leaves the period as it was.
            $this->periods->lock($period, $actor);
            $invoice = $period->invoices()->where('status', Invoice::DRAFT)->where('project_id', $project?->id)->first();
            $isNew = $invoice === null;
            $droppedDiscounts = 0;
            if ($invoice === null) {
                $invoice = new Invoice(['workspace_id' => $workspace->id, 'billing_period_id' => $period->id, 'project_id' => $project?->id, 'created_by' => $actor?->id]);
            } else {
                // E-63: Finance's own lines and whole-invoice discounts survive a rebuild; a discount
                // on a generated line goes with that line (the line is rebuilt with new numbers).
                $generated = $invoice->items()->where('manual', false)->pluck('id');
                $droppedDiscounts = $invoice->items()->where('type', 'discount')->whereIn('applies_to_item_id', $generated)->count();
                $invoice->items()->where('type', 'discount')->whereIn('applies_to_item_id', $generated)->get()->each->delete();
                $invoice->items()->where('manual', false)->get()->each->delete();
                $this->clearReview($invoice);
            }

            $grouping ??= $invoice->line_grouping ?? ($project !== null ? 'person' : 'department');

            $invoice->fill([
                'line_grouping' => $grouping,
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
            // The retainer belongs on the whole-client invoice only.
            if ($project === null && $profile->retainer_minor > 0) {
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
                ->with(['user:id,name', 'project:id,name'])
                ->where('workspace_id', $workspace->id)
                ->whereNotNull('approved_at')
                ->where('is_billable', true)
                ->whereNull('locked_at')
                ->where(fn ($q) => $q->whereNull('started_at')->orWhereNotNull('ended_at'))
                ->whereDate('entry_date', '>=', $from)
                ->whereDate('entry_date', '<=', $to)
                ->whereNotIn('id', $this->alreadyOnInvoices('time_entry', $invoice->id))
                ->when($project !== null, fn ($q) => $q->where('project_id', $project?->id))
                ->orderBy('entry_date')
                ->orderBy('id')
                ->get();

            $included = $retainer !== null ? $profile->included_minutes : 0;
            $absorb = $retainer !== null && $profile->overage_rule === 'absorb';
            /** @var array<string, array{department_id: int|null, label: string|null, rate: int, minutes: int, sources: list<array{0: TimeEntry, 1: int}>}> $groups */
            $groups = [];
            /** @var array<int|string, array{user_id: int|null, name: string, department_id: int|null, minutes: int, first: string, last: string}> $missing */
            $missing = [];

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

                $rate = $this->rates->resolve($workspace->id, $entry->department_id, $entry->user_id, $entry->entry_date->toDateString(), $profile->currency);
                if ($rate === null) {
                    // E-64: keep building; list the unpriced work and block only Finalize.
                    $date = $entry->entry_date->toDateString();
                    $k = $entry->user_id ?? 'none';
                    $missing[$k] ??= ['user_id' => $entry->user_id, 'name' => $entry->user->name ?? 'Former member', 'department_id' => $entry->department_id, 'minutes' => 0, 'first' => $date, 'last' => $date];
                    $missing[$k]['minutes'] += $over;
                    $missing[$k]['first'] = min($missing[$k]['first'], $date);
                    $missing[$k]['last'] = max($missing[$k]['last'], $date);

                    continue;
                }

                // Department stays part of every key so revenue-by-department reports remain exact.
                [$prefix, $label] = match ($grouping) {
                    'project' => [$entry->project !== null ? '1'.$entry->project->name : '2', $entry->project !== null ? $entry->project->name : 'Other work'],
                    'person' => [$entry->user->name ?? '', $entry->user->name ?? 'Former member'],
                    default => ['', null],
                };
                $key = $prefix.'|'.($entry->department_id ?? 0).'|'.$rate->rate_minor;
                $groups[$key] ??= ['department_id' => $entry->department_id, 'label' => $label, 'rate' => $rate->rate_minor, 'minutes' => 0, 'sources' => []];
                $groups[$key]['minutes'] += $over;
                $groups[$key]['sources'][] = [$entry, $over];
            }

            $departments = Department::query()->pluck('name', 'id');
            ksort($groups);
            foreach ($groups as $g) {
                $line = $this->item($invoice, $position++, [
                    'type' => 'hours',
                    'description' => ($g['label'] !== null ? $g['label'].' – ' : '')
                        .($g['department_id'] !== null ? ($departments[$g['department_id']] ?? 'Team') : 'Team')
                        .($g['label'] !== null ? ' hours' : ' – hours')
                        .($retainer !== null ? ' over retainer' : ''),
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
                ->when($project !== null, fn ($q) => $q->whereKey($project?->id))
                ->orderBy('id')
                ->get();
            // Not $project: that is the "only this project" parameter used by the expenses below.
            foreach ($projects as $fixed) {
                $line = $this->item($invoice, $position++, [
                    'type' => 'fixed',
                    'description' => 'Fixed fee – '.$fixed->name,
                    'quantity' => '1.00',
                    'unit_minor' => (int) $fixed->fixed_fee_minor,
                    'amount_minor' => (int) $fixed->fixed_fee_minor,
                ]);
                $this->source($line, $fixed, null, (int) $fixed->fixed_fee_minor);
            }

            // Expenses.
            $expenses = Expense::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_billable', true)
                ->whereNotNull('approved_at') // E-75: only approved expenses are billed
                ->whereNull('invoice_item_id')
                ->whereDate('incurred_on', '<=', $to)
                ->whereNotIn('id', $this->alreadyOnInvoices('expense', $invoice->id))
                ->when($project !== null, fn ($q) => $q->where('project_id', $project?->id))
                ->orderBy('incurred_on')
                ->orderBy('id')
                ->get();
            foreach ($expenses as $expense) {
                $billed = $expense->billedMinor();
                $line = $this->item($invoice, $position++, [
                    'type' => 'expense',
                    'description' => sprintf('Expense (%s) – %s%s', $expense->incurred_on->format('M j'), $expense->description,
                        $expense->markup_bp > 0 ? sprintf(' (incl. %s%% handling)', rtrim(rtrim(sprintf('%d.%02d', intdiv($expense->markup_bp, 100), $expense->markup_bp % 100), '0'), '.')) : ''),
                    'quantity' => '1.00',
                    'unit_minor' => $billed,
                    'amount_minor' => $billed,
                    'taxable' => $expense->taxable,
                ]);
                $this->source($line, $expense, null, $billed);
            }

            // Finance's lines follow the generated ones; discounts come last.
            foreach ($invoice->items()->where('manual', true)->get()->sortBy(fn (InvoiceItem $i) => [$i->type === 'discount' ? 1 : 0, $i->position, $i->id]) as $manual) {
                $manual->forceFill(['position' => $position++])->save();
            }

            $invoice->forceFill(['missing_rates' => $missing === [] ? null : array_values($missing)])->save();
            $this->recalculate($invoice);

            if ($isNew) {
                $this->history($invoice, null, Invoice::DRAFT, 'Draft created', $actor);
            } elseif ($droppedDiscounts > 0) {
                $this->history($invoice, Invoice::DRAFT, Invoice::DRAFT, sprintf('Rebuilt: %d line discount(s) removed with their lines', $droppedDiscounts), $actor);
            }

            return $invoice;
        });
    }

    /**
     * Number the invoice, freeze it and mark everything it bills as billed.
     *
     * @param  string|null  $issueDate  E-67: Y-m-d within the allowed back-dating window (default today)
     */
    public function finalize(Invoice $invoice, User $actor, ?string $issueDate = null): Invoice
    {
        if (! $invoice->isDraft()) {
            throw new BillingException('Only drafts can be finalized.');
        }
        if (! $invoice->items()->exists()) {
            throw new BillingException('There is nothing to bill on this invoice.');
        }
        if (($invoice->missing_rates ?? []) !== []) {
            throw new BillingException(__('Some hours have no :c rate yet. Add the missing rates, rebuild the draft, then finalize.', ['c' => $invoice->currency]));
        }
        if ($invoice->kind === 'invoice' && $invoice->total_minor < 0) {
            throw new BillingException('The total is below zero. Reduce the discount or issue a credit note instead.');
        }
        // E-66: maker-checker. The person who sent the draft for review cannot finalize it.
        if ($this->finance->needsReview($invoice)) {
            if ($invoice->review_requested_at === null) {
                throw new BillingException('This invoice needs a second person to check it. Send it for review first.');
            }
            if ($invoice->review_requested_by === $actor->id) {
                throw new BillingException('You sent this invoice for review, so someone else in Finance must finalize it.');
            }
        }
        $issue = $this->issueDate($invoice, $issueDate);

        $invoice = DB::transaction(function () use ($invoice, $actor, $issue) {
            $profile = BillingProfile::query()->where('workspace_id', $invoice->workspace_id)->first();

            // Postgres rejects FOR UPDATE with aggregates, so lock the highest numbered row instead.
            $last = Invoice::query()->where('series', $invoice->series)->whereNotNull('number')
                ->orderByDesc('number')->lockForUpdate()->value('number');
            $now = now();

            $invoice->forceFill([
                'number' => (int) $last + 1,
                'status' => Invoice::FINALIZED,
                'issue_date' => $issue->toDateString(),
                'due_date' => $issue->addDays($profile->terms_days ?? 15)->toDateString(),
                'bill_to' => $invoice->bill_to ?? $profile?->bill_to,
                'finalized_at' => $now,
                'finalized_by' => $actor->id,
            ])->save();

            $this->markBilled($invoice, true);

            // A project invoice leaves the period open for the client's other work.
            if ($invoice->billing_period_id !== null && $invoice->kind === 'invoice' && $invoice->project_id === null) {
                BillingPeriod::query()->whereKey($invoice->billing_period_id)->update(['status' => BillingPeriod::INVOICED]);
            }

            $this->history($invoice, Invoice::DRAFT, Invoice::FINALIZED, $invoice->review_requested_at !== null ? 'Checked and finalized' : null, $actor);

            // E-68: credit the client already has (an earlier overpayment) pays this invoice first.
            if ($invoice->kind === 'invoice') {
                $this->applyCredit($invoice, $actor);
            }

            return $invoice;
        });

        // Keep the PDF exactly as finalized. If rendering fails the invoice stays final;
        // the first download renders and stores it instead.
        try {
            app(InvoicePdf::class)->store($invoice);
        } catch (\Throwable $e) {
            report($e);
        }

        return $invoice;
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

            // E-61: the period stays Invoiced while a live whole-client invoice still covers it
            // (voiding a project invoice must not re-open the client's invoiced period).
            if ($invoice->billing_period_id !== null && $invoice->kind === 'invoice') {
                $stillInvoiced = Invoice::query()
                    ->where('billing_period_id', $invoice->billing_period_id)
                    ->where('kind', 'invoice')
                    ->whereNull('project_id')
                    ->whereNotIn('status', [Invoice::DRAFT, Invoice::VOID])
                    ->exists();
                BillingPeriod::query()->whereKey($invoice->billing_period_id)
                    ->update(['status' => $stillInvoiced ? BillingPeriod::INVOICED : BillingPeriod::LOCKED]);
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
     * Record money in. amount_minor is what settles the invoice (invoice currency) and
     * includes any bank fee or tax the client withheld (E-105). More than the balance
     * moves the excess to the client's credit (E-68) instead of being refused.
     *
     * @param  array{amount_minor: int, method: string, reference?: string|null, received_on: string, note?: string|null, fee_minor?: int, withholding_minor?: int, received_minor?: int|null, received_currency?: string|null, fx_rate?: string|null}  $data
     */
    public function recordPayment(Invoice $invoice, array $data, User $actor): Payment
    {
        if (! in_array($invoice->status, [Invoice::FINALIZED, Invoice::PARTIALLY_PAID], true) || $invoice->kind !== 'invoice') {
            throw new BillingException('Payments can only be recorded on open, finalized invoices.');
        }
        if ($data['amount_minor'] <= 0) {
            throw new BillingException('Enter the amount paid.');
        }
        $fee = $data['fee_minor'] ?? 0;
        $withheld = $data['withholding_minor'] ?? 0;
        if ($fee < 0 || $withheld < 0 || $fee + $withheld > $data['amount_minor']) {
            throw new BillingException('Fees and tax withheld are part of the amount paid, so they cannot be more than it.');
        }

        return DB::transaction(function () use ($invoice, $data, $actor) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $applied = min($data['amount_minor'], $invoice->balanceMinor());
            $excess = $data['amount_minor'] - $applied;

            $payment = Payment::create([...$data, 'amount_minor' => $applied, 'credit_minor' => $excess, 'invoice_id' => $invoice->id, 'recorded_by' => $actor->id]);
            $invoice->forceFill(['paid_minor' => $invoice->paid_minor + $applied])->save();
            if ($excess > 0) {
                ClientCredit::create(['workspace_id' => $invoice->workspace_id, 'currency' => $invoice->currency, 'amount_minor' => $excess,
                    'payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'note' => 'Overpayment on '.$invoice->displayNumber(), 'created_by' => $actor->id]);
            }
            $this->settle($invoice, $actor, sprintf('Payment %s via %s%s', Money::format($applied, $invoice->currency), Payment::METHODS[$data['method']] ?? $data['method'],
                $excess > 0 ? sprintf(' (%s extra kept as client credit)', Money::format($excess, $invoice->currency)) : ''));

            return $payment;
        });
    }

    /**
     * E-68: undo a payment recorded by mistake (bounced check, wrong invoice). The row stays,
     * marked reversed, so the history is complete. Credit created by it must still be unused.
     */
    public function reversePayment(Payment $payment, User $actor, string $reason): Payment
    {
        if ($payment->isReversed()) {
            throw new BillingException('This payment is already reversed.');
        }

        return DB::transaction(function () use ($payment, $actor, $reason) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);
            if ($invoice->status === Invoice::VOID) {
                throw new BillingException('The invoice is void.');
            }
            if ($payment->credit_minor > 0) {
                if (ClientCredit::balance($invoice->workspace_id, $invoice->currency) < $payment->credit_minor) {
                    throw new BillingException('Part of this payment became client credit that is already used. Reverse the payment that used it first.');
                }
                ClientCredit::create(['workspace_id' => $invoice->workspace_id, 'currency' => $invoice->currency, 'amount_minor' => -$payment->credit_minor,
                    'payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'note' => 'Payment reversed', 'created_by' => $actor->id]);
            }
            // Credit that paid this invoice goes back to the client.
            if ($payment->method === 'credit') {
                ClientCredit::create(['workspace_id' => $invoice->workspace_id, 'currency' => $invoice->currency, 'amount_minor' => $payment->amount_minor,
                    'payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'note' => 'Credit returned from '.$invoice->displayNumber(), 'created_by' => $actor->id]);
            }

            $payment->forceFill(['reversed_at' => now(), 'reversed_by' => $actor->id, 'reversal_reason' => $reason])->save();
            $invoice->forceFill(['paid_minor' => $invoice->paid_minor - $payment->amount_minor])->save();
            $this->settle($invoice, $actor, sprintf('Payment reversed %s: %s', Money::format($payment->amount_minor, $invoice->currency), $reason));

            return $payment;
        });
    }

    /**
     * Use the client's credit in this currency on an open invoice, up to its balance.
     */
    public function applyCredit(Invoice $invoice, User $actor): int
    {
        $available = ClientCredit::balance($invoice->workspace_id, $invoice->currency);
        $use = min($available, $invoice->balanceMinor());
        if ($use <= 0) {
            return 0;
        }

        $payment = Payment::create(['invoice_id' => $invoice->id, 'amount_minor' => $use, 'method' => 'credit', 'received_on' => now()->toDateString(),
            'note' => 'Client credit applied', 'recorded_by' => $actor->id]);
        ClientCredit::create(['workspace_id' => $invoice->workspace_id, 'currency' => $invoice->currency, 'amount_minor' => -$use,
            'payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'note' => 'Applied to '.$invoice->displayNumber(), 'created_by' => $actor->id]);
        $invoice->forceFill(['paid_minor' => $invoice->paid_minor + $use])->save();
        $this->settle($invoice, $actor, sprintf('Payment %s via %s', Money::format($use, $invoice->currency), Payment::METHODS['credit']));

        return $use;
    }

    /*
    |--------------------------------------------------------------------------
    | E-63: Finance's own lines and discounts on a draft
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array{description: string, quantity: string, unit_minor: int, taxable: bool, reason?: string|null}  $data
     */
    public function addLine(Invoice $invoice, array $data, User $actor): InvoiceItem
    {
        $this->assertDraft($invoice);
        $amount = $this->lineAmount($data['quantity'], $data['unit_minor']);

        return DB::transaction(function () use ($invoice, $data, $amount, $actor) {
            $line = $this->item($invoice, $this->nextPosition($invoice), [
                'type' => 'adjustment', 'manual' => true, 'description' => $data['description'], 'quantity' => $data['quantity'],
                'unit_minor' => $data['unit_minor'], 'amount_minor' => $amount, 'taxable' => $data['taxable'], 'reason' => $data['reason'] ?? null,
            ]);
            $this->changed($invoice, sprintf('Line added: %s %s', $data['description'], Money::format($amount, $invoice->currency)), $actor);

            return $line;
        });
    }

    /**
     * A discount as a percentage (basis points) or a fixed amount, on one line or the whole invoice.
     *
     * @param  array{percent_bp?: int|null, amount_minor?: int|null, applies_to_item_id?: int|null, reason: string}  $data
     */
    public function addDiscount(Invoice $invoice, array $data, User $actor): InvoiceItem
    {
        $this->assertDraft($invoice);
        $bp = $data['percent_bp'] ?? null;
        $fixed = $data['amount_minor'] ?? null;
        if (($bp === null) === ($fixed === null) || ($bp !== null && ($bp <= 0 || $bp > 10000)) || ($fixed !== null && $fixed <= 0)) {
            throw new BillingException('Enter either a percentage (up to 100) or an amount.');
        }
        $target = null;
        if (($data['applies_to_item_id'] ?? null) !== null) {
            $target = $invoice->items()->whereKey($data['applies_to_item_id'])->where('type', '!=', 'discount')->first();
            if ($target === null) {
                throw new BillingException('That line is not on this invoice.');
            }
        }

        return DB::transaction(function () use ($invoice, $bp, $fixed, $target, $data, $actor) {
            $label = $bp !== null ? rtrim(rtrim(sprintf('%d.%02d', intdiv($bp, 100), $bp % 100), '0'), '.').'%' : null;
            $line = $this->item($invoice, $this->nextPosition($invoice), [
                'type' => 'discount', 'manual' => true,
                'description' => trim('Discount '.($label ?? '').($target !== null ? ' on '.$target->description : '')).' – '.$data['reason'],
                'quantity' => '1.00', 'unit_minor' => -($fixed ?? 0), 'amount_minor' => -($fixed ?? 0),
                'discount_bp' => $bp, 'applies_to_item_id' => $target?->id, 'taxable' => $target->taxable ?? true, 'reason' => $data['reason'],
            ]);
            $this->changed($invoice, 'Discount added: '.$line->description, $actor);

            return $line;
        });
    }

    public function removeLine(InvoiceItem $item, User $actor): void
    {
        $invoice = Invoice::query()->findOrFail($item->invoice_id);
        $this->assertDraft($invoice);
        if (! $item->manual) {
            throw new BillingException('Generated lines come from time, fixed fees and expenses. Change those, or add a discount, then recalculate.');
        }

        DB::transaction(function () use ($invoice, $item, $actor) {
            // A discount on this line goes with it.
            $invoice->items()->where('applies_to_item_id', $item->id)->get()->each->delete();
            $item->delete();
            $this->changed($invoice, 'Line removed: '.$item->description, $actor);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | E-66: review before finalize
    |--------------------------------------------------------------------------
    */

    public function requestReview(Invoice $invoice, User $actor): void
    {
        $this->assertDraft($invoice);
        if (($invoice->missing_rates ?? []) !== [] || ! $invoice->items()->exists()) {
            throw new BillingException('Finish the draft first: every hour needs a rate and there must be something to bill.');
        }
        $invoice->forceFill(['review_requested_at' => now(), 'review_requested_by' => $actor->id, 'review_note' => null])->save();
        $this->history($invoice, Invoice::DRAFT, Invoice::DRAFT, 'Ready for review', $actor);
    }

    public function sendBack(Invoice $invoice, User $actor, string $note): void
    {
        $this->assertDraft($invoice);
        if ($invoice->review_requested_at === null) {
            throw new BillingException('This draft is not waiting for review.');
        }
        if ($invoice->review_requested_by === $actor->id) {
            throw new BillingException('Someone else in Finance reviews this draft.');
        }
        $invoice->forceFill(['review_requested_at' => null, 'review_note' => $note])->save();
        $this->history($invoice, Invoice::DRAFT, Invoice::DRAFT, 'Sent back: '.$note, $actor);
    }

    private function assertDraft(Invoice $invoice): void
    {
        if (! $invoice->isDraft()) {
            throw new BillingException('Finalized invoices cannot be edited. Issue a credit note instead.');
        }
    }

    /** Any change to a draft reopens it for review (the checker must see the final numbers). */
    private function changed(Invoice $invoice, string $note, User $actor): void
    {
        $this->clearReview($invoice);
        $this->recalculate($invoice);
        $this->history($invoice, Invoice::DRAFT, Invoice::DRAFT, $note, $actor);
    }

    private function clearReview(Invoice $invoice): void
    {
        if ($invoice->review_requested_at !== null) {
            $invoice->forceFill(['review_requested_at' => null])->save();
        }
    }

    private function nextPosition(Invoice $invoice): int
    {
        return (int) $invoice->items()->max('position') + 1;
    }

    /** "1.50" x 100000 -> 150000, rounded half away from zero. */
    private function lineAmount(string $quantity, int $unitMinor): int
    {
        if (preg_match('/^\d{1,8}(\.\d{1,2})?$/', $quantity) !== 1) {
            throw new BillingException('Quantity must be a number with up to 2 decimals.');
        }

        return Money::mulDiv(Money::parse($quantity), $unitMinor, 100);
    }

    /**
     * E-67: an issue date other than today must be inside the back-dating window and not
     * before the last invoice already issued in the same series (numbers stay in date order).
     */
    private function issueDate(Invoice $invoice, ?string $requested): CarbonImmutable
    {
        $today = now()->toImmutable()->startOfDay();
        if ($requested === null || $requested === '') {
            return $today;
        }
        $date = CarbonImmutable::parse($requested)->startOfDay(); // Y-m-d, validated by the request
        if ($date->greaterThan($today)) {
            throw new BillingException('The issue date cannot be in the future.');
        }
        $days = $this->finance->get()['backdate_days'];
        if ($date->lessThan($today->subDays($days))) {
            throw new BillingException(__('The issue date can be at most :d days back.', ['d' => $days]));
        }
        $last = Invoice::query()->where('series', $invoice->series)->whereNotNull('number')->orderByDesc('number')->value('issue_date');
        if ($last !== null && $date->lessThan(CarbonImmutable::parse((string) $last)->startOfDay())) {
            throw new BillingException(__('The last :s invoice was issued on :d; this one cannot be dated earlier.', ['s' => $invoice->series, 'd' => CarbonImmutable::parse((string) $last)->format('M j, Y')]));
        }

        return $date;
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
        $items = $invoice->items()->get();
        $lines = $items->where('type', '!=', 'discount');

        // E-63: percentage discounts follow the current amounts of what they discount.
        foreach ($items->where('type', 'discount')->whereNotNull('discount_bp') as $discount) {
            $base = $discount->applies_to_item_id !== null
                ? (int) ($lines->firstWhere('id', $discount->applies_to_item_id)->amount_minor ?? 0)
                : (int) $lines->sum('amount_minor');
            $amount = -Money::mulDiv(max(0, $base), (int) $discount->discount_bp, 10000);
            if ($discount->amount_minor !== $amount) {
                $discount->forceFill(['amount_minor' => $amount, 'unit_minor' => $amount])->save();
            }
        }

        // Tax only on taxable lines; a whole-invoice discount reduces the taxable part in proportion.
        $gross = (int) $lines->sum('amount_minor');
        $taxableGross = (int) $lines->where('taxable', true)->sum('amount_minor');
        $taxable = $taxableGross;
        $subtotal = $gross;
        foreach ($items->where('type', 'discount') as $discount) {
            $subtotal += $discount->amount_minor;
            if ($discount->applies_to_item_id !== null) {
                $taxable += $discount->taxable ? $discount->amount_minor : 0;
            } elseif ($gross > 0) {
                $taxable += Money::mulDiv($discount->amount_minor, $taxableGross, $gross);
            }
        }

        $tax = Money::tax($taxable, $invoice->tax_rate_bp);
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

        // E-62: every money event also lands in the admin activity log.
        $what = $invoice->kind === 'credit_note' ? 'credit-note' : 'invoice';
        $event = match (true) {
            $from === null && $to === Invoice::DRAFT => 'drafted',
            $from === Invoice::DRAFT && $to === Invoice::FINALIZED => 'finalized',
            $to === Invoice::VOID => 'voided',
            $note !== null && str_starts_with($note, 'Payment reversed') => 'payment-reversed',
            $note !== null && str_starts_with($note, 'Payment') => 'payment-recorded',
            $note === 'Ready for review' => 'review-requested',
            $note !== null && str_starts_with($note, 'Opened by the client') => 'viewed',
            $note !== null && str_starts_with($note, 'Follow-up') => 'followed-up',
            $note !== null && str_starts_with($note, 'Client link') => 'link-changed',
            $note !== null && str_starts_with($note, 'Sent back') => 'sent-back',
            $from === Invoice::DRAFT && $to === Invoice::DRAFT => 'adjusted',
            $note !== null && str_starts_with($note, 'Credit note') => 'credited',
            default => 'status-changed',
        };
        $this->activity->log('billing.'.$what.'-'.$event, $invoice, [
            'number' => $invoice->number !== null ? $invoice->displayNumber() : null,
            'workspace_id' => $invoice->workspace_id,
            'from' => $from,
            'to' => $to,
            'note' => $note,
            'total_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
        ], $actor);
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
