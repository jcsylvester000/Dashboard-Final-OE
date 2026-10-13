<?php

namespace Tests\Feature\Work;

use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Findings batch 3: start dates, calendar bars + week view + filters (S1-17),
 * timeline with projects, milestones, sprints and waits-on links (B-02, S2-09).
 */
class CalendarTimelineTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        $this->travelTo('2026-10-08 09:00:00');

        $this->ws = Workspace::factory()->create(['key' => 'BRW']);
        $this->member = User::factory()->withRole('member')->create();
        $this->ws->members()->attach($this->member->id, ['role' => Workspace::ROLE_MEMBER]);
    }

    public function test_start_date_is_saved_and_must_not_be_after_the_due_date()
    {
        $task = Task::factory()->create(['workspace_id' => $this->ws->id, 'due_on' => '2026-10-20']);

        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['start_on' => '2026-10-25'])
            ->assertSessionHasErrors('start_on');
        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['start_on' => '2026-10-12'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-10-12', $task->fresh()->start_on?->toDateString());
    }

    public function test_calendar_shows_bars_crossing_the_range_has_a_week_view_and_filters()
    {
        // Starts in the week, due after it: must appear in the week view.
        $long = Task::factory()->create(['workspace_id' => $this->ws->id, 'start_on' => '2026-10-07', 'due_on' => '2026-10-30', 'assignee_id' => $this->member->id]);
        $mine = Task::factory()->create(['workspace_id' => $this->ws->id, 'due_on' => '2026-10-11', 'assignee_id' => $this->member->id]);
        Task::factory()->create(['workspace_id' => $this->ws->id, 'due_on' => '2026-10-09']); // unassigned
        Task::factory()->create(['workspace_id' => $this->ws->id, 'due_on' => '2026-11-20']); // outside the week

        $ids = function (array $q): array {
            $out = [];
            $this->actingAs($this->member)->get(route('workspaces.tasks.calendar', [$this->ws, ...$q]))->assertOk()
                // A full closure (not fn): arrow functions capture by value, so &$out would never come back.
                ->assertInertia(function ($page) use (&$out) {
                    $page->where('tasks', function ($t) use (&$out) {
                        $out = collect($t)->pluck('id')->sort()->values()->all();

                        return true;
                    });
                });

            return $out;
        };

        $this->assertCount(3, $ids(['view' => 'week', 'date' => '2026-10-08']));
        $this->assertSame([$long->id, $mine->id], $ids(['view' => 'week', 'date' => '2026-10-08', 'assignee' => 'me']));
        // Last day of the grid is included (Sunday Oct 11).
        $this->assertContains($mine->id, $ids(['view' => 'week', 'date' => '2026-10-05']));
        $this->actingAs($this->member)->get(route('workspaces.tasks.calendar', [$this->ws, 'view' => 'week', 'date' => '2026-10-08']))
            ->assertInertia(fn ($page) => $page->where('view', 'week')->where('gridStart', '2026-10-05')->where('gridEnd', '2026-10-11'));
    }

    public function test_timeline_lists_projects_tasks_milestones_sprints_and_dependencies()
    {
        $campaign = Project::factory()->create(['workspace_id' => $this->ws->id, 'name' => 'Holiday', 'start_on' => '2026-09-20', 'due_on' => '2026-11-22']);
        $brief = Task::factory()->create(['workspace_id' => $this->ws->id, 'project_id' => $campaign->id, 'start_on' => '2026-10-01', 'due_on' => '2026-10-10']);
        $ads = Task::factory()->create(['workspace_id' => $this->ws->id, 'project_id' => $campaign->id, 'start_on' => '2026-10-11', 'due_on' => '2026-10-25']);
        $ads->dependencies()->attach($brief->id, ['type' => 'blocks']);
        Task::factory()->create(['workspace_id' => $this->ws->id, 'project_id' => $campaign->id, 'type' => 'milestone', 'start_on' => '2026-11-01', 'due_on' => '2026-11-01', 'title' => 'Launch']);
        Task::factory()->create(['workspace_id' => $this->ws->id, 'due_on' => '2027-03-01']); // outside 8 weeks
        Sprint::factory()->create(['workspace_id' => $this->ws->id, 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-16']);

        $this->actingAs($this->member)->get(route('workspaces.tasks.timeline', $this->ws))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('tasks/Timeline')
                ->where('range.from', '2026-09-21')
                ->where('range.to', '2026-11-15')
                ->has('projects', 1)
                ->has('tasks', 3)
                ->where('tasks.2.type', 'milestone')
                ->where('dependencies.0', ['task' => $ads->id, 'waits_on' => $brief->id])
                ->has('sprints', 1));

        $outsider = User::factory()->withRole('member')->create();
        $this->actingAs($outsider)->get(route('workspaces.tasks.timeline', $this->ws))->assertForbidden();
    }
}
