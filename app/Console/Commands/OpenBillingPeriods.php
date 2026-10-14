<?php

namespace App\Console\Commands;

use App\Domain\Billing\BillingException;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\Money;
use App\Domain\Billing\PeriodService;
use App\Domain\Identity\Permissions;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\Notifier;
use App\Models\BillingPeriod;
use App\Models\BillingProfile;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * E-73, daily: every active client with a billing profile gets a period covering today, and
 * when a retainer client's period has ended its draft invoice is built once and Finance is
 * told to check it. Safe to rerun: it never creates a second period or draft.
 */
class OpenBillingPeriods extends Command
{
    /** @var string */
    protected $signature = 'billing:periods';

    /** @var string */
    protected $description = 'Open the current billing period per client and build retainer drafts for ended periods';

    /** Ended periods older than this are left for Finance (no surprise drafts for old months). */
    private const DRAFT_LOOKBACK_DAYS = 45;

    public function handle(PeriodService $periods, InvoiceService $invoices, Notifier $notifier): int
    {
        $today = now()->toImmutable()->startOfDay();
        $opened = 0;
        $drafted = 0;
        $finance = null;

        $profiles = BillingProfile::query()->with('workspace')->get()
            ->filter(fn (BillingProfile $p) => $p->workspace !== null && $p->workspace->status === 'active');

        foreach ($profiles as $profile) {
            $workspace = $profile->workspace;

            if ($this->ensureCurrent($periods, $workspace, $profile, $today)) {
                $opened++;
            }

            if ($profile->retainer_minor <= 0) {
                continue;
            }
            $ended = BillingPeriod::query()
                ->where('workspace_id', $workspace->id)
                ->whereNull('auto_drafted_at')
                ->whereDate('ends_on', '<', $today->toDateString())
                ->whereDate('ends_on', '>=', $today->subDays(self::DRAFT_LOOKBACK_DAYS)->toDateString())
                ->whereDoesntHave('invoices', fn ($q) => $q->whereNull('project_id'))
                ->orderBy('starts_on')
                ->get();
            foreach ($ended as $period) {
                try {
                    $draft = $invoices->buildDraft($period, null);
                } catch (BillingException $e) {
                    $this->warn("{$workspace->name}: {$e->getMessage()}");

                    continue;
                }
                $period->forceFill(['auto_drafted_at' => now()])->save();
                $finance ??= User::query()->active()->get()->filter(fn (User $u) => $u->can(Permissions::BILLING_DRAFT))->pluck('id')->all();
                $notifier->send($finance, NotificationKind::BillingDraft, $workspace, [
                    'title' => __(':c: retainer draft ready', ['c' => $workspace->name]),
                    'body' => __(':s – :e · :t. Check it and finalize.', ['s' => $period->starts_on->format('M j'), 'e' => $period->ends_on->format('M j, Y'), 't' => Money::format($draft->total_minor, $draft->currency)]),
                    'url' => route('billing.invoices.show', $draft, false),
                ]);
                $drafted++;
            }
        }

        $this->info("Periods opened: {$opened}; retainer drafts built: {$drafted}");

        return self::SUCCESS;
    }

    /** Open the period that covers today (contiguous with the last one when it can be). */
    private function ensureCurrent(PeriodService $periods, Workspace $workspace, BillingProfile $profile, CarbonImmutable $today): bool
    {
        $date = $today->toDateString();
        if (BillingPeriod::query()->where('workspace_id', $workspace->id)->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->exists()) {
            return false;
        }
        $quarterly = $profile->cycle === 'quarterly';
        $start = $quarterly ? $today->firstOfQuarter() : $today->startOfMonth();
        $end = $quarterly ? $start->addMonthsNoOverflow(3)->subDay() : $start->addMonthNoOverflow()->subDay();
        $last = BillingPeriod::query()->where('workspace_id', $workspace->id)->latest('ends_on')->first();

        try {
            // Right after the last period: continue the sequence; after a gap, start this cycle fresh.
            if ($last !== null && $last->ends_on->toDateString() >= $start->subDay()->toDateString()) {
                $periods->next($workspace);
            } else {
                $periods->create($workspace, $start, $end);
            }
        } catch (BillingException $e) {
            $this->warn("{$workspace->name}: {$e->getMessage()}");

            return false;
        }

        return true;
    }
}
