<?php

namespace App\Console\Commands;

use App\Domain\Budgets\BudgetService;
use App\Domain\Notifications\Notifier;
use Illuminate\Console\Command;

/**
 * E-76: hourly, alert leads and Finance when a project passes 75, 90 or 100 % of its budget (once each).
 */
class CheckProjectBudgets extends Command
{
    /** @var string */
    protected $signature = 'budgets:check';

    /** @var string */
    protected $description = 'Send project budget burn alerts (75 / 90 / 100 %)';

    public function handle(BudgetService $budgets, Notifier $notifier): int
    {
        $this->info('Budget alerts sent: '.$budgets->checkAlerts($notifier));

        return self::SUCCESS;
    }
}
