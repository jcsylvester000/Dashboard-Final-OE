<?php

namespace Tests\Feature\People;

use App\Domain\People\Capacity;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\RateCard;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\HolidaySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Findings batch 4: holidays + leave (B-14), workload (B-05), people report (B-16), directory (B-18).
 * "Now" is Wed 2026-10-28. The week of Nov 2 has All Souls' Day (Mon, special non-working).
 */
class PeopleTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private User $clara;

    private User $other;

    private int $seo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class, HolidaySeeder::class]);
        $this->travelTo('2026-10-28 09:00:00');

        $this->seo = (int) Department::where('slug', 'seo')->value('id');
        $this->lead = User::factory()->withRole('dept-lead')->create(['name' => 'Ethan', 'primary_department_id' => $this->seo]);
        Department::whereKey($this->seo)->update(['lead_user_id' => $this->lead->id]);
        $this->clara = User::factory()->withRole('member')->create(['name' => 'Clara', 'primary_department_id' => $this->seo]);
        $this->other = User::factory()->withRole('member')->create(['name' => 'Bea', 'primary_department_id' => (int) Department::where('slug', 'marketing')->value('id')]);
    }

    public function test_holidays_and_leave_reduce_capacity()
    {
        $this->assertSame(19, Holiday::query()->count());
        LeaveRequest::create(['user_id' => $this->clara->id, 'type' => 'vacation', 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-05', 'working_days' => 2])
            ->forceFill(['status' => LeaveRequest::APPROVED])->save();

        $c = app(Capacity::class)->load('2026-11-02', '2026-11-08', [$this->clara->id, $this->other->id]);

        $this->assertSame(4 * 480, $c->minutes($this->other->id)); // Mon Nov 2 is All Souls' Day
        $this->assertSame(2 * 480, $c->minutes($this->clara->id)); // minus 2 days leave
        $this->assertSame(2, $c->leaveDays($this->clara->id));

        $this->actingAs($this->other)->get(route('time.timesheet', ['week' => '2026-11-02']))
            ->assertInertia(fn ($page) => $page->where('capacityMinutes', 4 * 480)->where('holidays.0.name', "All Souls' Day"));
    }

    public function test_filing_leave_counts_working_days_notifies_the_lead_and_blocks_overlaps()
    {
        // Fri Oct 30 to Tue Nov 3: Fri, (Sat, Sun), Mon = All Souls' (holiday), Tue -> 2 working days.
        $this->actingAs($this->clara)->post(route('leave.store'), ['type' => 'vacation', 'starts_on' => '2026-10-30', 'ends_on' => '2026-11-03'])
            ->assertSessionHasNoErrors();
        $leave = LeaveRequest::query()->firstOrFail();
        $this->assertSame([2, 'pending'], [$leave->working_days, $leave->status]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->lead->id, 'kind' => 'leave']);

        $this->actingAs($this->clara)->post(route('leave.store'), ['type' => 'sick', 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-03'])
            ->assertSessionHasErrors('starts_on');
        $this->actingAs($this->clara)->post(route('leave.store'), ['type' => 'vacation', 'starts_on' => '2026-10-31', 'ends_on' => '2026-11-01'])
            ->assertSessionHasErrors('ends_on'); // weekend + holiday only
    }

    public function test_only_the_department_lead_or_an_admin_decides_and_never_their_own()
    {
        $leave = LeaveRequest::create(['user_id' => $this->clara->id, 'type' => 'vacation', 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-04', 'working_days' => 1]);

        $this->actingAs($this->other)->post(route('leave.decide', $leave), ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($this->clara)->post(route('leave.decide', $leave), ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($this->lead)->post(route('leave.decide', $leave), ['decision' => 'reject'])->assertSessionHasErrors('note');

        $this->actingAs($this->lead)->get(route('leave.index'))->assertInertia(fn ($page) => $page->has('toDecide', 1));
        $this->actingAs($this->lead)->post(route('leave.decide', $leave), ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame([LeaveRequest::APPROVED, $this->lead->id], [$leave->fresh()->status, $leave->fresh()->reviewed_by]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->clara->id, 'kind' => 'leave']);

        $this->actingAs($this->clara)->post(route('leave.cancel', $leave))->assertSessionHasNoErrors();
        $this->assertSame(LeaveRequest::CANCELLED, $leave->fresh()->status);

        // The lead's own leave goes to an admin.
        $admin = User::factory()->withRole('admin')->create();
        $own = LeaveRequest::create(['user_id' => $this->lead->id, 'type' => 'sick', 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-05', 'working_days' => 1]);
        $this->actingAs($this->lead)->post(route('leave.decide', $own), ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($admin)->post(route('leave.decide', $own), ['decision' => 'approve'])->assertSessionHasNoErrors();
    }

    public function test_workload_spreads_estimates_and_is_for_leads()
    {
        $ws = Workspace::factory()->create();
        $ws->members()->attach($this->clara->id, ['role' => 'member']);
        // 16h over Mon Nov 2 - Fri Nov 6: Mon is a holiday, so 4h/day on 4 days.
        Task::factory()->create(['workspace_id' => $ws->id, 'assignee_id' => $this->clara->id, 'estimate_minutes' => 960, 'start_on' => '2026-11-02', 'due_on' => '2026-11-06']);

        $this->actingAs($this->clara)->get(route('workload'))->assertForbidden();

        $this->actingAs($this->lead)->get(route('workload', ['from' => '2026-11-02', 'weeks' => 2]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('people/Workload')
                ->has('people', 2) // Clara and Ethan (SEO); Bea is in Marketing
                ->where('people.0.name', 'Clara')
                ->where('people.0.weeks.0.planned', 960)
                ->where('people.0.weeks.0.capacity', 4 * 480)
                ->where('people.0.weeks.0.load', 50)
                ->where('people.0.weeks.1.planned', 0));
    }

    public function test_people_report_utilisation_cost_and_value_for_finance_only()
    {
        $ws = Workspace::factory()->create();
        $task = Task::factory()->create(['workspace_id' => $ws->id]);
        RateCard::create(['rate_minor' => 300000, 'cost_minor' => 100000, 'effective_from' => '2026-01-01']);
        foreach ([['2026-10-05', 600, true], ['2026-10-06', 120, false]] as [$day, $min, $billable]) {
            TimeEntry::create(['user_id' => $this->clara->id, 'workspace_id' => $ws->id, 'task_id' => $task->id, 'department_id' => $this->seo, 'entry_date' => $day, 'minutes' => $min, 'is_billable' => $billable]);
        }

        $this->actingAs($this->clara)->get(route('reports.people'))->assertForbidden();

        $finance = User::factory()->withRole('finance')->create();
        $this->actingAs($finance)->get(route('reports.people', ['month' => '2026-10', 'department' => $this->seo]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('people/PeopleReport')
                ->where('report.rows.0.name', 'Clara')
                ->where('report.rows.0.capacity', 22 * 480) // October 2026: 22 weekdays, no holidays
                ->where('report.rows.0.logged', 720)
                ->where('report.rows.0.billable', 600)
                ->where('report.rows.0.billable_pct', 83)
                ->where('report.rows.0.utilisation_pct', 6)
                ->where('report.rows.0.cost_minor', 1200000)   // 12h x 1,000
                ->where('report.rows.0.value_minor', 3000000)  // 10h x 3,000
                ->where('report.rows.0.margin_minor', 1800000));

        $csv = $this->actingAs($finance)->get(route('reports.people.csv', ['month' => '2026-10']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Clara,SEO,176.00,0,12.00,10.00,83,6,12000.00,30000.00,18000.00', $csv);
    }

    public function test_directory_shows_people_leads_and_only_shared_clients()
    {
        $shared = Workspace::factory()->create(['name' => 'Brewhouse']);
        $hidden = Workspace::factory()->create(['name' => 'Secret Client']);
        $shared->members()->attach([$this->clara->id => ['role' => 'member'], $this->other->id => ['role' => 'member']]);
        $hidden->members()->attach($this->clara->id, ['role' => 'member']);
        LeaveRequest::create(['user_id' => $this->clara->id, 'type' => 'sick', 'starts_on' => '2026-10-28', 'ends_on' => '2026-10-29', 'working_days' => 2])
            ->forceFill(['status' => LeaveRequest::APPROVED])->save();

        $this->actingAs($this->other)->get(route('directory', ['search' => 'cla']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('people/Directory')
                ->has('people', 1)
                ->where('people.0.clients', ['Brewhouse'])
                ->where('people.0.out_until', '2026-10-29'));

        $this->actingAs($this->other)->get(route('directory', ['search' => 'ethan']))
            ->assertInertia(fn ($page) => $page->where('people.0.leads', ['SEO']));
    }

    public function test_admins_manage_holidays()
    {
        $this->actingAs($this->clara)->post(route('admin.holidays.store'), ['date' => '2026-03-20', 'name' => "Eid'l Fitr", 'kind' => 'regular'])->assertForbidden();

        $admin = User::factory()->withRole('admin')->create();
        $this->actingAs($admin)->post(route('admin.holidays.store'), ['date' => '2026-03-20', 'name' => "Eid'l Fitr", 'kind' => 'regular'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.holidays.store'), ['date' => '2026-03-20', 'name' => 'Dup', 'kind' => 'regular'])->assertSessionHasErrors('date');
        $this->assertSame(20, DB::table('holidays')->count());
    }
}
