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
 * Findings batch 5: estimate vs actual + project filter (S1-27), departments on projects (S3-02).
 */
class FindingsBatchFiveTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        $this->travelTo('2026-10-13 09:00:00');

        $this->ws = Workspace::factory()->create();
        $this->lead = User::factory()->withRole('member')->create();
        $this->ws->members()->attach($this->lead->id, ['role' => Workspace::ROLE_LEAD]);
    }

    public function test_estimate_vs_actual_per_project_and_per_task_with_project_filter()
    {
        $mvp = Project::factory()->create(['workspace_id' => $this->ws->id, 'name' => 'MVP']);
        $seo = Project::factory()->create(['workspace_id' => $this->ws->id, 'name' => 'SEO']);
        $cart = Task::factory()->create(['workspace_id' => $this->ws->id, 'project_id' => $mvp->id, 'title' => 'Cart', 'estimate_minutes' => 600]);
        $pay = Task::factory()->create(['workspace_id' => $this->ws->id, 'project_id' => $mvp->id, 'title' => 'Pay', 'estimate_minutes' => 120]);
        $audit = Task::factory()->create(['workspace_id' => $this->ws->id, 'project_id' => $seo->id, 'title' => 'Audit', 'estimate_minutes' => 240]);
        Task::factory()->create(['workspace_id' => $this->ws->id, 'project_id' => $mvp->id, 'title' => 'No estimate']);
        foreach ([[$cart, 900], [$pay, 60], [$audit, 240]] as [$task, $min]) {
            TimeEntry::create(['user_id' => $this->lead->id, 'workspace_id' => $this->ws->id, 'task_id' => $task->id, 'project_id' => $task->project_id, 'entry_date' => '2026-10-05', 'minutes' => $min, 'is_billable' => true]);
        }

        $this->actingAs($this->lead)->get(route('reports.index', ['report' => 'estimates']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('reports/Index')
                ->where('result.rows.0.name', 'MVP')          // most over first
                ->where('result.rows.0.estimate_hours', 12)
                ->where('result.rows.0.actual_hours', 16)
                ->where('result.rows.0.variance_pct', 33)
                ->where('result.rows.1.name', 'SEO')
                ->where('result.rows.1.variance_hours', 0)
                ->where('result.summary.Over / under', '+25%')
                ->has('projects', 2));

        // One project: per task.
        $this->actingAs($this->lead)->get(route('reports.index', ['report' => 'estimates', 'project' => $mvp->id]))
            ->assertInertia(fn ($page) => $page->where('result.columns.0.label', 'Task')
                ->has('result.rows', 2)
                ->where('result.rows.0.name', 'Cart')
                ->where('result.rows.1.name', 'Pay'));

        // The project filter also narrows other reports, and other clients' projects are refused.
        $this->actingAs($this->lead)->get(route('reports.index', ['report' => 'time', 'group' => 'task', 'project' => $seo->id]))
            ->assertInertia(fn ($page) => $page->has('result.rows', 1)->where('result.rows.0.name', 'Audit'));
        $foreign = Project::factory()->create();
        $this->actingAs($this->lead)->get(route('reports.index', ['project' => $foreign->id]))->assertSessionHasErrors('project');
    }

    public function test_projects_record_departments_involved_and_can_clear_them()
    {
        $seo = (int) Department::where('slug', 'seo')->value('id');
        $mkt = (int) Department::where('slug', 'marketing')->value('id');
        $body = ['name' => 'Holiday', 'type' => 'campaign', 'status' => 'active'];

        $this->actingAs($this->lead)->post(route('workspaces.projects.store', $this->ws), [...$body, 'department_ids' => ['', $seo, $mkt]])
            ->assertSessionHasNoErrors();
        $project = Project::query()->where('name', 'Holiday')->firstOrFail();
        $this->assertEqualsCanonicalizing([$seo, $mkt], $project->departments()->pluck('departments.id')->all());

        $this->actingAs($this->lead)->get(route('workspaces.projects.show', [$this->ws, $project]))
            ->assertInertia(fn ($page) => $page->has('project.involved_departments', 2));

        // The form's hidden empty value clears the list when nothing is ticked.
        $this->actingAs($this->lead)->put(route('workspaces.projects.update', [$this->ws, $project]), [...$body, 'department_ids' => ['']])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $project->departments()->count());
    }
}
