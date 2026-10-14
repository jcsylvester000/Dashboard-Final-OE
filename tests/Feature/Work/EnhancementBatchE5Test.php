<?php

namespace Tests\Feature\Work;

use App\Models\Attachment;
use App\Models\Department;
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
 * Enhancement batch E5: named reviewers (E-07), review queue (E-32), review reminders
 * (E-33), new version re-requests review (E-35), checklists (E-36), timesheet nudges (E-39).
 */
class EnhancementBatchE5Test extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $lead;

    private User $designer;

    private User $ana;

    private User $ben;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        $this->travelTo('2026-10-12 09:00:00'); // Monday

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
        $this->task = Task::factory()->create(['workspace_id' => $this->ws->id, 'assignee_id' => $this->designer->id]);
    }

    private function setReviewers(array $users, string $rule = 'all')
    {
        return $this->actingAs($this->designer)->put(route('workspaces.tasks.reviewers', [$this->ws, $this->task]), [
            'user_ids' => array_map(fn (User $u) => $u->id, $users), 'rule' => $rule,
        ]);
    }

    private function toReview(): void
    {
        $this->actingAs($this->designer)->put(route('workspaces.tasks.update', [$this->ws, $this->task]), ['status_id' => TaskStatus::idFor('in-review')])
            ->assertSessionHasNoErrors();
    }

    private function decide(User $user, string $decision, ?string $note = null)
    {
        return $this->actingAs($user)->post(route('workspaces.tasks.review', [$this->ws, $this->task]), ['decision' => $decision, 'note' => $note]);
    }

    public function test_all_named_reviewers_must_approve_and_a_new_round_starts_on_review()
    {
        $this->setReviewers([$this->designer])->assertSessionHasErrors('user_ids'); // assignee can't review
        $this->setReviewers([$this->ana, $this->ben])->assertSessionHasNoErrors();
        $this->toReview();

        $this->decide($this->ana, 'approve')->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data.toast', fn ($t) => str_contains($t['message'], 'Waiting for Ben Cruz'));
        $this->assertSame(TaskStatus::idFor('in-review'), $this->task->fresh()?->status_id);

        // A member can't skip the reviewers by moving it to Done; a lead can.
        $this->actingAs($this->designer)->put(route('workspaces.tasks.update', [$this->ws, $this->task]), ['status_id' => TaskStatus::idFor('done')])
            ->assertSessionHasErrors('status_id');

        $this->decide($this->ben, 'changes', 'Fix the headline')->assertSessionHasNoErrors();
        $this->assertSame(TaskStatus::idFor('in-progress'), $this->task->fresh()?->status_id);

        $this->toReview(); // new round: Ana must approve again
        $this->assertSame(0, $this->task->reviewers()->whereNotNull('decision')->count());
        $this->decide($this->ana, 'approve');
        $this->decide($this->ben, 'approve');
        $this->assertNotNull($this->task->fresh()?->completed_at);
    }

    public function test_any_rule_needs_one_approval_and_outsiders_cannot_review()
    {
        $this->setReviewers([$this->ana, $this->ben], 'any');
        $this->toReview();

        $outsider = User::factory()->withRole('member')->create();
        $this->ws->members()->attach($outsider->id, ['role' => Workspace::ROLE_MEMBER]);
        $this->decide($outsider, 'approve')->assertForbidden();

        $this->decide($this->ben, 'approve')->assertSessionHasNoErrors();
        $this->assertNotNull($this->task->fresh()?->completed_at);
    }

    public function test_review_queue_lists_only_my_pending_decisions()
    {
        $this->setReviewers([$this->ana]);
        $this->toReview();
        $other = Task::factory()->create(['workspace_id' => $this->ws->id, 'status_id' => TaskStatus::idFor('in-review')]);

        $this->actingAs($this->ana)->get(route('reviews.index'))
            ->assertInertia(fn (Assert $page) => $page->component('reviews/Index')->has('items', 1)->where('items.0.id', $this->task->id));
        $this->actingAs($this->ben)->get(route('reviews.index'))->assertInertia(fn (Assert $page) => $page->has('items', 0));
        // The lead reviews tasks without named reviewers.
        $this->actingAs($this->lead)->get(route('reviews.index'))
            ->assertInertia(fn (Assert $page) => $page->has('items', 1)->where('items.0.id', $other->id));
    }

    public function test_overdue_reviews_remind_once_then_escalate_skipping_weekends()
    {
        $this->setReviewers([$this->ana]);
        $this->toReview(); // Monday: Ana gets the "sent for your review" alert
        $base = $this->ana->notifications()->where('kind', 'review')->count();

        $this->travelTo('2026-10-12 16:00:00');
        $this->artisan('work:review-reminders')->assertSuccessful();
        $this->assertSame($base, $this->ana->notifications()->where('kind', 'review')->count(), 'same day: nothing');

        $this->travelTo('2026-10-13 10:00:00'); // 1 working day
        $this->artisan('work:review-reminders');
        $this->artisan('work:review-reminders');
        $this->assertSame($base + 1, $this->ana->notifications()->where('kind', 'review')->count(), 'one reminder only');
        $this->assertSame(0, $this->lead->notifications()->where('kind', 'escalation')->count());

        $this->travelTo('2026-10-14 10:00:00'); // 2 working days
        $this->artisan('work:review-reminders');
        $this->assertSame(1, $this->lead->notifications()->where('kind', 'escalation')->count());
    }

    public function test_a_new_version_answering_changes_sends_the_task_back_to_review()
    {
        $this->actingAs($this->designer)->post(route('attachments.store'), ['attachable_type' => 'task', 'attachable_id' => $this->task->id,
            'file' => UploadedFile::fake()->create('ad.pdf', 10, 'application/pdf')]);
        $v1 = Attachment::query()->firstOrFail();
        $this->toReview();
        $this->actingAs($this->lead)->post(route('attachments.review', $v1), ['decision' => 'changes', 'note' => 'Bigger logo']);
        $this->assertSame(TaskStatus::idFor('in-progress'), $this->task->fresh()?->status_id);

        $this->actingAs($this->designer)->post(route('attachments.store'), ['attachable_type' => 'task', 'attachable_id' => $this->task->id, 'version_of' => $v1->id,
            'file' => UploadedFile::fake()->create('ad.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
        $this->assertSame(TaskStatus::idFor('in-review'), $this->task->fresh()?->status_id);
    }

    public function test_checklist_templates_and_required_items_block_done()
    {
        $this->actingAs($this->lead)->put(route('workspaces.checklists.update', $this->ws), ['templates' => ['story' => "Tests added\nDocs updated\n", 'nope' => 'x']])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->designer)->put(route('workspaces.checklists.update', $this->ws), ['templates' => []])->assertForbidden();

        $this->actingAs($this->designer)->post(route('workspaces.tasks.store', $this->ws), ['title' => 'Cart page', 'type' => 'story'])->assertSessionHasNoErrors();
        $story = Task::query()->where('title', 'Cart page')->firstOrFail();
        $this->assertSame(['Tests added', 'Docs updated'], $story->checklistItems()->pluck('label')->all());

        $this->actingAs($this->designer)->put(route('workspaces.tasks.update', [$this->ws, $story]), ['status_id' => TaskStatus::idFor('done')])
            ->assertSessionHasErrors('status_id');
        foreach ($story->checklistItems()->get() as $item) {
            $this->actingAs($this->designer)->patch(route('workspaces.tasks.checklist.update', [$this->ws, $story, $item]), ['done' => true])->assertSessionHasNoErrors();
        }
        $this->actingAs($this->designer)->put(route('workspaces.tasks.update', [$this->ws, $story]), ['status_id' => TaskStatus::idFor('done')])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->designer)->get(route('workspaces.tasks.board', $this->ws))
            ->assertInertia(fn (Assert $page) => $page->where('tasks', fn ($tasks) => collect($tasks)->firstWhere('id', $story->id)['checklist'] === ['done' => 2, 'total' => 2]));
    }

    public function test_a_final_approval_blocked_by_the_checklist_is_not_recorded()
    {
        $this->setReviewers([$this->ana]);
        $item = $this->task->checklistItems()->create(['label' => 'Numbers match', 'required' => true, 'position' => 0]);
        $this->toReview();

        $this->decide($this->ana, 'approve')->assertSessionHasErrors('status_id');
        $this->assertNull($this->task->reviewers()->firstOrFail()->decision, 'rolled back with the blocked Done');
        $this->assertSame(TaskStatus::idFor('in-review'), $this->task->fresh()?->status_id);

        $this->actingAs($this->designer)->patch(route('workspaces.tasks.checklist.update', [$this->ws, $this->task, $item]), ['done' => true]);
        $this->decide($this->ana, 'approve')->assertSessionHasNoErrors();
        $this->assertNotNull($this->task->fresh()?->completed_at);
    }

    public function test_timesheet_nudges_on_friday_and_monday()
    {
        $dept = Department::query()->where('slug', 'marketing')->firstOrFail();
        $dept->forceFill(['lead_user_id' => $this->lead->id])->save();
        $this->designer->forceFill(['primary_department_id' => $dept->id])->save();
        TimeEntry::create(['user_id' => $this->designer->id, 'workspace_id' => $this->ws->id, 'task_id' => $this->task->id, 'entry_date' => '2026-10-13', 'minutes' => 60, 'is_billable' => true]);

        $this->travelTo('2026-10-16 16:00:00'); // Friday
        $this->artisan('time:nudges', ['--friday' => true])->assertSuccessful();
        $this->assertSame(1, $this->designer->notifications()->where('kind', 'timesheet')->count());

        $this->travelTo('2026-10-19 09:00:00'); // Monday: last week not submitted
        $this->artisan('time:nudges', ['--monday' => true])->assertSuccessful();
        $this->assertSame(2, $this->designer->notifications()->where('kind', 'timesheet')->count());
        $this->assertStringContainsString($this->designer->name, (string) $this->lead->notifications()->where('kind', 'timesheet')->latest()->first()?->data['body']);
    }
}
