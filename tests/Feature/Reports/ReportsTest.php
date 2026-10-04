<?php

namespace Tests\Feature\Reports;

use App\Models\Department;
use App\Models\ReportSnapshot;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Report numbers checked against a fixed fixture (P5-08). "Now" is Wednesday 2026-10-07 10:00.
 *
 * Workspace A (lead + member):
 *   t1 dev  done  created 09-28 10:00, done 09-30 10:00 (2 days)
 *   t2 dev  done  created 09-29 10:00, done 10-05 10:00 (6 days)
 *   t3 seo  done  created 10-01 00:00, done 10-06 00:00 (5 days)
 *   t4 seo  to do, due 10-01 (overdue), member
 *   t5 seo  in progress, due 10-09, member, high
 *   t6 dev  blocked, lead
 *   t7 dev  backlog handoff from t4 (still waiting), created 10-05 10:00
 *   t8 mkt  to do handoff from t1 (done 09-30 10:00), created 09-29 10:00
 *   time: member 90 min billable 10-06 (t5), lead 30 min non-billable 10-07 (t6), a running timer
 * Workspace B (admin only): b1 dev done 10-06; b2 seo overdue; member 60 min 10-06
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $a;

    private Workspace $b;

    private User $lead;

    private User $member;

    private User $admin;

    /** @var array<string, Task> */
    private array $t = [];

    private const RANGE = ['from' => '2026-09-28', 'to' => '2026-10-07'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00'));

        $this->a = Workspace::factory()->create(['name' => 'Alpha']);
        $this->b = Workspace::factory()->create(['name' => 'Beta']);
        $this->lead = User::factory()->withRole('member')->create(['name' => 'Lena Lead']);
        $this->member = User::factory()->withRole('member')->create(['name' => 'Max Member']);
        $this->admin = User::factory()->withRole('admin')->create(['name' => 'Ada Admin']);
        $this->a->members()->attach($this->lead->id, ['role' => Workspace::ROLE_LEAD]);
        $this->a->members()->attach($this->member->id, ['role' => Workspace::ROLE_MEMBER]);
        $this->b->members()->attach($this->member->id, ['role' => Workspace::ROLE_MEMBER]);

        $dev = $this->dept('development');
        $seo = $this->dept('seo');
        $mkt = $this->dept('marketing');
        $done = TaskStatus::idFor('done');
        $old = '2026-09-20 09:00:00';

        $this->t['t1'] = $this->task($this->a, ['department_id' => $dev, 'status_id' => $done, 'created_at' => '2026-09-28 10:00:00', 'completed_at' => '2026-09-30 10:00:00']);
        $this->t['t2'] = $this->task($this->a, ['department_id' => $dev, 'status_id' => $done, 'created_at' => '2026-09-29 10:00:00', 'completed_at' => '2026-10-05 10:00:00']);
        $this->t['t3'] = $this->task($this->a, ['department_id' => $seo, 'status_id' => $done, 'created_at' => '2026-10-01 00:00:00', 'completed_at' => '2026-10-06 00:00:00']);
        $this->t['t4'] = $this->task($this->a, ['department_id' => $seo, 'status_id' => TaskStatus::idFor('todo'), 'due_on' => '2026-10-01', 'assignee_id' => $this->member->id, 'created_at' => $old]);
        $this->t['t5'] = $this->task($this->a, ['title' => '=cmd', 'department_id' => $seo, 'status_id' => TaskStatus::idFor('in-progress'), 'due_on' => '2026-10-09', 'assignee_id' => $this->member->id, 'priority' => 'high', 'created_at' => $old]);
        $this->t['t6'] = $this->task($this->a, ['department_id' => $dev, 'status_id' => TaskStatus::idFor('blocked'), 'assignee_id' => $this->lead->id, 'created_at' => $old]);
        $this->t['t7'] = $this->task($this->a, ['department_id' => $dev, 'status_id' => TaskStatus::idFor('backlog'), 'handoff_from_task_id' => $this->t['t4']->id, 'created_at' => '2026-10-05 10:00:00']);
        $this->t['t8'] = $this->task($this->a, ['department_id' => $mkt, 'status_id' => TaskStatus::idFor('todo'), 'handoff_from_task_id' => $this->t['t1']->id, 'created_at' => '2026-09-29 10:00:00']);

        $this->task($this->b, ['department_id' => $dev, 'status_id' => $done, 'created_at' => '2026-10-05 09:00:00', 'completed_at' => '2026-10-06 09:00:00']);
        $this->task($this->b, ['department_id' => $seo, 'status_id' => TaskStatus::idFor('todo'), 'due_on' => '2026-09-01', 'created_at' => $old]);

        $this->entry($this->member, $this->t['t5'], '2026-10-06', 90, true);
        $this->entry($this->lead, $this->t['t6'], '2026-10-07', 30, false);
        TimeEntry::create([
            'user_id' => $this->lead->id, 'workspace_id' => $this->a->id, 'task_id' => $this->t['t6']->id,
            'department_id' => $dev, 'entry_date' => '2026-10-07', 'started_at' => now(), 'minutes' => 0, 'is_billable' => true,
        ]);
        TimeEntry::create([
            'user_id' => $this->member->id, 'workspace_id' => $this->b->id, 'department_id' => $seo,
            'entry_date' => '2026-10-06', 'minutes' => 60, 'is_billable' => true,
        ]);
    }

    private function dept(string $slug): int
    {
        return (int) Department::where('slug', $slug)->value('id');
    }

    private function task(Workspace $ws, array $attributes): Task
    {
        return Task::factory()->create(['workspace_id' => $ws->id, ...$attributes]);
    }

    private function entry(User $user, Task $task, string $date, int $minutes, bool $billable): void
    {
        TimeEntry::create([
            'user_id' => $user->id, 'workspace_id' => $task->workspace_id, 'task_id' => $task->id,
            'department_id' => $task->department_id, 'entry_date' => $date, 'minutes' => $minutes, 'is_billable' => $billable,
        ]);
    }

    private function report(User $user, string $report, array $extra = [])
    {
        return $this->actingAs($user)->get(route('reports.index', ['report' => $report, ...self::RANGE, ...$extra]));
    }

    public function test_members_without_a_lead_role_cannot_open_reports()
    {
        $this->report($this->member, 'throughput')->assertForbidden();
        $this->actingAs($this->member)->get(route('reports.csv', ['report' => 'time']))->assertForbidden();
        $this->actingAs($this->lead)->get(route('reports.agency'))->assertForbidden();
    }

    public function test_leads_only_see_their_own_workspaces()
    {
        $this->report($this->lead, 'throughput', ['workspace' => $this->b->id])->assertSessionHasErrors('workspace');

        $this->report($this->lead, 'throughput')->assertInertia(fn (Assert $page) => $page
            ->component('reports/Index')
            ->has('workspaces', 1)
            ->where('workspaces.0.name', 'Alpha'));
    }

    public function test_throughput_counts_done_tasks_per_week()
    {
        $this->report($this->lead, 'throughput')->assertInertia(fn (Assert $page) => $page
            ->where('result.rows', [
                ['week' => '2026-09-28', 'completed' => 1],
                ['week' => '2026-10-05', 'completed' => 2],
            ])
            ->where('result.summary.Tasks done', 3));

        // Admins see every workspace (Beta adds one).
        $this->report($this->admin, 'throughput')->assertInertia(fn (Assert $page) => $page
            ->where('result.summary.Tasks done', 4));

        // Department filter.
        $this->report($this->lead, 'throughput', ['department' => $this->dept('seo')])->assertInertia(fn (Assert $page) => $page
            ->where('result.summary.Tasks done', 1));
    }

    public function test_cycle_time_by_department()
    {
        $this->report($this->lead, 'cycle_time')->assertInertia(fn (Assert $page) => $page
            ->where('result.rows', [
                ['department' => 'Development', 'completed' => 2, 'avg_days' => 4, 'median_days' => 4],
                ['department' => 'SEO', 'completed' => 1, 'avg_days' => 5, 'median_days' => 5],
            ])
            ->where('result.summary.Average days', 4.3)
            ->where('result.summary.Median days', 5));
    }

    public function test_overdue_by_department()
    {
        $this->report($this->lead, 'overdue')->assertInertia(fn (Assert $page) => $page
            ->where('result.rows.0', ['department' => 'SEO', 'open' => 2, 'overdue' => 1, 'overdue_pct' => 50])
            ->where('result.summary', ['Open tasks' => 5, 'Overdue' => 1]));
    }

    public function test_workload_by_person()
    {
        $this->report($this->lead, 'workload')->assertInertia(fn (Assert $page) => $page
            ->where('result.rows.0', ['person' => 'Max Member', 'open' => 2, 'overdue' => 1, 'due_week' => 1, 'urgent' => 1, 'hours' => 1.5])
            ->where('result.rows.1', ['person' => 'Unassigned', 'open' => 2, 'overdue' => 0, 'due_week' => 0, 'urgent' => 0, 'hours' => 0])
            ->where('result.rows.2', ['person' => 'Lena Lead', 'open' => 1, 'overdue' => 0, 'due_week' => 0, 'urgent' => 0, 'hours' => 0.5]));
    }

    public function test_handoff_wait_time()
    {
        $this->report($this->lead, 'handoffs')->assertInertia(fn (Assert $page) => $page
            ->where('result.rows', [
                ['department' => 'Development', 'handoffs' => 1, 'still_waiting' => 1, 'avg_wait_days' => 2, 'max_wait_days' => 2],
                ['department' => 'Marketing', 'handoffs' => 1, 'still_waiting' => 0, 'avg_wait_days' => 1, 'max_wait_days' => 1],
            ])
            ->where('result.summary.Handoffs', 2)
            ->where('result.summary.Average wait (days)', 1.5));
    }

    public function test_time_report_totals_match_time_entries()
    {
        $this->report($this->lead, 'time', ['group' => 'person'])->assertInertia(fn (Assert $page) => $page
            ->where('result.rows.0.name', 'Max Member')
            ->where('result.rows.0.minutes', 90)
            ->where('result.rows.0.billable_hours', 1.5)
            ->where('result.rows.1.name', 'Lena Lead')
            ->where('result.rows.1.non_billable_hours', 0.5)
            ->where('result.summary', ['Hours' => 2, 'Billable hours' => 1.5, 'Non-billable hours' => 0.5]));

        $this->report($this->admin, 'time', ['group' => 'workspace'])->assertInertia(fn (Assert $page) => $page
            ->where('result.rows.0', fn ($row) => $row['name'] === 'Alpha' && $row['minutes'] === 120)
            ->where('result.rows.1', fn ($row) => $row['name'] === 'Beta' && $row['minutes'] === 60));

        $this->report($this->lead, 'time', ['group' => 'department', 'department' => $this->dept('seo')])->assertInertia(fn (Assert $page) => $page
            ->has('result.rows', 1)
            ->where('result.rows.0.name', 'SEO')
            ->where('result.rows.0.minutes', 90));
    }

    public function test_csv_export_opens_in_excel_and_neutralises_formulas()
    {
        $response = $this->actingAs($this->lead)->get(route('reports.csv', ['report' => 'time', 'group' => 'task', ...self::RANGE]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFTask,Hours,Billable", $csv);
        $this->assertStringContainsString("'=cmd,1.5,1.5,0,0,90", $csv);
        $this->assertStringNotContainsString("\n=cmd", $csv);
    }

    public function test_agency_overview_health_and_workload()
    {
        $this->actingAs($this->admin)->get(route('reports.agency'))->assertInertia(fn (Assert $page) => $page
            ->component('reports/Agency')
            ->where('totals', ['workspaces' => 2, 'on_track' => 0, 'at_risk' => 1, 'overdue' => 1])
            ->where('workspaces.0.name', 'Beta')
            ->where('workspaces.0.health', 'overdue')
            ->where('workspaces.1.health', 'at_risk')
            ->where('workspaces.1.open', 5)
            ->where('workspaces.1.blocked', 1)
            ->where('team', function ($team) {
                $max = collect($team)->firstWhere('name', 'Max Member');

                return $max['open'] === 2 && $max['overdue'] === 1 && $max['due_week'] === 1 && $max['hours_week'] === 2.5;
            }));
    }

    public function test_workspace_overview_and_my_dashboard()
    {
        $this->actingAs($this->member)->get(route('workspaces.show', $this->a))->assertInertia(fn (Assert $page) => $page
            ->has('overview.blocked', 1)
            ->where('overview.blocked.0.id', $this->t['t6']->id)
            ->has('overview.dueSoon', 2)
            ->where('overview.departments', function ($rows) {
                $seo = collect($rows)->firstWhere('name', 'SEO');

                return $seo['open'] === 1 && $seo['active'] === 1 && $seo['blocked'] === 0 && $seo['done'] === 1 && $seo['overdue'] === 1;
            }));

        $this->actingAs($this->member)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('myTasks', ['open' => 2, 'overdue' => 1, 'dueWeek' => 1])
            ->where('nextTasks.0.id', $this->t['t4']->id)
            ->has('nextTasks', 2));
    }

    public function test_weekly_snapshots_are_saved_and_listed_for_leads()
    {
        $this->actingAs($this->member)->get(route('workspaces.reports.index', $this->a))->assertForbidden();

        $this->actingAs($this->lead)->post(route('workspaces.reports.store', $this->a), ['week' => '2026-10-05'])->assertRedirect();
        $snapshot = ReportSnapshot::firstOrFail();
        $this->assertSame(2, $snapshot->data['completed']);
        $this->assertSame(1, $snapshot->data['created']);
        $this->assertEquals(2, $snapshot->data['hours']);
        $this->assertEquals(1.5, $snapshot->data['billable_hours']);

        // Saving the same week again replaces it.
        $this->actingAs($this->lead)->post(route('workspaces.reports.store', $this->a), ['week' => '2026-10-07']);
        $this->assertSame(1, ReportSnapshot::count());

        $this->artisan('reports:snapshot', ['--week' => '2026-09-28'])->assertSuccessful();
        $this->assertSame(3, ReportSnapshot::count());

        $this->actingAs($this->lead)->get(route('workspaces.reports.index', $this->a))->assertInertia(fn (Assert $page) => $page
            ->component('workspaces/Reports')
            ->has('snapshots', 2)
            ->where('snapshots.0.week_start', '2026-10-05')
            ->where('snapshots.1.completed', 1));
    }
}
