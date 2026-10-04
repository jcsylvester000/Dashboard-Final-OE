<?php

namespace Tests\Feature\Work;

use App\Models\Department;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    private User $lead;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);

        $this->workspace = Workspace::factory()->create();
        $this->member = $this->join(Workspace::ROLE_MEMBER);
        $this->lead = $this->join(Workspace::ROLE_LEAD);
        $this->task = Task::factory()->create([
            'workspace_id' => $this->workspace->id,
            'department_id' => Department::where('slug', 'seo')->value('id'),
        ]);
    }

    private function join(string $role): User
    {
        $user = User::factory()->withRole('member')->create();
        $this->workspace->members()->attach($user->id, ['role' => $role]);

        return $user;
    }

    public function test_timer_starts_stops_and_rounds_up_to_minutes()
    {
        // Whole seconds: timestamps are stored without microseconds.
        $this->freezeSecond();

        $this->actingAs($this->member)
            ->post(route('workspaces.tasks.timer', [$this->workspace, $this->task]))
            ->assertRedirect();

        $entry = TimeEntry::firstOrFail();
        $this->assertTrue($entry->isRunning());
        $this->assertSame($this->task->department_id, $entry->department_id);

        $this->travel(25)->minutes();
        $this->travel(10)->seconds();

        $this->actingAs($this->member)->post(route('time.timer.stop'))->assertRedirect();

        $entry->refresh();
        $this->assertFalse($entry->isRunning());
        $this->assertSame(26, $entry->minutes);
    }

    public function test_starting_a_new_timer_stops_the_previous_one()
    {
        $this->freezeSecond();
        $other = Task::factory()->create(['workspace_id' => $this->workspace->id]);

        $this->actingAs($this->member)->post(route('workspaces.tasks.timer', [$this->workspace, $this->task]));
        $this->travel(5)->minutes();
        $this->actingAs($this->member)->post(route('workspaces.tasks.timer', [$this->workspace, $other]));

        $this->assertSame(1, TimeEntry::query()->running()->count());
        $this->assertSame($other->id, TimeEntry::query()->running()->value('task_id'));
        $this->assertSame(5, TimeEntry::where('task_id', $this->task->id)->value('minutes'));
    }

    public function test_running_timer_is_shared_with_every_page()
    {
        $this->actingAs($this->member)->post(route('workspaces.tasks.timer', [$this->workspace, $this->task]));

        $this->actingAs($this->member)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('runningTimer.task.id', $this->task->id));
    }

    public function test_manual_time_is_logged_and_validated()
    {
        $this->actingAs($this->member)->post(route('workspaces.tasks.time.store', [$this->workspace, $this->task]), [
            'entry_date' => now()->toDateString(),
            'hours' => 1,
            'minutes' => 30,
            'note' => 'Keyword research',
            'is_billable' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(90, TimeEntry::firstOrFail()->minutes);

        $this->actingAs($this->member)->post(route('workspaces.tasks.time.store', [$this->workspace, $this->task]), [
            'entry_date' => now()->toDateString(),
            'hours' => 0,
            'minutes' => 0,
        ])->assertSessionHasErrors('minutes');

        $this->actingAs($this->member)->post(route('workspaces.tasks.time.store', [$this->workspace, $this->task]), [
            'entry_date' => now()->addDay()->toDateString(),
            'minutes' => 10,
        ])->assertSessionHasErrors('entry_date');
    }

    public function test_guests_cannot_log_time()
    {
        $guest = $this->join(Workspace::ROLE_GUEST);

        $this->actingAs($guest)
            ->post(route('workspaces.tasks.timer', [$this->workspace, $this->task]))
            ->assertForbidden();
    }

    public function test_leads_approve_time_and_approved_entries_are_locked()
    {
        $entry = $this->logFor($this->member, 60);

        $this->actingAs($this->lead)->post(route('time.approve'), [
            'ids' => [$entry->id], 'action' => 'approve',
        ])->assertRedirect();

        $entry->refresh();
        $this->assertNotNull($entry->approved_at);
        $this->assertSame($this->lead->id, $entry->approved_by);

        // Author can no longer edit or delete it.
        $this->actingAs($this->member)->put(route('time.update', $entry), ['minutes' => 5])->assertSessionHasErrors('entry');
        $this->actingAs($this->member)->delete(route('time.destroy', $entry))->assertSessionHasErrors('entry');
        $this->assertNotNull($entry->fresh());
    }

    public function test_people_cannot_approve_their_own_time_or_time_outside_their_workspaces()
    {
        $own = $this->logFor($this->lead, 30);
        $memberEntry = $this->logFor($this->member, 30);
        $otherLead = User::factory()->withRole('member')->create();
        Workspace::factory()->create()->members()->attach($otherLead->id, ['role' => Workspace::ROLE_LEAD]);

        $this->actingAs($this->lead)->post(route('time.approve'), ['ids' => [$own->id], 'action' => 'approve']);
        $this->assertNull($own->fresh()->approved_at);

        $this->actingAs($otherLead)->post(route('time.approve'), ['ids' => [$memberEntry->id], 'action' => 'approve']);
        $this->assertNull($memberEntry->fresh()->approved_at);

        // Plain members see nothing to approve.
        $this->actingAs($this->member)->get(route('time.approvals'))
            ->assertInertia(fn ($page) => $page->has('entries', 0));
    }

    public function test_only_the_author_edits_unapproved_time()
    {
        $entry = $this->logFor($this->member, 45);

        $this->actingAs($this->lead)->put(route('time.update', $entry), ['minutes' => 5])->assertForbidden();
        $this->actingAs($this->member)->put(route('time.update', $entry), ['minutes' => 50])->assertSessionHasNoErrors();

        $this->assertSame(50, $entry->fresh()->minutes);
    }

    public function test_timesheet_shows_my_week_only()
    {
        $this->logFor($this->member, 60, now()->startOfWeek()->toDateString());
        $this->logFor($this->member, 30, now()->startOfWeek()->subWeek()->toDateString());
        $this->logFor($this->lead, 15, now()->startOfWeek()->toDateString());

        $this->actingAs($this->member)->get(route('time.timesheet'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('time/Timesheet')->has('entries', 1)->where('entries.0.minutes', 60));
    }

    public function test_seo_work_details_are_saved_and_validated()
    {
        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->workspace, $this->task]), [
            'work_details' => ['target_url' => 'https://client.test/services', 'keyword' => 'plumber manila', 'work_type' => 'On-page'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('plumber manila', $this->task->fresh()->work_details['keyword'] ?? null);

        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->workspace, $this->task]), [
            'work_details' => ['secret_field' => 'x'],
        ])->assertSessionHasErrors('work_details');
    }

    public function test_calendar_renders_tasks_due_in_the_month()
    {
        $this->task->forceFill(['due_on' => '2026-11-12'])->save();

        $this->actingAs($this->member)->get(route('workspaces.tasks.calendar', [$this->workspace, 'month' => '2026-11']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('tasks/Calendar')->has('tasks', 1)->where('month', '2026-11'));
    }

    private function logFor(User $user, int $minutes, ?string $date = null): TimeEntry
    {
        return TimeEntry::create([
            'user_id' => $user->id,
            'workspace_id' => $this->workspace->id,
            'task_id' => $this->task->id,
            'entry_date' => $date ?? now()->toDateString(),
            'minutes' => $minutes,
            'is_billable' => true,
        ]);
    }
}
