<?php

namespace App\Domain\Budgets;

use App\Domain\Billing\Money;
use App\Domain\Billing\RateResolver;
use App\Domain\Identity\Permissions;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\Notifier;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\Expense;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * E-76 / E-110: project budgets. Hours burn = all finished time logged on the project.
 * Amount burn = billable time at the client's rates in the budget currency, plus the
 * project's billable expenses (cost + markup). Alerts at 75, 90 and 100 %, each once:
 * leads hear about hours, Finance (billing.view) about hours and amounts. Members see neither.
 */
class BudgetService
{
    /** Burn steps that send an alert. */
    public const STEPS = [75, 90, 100];

    public function __construct(
        private readonly RateResolver $rates,
        private readonly WorkspaceAccess $access,
    ) {}

    /**
     * @return array{minutes_budget: int|null, minutes_used: int, hours_pct: int|null, currency: string, amount_budget: int|null, amount_used: int, amount_pct: int|null, unpriced_minutes: int, expenses_minor: int}
     */
    public function burn(Project $project): array
    {
        $currency = $project->budget_currency ?? $this->rates->currencyOf($project->workspace_id);
        $entries = TimeEntry::query()->where('project_id', $project->id)
            ->where(fn ($q) => $q->whereNull('started_at')->orWhereNotNull('ended_at'))
            ->get(['id', 'workspace_id', 'department_id', 'user_id', 'entry_date', 'minutes', 'is_billable']);

        $minutes = (int) $entries->sum('minutes');
        $amount = 0;
        $unpriced = 0;
        $expenses = 0;
        if ($project->budget_minor !== null) {
            foreach ($entries->where('is_billable', true) as $e) {
                $rate = $this->rates->resolve($e->workspace_id, $e->department_id, $e->user_id, $e->entry_date->toDateString(), $currency);
                if ($rate === null) {
                    $unpriced += $e->minutes;
                } else {
                    $amount += Money::mulDiv($e->minutes, $rate->rate_minor, 60);
                }
            }
            // Expenses are kept in the client's billing currency; count them when that is the budget's.
            if ($currency === $this->rates->currencyOf($project->workspace_id)) {
                $expenses = (int) Expense::query()->where('project_id', $project->id)->where('is_billable', true)->get()
                    ->sum(fn (Expense $x) => $x->billedMinor());
                $amount += $expenses;
            }
        }

        return [
            'minutes_budget' => $project->budget_minutes,
            'minutes_used' => $minutes,
            'hours_pct' => self::pct($minutes, $project->budget_minutes),
            'currency' => $currency,
            'amount_budget' => $project->budget_minor,
            'amount_used' => $amount,
            'amount_pct' => self::pct($amount, $project->budget_minor),
            'unpriced_minutes' => $unpriced,
            'expenses_minor' => $expenses,
        ];
    }

    /**
     * What this person may see: leads and Finance see hours, only Finance sees amounts.
     *
     * @return array<string, mixed>|null
     */
    public function present(Project $project, User $user, Workspace $workspace): ?array
    {
        $finance = $user->can(Permissions::BILLING_VIEW);
        $lead = $this->access->canLead($user, $workspace);
        if (! $finance && ! $lead) {
            return null;
        }
        $b = $this->burn($project);
        $out = [
            'can_edit_hours' => $lead || $user->can(Permissions::BILLING_DRAFT),
            'can_edit_amount' => $user->can(Permissions::BILLING_DRAFT),
            'minutes_budget' => $b['minutes_budget'],
            'minutes_used' => $b['minutes_used'],
            'hours_pct' => $b['hours_pct'],
        ];
        if ($finance) {
            $out += [
                'currency' => $b['currency'],
                'amount_budget' => $b['amount_budget'],
                'amount_used' => $b['amount_used'],
                'amount_pct' => $b['amount_pct'],
                'unpriced_minutes' => $b['unpriced_minutes'],
            ];
        }

        return $out;
    }

    /**
     * Hours burn for a page of projects in one query (project list and board).
     *
     * @param  list<int>  $projectIds
     * @return array<int, int> minutes logged per project id
     */
    public function minutesFor(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        return DB::table('time_entries')->whereIn('project_id', $projectIds)
            ->where(fn ($q) => $q->whereNull('started_at')->orWhereNotNull('ended_at'))
            ->groupBy('project_id')->selectRaw('project_id, sum(minutes) as m')->pluck('m', 'project_id')
            ->mapWithKeys(fn ($m, $id) => [(int) $id => (int) $m])->all();
    }

    /**
     * Send the 75 / 90 / 100 % alerts that are due, once each. Returns alerts sent.
     */
    public function checkAlerts(Notifier $notifier): int
    {
        $sent = 0;
        $finance = $this->financeIds();
        Project::query()->with('workspace')
            ->where(fn ($q) => $q->whereNotNull('budget_minutes')->orWhereNotNull('budget_minor'))
            ->whereNotIn('status', ['completed', 'archived'])
            ->whereHas('workspace')
            ->lazyById()
            ->each(function (Project $project) use ($notifier, &$sent, $finance) {
                $b = $this->burn($project);
                $hoursStep = self::step($b['hours_pct']);
                $amountStep = self::step($b['amount_pct']);
                $url = route('workspaces.projects.show', [$project->workspace, $project], false);

                if ($hoursStep > $project->budget_hours_alerted_pct) {
                    $leads = DB::table('workspace_user')->where('workspace_id', $project->workspace_id)
                        ->whereIn('role', [Workspace::ROLE_OWNER, Workspace::ROLE_LEAD])->pluck('user_id')->map(fn ($id) => (int) $id)->all();
                    $notifier->send([...$leads, $project->lead_user_id, ...$finance], NotificationKind::Budget, $project->workspace, [
                        'title' => __(':project has used :pct% of its hours budget', ['project' => $project->name, 'pct' => $b['hours_pct']]),
                        'body' => __(':used h of :budget h logged', ['used' => round($b['minutes_used'] / 60, 1), 'budget' => round(($b['minutes_budget'] ?? 0) / 60, 1)]),
                        'url' => $url,
                    ]);
                    $project->forceFill(['budget_hours_alerted_pct' => $hoursStep])->saveQuietly();
                    $sent++;
                }
                if ($amountStep > $project->budget_amount_alerted_pct) {
                    $notifier->send($finance, NotificationKind::Budget, $project->workspace, [
                        'title' => __(':project has used :pct% of its budget', ['project' => $project->name, 'pct' => $b['amount_pct']]),
                        'body' => __(':used of :budget', ['used' => Money::format($b['amount_used'], $b['currency']), 'budget' => Money::format($b['amount_budget'] ?? 0, $b['currency'])]),
                        'url' => $url,
                    ]);
                    $project->forceFill(['budget_amount_alerted_pct' => $amountStep])->saveQuietly();
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * @return list<int>
     */
    private function financeIds(): array
    {
        return User::query()->active()->get()->filter(fn (User $u) => $u->can(Permissions::BILLING_VIEW))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    public static function pct(int $used, ?int $budget): ?int
    {
        if ($budget === null || $budget <= 0) {
            return null;
        }

        return intdiv($used * 100 + intdiv($budget, 2), $budget);
    }

    /** The highest alert step reached (0 when below 75 %). */
    public static function step(?int $pct): int
    {
        $reached = 0;
        foreach (self::STEPS as $s) {
            if ($pct !== null && $pct >= $s) {
                $reached = $s;
            }
        }

        return $reached;
    }
}
