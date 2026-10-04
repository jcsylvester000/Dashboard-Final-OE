<?php

namespace App\Domain\Billing;

use App\Models\BillingPeriod;
use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Billing periods per client. Locking a period freezes its time entries
 * (no new, edited or deleted time dated inside it) until it is unlocked.
 */
class PeriodService
{
    /**
     * True when the date falls in a locked or invoiced period of the workspace.
     */
    public static function isLocked(int $workspaceId, string $date): bool
    {
        return DB::table('billing_periods')
            ->where('workspace_id', $workspaceId)
            ->where('status', '!=', BillingPeriod::OPEN)
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->exists();
    }

    /**
     * Create the next period: after the latest one, or the current month/quarter.
     */
    public function next(Workspace $workspace): BillingPeriod
    {
        $profile = BillingProfile::query()->where('workspace_id', $workspace->id)->first();
        $quarterly = $profile !== null && $profile->cycle === 'quarterly';

        $last = BillingPeriod::query()->where('workspace_id', $workspace->id)->latest('ends_on')->first();
        $start = $last !== null
            ? CarbonImmutable::parse($last->ends_on->toDateString())->addDay()
            : ($quarterly ? now()->toImmutable()->firstOfQuarter() : now()->toImmutable()->startOfMonth());

        $end = $quarterly ? $start->addMonthsNoOverflow(3)->subDay() : $start->addMonthNoOverflow()->subDay();

        return $this->create($workspace, $start, $end);
    }

    public function create(Workspace $workspace, CarbonImmutable $start, CarbonImmutable $end): BillingPeriod
    {
        if ($end->lt($start)) {
            throw new BillingException('The period must end on or after its start.');
        }

        $overlaps = BillingPeriod::query()
            ->where('workspace_id', $workspace->id)
            ->whereDate('starts_on', '<=', $end->toDateString())
            ->whereDate('ends_on', '>=', $start->toDateString())
            ->exists();
        if ($overlaps) {
            throw new BillingException('This period overlaps an existing one.');
        }

        return BillingPeriod::create([
            'workspace_id' => $workspace->id,
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
        ]);
    }

    public function lock(BillingPeriod $period, User $by): void
    {
        if (! $period->isOpen()) {
            return;
        }
        $period->forceFill(['status' => BillingPeriod::LOCKED, 'locked_at' => now(), 'locked_by' => $by->id])->save();
    }

    public function unlock(BillingPeriod $period): void
    {
        if ($period->status === BillingPeriod::INVOICED) {
            throw new BillingException('This period is invoiced. Void the invoice first.');
        }
        // A draft built on frozen time would go stale once time can change again.
        $period->invoices()->where('status', Invoice::DRAFT)->get()->each->delete();
        $period->forceFill(['status' => BillingPeriod::OPEN, 'locked_at' => null, 'locked_by' => null])->save();
    }
}
