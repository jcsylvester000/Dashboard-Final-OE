<?php

namespace Tests\Feature\Work;

use App\Domain\Work\SprintService;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Build 8c / brutal scenario J1: plan, start, burndown, close with carry-over, velocity.
 */
class SprintTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $lead;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        $this->travelTo('2026-10-12 10:00:00'); // a Monday

        $this->workspace = Workspace::factory()->create(['key' => 'BRW']);
        $this->lead = $this->join(Workspace::ROLE_LEAD);
        $this->member = $this->join(Workspace::ROLE_MEMBER);
    }

    public function test_leads_plan_sprints_and_members_cannot()
    {
        $body = ['name' => 'Sprint 4', 'goal' => 'Pay with GCash', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-23'];

        $this->actingAs($this->member)->post(route('workspaces.sprints.store', $this->workspace), $body)->assertForbidden();
        $this->actingAs($this->lead)->post(route('workspaces.sprints.store', $this->workspace), $body)->assertRedirect();
        $this->assertDatabaseHas('sprints', ['workspace_id' => $this->workspace->id, 'name' => 'Sprint 4', 'status' => 'planned']);

        $this->actingAs($this->lead)->post(route('workspaces.sprints.store', $this->workspace), [...$body, 'ends_on' => '2026-10-01'])
            ->assertSessionHasErrors('ends_on');
        $this->actingAs($this->lead)->post(route('workspaces.sprints.store', $this->workspace), [...$body, 'ends_on' => '2027-01-30'])
            ->assertSessionHasErrors('ends_on');
    }

    public function test_members_move_tasks_into_a_sprint_and_point_them()
    {
        $sprint = Sprint::factory()->create(['workspace_id' => $this->workspace->id]);
        $task = Task::factory()->create(['workspace_id' => $this->workspace->id]);

        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->workspace, $task]), ['sprint_id' => $sprint->id, 'story_points' => 5])
            ->assertSessionHasNoErrors();
        $this->assertSame([$sprint->id, 5], [$task->fresh()->sprint_id, $task->fresh()->story_points]);

        // Not another client's sprint, not a closed sprint.
        $foreign = Sprint::factory()->create();
        $closed = Sprint::factory()->create(['workspace_id' => $this->workspace->id]);
        $closed->forceFill(['status' => Sprint::CLOSED])->save();
        foreach ([$foreign, $closed] as $bad) {
            $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->workspace, $task]), ['sprint_id' => $bad->id])
                ->assertSessionHasErrors('sprint_id');
        }

        // Bulk move to the backlog.
        $this->actingAs($this->member)->post(route('workspaces.tasks.bulk', $this->workspace), ['ids' => [$task->id], 'action' => 'update', 'sprint_id' => 'none'])
            ->assertSessionHasNoErrors();
        $this->assertNull($task->fresh()->sprint_id);
    }

    public function test_start_commits_points_and_only_one_sprint_runs_at_a_time()
    {
        $one = Sprint::factory()->create(['workspace_id' => $this->workspace->id]);
        $two = Sprint::factory()->create(['workspace_id' => $this->workspace->id]);

        $this->actingAs($this->lead)->post(route('workspaces.sprints.start', [$this->workspace, $one]))->assertSessionHasErrors('sprint'); // empty

        Task::factory()->create(['workspace_id' => $this->workspace->id, 'sprint_id' => $one->id, 'story_points' => 5]);
        Task::factory()->create(['workspace_id' => $this->workspace->id, 'sprint_id' => $one->id, 'story_points' => 3]);
        Task::factory()->create(['workspace_id' => $this->workspace->id, 'sprint_id' => $two->id, 'story_points' => 2]);

        $this->actingAs($this->member)->post(route('workspaces.sprints.start', [$this->workspace, $one]))->assertForbidden();
        $this->actingAs($this->lead)->post(route('workspaces.sprints.start', [$this->workspace, $one]))->assertSessionHasNoErrors();

        $one->refresh();
        $this->assertSame([Sprint::ACTIVE, 8], [$one->status, $one->committed_points]);

        $this->actingAs($this->lead)->post(route('workspaces.sprints.start', [$this->workspace, $two]))->assertSessionHasErrors('sprint');
        $this->assertSame(Sprint::PLANNED, $two->fresh()->status);
    }

    public function test_burndown_counts_points_completed_each_day()
    {
        $sprint = Sprint::factory()->create(['workspace_id' => $this->workspace->id, 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-16']);
        $a = Task::factory()->create(['workspace_id' => $this->workspace->id, 'sprint_id' => $sprint->id, 'story_points' => 5]);
        Task::factory()->create(['workspace_id' => $this->workspace->id, 'sprint_id' => $sprint->id, 'story_points' => 3]);
        $this->actingAs($this->lead)->post(route('workspaces.sprints.start', [$this->workspace, $sprint]));

        $this->travelTo('2026-10-14 15:00:00');
        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->workspace, $a]), ['status_id' => TaskStatus::idFor('done')])
            ->assertSessionHasNoErrors();

        $chart = app(SprintService::class)->burndown($sprint->fresh());

        $this->assertSame('points', $chart['unit']);
        $this->assertSame(8, $chart['scope']);
        $this->assertSame([8, 8, 3, null, null], array_column($chart['days'], 'remaining'));
        $this->assertEquals([8, 6, 4, 2, 0], array_column($chart['days'], 'ideal'));

        $this->actingAs($this->member)->get(route('workspaces.sprints.index', $this->workspace))
            ->assertInertia(fn ($page) => $page->component('sprints/Index')
                ->where('burndown.scope', 8)
                ->where('sprints.0.done_points', 5)
                ->where('canManage', false)
                ->where('canPlan', true));
    }

    public function test_close_reports_and_carries_unfinished_work_over()
    {
        $current = Sprint::factory()->create(['workspace_id' => $this->workspace->id]);
        $next = Sprint::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Sprint 5']);
        $done = Task::factory()->create(['workspace_id' => $this->workspace->id, 'sprint_id' => $current->id, 'story_points' => 5]);
        $open = Task::factory()->create(['workspace_id' => $this->workspace->id, 'sprint_id' => $current->id, 'story_points' => 3]);
        $this->actingAs($this->lead)->post(route('workspaces.sprints.start', [$this->workspace, $current]));
        $this->actingAs($this->lead)->put(route('workspaces.tasks.update', [$this->workspace, $done]), ['status_id' => TaskStatus::idFor('done')]);

        $this->actingAs($this->lead)->post(route('workspaces.sprints.close', [$this->workspace, $current]), ['next_sprint_id' => $next->id])
            ->assertSessionHasNoErrors();

        $current->refresh();
        $this->assertSame([Sprint::CLOSED, 8, 5, 1], [$current->status, $current->committed_points, $current->completed_points, $current->carried_over]);
        $this->assertSame($next->id, $open->fresh()->sprint_id);
        $this->assertSame($current->id, $done->fresh()->sprint_id); // history stays with the sprint
        $this->assertDatabaseHas('activity_log', ['action' => 'sprint.closed', 'subject_id' => $current->id]);

        $this->actingAs($this->lead)->get(route('workspaces.sprints.index', $this->workspace))
            ->assertInertia(fn ($page) => $page->where('closed.0.completed_points', 5)->where('sprints.0.id', $next->id));
    }

    public function test_deleting_a_planned_sprint_returns_tasks_to_the_backlog()
    {
        $sprint = Sprint::factory()->create(['workspace_id' => $this->workspace->id]);
        $task = Task::factory()->create(['workspace_id' => $this->workspace->id, 'sprint_id' => $sprint->id]);

        $this->actingAs($this->lead)->delete(route('workspaces.sprints.destroy', [$this->workspace, $sprint]))->assertRedirect();

        $this->assertNull($task->fresh()->sprint_id);
        $this->assertDatabaseMissing('sprints', ['id' => $sprint->id]);
    }

    public function test_sprints_are_scoped_to_their_client()
    {
        $other = Sprint::factory()->create();

        $this->actingAs($this->lead)->post(route('workspaces.sprints.start', [$this->workspace, $other]))->assertNotFound();
    }

    private function join(string $role): User
    {
        $user = User::factory()->withRole('member')->create();
        $this->workspace->members()->attach($user->id, ['role' => $role]);

        return $user;
    }
}
