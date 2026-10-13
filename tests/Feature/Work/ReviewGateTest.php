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
 * Finding S2-13: in projects that need sign-off, only a reviewer marks work Done.
 */
class ReviewGateTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $lead;

    private User $member;

    private Project $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);

        $this->ws = Workspace::factory()->create();
        $this->lead = User::factory()->withRole('member')->create();
        $this->member = User::factory()->withRole('member')->create();
        $this->ws->members()->attach([$this->lead->id => ['role' => Workspace::ROLE_LEAD], $this->member->id => ['role' => Workspace::ROLE_MEMBER]]);
        $this->campaign = Project::factory()->create(['workspace_id' => $this->ws->id, 'review_required' => true]);
    }

    public function test_members_cannot_mark_signed_off_work_done_but_can_send_it_to_review()
    {
        $task = Task::factory()->create(['workspace_id' => $this->ws->id, 'project_id' => $this->campaign->id, 'assignee_id' => $this->member->id]);

        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['status_id' => TaskStatus::idFor('done')])
            ->assertSessionHasErrors('status_id');
        $this->assertNull($task->fresh()->completed_at);

        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['status_id' => TaskStatus::idFor('in-review')])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->member)->get(route('workspaces.tasks.show', [$this->ws, $task]))
            ->assertInertia(fn ($page) => $page->where('reviewRequired', true)->where('canReview', false));

        // The lead approves through the review action, which marks it Done.
        $this->actingAs($this->lead)->post(route('workspaces.tasks.review', [$this->ws, $task]), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_the_gate_also_applies_to_board_moves_and_bulk_edits_and_not_to_other_projects()
    {
        $task = Task::factory()->create(['workspace_id' => $this->ws->id, 'project_id' => $this->campaign->id]);
        $free = Task::factory()->create(['workspace_id' => $this->ws->id, 'project_id' => Project::factory()->create(['workspace_id' => $this->ws->id])->id]);

        $this->actingAs($this->member)->patch(route('workspaces.tasks.move', [$this->ws, $task]), ['status_id' => TaskStatus::idFor('done'), 'position' => 0])
            ->assertSessionHasErrors('status_id');

        $this->actingAs($this->member)->post(route('workspaces.tasks.bulk', $this->ws), [
            'ids' => [$task->id, $free->id], 'action' => 'update', 'status_id' => TaskStatus::idFor('done'),
        ]);
        $this->assertNull($task->fresh()->completed_at);
        $this->assertNotNull($free->fresh()->completed_at);

        // Leads can close it directly.
        $this->actingAs($this->lead)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['status_id' => TaskStatus::idFor('done')])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_leads_turn_the_setting_on_from_the_project_form()
    {
        $project = Project::factory()->create(['workspace_id' => $this->ws->id, 'name' => 'Arizona launch', 'type' => 'campaign', 'status' => 'active']);

        $this->actingAs($this->lead)->put(route('workspaces.projects.update', [$this->ws, $project]), [
            'name' => 'Arizona launch', 'type' => 'campaign', 'status' => 'active', 'review_required' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($project->fresh()->review_required);
    }
}
