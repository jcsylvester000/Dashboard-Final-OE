<?php

namespace App\Domain\Billing;

use App\Models\ClientCredit;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * E-69: statement of account for one client in one currency. Invoices add to what the
 * client owes; payments (the money actually received, overpayment included) and credit
 * notes reduce it. Void invoices, drafts and reversed payments are left out.
 *
 * Closing balance = sum of open invoice balances (the aging total) minus unused credit.
 */
class StatementService
{
    /**
     * @return array{
     *     currency: string, from: string, to: string, opening_minor: int, closing_minor: int,
     *     invoiced_minor: int, received_minor: int,
     *     lines: list<array{date: string, kind: string, ref: string, description: string, debit_minor: int, credit_minor: int, balance_minor: int}>,
     *     aging: array{current: int, '1_30': int, '31_60': int, '61_90': int, '90_plus': int, total: int},
     *     open: list<array{number: string, issue_date: string|null, due_date: string|null, days_overdue: int, balance_minor: int}>,
     *     unused_credit_minor: int
     * }
     */
    public function build(Workspace $workspace, string $currency, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $entries = [];

        $documents = Invoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('currency', $currency)
            ->whereNotIn('status', [Invoice::DRAFT, Invoice::VOID])
            ->whereNotNull('issue_date')
            ->get();
        foreach ($documents as $doc) {
            $issued = $doc->issue_date?->toDateString() ?? '';
            if ($doc->kind === 'credit_note') {
                $entries[] = ['date' => $issued, 'order' => 2, 'kind' => 'credit_note', 'ref' => $doc->displayNumber(),
                    'description' => __('Credit note'), 'debit_minor' => 0, 'credit_minor' => abs($doc->total_minor)];
            } else {
                $entries[] = ['date' => $issued, 'order' => 1, 'kind' => 'invoice', 'ref' => $doc->displayNumber(),
                    'description' => $doc->service_label ?? ($doc->period_start !== null && $doc->period_end !== null
                        ? __('Services :a – :b', ['a' => $doc->period_start->format('M j'), 'b' => $doc->period_end->format('M j, Y')])
                        : __('Invoice')),
                    'debit_minor' => $doc->total_minor, 'credit_minor' => 0];
            }
        }

        $payments = Payment::query()
            ->with('invoice:id,series,number')
            ->whereNull('reversed_at')
            ->where('method', '!=', 'credit') // using credit moves no money; the overpayment was counted when received
            ->whereHas('invoice', fn ($q) => $q->where('workspace_id', $workspace->id)->where('currency', $currency)->where('status', '!=', Invoice::VOID))
            ->get();
        foreach ($payments as $p) {
            $entries[] = ['date' => $p->received_on->toDateString(), 'order' => 3, 'kind' => 'payment', 'ref' => $p->reference ?? '',
                'description' => __('Payment, :m (:inv)', ['m' => Payment::METHODS[$p->method] ?? $p->method, 'inv' => $p->invoice->displayNumber()]),
                'debit_minor' => 0, 'credit_minor' => $p->amount_minor + $p->credit_minor];
        }

        usort($entries, fn ($a, $b) => [$a['date'], $a['order']] <=> [$b['date'], $b['order']]);

        $opening = 0;
        $balance = 0;
        $lines = [];
        $invoiced = 0;
        $received = 0;
        foreach ($entries as $e) {
            if ($e['date'] > $to->toDateString()) {
                continue;
            }
            if ($e['date'] < $from->toDateString()) {
                $opening += $e['debit_minor'] - $e['credit_minor'];

                continue;
            }
            $balance = ($lines === [] ? $opening : $balance) + $e['debit_minor'] - $e['credit_minor'];
            $invoiced += $e['debit_minor'];
            $received += $e['credit_minor'];
            unset($e['order']);
            $lines[] = [...$e, 'balance_minor' => $balance];
        }
        $closing = $lines === [] ? $opening : $balance;

        // Aging of what is still open, as of the statement date.
        $aging = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, '90_plus' => 0, 'total' => 0];
        $open = [];
        foreach ($documents->where('kind', 'invoice')->whereIn('status', [Invoice::FINALIZED, Invoice::PARTIALLY_PAID])->sortBy('due_date') as $inv) {
            $left = $inv->balanceMinor();
            if ($left <= 0) {
                continue;
            }
            $days = $inv->due_date !== null ? max(0, (int) CarbonImmutable::parse($inv->due_date->toDateString())->diffInDays($to, false)) : 0;
            $bucket = match (true) {
                $days <= 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => '90_plus',
            };
            $aging[$bucket] += $left;
            $aging['total'] += $left;
            $open[] = ['number' => $inv->displayNumber(), 'issue_date' => $inv->issue_date?->toDateString(), 'due_date' => $inv->due_date?->toDateString(),
                'days_overdue' => $days, 'balance_minor' => $left];
        }

        return [
            'currency' => $currency,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'opening_minor' => $opening,
            'closing_minor' => $closing,
            'invoiced_minor' => $invoiced,
            'received_minor' => $received,
            'lines' => $lines,
            'aging' => $aging,
            'open' => $open,
            'unused_credit_minor' => ClientCredit::balance($workspace->id, $currency),
        ];
    }
}
