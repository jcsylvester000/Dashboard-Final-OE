<?php

namespace Tests\Feature\Work;

use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Brutal scenarios H1 (submit / return / resubmit) and H3 (offboarding hand-over).
 */
class TimesheetWorkflowTest extends TestCase
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

    public function test_submitting_a_week_locks_its_entries_for_the_author()
    {
        $monday = now()->startOfWeek()->toDateString();
        $inWeek = $this->logFor($this->member, 60, $monday);
        $lastWeek = $this->logFor($this->member, 30, now()->startOfWeek()->subWeek()->toDateString());

        $this->actingAs($this->member)->post(route('time.submit'), ['week' => $monday])->assertRedirect();

        $this->assertNotNull($inWeek->fresh()->submitted_at);
        $this->assertNull($lastWeek->fresh()->submitted_at);

        $this->actingAs($this->member)->put(route('time.update', $inWeek), ['minutes' => 5])->assertSessionHasErrors('entry');
        $this->assertSame(60, $inWeek->fresh()->minutes);

        $this->actingAs($this->member)->get(route('time.timesheet', ['week' => $monday]))
            ->assertInertia(fn ($page) => $page->where('status', 'submitted'));
    }

    public function test_lead_returns_entries_with_a_reason_and_the_author_fixes_and_resubmits()
    {
        $monday = now()->startOfWeek()->toDateString();
        $entry = $this->logFor($this->member, 240, $monday);
        $this->actingAs($this->member)->post(route('time.submit'), ['week' => $monday]);

        $this->actingAs($this->lead)->post(route('time.return'), [
            'ids' => [$entry->id], 'reason' => 'Split Tuesday between the two tasks',
        ])->assertRedirect();

        $entry->refresh();
        $this->assertNull($entry->submitted_at);
        $this->assertNotNull($entry->returned_at);
        $this->assertSame($this->lead->id, $entry->returned_by);
        $this->assertSame('Split Tuesday between the two tasks', $entry->return_reason);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->member->id, 'kind' => 'timesheet']);

        $this->actingAs($this->member)->get(route('time.timesheet', ['week' => $monday]))
            ->assertInertia(fn ($page) => $page->where('status', 'returned'));

        // Author can edit again, then resubmits; the return note is cleared.
        $this->actingAs($this->member)->put(route('time.update', $entry), ['minutes' => 120])->assertSessionHasNoErrors();
        $this->actingAs($this->member)->post(route('time.submit'), ['week' => $monday]);

        $entry->refresh();
        $this->assertSame(120, $entry->minutes);
        $this->assertNotNull($entry->submitted_at);
        $this->assertNull($entry->return_reason);

        // Approval clears the workflow fields too.
        $this->actingAs($this->lead)->post(route('time.approve'), ['ids' => [$entry->id], 'action' => 'approve']);
        $this->assertNotNull($entry->fresh()->approved_at);
    }

    public function test_a_reason_is_required_and_nobody_returns_their_own_or_other_clients_time()
    {
        $memberEntry = $this->logFor($this->member, 30);
        $own = $this->logFor($this->lead, 30);
        $otherLead = User::factory()->withRole('member')->create();
        Workspace::factory()->create()->members()->attach($otherLead->id, ['role' => Workspace::ROLE_LEAD]);

        $this->actingAs($this->lead)->post(route('time.return'), ['ids' => [$memberEntry->id], 'reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->lead)->post(route('time.return'), ['ids' => [$own->id], 'reason' => 'Nope nope']);
        $this->actingAs($otherLead)->post(route('time.return'), ['ids' => [$memberEntry->id], 'reason' => 'Not mine to judge']);

        $this->assertNull($memberEntry->fresh()->returned_at);
        $this->assertNull($own->fresh()->returned_at);
    }

    public function test_offboarding_hands_over_open_work_and_keeps_time_history()
    {
        $admin = User::factory()->withRole('admin')->create();
        $other = Workspace::factory()->create();
        $other->members()->attach($this->member->id, ['role' => Workspace::ROLE_MEMBER]);

        $open = Task::factory()->create(['workspace_id' => $this->workspace->id, 'assignee_id' => $this->member->id]);
        $done = Task::factory()->done()->create(['workspace_id' => $this->workspace->id, 'assignee_id' => $this->member->id]);
        $elsewhere = Task::factory()->create(['workspace_id' => $other->id, 'assignee_id' => $this->member->id]);
        $project = Project::factory()->create(['workspace_id' => $this->workspace->id, 'lead_user_id' => $this->member->id]);
        $logged = $this->logFor($this->member, 90);
        $this->freezeSecond();
        $running = TimeEntry::create([
            'user_id' => $this->member->id, 'workspace_id' => $this->workspace->id, 'task_id' => $open->id,
            'entry_date' => now()->toDateString(), 'started_at' => now()->subMinutes(20), 'minutes' => 0, 'is_billable' => true,
        ]);

        $this->actingAs($admin)->get(route('admin.users.edit', $this->member))
            ->assertInertia(fn ($page) => $page->where('handover.open_tasks', 2)->where('handover.running_timers', 1));

        $this->actingAs($admin)->post(route('admin.users.deactivate', $this->member), ['reassign_to' => $this->lead->id])
            ->assertRedirect();

        $this->assertFalse($this->member->fresh()->is_active);
        $this->assertSame($this->lead->id, $open->fresh()->assignee_id);
        $this->assertSame($this->member->id, $done->fresh()->assignee_id);   // history kept
        $this->assertNull($elsewhere->fresh()->assignee_id);                 // lead isn't on that client
        $this->assertSame($this->lead->id, $project->fresh()->lead_user_id);
        $this->assertSame(20, $running->fresh()->minutes);
        $this->assertNotNull($running->fresh()->ended_at);
        $this->assertSame($this->member->id, $logged->fresh()->user_id);
        $this->assertDatabaseHas('activity_log', ['action' => 'user.offboarded']);
    }

    public function test_offboarding_rejects_an_inactive_or_same_person_as_new_owner()
    {
        $admin = User::factory()->withRole('admin')->create();
        $gone = User::factory()->withRole('member')->create(['is_active' => false]);

        $this->actingAs($admin)->post(route('admin.users.deactivate', $this->member), ['reassign_to' => $gone->id])
            ->assertSessionHasErrors('reassign_to');
        $this->actingAs($admin)->post(route('admin.users.deactivate', $this->member), ['reassign_to' => $this->member->id])
            ->assertSessionHasErrors('reassign_to');
        $this->assertTrue($this->member->fresh()->is_active);
    }

    public function test_people_who_approve_nobody_cannot_post_approvals_or_returns()
    {
        $entry = $this->logFor($this->lead, 30);

        $this->actingAs($this->member)->post(route('time.approve'), ['ids' => [$entry->id], 'action' => 'approve'])->assertForbidden();
        $this->actingAs($this->member)->post(route('time.return'), ['ids' => [$entry->id], 'reason' => 'Looks wrong'])->assertForbidden();
        $this->assertNull($entry->fresh()->approved_at);
    }

    private function join(string $role): User
    {
        $user = User::factory()->withRole('member')->create();
        $this->workspace->members()->attach($user->id, ['role' => $role]);

        return $user;
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
