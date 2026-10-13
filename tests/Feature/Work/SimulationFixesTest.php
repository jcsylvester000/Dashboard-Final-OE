<?php

namespace Tests\Feature\Work;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fixes from the 2026-10-06 user-journey simulation (Phase A).
 */
class SimulationFixesTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);

        $this->workspace = Workspace::factory()->create();
        $this->member = User::factory()->withRole('member')->create();
        $this->workspace->members()->attach($this->member->id, ['role' => Workspace::ROLE_MEMBER]);
    }

    public function test_app_runs_on_the_teams_local_timezone_by_default()
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
    }

    public function test_logging_time_moves_a_to_do_task_to_in_progress()
    {
        $task = Task::factory()->create(['workspace_id' => $this->workspace->id]);

        $this->actingAs($this->member)->post(route('workspaces.tasks.time.store', [$this->workspace, $task]), [
            'entry_date' => now()->toDateString(),
            'hours' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame(TaskStatus::idFor('in-progress'), $task->fresh()->status_id);
    }

    public function test_starting_a_timer_moves_the_task_to_in_progress_but_leaves_review_alone()
    {
        $task = Task::factory()->create(['workspace_id' => $this->workspace->id]);
        $this->actingAs($this->member)->post(route('workspaces.tasks.timer', [$this->workspace, $task]));
        $this->assertSame(TaskStatus::idFor('in-progress'), $task->fresh()->status_id);

        $review = Task::factory()->create(['workspace_id' => $this->workspace->id, 'status_id' => TaskStatus::idFor('in-review')]);
        $this->actingAs($this->member)->post(route('workspaces.tasks.timer', [$this->workspace, $review]));
        $this->assertSame(TaskStatus::idFor('in-review'), $review->fresh()->status_id);
    }

    public function test_a_backlog_task_still_waiting_on_open_work_is_not_moved()
    {
        $blocker = Task::factory()->create(['workspace_id' => $this->workspace->id]);
        $task = Task::factory()->create(['workspace_id' => $this->workspace->id, 'status_id' => TaskStatus::idFor('backlog')]);
        $task->dependencies()->attach($blocker->id, ['type' => 'blocks']);

        $this->actingAs($this->member)->post(route('workspaces.tasks.time.store', [$this->workspace, $task]), [
            'entry_date' => now()->toDateString(),
            'minutes' => 30,
        ]);

        $this->assertSame(TaskStatus::idFor('backlog'), $task->fresh()->status_id);
    }

    public function test_quick_add_takes_project_due_estimate_in_hours_and_links_the_new_task()
    {
        $project = Project::factory()->create(['workspace_id' => $this->workspace->id]);

        $this->actingAs($this->member)->post(route('workspaces.tasks.store', $this->workspace), [
            'title' => 'Checkout API',
            'project_id' => $project->id,
            'due_on' => '2026-11-02',
            'estimate_minutes' => 330,
            'priority' => 'high',
            'stay' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $task = Task::where('title', 'Checkout API')->firstOrFail();
        $this->assertSame($project->id, $task->project_id);
        $this->assertSame(330, $task->estimate_minutes);
        $this->assertSame('high', $task->priority);

        $toast = session('inertia.flash_data')['toast'] ?? null;
        $this->assertIsArray($toast);
        $this->assertSame(route('workspaces.tasks.show', [$this->workspace, $task], false), $toast['action']['url']);
    }

    public function test_estimates_can_be_edited_in_hours()
    {
        $task = Task::factory()->create(['workspace_id' => $this->workspace->id]);

        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->workspace, $task]), ['estimate_hours' => '2.5'])
            ->assertSessionHasNoErrors();
        $this->assertSame(150, $task->fresh()->estimate_minutes);

        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->workspace, $task]), ['estimate_hours' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull($task->fresh()->estimate_minutes);
    }

    public function test_task_rows_carry_the_project_for_context()
    {
        $project = Project::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'MVP 1']);
        $task = Task::factory()->create(['workspace_id' => $this->workspace->id, 'project_id' => $project->id]);

        $this->actingAs($this->member)->get(route('workspaces.tasks.show', [$this->workspace, $task]))
            ->assertInertia(fn ($page) => $page->where('task.project.name', 'MVP 1'));
    }
}
