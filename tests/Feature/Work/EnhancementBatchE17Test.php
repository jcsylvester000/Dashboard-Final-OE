<?php

namespace Tests\Feature\Work;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Finding;
use App\Models\Holiday;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Enhancement batch E17: PDF text highlights (E-30), findings library (E-31) and
 * workflow templates with review steps, checklists and working-day dates (E-40).
 */
class EnhancementBatchE17Test extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $lead;

    private User $researcher;

    private User $reviewer;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        $this->travelTo('2026-10-15 09:00:00');
        $this->ws = Workspace::factory()->create(['key' => 'RSN', 'name' => 'Resona']);
        $this->lead = User::factory()->withRole('member')->create(['name' => 'Lia Lead']);
        $this->researcher = User::factory()->withRole('member')->create(['name' => 'Rae Research']);
        $this->reviewer = User::factory()->withRole('member')->create(['name' => 'Rex Reviewer']);
        $this->ws->members()->attach([
            $this->lead->id => ['role' => Workspace::ROLE_LEAD],
            $this->researcher->id => ['role' => Workspace::ROLE_MEMBER],
            $this->reviewer->id => ['role' => Workspace::ROLE_MEMBER],
        ]);
        $this->task = Task::factory()->create(['workspace_id' => $this->ws->id, 'assignee_id' => $this->researcher->id, 'number' => 7, 'title' => 'Competitor pricing study']);
    }

    // E-30 -----------------------------------------------------------------

    public function test_text_on_a_pdf_page_can_be_highlighted_with_a_comment()
    {
        $pdf = $this->file('report.pdf', 'application/pdf');

        $this->actingAs($this->researcher)->postJson(route('attachments.annotations.store', $pdf), [
            'body' => 'Key stat for the deck', 'page' => 3, 'quote' => '62% of buyers compare prices on mobile',
            'rects' => [[0.1, 0.2, 0.5, 0.02], [0.1, 0.225, 0.3, 0.02]],
        ])->assertCreated()
            ->assertJsonPath('pins.0.page', 3)
            ->assertJsonPath('pins.0.quote', '62% of buyers compare prices on mobile')
            ->assertJsonPath('pins.0.rects.1', [0.1, 0.225, 0.3, 0.02]);

        // A highlight needs its page and its text; highlights only go on PDFs.
        $this->actingAs($this->researcher)->postJson(route('attachments.annotations.store', $pdf), ['body' => 'x', 'quote' => 'y', 'rects' => [[0, 0, 1, 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->actingAs($this->researcher)->postJson(route('attachments.annotations.store', $pdf), ['body' => 'x', 'page' => 1, 'rects' => [[0, 0, 1, 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('quote');
        $image = $this->file('hero.png', 'image/png');
        $this->actingAs($this->researcher)->postJson(route('attachments.annotations.store', $image), ['body' => 'x', 'page' => 1, 'quote' => 'y', 'rects' => [[0, 0, 1, 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    // E-31 -----------------------------------------------------------------

    public function test_a_highlight_or_comment_becomes_a_finding_once()
    {
        $pdf = $this->file('report.pdf', 'application/pdf');
        $this->actingAs($this->researcher)->postJson(route('attachments.annotations.store', $pdf), [
            'body' => 'Mobile matters', 'page' => 2, 'quote' => 'Most compare on mobile', 'rects' => [[0.1, 0.1, 0.5, 0.02]],
        ])->assertCreated();
        $highlightId = (int) $pdf->annotations()->value('id');

        $this->actingAs($this->researcher)->postJson(route('findings.store'), [
            'source_type' => 'annotation', 'source_id' => $highlightId, 'title' => 'Buyers compare prices on mobile',
            'tags' => ['Pricing Page', 'pricing page', ' Mobile '], 'confidence' => 'high',
        ])->assertCreated();
        $finding = Finding::query()->firstOrFail();
        $this->assertSame([$this->ws->id, $this->task->id, $pdf->id, 'Most compare on mobile', 2, ['pricing-page', 'mobile']], [
            $finding->workspace_id, $finding->task_id, $finding->attachment_id, $finding->quote, $finding->page, $finding->tags,
        ]);
        $this->actingAs($this->researcher)->postJson(route('findings.store'), ['source_type' => 'annotation', 'source_id' => $highlightId, 'title' => 'Again', 'confidence' => 'low'])
            ->assertUnprocessable()->assertJsonValidationErrors('source_id');

        $comment = Comment::query()->create(['workspace_id' => $this->ws->id, 'commentable_type' => $this->task->getMorphClass(), 'commentable_id' => $this->task->id, 'user_id' => $this->researcher->id, 'body' => 'Price anchoring works for the mid tier.']);
        $this->actingAs($this->researcher)->post(route('findings.store'), ['source_type' => 'comment', 'source_id' => $comment->id, 'title' => 'Anchoring works for mid tier', 'confidence' => 'medium'])
            ->assertRedirect();
        $this->assertSame(2, Finding::query()->count());

        $outsider = User::factory()->withRole('member')->create();
        $this->actingAs($outsider)->postJson(route('findings.store'), ['source_type' => 'comment', 'source_id' => $comment->id, 'title' => 'x', 'confidence' => 'low'])->assertForbidden();
    }

    public function test_the_library_searches_filters_and_respects_client_access()
    {
        $this->finding('Buyers compare prices on mobile', ['pricing', 'mobile'], 'high');
        $this->finding('Onboarding drop-off at step 3', ['onboarding'], 'low');
        $other = Workspace::factory()->create();
        Finding::query()->create(['workspace_id' => $other->id, 'title' => 'Secret pricing note', 'tags' => ['pricing'], 'confidence' => 'high', 'source_type' => 'manual']);

        $this->actingAs($this->researcher)->get(route('findings.index', ['q' => 'PRICES']))
            ->assertInertia(fn (Assert $page) => $page->component('findings/Index')
                ->has('findings.data', 1)
                ->where('findings.data.0.title', 'Buyers compare prices on mobile'));
        $this->actingAs($this->researcher)->get(route('findings.index', ['tag' => 'onboarding']))
            ->assertInertia(fn (Assert $page) => $page->has('findings.data', 1)->where('findings.data.0.title', 'Onboarding drop-off at step 3'));
        $this->actingAs($this->researcher)->get(route('findings.index', ['confidence' => 'high']))
            ->assertInertia(fn (Assert $page) => $page->has('findings.data', 1)->where('tags', fn ($t) => collect($t)->contains('pricing')));
    }

    public function test_findings_link_to_tasks_and_only_author_or_lead_edits()
    {
        $finding = $this->finding('Anchoring works', ['pricing'], 'medium');
        $decision = Task::factory()->create(['workspace_id' => $this->ws->id, 'title' => 'Decide pricing tiers']);
        $foreign = Task::factory()->create();

        $this->actingAs($this->reviewer)->post(route('findings.link', $finding), ['task_id' => $decision->id])->assertSessionHasNoErrors();
        $this->actingAs($this->reviewer)->post(route('findings.link', $finding), ['task_id' => $foreign->id])->assertSessionHasErrors('task_id');
        $this->assertSame([$decision->id], $finding->linkedTasks()->pluck('tasks.id')->all());

        $this->actingAs($this->reviewer)->put(route('findings.update', $finding), ['title' => 'Mine now', 'confidence' => 'low'])->assertForbidden();
        $this->actingAs($this->researcher)->put(route('findings.update', $finding), ['title' => 'Anchoring works (mid tier)', 'tags' => ['Pricing'], 'confidence' => 'high'])->assertSessionHasNoErrors();
        $this->assertSame('high', $finding->fresh()?->confidence);

        $this->actingAs($this->reviewer)->delete(route('findings.unlink', [$finding, $decision]))->assertRedirect();
        $this->assertSame(0, $finding->linkedTasks()->count());
        $this->actingAs($this->lead)->delete(route('findings.destroy', $finding))->assertRedirect();
        $this->assertNull(Finding::query()->find($finding->id));
    }

    // E-40 -----------------------------------------------------------------

    public function test_a_template_creates_review_steps_checklists_and_working_day_dates()
    {
        Holiday::query()->create(['date' => '2026-11-02', 'name' => 'All Souls (test)', 'kind' => 'regular']);
        $outsider = User::factory()->withRole('member')->create(['name' => 'Otto Outsider']);
        $template = WorkflowTemplate::query()->create(['name' => 'Research sprint', 'slug' => 'research-sprint', 'is_active' => true, 'working_days' => true]);
        $template->steps()->create(['position' => 0, 'title' => 'Desk research', 'offset_days' => 0, 'depends_on_previous' => false,
            'review_user_ids' => [$this->reviewer->id, $outsider->id], 'review_leads' => true, 'review_mode' => 'any', 'review_sla_days' => 2,
            'checklist' => ['Sources listed', '? Raw notes attached']]);
        $template->steps()->create(['position' => 1, 'title' => 'Synthesis', 'offset_days' => 3, 'depends_on_previous' => true]);
        $template->steps()->create(['position' => 2, 'title' => 'Readout', 'offset_days' => 5, 'depends_on_previous' => true]);

        // Saturday start: rolls to Monday, which is a holiday, so Tuesday.
        $this->actingAs($this->researcher)->post(route('workspaces.templates.apply', [$this->ws, $template]), ['start_on' => '2026-10-31'])
            ->assertRedirect();

        $tasks = Task::query()->where('workspace_id', $this->ws->id)->whereIn('title', ['Desk research', 'Synthesis', 'Readout'])->orderBy('id')->get();
        $this->assertSame(['2026-11-03', '2026-11-06', '2026-11-10'], $tasks->map(fn (Task $t) => $t->due_on?->toDateString())->all());

        $first = $tasks[0];
        $this->assertEqualsCanonicalizing([$this->reviewer->id, $this->lead->id], $first->reviewers()->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['any', 2], [$first->fresh()?->review_rule, $first->fresh()?->review_sla_days]);
        $items = TaskChecklistItem::query()->where('task_id', $first->id)->where('label', '!=', '')->orderBy('position')->get();
        $this->assertTrue($items->firstWhere('label', 'Sources listed')?->required);
        $this->assertFalse($items->firstWhere('label', 'Raw notes attached')?->required);
    }

    public function test_calendar_day_templates_keep_their_old_dates()
    {
        $template = WorkflowTemplate::query()->create(['name' => 'Calendar days', 'slug' => 'calendar-days', 'is_active' => true, 'working_days' => false]);
        $template->steps()->create(['position' => 0, 'title' => 'One', 'offset_days' => 5, 'depends_on_previous' => false]);

        $this->actingAs($this->researcher)->post(route('workspaces.templates.apply', [$this->ws, $template]), ['start_on' => '2026-11-02'])->assertRedirect();

        $this->assertSame('2026-11-07', Task::query()->where('title', 'One')->firstOrFail()->due_on?->toDateString());
    }

    // helpers -----------------------------------------------------------------

    private function file(string $name, string $mime): Attachment
    {
        Storage::disk('local')->put('attachments/'.$name, 'x');

        return Attachment::create([
            'workspace_id' => $this->ws->id, 'attachable_type' => $this->task->getMorphClass(), 'attachable_id' => $this->task->id,
            'uploaded_by' => $this->researcher->id, 'disk' => 'local', 'key' => 'attachments/'.$name, 'original_name' => $name, 'mime' => $mime, 'size' => 1,
        ]);
    }

    /**
     * @param  list<string>  $tags
     */
    private function finding(string $title, array $tags, string $confidence): Finding
    {
        return Finding::query()->create(['workspace_id' => $this->ws->id, 'title' => $title, 'tags' => $tags, 'confidence' => $confidence, 'source_type' => 'manual', 'created_by' => $this->researcher->id]);
    }
}
