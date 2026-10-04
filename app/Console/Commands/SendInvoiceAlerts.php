<?php

namespace App\Console\Commands;

use App\Domain\Billing\Money;
use App\Domain\Identity\Permissions;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\Notifier;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Daily: tell Finance (in-app) about client invoices that passed their due date. Once per invoice.
 */
class SendInvoiceAlerts extends Command
{
    /** @var string */
    protected $signature = 'billing:alerts';

    /** @var string */
    protected $description = 'Alert Finance in-app about overdue client invoices';

    public function handle(Notifier $notifier): int
    {
        $finance = User::query()->active()->get()
            ->filter(fn (User $u) => $u->can(Permissions::BILLING_MANAGE))
            ->pluck('id')
            ->all();

        $count = 0;
        Invoice::query()
            ->with('workspace')
            ->where('kind', 'invoice')
            ->whereIn('status', [Invoice::FINALIZED, Invoice::PARTIALLY_PAID])
            ->whereNull('overdue_alerted_at')
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereHas('workspace')
            // By id, not offset: each alerted invoice drops out of the where clause.
            ->lazyById()
            ->each(function (Invoice $invoice) use ($notifier, $finance, &$count) {
                $notifier->send($finance, NotificationKind::InvoiceOverdue, $invoice->workspace, [
                    'title' => __('Invoice :number is overdue', ['number' => $invoice->displayNumber()]),
                    'body' => __(':balance due since :date', [
                        'balance' => Money::format($invoice->balanceMinor(), $invoice->currency),
                        'date' => $invoice->due_date?->format('M j, Y'),
                    ]),
                    'url' => route('billing.invoices.show', $invoice, false),
                ]);
                $invoice->forceFill(['overdue_alerted_at' => now()])->save();
                $count++;
            });

        $this->info("Overdue invoice alerts: {$count}");

        return self::SUCCESS;
    }
}
