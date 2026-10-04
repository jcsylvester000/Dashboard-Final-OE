<?php

namespace Tests\Feature\Work;

use App\Models\ActivityLog;
use App\Models\Comment;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\WorkflowTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class, WorkflowTemplateSeeder::class]);

        $this->workspace = Workspace::factory()->create();
        $this->member = $this->join(Workspace::ROLE_MEMBER);
    }

    private function join(string $role = Workspace::ROLE_MEMBER): User
    {
        $user = User::factory()->withRole('member')->create();
        $this->workspace->members()->attach($user->id, ['role' => $role]);

        return $user;
    }

    private function dept(string $slug): Department
    {
        return Department::where('slug', $slug)->firstOrFail();
    }

    private function task(array $attributes = []): Task
    {
        return Task::factory()->create(['workspace_id' => $this->workspace->id, ...$attributes]);
    }

    public function test_members_create_tasks_and_become_watchers()
    {
        $assignee = $this->join();

        $this->actingAs($this->member)->post(route('workspaces.tasks.store', $this->workspace), [
            'title' => 'Write landing page copy',
            'department_id' => $this->dept('marketing')->id,
            'assignee_id' => $assignee->id,
        ])->assertRedirect();

        $task = Task::where('title', 'Write landing page copy')->firstOrFail();
        $this->assertSame(TaskStatus::idFor('todo'), $task->status_id);
        $this->assertSame($this->member->id, $task->reporter_id);
        $this->assertEqualsCanonicalizing([$this->member->id, $assignee->id], $task->watchers()->pluck('users.id')->all());
        $this->assertTrue(ActivityLog::where('action', 'task.created')->where('subject_id', $task->id)->exists());
    }

    public function test_assignees_must_belong_to_the_workspace()
    {
        $outsider = User::factory()->create();

        $this->actingAs($this->member)->post(route('workspaces.tasks.store', $this->workspace), [
            'title' => 'X', 'assignee_id' => $outsider->id,
        ])->assertSessionHasErrors('assignee_id');
    }

    public function test_non_members_and_guests_cannot_change_tasks()
    {
        $task = $this->task();
        $guest = $this->join(Workspace::ROLE_GUEST);
        $outsider = User::factory()->withRole('member')->create();

        $this->actingAs($outsider)->get(route('workspaces.tasks.show', [$this->workspace, $task]))->assertForbidden();
        $this->actingAs($guest)->get(route('workspaces.tasks.show', [$this->workspace, $task]))->assertOk();
        $this->actingAs($guest)->put(route('workspaces.tasks.update', [$this->workspace, $task]), ['title' => 'Hacked'])->assertForbidden();
    }

    public function test_tasks_are_scoped_to_their_workspace_in_the_url()
    {
        $foreign = Task::factory()->create();

        $this->actingAs($this->member)
            ->get(route('workspaces.tasks.show', [$this->workspace, $foreign]))
            ->assertNotFound();
    }

    public function test_status_changes_are_recorded_on_the_timeline()
    {
        $task = $this->task();

        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->workspace, $task]), [
            'status_id' => TaskStatus::idFor('in-progress'),
        ])->assertRedirect();

        $log = ActivityLog::where('action', 'task.updated')->where('subject_id', $task->id)->firstOrFail();
        $this->assertSame(['from' => 'To Do', 'to' => 'In Progress'], $log->properties['changes']['status_id']);
    }

    public function test_a_task_cannot_be_finished_while_waiting_on_another()
    {
        $blocker = $this->task(['title' => 'Research']);
        $task = $this->task(['title' => 'Build']);

        $this->actingAs($this->member)->post(route('workspaces.tasks.dependencies.store', [$this->workspace, $task]), [
            'depends_on_task_id' => $blocker->id,
        ])->assertRedirect();

        $this->actingAs($this->member)->patch(route('workspaces.tasks.move', [$this->workspace, $task]), [
            'status_id' => TaskStatus::idFor('done'), 'position' => 1,
        ])->assertSessionHasErrors('status_id');

        $this->assertNull($task->fresh()->completed_at);

        // Finish the blocker, then the task can be done.
        $this->actingAs($this->member)->patch(route('workspaces.tasks.move', [$this->workspace, $blocker]), [
            'status_id' => TaskStatus::idFor('done'), 'position' => 1,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->member)->patch(route('workspaces.tasks.move', [$this->workspace, $task]), [
            'status_id' => TaskStatus::idFor('done'), 'position' => 2,
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_direct_dependency_cycles_are_refused()
    {
        $a = $this->task();
        $b = $this->task();
        $a->dependencies()->attach($b->id, ['type' => 'blocks']);

        $this->actingAs($this->member)->post(route('workspaces.tasks.dependencies.store', [$this->workspace, $b]), [
            'depends_on_task_id' => $a->id,
        ])->assertSessionHasErrors('depends_on_task_id');
    }

    public function test_handoff_creates_a_waiting_task_in_the_next_department_that_unblocks_when_done()
    {
        $research = $this->task(['title' => 'Audience research', 'department_id' => $this->dept('research')->id]);
        $dev = $this->join();

        $this->actingAs($this->member)->post(route('workspaces.tasks.handoff', [$this->workspace, $research]), [
            'department_id' => $this->dept('development')->id,
            'assignee_id' => $dev->id,
            'note' => 'Build the landing page from these findings.',
        ])->assertRedirect();

        $next = Task::where('handoff_from_task_id', $research->id)->firstOrFail();
        $this->assertSame($this->dept('development')->id, $next->department_id);
        $this->assertSame($dev->id, $next->assignee_id);
        $this->assertSame(TaskStatus::idFor('backlog'), $next->status_id);
        $this->assertTrue($next->dependencies()->whereKey($research->id)->exists());

        // Finishing the research moves the handoff to To Do.
        $this->actingAs($this->member)->patch(route('workspaces.tasks.move', [$this->workspace, $research]), [
            'status_id' => TaskStatus::idFor('done'), 'position' => 1,
        ]);

        $this->assertSame(TaskStatus::idFor('todo'), $next->fresh()->status_id);
    }

    public function test_applying_a_template_creates_a_chained_cross_department_workflow()
    {
        $template = WorkflowTemplate::where('slug', 'client-onboarding')->firstOrFail();

        $this->actingAs($this->member)->post(route('workspaces.templates.apply', [$this->workspace, $template]), [
            'start_on' => '2026-11-02',
        ])->assertRedirect(route('workspaces.tasks.board', $this->workspace));

        $tasks = Task::where('workspace_id', $this->workspace->id)->orderBy('id')->get();
        $this->assertCount(5, $tasks);
        $this->assertSame(
            ['research', 'product', 'development', 'seo', 'marketing'],
            $tasks->map(fn (Task $t) => Department::find($t->department_id)?->slug)->all(),
        );
        $this->assertSame(TaskStatus::idFor('todo'), $tasks[0]->status_id);
        $this->assertSame(TaskStatus::idFor('backlog'), $tasks[1]->status_id);
        $this->assertTrue($tasks[1]->dependencies()->whereKey($tasks[0]->id)->exists());
        $this->assertSame('2026-11-07', $tasks[1]->due_on?->toDateString());
    }

    public function test_comments_record_mentions_and_add_watchers()
    {
        $task = $this->task();
        $colleague = $this->join();

        $this->actingAs($this->member)->post(route('workspaces.tasks.comments.store', [$this->workspace, $task]), [
            'body' => "Can you check this @[{$colleague->name}](user:{$colleague->id})?",
        ])->assertRedirect();

        $comment = Comment::firstOrFail();
        $this->assertSame([$colleague->id], $comment->mentions()->pluck('mentioned_user_id')->all());
        $this->assertTrue($task->watchers()->whereKey($colleague->id)->exists());
    }

    public function test_only_the_author_edits_a_comment_within_the_window()
    {
        $task = $this->task();
        $other = $this->join();
        $comment = Comment::create([
            'workspace_id' => $this->workspace->id,
            'commentable_type' => 'task',
            'commentable_id' => $task->id,
            'user_id' => $this->member->id,
            'body' => 'First',
        ]);

        $this->actingAs($other)->put(route('workspaces.comments.update', [$this->workspace, $comment]), ['body' => 'Nope'])->assertForbidden();

        $this->travel(20)->minutes();
        $this->actingAs($this->member)->put(route('workspaces.comments.update', [$this->workspace, $comment]), ['body' => 'Late'])->assertForbidden();
    }

    public function test_board_and_list_pages_render()
    {
        $this->task(['title' => 'Visible task']);

        $this->actingAs($this->member)->get(route('workspaces.tasks.index', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('tasks/Index')->has('tasks.data', 1));

        $this->actingAs($this->member)->get(route('workspaces.tasks.board', $this->workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('tasks/Board')->has('tasks', 1));
    }

    public function test_my_tasks_only_lists_my_open_tasks()
    {
        $this->task(['title' => 'Mine', 'assignee_id' => $this->member->id]);
        $this->task(['title' => 'Mine but done', 'assignee_id' => $this->member->id, 'status_id' => TaskStatus::idFor('done')]);
        $this->task(['title' => 'Someone else']);

        $this->actingAs($this->member)->get(route('tasks.mine'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('tasks', 1)->where('tasks.0.title', 'Mine'));
    }

    public function test_department_queue_shows_only_visible_workspaces()
    {
        $seo = $this->dept('seo');
        $lead = $this->join(Workspace::ROLE_LEAD);
        $lead->forceFill(['primary_department_id' => $seo->id])->save();

        $this->task(['title' => 'SEO here', 'department_id' => $seo->id]);
        Task::factory()->create(['title' => 'SEO elsewhere', 'department_id' => $seo->id]);

        $this->actingAs($lead)->get(route('departments.queue', $seo))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('tasks', 1)->where('tasks.0.title', 'SEO here'));

        $marketingOnly = User::factory()->withRole('member')->create(['primary_department_id' => $this->dept('marketing')->id]);
        $this->actingAs($marketingOnly)->get(route('departments.queue', $seo))->assertForbidden();
    }

    public function test_only_leads_or_the_reporter_can_delete_a_task()
    {
        $task = $this->task(['reporter_id' => $this->member->id]);
        $other = $this->join();

        $this->actingAs($other)->delete(route('workspaces.tasks.destroy', [$this->workspace, $task]))->assertForbidden();
        $this->actingAs($this->member)->delete(route('workspaces.tasks.destroy', [$this->workspace, $task]))->assertRedirect();

        $this->assertSoftDeleted($task);
    }

    public function test_admins_manage_workflow_templates()
    {
        $admin = User::factory()->withRole('admin')->create();

        $this->actingAs($this->member)->get(route('admin.templates.index'))->assertForbidden();

        $this->actingAs($admin)->post(route('admin.templates.store'), [
            'name' => 'Blog post',
            'steps' => [
                ['department_id' => $this->dept('seo')->id, 'title' => 'Keyword brief', 'offset_days' => 0],
                ['department_id' => $this->dept('marketing')->id, 'title' => 'Write and publish', 'offset_days' => 3, 'depends_on_previous' => true],
            ],
        ])->assertRedirect();

        $template = WorkflowTemplate::where('name', 'Blog post')->firstOrFail();
        $this->assertSame(2, $template->steps()->count());
    }
}
