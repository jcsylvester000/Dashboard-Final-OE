<?php

namespace Tests\Feature\Work;

use App\Domain\Work\ChecklistDefaults;
use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Department;
use App\Models\InboxNotification;
use App\Models\Project;
use App\Models\ReviewRule;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Enhancement batch E13a: agency definition of done (E-25), research work details (E-28),
 * review rules that fill reviewers (E-34), approve from the inbox (E-38).
 */
class EnhancementBatchE13aTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $lead;

    private User $designer;

    private User $ana;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        $this->travelTo('2026-10-12 09:00:00');
        $this->ws = Workspace::factory()->create();
        $this->lead = User::factory()->withRole('member')->create();
        $this->designer = User::factory()->withRole('member')->create();
        $this->ana = User::factory()->withRole('member')->create(['name' => 'Ana Reyes']);
        $this->ben = User::factory()->withRole('member')->create(['name' => 'Ben Cruz']);
        $this->ws->members()->attach([
            $this->lead->id => ['role' => Workspace::ROLE_LEAD],
            $this->designer->id => ['role' => Workspace::ROLE_MEMBER],
            $this->ana->id => ['role' => Workspace::ROLE_MEMBER],
            $this->ben->id => ['role' => Workspace::ROLE_MEMBER],
        ]);
    }

    private function toReview(Task $task): void
    {
        $this->actingAs($this->designer)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['status_id' => TaskStatus::idFor('in-review')])
            ->assertSessionHasNoErrors();
    }

    /**
     * @return list<int>
     */
    private function reviewerIds(Task $task): array
    {
        return $task->reviewers()->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_stories_get_the_agency_definition_of_done_unless_the_client_has_its_own()
    {
        $this->actingAs($this->designer)->post(route('workspaces.tasks.store', $this->ws), ['title' => 'Cart page', 'type' => 'story'])->assertSessionHasNoErrors();
        $story = Task::query()->where('title', 'Cart page')->firstOrFail();
        $this->assertSame(ChecklistDefaults::BUILT_IN['story'], $story->checklistItems()->orderBy('position')->pluck('label')->all());
        $this->actingAs($this->designer)->put(route('workspaces.tasks.update', [$this->ws, $story]), ['status_id' => TaskStatus::idFor('done')])
            ->assertSessionHasErrors('status_id');

        $admin = User::factory()->withRole('admin')->create();
        $this->actingAs($admin)->put(route('admin.checklist-defaults.update'), ['templates' => ['story' => "Tests pass\nDocs updated", 'task' => '']])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->designer)->put(route('admin.checklist-defaults.update'), ['templates' => []])->assertForbidden();
        $this->assertCount(5, $story->checklistItems()->get(), 'existing tasks keep their checklist');

        $this->actingAs($this->designer)->post(route('workspaces.tasks.store', $this->ws), ['title' => 'Search page', 'type' => 'story']);
        $this->assertSame(['Tests pass', 'Docs updated'], Task::query()->where('title', 'Search page')->firstOrFail()->checklistItems()->orderBy('position')->pluck('label')->all());

        // A client's own checklist wins over the agency default.
        $this->actingAs($this->lead)->put(route('workspaces.checklists.update', $this->ws), ['templates' => ['story' => 'Client sign-off']]);
        $this->actingAs($this->designer)->post(route('workspaces.tasks.store', $this->ws), ['title' => 'Promo page', 'type' => 'story']);
        $this->assertSame(['Client sign-off'], Task::query()->where('title', 'Promo page')->firstOrFail()->checklistItems()->pluck('label')->all());
    }

    public function test_research_tasks_have_their_own_fields_filters_and_export_columns()
    {
        $research = (int) Department::query()->where('slug', 'research')->value('id');
        $task = Task::factory()->create(['workspace_id' => $this->ws->id, 'department_id' => $research, 'title' => 'Why carts are abandoned']);
        $this->actingAs($this->designer)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['work_details' => [
            'question' => 'Why do shoppers leave at shipping?', 'method' => 'Interviews', 'sources' => '12 customer calls', 'confidence' => 'Medium', 'decision' => 'Free shipping threshold',
        ]])->assertSessionHasNoErrors();
        $this->actingAs($this->designer)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['work_details' => ['method' => 'Guessing']])
            ->assertSessionHasErrors('work_details.method');
        Task::factory()->create(['workspace_id' => $this->ws->id, 'department_id' => $research, 'title' => 'Survey', 'work_details' => ['method' => 'Survey', 'confidence' => 'High']]);

        $this->actingAs($this->designer)->get(route('workspaces.tasks.index', [$this->ws, 'method' => 'Interviews']))
            ->assertInertia(fn (Assert $page) => $page->where('tasks.data', fn ($rows) => collect($rows)->pluck('title')->all() === ['Why carts are abandoned']));
        $this->actingAs($this->designer)->get(route('workspaces.tasks.index', [$this->ws, 'confidence' => 'High']))
            ->assertInertia(fn (Assert $page) => $page->where('tasks.data', fn ($rows) => collect($rows)->pluck('title')->all() === ['Survey']));

        $csv = $this->actingAs($this->lead)->get(route('workspaces.work-summary.csv', $this->ws))->assertOk()->streamedContent();
        $this->assertStringContainsString('"Research question",Method,Sources,Confidence,"Decision it informs"', $csv);
    }

    public function test_review_rules_fill_reviewers_by_specificity_and_take_turns()
    {
        $project = Project::factory()->create(['workspace_id' => $this->ws->id]);
        ReviewRule::query()->create(['workspace_id' => $this->ws->id, 'user_ids' => [$this->ana->id, $this->ben->id], 'mode' => 'round_robin']);
        ReviewRule::query()->create(['workspace_id' => $this->ws->id, 'project_id' => $project->id, 'user_ids' => [$this->ana->id, $this->ben->id, $this->designer->id], 'mode' => 'all']);
        ReviewRule::query()->create(['workspace_id' => $this->ws->id, 'deliverable_type' => 'Ad creative', 'user_ids' => [$this->lead->id], 'mode' => 'all']);

        // Project rule wins; the assignee never reviews their own work.
        $inProject = Task::factory()->create(['workspace_id' => $this->ws->id, 'project_id' => $project->id, 'assignee_id' => $this->designer->id]);
        $this->toReview($inProject);
        $this->assertSame([$this->ana->id, $this->ben->id], $this->reviewerIds($inProject));
        $this->assertSame('all', $inProject->fresh()?->review_rule);

        $ad = Task::factory()->create(['workspace_id' => $this->ws->id, 'assignee_id' => $this->designer->id, 'work_details' => ['deliverable_type' => 'Ad creative']]);
        $this->toReview($ad);
        $this->assertSame([$this->lead->id], $this->reviewerIds($ad));

        // Round-robin: one reviewer each time, in turn.
        $first = Task::factory()->create(['workspace_id' => $this->ws->id, 'assignee_id' => $this->designer->id]);
        $second = Task::factory()->create(['workspace_id' => $this->ws->id, 'assignee_id' => $this->designer->id]);
        $this->toReview($first);
        $this->toReview($second);
        $this->assertSame([$this->ana->id], $this->reviewerIds($first));
        $this->assertSame([$this->ben->id], $this->reviewerIds($second));
        $this->assertSame('any', $first->fresh()?->review_rule);

        // People named by hand are kept.
        $named = Task::factory()->create(['workspace_id' => $this->ws->id, 'project_id' => $project->id, 'assignee_id' => $this->designer->id]);
        $this->actingAs($this->designer)->put(route('workspaces.tasks.reviewers', [$this->ws, $named]), ['user_ids' => [$this->lead->id], 'rule' => 'all']);
        $this->toReview($named);
        $this->assertSame([$this->lead->id], $this->reviewerIds($named));

        // Leads manage the rules; members can't.
        $this->actingAs($this->designer)->post(route('workspaces.review-rules.store', $this->ws), ['user_ids' => [$this->ana->id], 'mode' => 'all'])->assertForbidden();
        $this->actingAs($this->lead)->post(route('workspaces.review-rules.store', $this->ws), ['task_type' => 'bug', 'user_ids' => [$this->ana->id], 'mode' => 'any'])
            ->assertSessionHasNoErrors();
        $this->assertSame(4, ReviewRule::query()->count());
    }

    public function test_a_reviewer_can_approve_from_the_inbox_only_after_a_recent_look()
    {
        $task = Task::factory()->create(['workspace_id' => $this->ws->id, 'assignee_id' => $this->designer->id, 'title' => 'Banner set']);
        $this->actingAs($this->designer)->put(route('workspaces.tasks.reviewers', [$this->ws, $task]), ['user_ids' => [$this->ana->id], 'rule' => 'all']);
        $this->toReview($task);
        $alert = InboxNotification::query()->where('notifiable_id', $this->ana->id)->where('kind', 'review')->firstOrFail();

        $canApprove = fn () => $this->actingAs($this->ana)->get(route('inbox.index'))
            ->assertInertia(fn (Assert $page) => $page->where('items.data', fn ($rows) => collect($rows)->firstWhere('id', $alert->id)['can_approve'] === true));
        $cannotApprove = fn () => $this->actingAs($this->ana)->get(route('inbox.index'))
            ->assertInertia(fn (Assert $page) => $page->where('items.data', fn ($rows) => collect($rows)->firstWhere('id', $alert->id)['can_approve'] === false));

        $cannotApprove();
        $this->actingAs($this->ana)->post(route('inbox.approve', $alert->id))->assertSessionHasErrors('approve');

        $this->actingAs($this->ana)->get(route('workspaces.tasks.show', [$this->ws, $task]))->assertOk(); // looked at it
        $canApprove();

        // A newer file after that look needs another look.
        $this->travel(5)->minutes();
        Attachment::query()->create(['workspace_id' => $this->ws->id, 'attachable_type' => $task->getMorphClass(), 'attachable_id' => $task->id,
            'uploaded_by' => $this->designer->id, 'disk' => 'local', 'key' => 'attachments/x.png', 'original_name' => 'v2.png', 'mime' => 'image/png', 'size' => 10]);
        $cannotApprove();
        $this->travel(1)->minutes();
        $this->actingAs($this->ana)->get(route('workspaces.tasks.show', [$this->ws, $task]));

        $this->actingAs($this->ana)->post(route('inbox.approve', $alert->id))->assertSessionHasNoErrors();
        $this->assertSame(TaskStatus::idFor('done'), $task->fresh()?->status_id);
        $this->assertTrue(ActivityLog::query()->where('action', 'task.approved')->where('properties->via', 'inbox')->exists());
        $this->assertNotNull($alert->fresh()?->done_at);

        // Old looks expire after 24 hours.
        $other = Task::factory()->create(['workspace_id' => $this->ws->id, 'assignee_id' => $this->designer->id]);
        $this->actingAs($this->designer)->put(route('workspaces.tasks.reviewers', [$this->ws, $other]), ['user_ids' => [$this->ben->id], 'rule' => 'all']);
        $this->toReview($other);
        $this->actingAs($this->ben)->get(route('workspaces.tasks.show', [$this->ws, $other]));
        $this->travel(25)->hours();
        $benAlert = InboxNotification::query()->where('notifiable_id', $this->ben->id)->where('kind', 'review')->firstOrFail();
        $this->actingAs($this->ben)->post(route('inbox.approve', $benAlert->id))->assertSessionHasErrors('approve');
    }
}
