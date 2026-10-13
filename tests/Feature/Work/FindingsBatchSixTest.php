<?php

namespace Tests\Feature\Work;

use App\Models\ActivityLog;
use App\Models\Label;
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
 * Findings batch 6: recurring tasks (B-04), duplicate + linked status (B-11),
 * edit conflicts (B-12), client status page (B-06).
 */
class FindingsBatchSixTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $lead;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        $this->travelTo('2026-10-13 09:00:00');

        $this->ws = Workspace::factory()->create();
        $this->lead = User::factory()->withRole('member')->create();
        $this->member = User::factory()->withRole('member')->create(['name' => 'Secret Person']);
        $this->ws->members()->attach([$this->lead->id => ['role' => Workspace::ROLE_LEAD], $this->member->id => ['role' => Workspace::ROLE_MEMBER]]);
    }

    private function done(Task $task, ?User $as = null): void
    {
        $this->actingAs($as ?? $this->lead)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['status_id' => TaskStatus::idFor('done')])
            ->assertSessionHasNoErrors();
    }

    public function test_finishing_a_recurring_task_creates_the_next_one_once()
    {
        $label = Label::create(['workspace_id' => $this->ws->id, 'name' => 'Report', 'color' => 'sky']);
        $this->actingAs($this->lead)->post(route('workspaces.tasks.store', $this->ws), [
            'title' => 'Weekly SEO report', 'due_on' => '2026-10-16', 'start_on' => '2026-10-14',
            'recurrence' => 'weekly', 'label_ids' => [$label->id], 'assignee_id' => $this->member->id,
        ])->assertSessionHasNoErrors();
        $task = Task::query()->where('title', 'Weekly SEO report')->firstOrFail();

        $this->done($task);
        $next = Task::query()->where('recurred_from_task_id', $task->id)->firstOrFail();
        $this->assertSame('2026-10-23', $next->due_on?->toDateString());
        $this->assertSame('2026-10-21', $next->start_on?->toDateString());
        $this->assertSame($this->member->id, $next->assignee_id);
        $this->assertSame('weekly', $next->recurrence);
        $this->assertNull($next->completed_at);
        $this->assertSame([$label->id], $next->labels()->pluck('labels.id')->all());

        // Reopen and finish again: still only one follow-up.
        $this->actingAs($this->lead)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['status_id' => TaskStatus::idFor('todo')]);
        $this->done($task);
        $this->assertSame(1, Task::query()->where('recurred_from_task_id', $task->id)->count());
    }

    public function test_monthly_recurrence_keeps_month_end_and_stops_after_the_until_date()
    {
        $task = Task::factory()->create(['workspace_id' => $this->ws->id, 'due_on' => '2027-01-31', 'recurrence' => 'monthly', 'recurrence_until' => '2027-03-15']);

        $this->done($task);
        $feb = Task::query()->where('recurred_from_task_id', $task->id)->firstOrFail();
        $this->assertSame('2027-02-28', $feb->due_on?->toDateString());

        $this->done($feb);
        $this->assertFalse(Task::query()->where('recurred_from_task_id', $feb->id)->exists(), 'March 28 is past the until date');

        // Tasks without a due date or without recurrence never repeat.
        $plain = Task::factory()->create(['workspace_id' => $this->ws->id, 'due_on' => '2026-10-20']);
        $this->done($plain);
        $this->assertFalse(Task::query()->where('recurred_from_task_id', $plain->id)->exists());

        // R-21: turning repeat on shows in the task history.
        $this->actingAs($this->lead)->put(route('workspaces.tasks.update', [$this->ws, $plain]), ['recurrence' => 'biweekly'])->assertSessionHasNoErrors();
        $log = ActivityLog::query()->where('action', 'task.updated')->where('subject_id', $plain->id)->latest('id')->firstOrFail();
        $this->assertSame('Every 2 weeks', $log->properties['changes']['recurrence']['to'] ?? null);
    }

    public function test_duplicate_copies_fields_labels_and_subtasks_as_a_fresh_task()
    {
        $label = Label::create(['workspace_id' => $this->ws->id, 'name' => 'Ads', 'color' => 'rose']);
        $task = Task::factory()->create(['workspace_id' => $this->ws->id, 'title' => 'Launch ads', 'estimate_minutes' => 90, 'status_id' => TaskStatus::idFor('done')]);
        $task->labels()->attach($label->id);
        Task::factory()->create(['workspace_id' => $this->ws->id, 'parent_id' => $task->id, 'title' => 'Write copy']);

        $res = $this->actingAs($this->member)->post(route('workspaces.tasks.duplicate', [$this->ws, $task]));
        $copy = Task::query()->where('title', 'Copy of Launch ads')->firstOrFail();
        $res->assertRedirect(route('workspaces.tasks.show', [$this->ws, $copy]));

        $this->assertSame(90, $copy->estimate_minutes);
        $this->assertNull($copy->completed_at);
        $this->assertNotSame($task->status_id, $copy->status_id);
        $this->assertSame([$label->id], $copy->labels()->pluck('labels.id')->all());
        $this->assertSame(['Write copy'], Task::query()->where('parent_id', $copy->id)->pluck('title')->all());

        // Outsiders cannot duplicate.
        $this->actingAs(User::factory()->withRole('member')->create())->post(route('workspaces.tasks.duplicate', [$this->ws, $task]))->assertForbidden();
    }

    public function test_linked_tasks_show_their_current_status()
    {
        $a = Task::factory()->create(['workspace_id' => $this->ws->id]);
        $b = Task::factory()->create(['workspace_id' => $this->ws->id, 'status_id' => TaskStatus::idFor('in-review')]);
        $this->actingAs($this->member)->post(route('links.store'), [
            'source_type' => 'task', 'source_id' => $a->id, 'target_type' => 'task', 'target_id' => $b->id, 'relation' => 'relates',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->member)->get(route('workspaces.tasks.show', [$this->ws, $a]))
            ->assertInertia(fn ($page) => $page->where('linkedWork.0.id', $b->id)
                ->where('linkedWork.0.status.category', TaskStatus::query()->findOrFail(TaskStatus::idFor('in-review'))->category));
    }

    public function test_saving_over_someone_elses_newer_change_is_refused_but_my_own_is_not()
    {
        $task = Task::factory()->create(['workspace_id' => $this->ws->id, 'title' => 'Old']);
        $seen = $task->fresh()?->updated_at?->toIso8601String();

        $this->travel(5)->minutes();
        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['title' => 'Member edit'])
            ->assertSessionHasNoErrors();

        // The lead's page is stale: refused, nothing overwritten.
        $this->actingAs($this->lead)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['title' => 'Lead edit', '_seen_at' => $seen])
            ->assertSessionHasErrors('conflict');
        $this->assertSame('Member edit', $task->fresh()?->title);

        // After reloading, the lead can save.
        $fresh = $task->fresh()?->updated_at?->toIso8601String();
        $this->actingAs($this->lead)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['title' => 'Lead edit', '_seen_at' => $fresh])
            ->assertSessionHasNoErrors();

        // My own quick successive saves are never a conflict.
        $this->travel(5)->minutes();
        $this->actingAs($this->lead)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['priority' => 'high', '_seen_at' => $fresh])
            ->assertSessionHasNoErrors();
    }

    public function test_client_status_page_shows_progress_without_names_and_honours_expiry_and_disable()
    {
        $project = Project::factory()->create(['workspace_id' => $this->ws->id, 'name' => 'Holiday Campaign']);
        Task::factory()->create(['workspace_id' => $this->ws->id, 'project_id' => $project->id, 'title' => 'Launch day', 'type' => 'milestone', 'due_on' => '2026-10-30', 'assignee_id' => $this->member->id]);
        Task::factory()->create(['workspace_id' => $this->ws->id, 'project_id' => $project->id, 'title' => 'Banner set', 'status_id' => TaskStatus::idFor('done'), 'completed_at' => now(), 'assignee_id' => $this->member->id]);

        // Members cannot create the link.
        $this->actingAs($this->member)->post(route('workspaces.projects.status-link', [$this->ws, $project]))->assertForbidden();

        $this->actingAs($this->lead)->post(route('workspaces.projects.status-link', [$this->ws, $project]), ['expires_days' => 7])
            ->assertSessionHasNoErrors();
        $token = (string) $project->fresh()?->status_token;
        $this->assertSame(40, strlen($token));

        $this->actingAs($this->lead)->get(route('workspaces.projects.show', [$this->ws, $project]))
            ->assertInertia(fn ($page) => $page->where('project.status_link.views', 0)->where('project.can_share_status', true));
        $this->actingAs($this->member)->get(route('workspaces.projects.show', [$this->ws, $project]))
            ->assertInertia(fn ($page) => $page->where('project.status_link', null)->where('project.can_share_status', false));

        auth()->logout();
        $this->get(route('project-status.show', $token))
            ->assertOk()
            ->assertSee('Holiday Campaign')
            ->assertSee('Launch day')
            ->assertSee('Banner set')
            ->assertDontSee('Secret Person')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame(1, $project->fresh()?->status_views);

        $this->get(route('project-status.show', str_repeat('a', 40)))->assertNotFound();

        // Expired.
        $this->travel(8)->days();
        $this->get(route('project-status.show', $token))->assertNotFound();

        // Disabled.
        $this->actingAs($this->lead)->post(route('workspaces.projects.status-link', [$this->ws, $project]));
        $token = (string) $project->fresh()?->status_token;
        $this->actingAs($this->lead)->delete(route('workspaces.projects.status-link.destroy', [$this->ws, $project]));
        auth()->logout();
        $this->get(route('project-status.show', $token))->assertNotFound();
    }
}
