<?php

namespace Tests\Feature\Work;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Department;
use App\Models\InboxNotification;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase B of the 2026-10-06 simulation: review sign-off, per-file approval,
 * files that travel with handoffs, and department-lead time approvals.
 */
class ReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $designer;

    private User $marketingLead;

    private User $researchLead;

    private User $colleague;

    private Department $marketing;

    private Department $research;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');

        $this->ws = Workspace::factory()->create();
        $this->marketing = Department::where('slug', 'marketing')->firstOrFail();
        $this->research = Department::where('slug', 'research')->firstOrFail();

        $this->designer = $this->member();
        $this->colleague = $this->member();
        $this->marketingLead = $this->member();
        $this->researchLead = $this->member();
        $this->marketing->update(['lead_user_id' => $this->marketingLead->id]);
        $this->research->update(['lead_user_id' => $this->researchLead->id]);
    }

    private function member(): User
    {
        $user = User::factory()->withRole('member')->create();
        $this->ws->members()->attach($user->id, ['role' => Workspace::ROLE_MEMBER]);

        return $user;
    }

    private function reviewTask(array $attrs = []): Task
    {
        return Task::factory()->create([
            'workspace_id' => $this->ws->id,
            'department_id' => $this->marketing->id,
            'assignee_id' => $this->designer->id,
            'status_id' => TaskStatus::idFor('in-review'),
            ...$attrs,
        ]);
    }

    public function test_the_department_lead_approves_work_in_review()
    {
        $task = $this->reviewTask();

        $this->actingAs($this->marketingLead)->get(route('workspaces.tasks.show', [$this->ws, $task]))
            ->assertInertia(fn (Assert $page) => $page->where('canReview', true));

        $this->actingAs($this->marketingLead)
            ->post(route('workspaces.tasks.review', [$this->ws, $task]), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();

        $this->assertSame(TaskStatus::idFor('done'), $task->fresh()->status_id);
        $this->assertDatabaseHas('activity_log', ['action' => 'task.approved', 'subject_id' => $task->id]);
    }

    public function test_the_project_lead_can_review_but_the_assignee_and_colleagues_cannot()
    {
        $project = Project::factory()->create(['workspace_id' => $this->ws->id, 'lead_user_id' => $this->colleague->id]);
        $task = $this->reviewTask(['project_id' => $project->id, 'department_id' => $this->research->id]);
        $other = $this->member();

        $this->actingAs($this->designer)->post(route('workspaces.tasks.review', [$this->ws, $task]), ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($other)->post(route('workspaces.tasks.review', [$this->ws, $task]), ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($this->marketingLead)->post(route('workspaces.tasks.review', [$this->ws, $task]), ['decision' => 'approve'])->assertForbidden();

        $this->actingAs($this->colleague)->post(route('workspaces.tasks.review', [$this->ws, $task]), ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame(TaskStatus::idFor('done'), $task->fresh()->status_id);
    }

    public function test_requesting_changes_needs_a_note_tags_the_assignee_and_reopens_the_task()
    {
        $task = $this->reviewTask();

        $this->actingAs($this->marketingLead)
            ->post(route('workspaces.tasks.review', [$this->ws, $task]), ['decision' => 'changes', 'note' => ''])
            ->assertSessionHasErrors('note');

        $this->actingAs($this->marketingLead)
            ->post(route('workspaces.tasks.review', [$this->ws, $task]), ['decision' => 'changes', 'note' => 'Logo too small on the 1080x1920'])
            ->assertSessionHasNoErrors();

        $this->assertSame(TaskStatus::idFor('in-progress'), $task->fresh()->status_id);
        $comment = Comment::query()->latest('id')->firstOrFail();
        $this->assertStringContainsString('Logo too small', $comment->body);
        $this->assertDatabaseHas('mentions', ['mentioned_user_id' => $this->designer->id, 'mentionable_id' => $comment->id]);
    }

    public function test_only_tasks_in_review_can_be_signed_off()
    {
        $task = $this->reviewTask(['status_id' => TaskStatus::idFor('todo')]);

        $this->actingAs($this->marketingLead)
            ->post(route('workspaces.tasks.review', [$this->ws, $task]), ['decision' => 'approve'])
            ->assertSessionHasErrors('decision');
    }

    public function test_reviewers_approve_files_and_the_approved_file_travels_with_the_handoff()
    {
        $task = $this->reviewTask();
        $this->actingAs($this->designer)->post(route('attachments.store'), [
            'attachable_type' => 'task',
            'attachable_id' => $task->id,
            'file' => UploadedFile::fake()->create('ad-1080x1080.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $file = Attachment::firstOrFail();

        // The designer can't approve their own creative; the department lead can.
        $this->actingAs($this->designer)->post(route('attachments.approve', $file), ['approved' => true])->assertForbidden();
        $this->actingAs($this->marketingLead)->post(route('attachments.approve', $file), ['approved' => true])->assertRedirect();
        $this->assertNotNull($file->fresh()->approved_at);
        $this->assertSame($this->marketingLead->id, $file->fresh()->approved_by);

        // Hand off to Development: the new task shows the source files, read-only, approved first.
        $this->actingAs($this->marketingLead)->post(route('workspaces.tasks.handoff', [$this->ws, $task]), [
            'department_id' => Department::where('slug', 'development')->value('id'),
        ])->assertRedirect();
        $next = Task::where('handoff_from_task_id', $task->id)->firstOrFail();

        $this->actingAs($this->colleague)->get(route('workspaces.tasks.show', [$this->ws, $next]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('panels', fn (Assert $reload) => $reload
                ->has('handoffFiles', 1)
                ->where('handoffFiles.0.name', 'ad-1080x1080.pdf')
                ->where('handoffFiles.0.approved', true)
                ->where('handoffFiles.0.can_delete', false)
                ->where('handoffFiles.0.can_approve', false)));
    }

    public function test_department_leads_approve_their_own_departments_time_only()
    {
        $researchTask = Task::factory()->create(['workspace_id' => $this->ws->id, 'department_id' => $this->research->id]);
        $marketingTask = Task::factory()->create(['workspace_id' => $this->ws->id, 'department_id' => $this->marketing->id]);
        $make = fn (Task $t) => TimeEntry::create([
            'user_id' => $this->designer->id,
            'workspace_id' => $this->ws->id,
            'task_id' => $t->id,
            'department_id' => $t->department_id,
            'entry_date' => now()->toDateString(),
            'minutes' => 60,
            'is_billable' => true,
        ]);
        $research = $make($researchTask);
        $marketing = $make($marketingTask);

        $this->actingAs($this->researchLead)->get(route('time.approvals'))
            ->assertInertia(fn (Assert $page) => $page->has('entries', 1)->where('entries.0.id', $research->id));

        $this->actingAs($this->researchLead)->post(route('time.approve'), ['ids' => [$research->id, $marketing->id], 'action' => 'approve']);

        $this->assertNotNull($research->fresh()->approved_at);
        $this->assertNull($marketing->fresh()->approved_at);
    }

    public function test_department_leads_get_the_approvals_link()
    {
        $this->actingAs($this->researchLead)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.leads', true));

        $this->actingAs($this->designer)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.leads', false));
    }

    public function test_blocked_work_restarts_when_its_blocker_is_approved()
    {
        $blocker = $this->reviewTask();
        $bug = Task::factory()->create([
            'workspace_id' => $this->ws->id,
            'department_id' => $this->marketing->id,
            'assignee_id' => $this->colleague->id,
            'status_id' => TaskStatus::idFor('blocked'),
        ]);
        $bug->dependencies()->attach($blocker->id, ['type' => 'blocks']);

        $this->actingAs($this->marketingLead)->post(route('workspaces.tasks.review', [$this->ws, $blocker]), ['decision' => 'approve']);

        $this->assertSame(TaskStatus::idFor('todo'), $bug->fresh()->status_id);
        $this->assertTrue(InboxNotification::query()->where('notifiable_id', $this->colleague->id)->where('kind', 'handoff')->exists());
    }

    public function test_reviewers_hear_right_away_when_work_is_sent_for_review()
    {
        $task = $this->reviewTask(['status_id' => TaskStatus::idFor('in-progress')]);

        $this->actingAs($this->designer)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['status_id' => TaskStatus::idFor('in-review')])
            ->assertSessionHasNoErrors();

        $alert = InboxNotification::query()->where('notifiable_id', $this->marketingLead->id)->where('kind', 'review')->first();
        $this->assertNotNull($alert);
        $this->assertFalse((bool) $alert->quiet);
    }
}
