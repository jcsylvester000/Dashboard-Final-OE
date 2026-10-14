<?php

namespace App\Domain\Dashboard;

use App\Domain\Billing\BillingReports;
use App\Domain\Billing\Money;
use App\Domain\Identity\Permissions;
use App\Domain\System\HealthChecks;
use App\Domain\Work\TimeService;
use App\Models\Invoice;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E-98: the admin and Finance tiles on the dashboard. Each tile counts exactly what the
 * page it links to shows (same filters), so the numbers always match.
 */
class RoleHome
{
    public function __construct(private readonly BillingReports $reports) {}

    /**
     * @return array{admin: list<array{key: string, label: string, value: string, hint: string, href: string, tone: string}>, finance: list<array{key: string, label: string, value: string, hint: string, href: string, tone: string}>}
     */
    public function tiles(User $user): array
    {
        return ['admin' => $this->admin($user), 'finance' => $this->finance($user)];
    }

    /**
     * @return list<array{key: string, label: string, value: string, hint: string, href: string, tone: string}>
     */
    private function admin(User $user): array
    {
        $tiles = [];
        if ($user->can(Permissions::USERS_MANAGE)) {
            $n = self::pendingSetup()->count();
            $tiles[] = $this->tile('pending-setup', 'People not set up yet', (string) $n, 'Active, but never signed in or still on a temporary password',
                route('admin.users.index', ['status' => 'pending'], false), $n > 0 ? 'warn' : 'ok');
        }
        if ($user->can(Permissions::WORKSPACES_MANAGE)) {
            $start = TimeService::weekStart(null);
            $n = TimeEntry::query()
                ->whereIn('workspace_id', Workspace::query()->visibleTo($user)->select('id'))
                ->whereNull('approved_at')
                ->whereDate('entry_date', '>=', $start->toDateString())
                ->whereDate('entry_date', '<=', $start->addDays(6)->toDateString())
                ->where(fn ($q) => $q->whereNull('started_at')->orWhereNotNull('ended_at'))
                ->count();
            $tiles[] = $this->tile('approvals', 'Time waiting for approval', (string) $n, 'Entries this week not approved yet',
                route('time.approvals', [], false), $n > 0 ? 'warn' : 'ok');
        }
        if ($user->can(Permissions::SYSTEM_JOBS)) {
            $n = DB::table('failed_jobs')->count();
            $tiles[] = $this->tile('failed-jobs', 'Failed background jobs', (string) $n, 'Retry or discard them',
                route('admin.failed-jobs.index', [], false), $n > 0 ? 'bad' : 'ok');
        }
        if ($user->can(Permissions::SYSTEM_HEALTH)) {
            $checks = collect(app(HealthChecks::class)->run());
            $fail = $checks->where('status', 'fail')->count();
            $warn = $checks->where('status', 'warn')->count();
            $tiles[] = $this->tile('health', 'System health', $fail > 0 ? "{$fail} failing" : ($warn > 0 ? "{$warn} to check" : 'All good'),
                'Scheduler, queue, storage, backups', route('admin.health', [], false), $fail > 0 ? 'bad' : ($warn > 0 ? 'warn' : 'ok'));
        }

        return $tiles;
    }

    /**
     * @return list<array{key: string, label: string, value: string, hint: string, href: string, tone: string}>
     */
    private function finance(User $user): array
    {
        if (! $user->can(Permissions::BILLING_VIEW)) {
            return [];
        }
        $month = CarbonImmutable::now()->startOfMonth();
        $unbilled = (int) TimeEntry::query()->whereNotNull('approved_at')->where('is_billable', true)->whereNull('locked_at')
            ->where(fn ($q) => $q->whereNull('started_at')->orWhereNotNull('ended_at'))
            ->whereHas('workspace')->sum('minutes');
        $review = Invoice::query()->where('status', Invoice::DRAFT)->whereNotNull('review_requested_at')->count();
        $overdue = self::overdueInvoices()->count();
        $aging = $this->reports->agingInPhp();
        $overduePhp = $aging['1_30'] + $aging['31_60'] + $aging['61_90'] + $aging['90_plus'];
        $cash = $this->reports->cashReceived($month, $month->endOfMonth());

        return [
            $this->tile('unbilled', 'Unbilled approved hours', sprintf('%d.%02d h', intdiv($unbilled, 60), intdiv(($unbilled % 60) * 100 + 30, 60)),
                'Approved billable time not on an invoice yet', route('billing.reports.index', [], false).'#unbilled', $unbilled > 0 ? 'warn' : 'ok'),
            $this->tile('review', 'Drafts waiting for review', (string) $review, 'Sent for a second check (maker-checker)',
                route('billing.invoices.index', ['status' => Invoice::DRAFT, 'review' => 1], false), $review > 0 ? 'warn' : 'ok'),
            $this->tile('overdue', 'Overdue invoices', (string) $overdue, Money::format($overduePhp, 'PHP').' past due (in PHP)',
                route('billing.invoices.index', ['status' => 'overdue'], false), $overdue > 0 ? 'bad' : 'ok'),
            $this->tile('cash', 'Cash received this month', Money::format($cash['total_php_minor'], 'PHP'),
                $cash['missing'] > 0 ? "{$cash['missing']} payment(s) without a PHP rate not included" : 'All currencies, in PHP',
                route('billing.reports.index', ['from' => $month->format('Y-m'), 'to' => $month->format('Y-m')], false).'#cash', 'ok'),
        ];
    }

    /**
     * Active people who never signed in or still have an admin-issued password (Users page filter "pending").
     *
     * @return Builder<User>
     */
    public static function pendingSetup(): Builder
    {
        return User::query()->where('is_active', true)->where(fn ($q) => $q->whereNull('last_login_at')->orWhere('must_change_password', true));
    }

    /**
     * Open invoices past their due date (Invoices page filter "overdue").
     *
     * @return Builder<Invoice>
     */
    public static function overdueInvoices(): Builder
    {
        return Invoice::query()->where('kind', 'invoice')->whereIn('status', [Invoice::FINALIZED, Invoice::PARTIALLY_PAID])
            ->whereDate('due_date', '<', now()->toDateString());
    }

    /**
     * @return array{key: string, label: string, value: string, hint: string, href: string, tone: string}
     */
    private function tile(string $key, string $label, string $value, string $hint, string $href, string $tone): array
    {
        return compact('key', 'label', 'value', 'hint', 'href', 'tone');
    }
}
