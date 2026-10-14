<?php

namespace App\Console\Commands;

use App\Domain\Billing\Money;
use App\Domain\Identity\Permissions;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\Notifier;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Daily: tell Finance (in-app) about client invoices that passed their due date (once per
 * invoice), then E-72 follow-up stages at 7, 14 and 30 days overdue. Stage 3 also tells the
 * client's leads. A promise-to-pay date pauses the stages; when it passes, Finance hears.
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

        $stages = $this->followUps($notifier, $finance);

        $this->info("Overdue invoice alerts: {$count}; follow-up alerts: {$stages}");

        return self::SUCCESS;
    }

    /** Days overdue that start each follow-up stage. */
    public const STAGES = [1 => 7, 2 => 14, 3 => 30];

    /**
     * @param  list<int>  $finance
     */
    private function followUps(Notifier $notifier, array $finance): int
    {
        $today = now()->toDateString();
        $sent = 0;

        Invoice::query()
            ->with('workspace')
            ->where('kind', 'invoice')
            ->whereIn('status', [Invoice::FINALIZED, Invoice::PARTIALLY_PAID])
            ->whereDate('due_date', '<=', now()->subDays(self::STAGES[1])->toDateString())
            ->whereHas('workspace')
            ->lazyById()
            ->each(function (Invoice $invoice) use ($notifier, $finance, $today, &$sent) {
                $workspace = $invoice->workspace;
                $url = route('billing.invoices.show', $invoice, false);

                // A broken promise: tell Finance once and let the stages run again.
                if ($invoice->promise_to_pay_on !== null && $invoice->promise_to_pay_on->toDateString() < $today) {
                    $notifier->send($finance, NotificationKind::InvoiceOverdue, $workspace, [
                        'title' => __(':n: promised payment date passed', ['n' => $invoice->displayNumber()]),
                        'body' => __('The client promised to pay by :d. :b still due.', ['d' => $invoice->promise_to_pay_on->format('M j'), 'b' => Money::format($invoice->balanceMinor(), $invoice->currency)]),
                        'url' => $url,
                    ]);
                    $invoice->forceFill(['promise_to_pay_on' => null])->save();
                    $sent++;

                    return;
                }
                if ($invoice->promise_to_pay_on !== null) {
                    return; // paused until the promised date
                }

                $days = $invoice->daysOverdue();
                $stage = 0;
                foreach (self::STAGES as $s => $from) {
                    if ($days >= $from) {
                        $stage = $s;
                    }
                }
                if ($stage <= $invoice->collection_stage) {
                    return;
                }

                $payload = [
                    'title' => __(':n is :d days overdue', ['n' => $invoice->displayNumber(), 'd' => $days]),
                    'body' => __(':b due. Follow up with the client and log it on the invoice.', ['b' => Money::format($invoice->balanceMinor(), $invoice->currency)]),
                    'url' => $url,
                ];
                $notifier->send($finance, NotificationKind::InvoiceOverdue, $workspace, $payload);
                if ($stage >= 3) {
                    // Leads don't see amounts (members never see invoice totals).
                    $leads = $workspace->members()->wherePivotIn('role', [Workspace::ROLE_OWNER, Workspace::ROLE_LEAD])->pluck('users.id')
                        ->map(fn ($id) => (int) $id)->reject(fn (int $id) => in_array($id, $finance, true))->values()->all();
                    $notifier->send($leads, NotificationKind::InvoiceOverdue, $workspace, [
                        'title' => __(':c: an invoice is :d days overdue', ['c' => $workspace->name, 'd' => $days]),
                        'body' => __('Finance may ask you to help follow up with the client.'),
                        'url' => route('workspaces.show', $workspace, false),
                    ]);
                }
                $invoice->forceFill(['collection_stage' => $stage])->save();
                $sent++;
            });

        return $sent;
    }
}
