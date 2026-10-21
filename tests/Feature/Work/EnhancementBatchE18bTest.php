<?php

namespace Tests\Feature\Work;

use App\Domain\Documents\DocumentMerger;
use App\Domain\Documents\ReportPacks;
use App\Jobs\GenerateReportPack;
use App\Models\Attachment;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Department;
use App\Models\DocumentTemplate;
use App\Models\Form;
use App\Models\InboxNotification;
use App\Models\Label;
use App\Models\Project;
use App\Models\ReportPack;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Enhancement batch E18b: automation rules (E-37), report packs (E-51), document
 * templates (E-52) and the template gallery (E-55).
 */
class EnhancementBatchE18bTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $lead;

    private User $member;

    private Task $task;

    private Label $urgent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        $this->travelTo('2026-10-15 09:00:00'); // a Thursday
        $this->ws = Workspace::factory()->create(['key' => 'BRW', 'name' => 'Brewhouse']);
        $this->lead = User::factory()->withRole('member')->create(['name' => 'Lia Lead']);
        $this->member = User::factory()->withRole('member')->create(['name' => 'Max Member']);
        $this->ws->members()->attach([
            $this->lead->id => ['role' => Workspace::ROLE_LEAD],
            $this->member->id => ['role' => Workspace::ROLE_MEMBER],
        ]);
        $this->task = Task::factory()->create(['workspace_id' => $this->ws->id, 'number' => 5, 'title' => 'Landing page', 'status_id' => TaskStatus::idFor('todo')]);
        $this->urgent = Label::query()->create(['workspace_id' => $this->ws->id, 'name' => 'Urgent', 'color' => 'rose']);
    }

    /**
     * @param  list<array{type: string, value: string|null}>  $actions
     * @param  array<string, mixed>  $conditions
     */
    private function rule(string $trigger, ?string $value, array $actions, array $conditions = []): AutomationRule
    {
        return AutomationRule::query()->create(['workspace_id' => $this->ws->id, 'name' => 'Rule '.$trigger, 'trigger' => $trigger, 'trigger_value' => $value,
            'conditions' => $conditions, 'actions' => $actions, 'enabled' => true, 'created_by' => $this->lead->id]);
    }

    // E-37 -----------------------------------------------------------------

    public function test_a_label_rule_fires_once_and_is_logged()
    {
        $rule = $this->rule('label_added', (string) $this->urgent->id, [['type' => 'notify', 'value' => 'leads']]);

        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->ws, $this->task]), ['label_ids' => [$this->urgent->id]])->assertSessionHasNoErrors();
        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->ws, $this->task]), ['label_ids' => [$this->urgent->id]])->assertSessionHasNoErrors(); // no new label

        $this->assertSame(1, AutomationRun::query()->where('automation_rule_id', $rule->id)->where('outcome', 'done')->count());
        $this->assertSame(1, $rule->fresh()?->runs);
        $this->assertSame(1, InboxNotification::query()->where('notifiable_id', $this->lead->id)->where('kind', 'status')->count());
    }

    public function test_rules_that_set_each_other_off_stop_at_depth_three()
    {
        $this->rule('status_changed', 'in-progress', [['type' => 'set_status', 'value' => 'in-review']]);
        $this->rule('status_changed', 'in-review', [['type' => 'set_status', 'value' => 'in-progress']]);

        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->ws, $this->task]), ['status_id' => TaskStatus::idFor('in-progress')])->assertSessionHasNoErrors();

        $this->assertSame(1, AutomationRun::query()->where('outcome', 'loop')->count());
        $this->assertSame(3, AutomationRun::query()->where('outcome', 'done')->count());
    }

    public function test_conditions_filter_and_actions_assign_and_create_subtasks_on_upload()
    {
        $design = Department::query()->where('slug', 'marketing')->firstOrFail();
        $this->rule('file_uploaded', null, [['type' => 'assign', 'value' => (string) $this->member->id], ['type' => 'create_subtask', 'value' => 'Check the upload']]);
        $this->rule('file_uploaded', null, [['type' => 'set_status', 'value' => 'blocked']], ['department_id' => $design->id]); // task has no department: skipped

        $this->actingAs($this->member)->post(route('attachments.store'), [
            'attachable_type' => 'task', 'attachable_id' => $this->task->id, 'file' => UploadedFile::fake()->createWithContent('brief.pdf', '%PDF-1.4 x'),
        ])->assertSessionHasNoErrors();

        $task = $this->task->fresh();
        $this->assertSame($this->member->id, $task?->assignee_id);
        $this->assertSame(TaskStatus::idFor('todo'), $task?->status_id);
        $this->assertTrue(Task::query()->where('parent_id', $this->task->id)->where('title', 'Check the upload')->exists());
    }

    public function test_a_blocked_action_is_logged_as_failed()
    {
        TaskChecklistItem::query()->create(['task_id' => $this->task->id, 'label' => 'QA', 'required' => true, 'position' => 1]);
        $rule = $this->rule('label_added', (string) $this->urgent->id, [['type' => 'set_status', 'value' => 'done']]);

        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->ws, $this->task]), ['label_ids' => [$this->urgent->id]])->assertSessionHasNoErrors();

        $run = AutomationRun::query()->where('automation_rule_id', $rule->id)->firstOrFail();
        $this->assertSame('failed', $run->outcome);
        $this->assertStringContainsString('checklist', (string) $run->detail);
    }

    public function test_only_leads_manage_rules()
    {
        $body = ['name' => 'Urgent → leads', 'trigger' => 'label_added', 'trigger_value' => (string) $this->urgent->id, 'actions' => [['type' => 'notify', 'value' => 'leads']]];
        $this->actingAs($this->member)->post(route('workspaces.automations.store', $this->ws), $body)->assertForbidden();
        $this->actingAs($this->lead)->post(route('workspaces.automations.store', $this->ws), [...$body, 'trigger' => 'status_changed', 'trigger_value' => 'nope'])->assertSessionHasErrors('trigger_value');
        $this->actingAs($this->lead)->post(route('workspaces.automations.store', $this->ws), $body)->assertSessionHasNoErrors();
        $rule = AutomationRule::query()->firstOrFail();

        $this->actingAs($this->lead)->put(route('workspaces.automations.update', [$this->ws, $rule]), ['enabled' => false])->assertRedirect();
        $this->assertFalse($rule->fresh()?->enabled);
        $this->actingAs($this->lead)->get(route('workspaces.edit', $this->ws))
            ->assertInertia(fn (Assert $page) => $page->where('automations.0.name', 'Urgent → leads'));
    }

    // E-51 -----------------------------------------------------------------

    public function test_next_run_dates()
    {
        $thu = CarbonImmutable::parse('2026-10-15');
        $this->assertSame('2026-10-19', ReportPacks::nextRun('weekly', 1, $thu)->toDateString()); // next Monday
        $this->assertSame('2026-11-01', ReportPacks::nextRun('monthly', 1, $thu)->toDateString());
        $this->assertSame('2026-10-20', ReportPacks::nextRun('monthly', 20, $thu)->toDateString());
    }

    public function test_a_pack_is_made_saved_to_client_files_and_announced()
    {
        $this->actingAs($this->member)->post(route('workspaces.report-packs.store', $this->ws), ['name' => 'x', 'sections' => ['hours'], 'frequency' => 'weekly', 'day' => 1])->assertForbidden();
        $this->actingAs($this->lead)->post(route('workspaces.report-packs.store', $this->ws), [
            'name' => 'Monthly report', 'sections' => ['hours', 'completed', 'open', 'overdue', 'estimates'], 'frequency' => 'monthly', 'day' => 1,
            'recipient_ids' => [$this->member->id],
        ])->assertSessionHasNoErrors();
        $pack = ReportPack::query()->firstOrFail();
        $this->assertSame('2026-11-01', $pack->next_run_on->toDateString());

        $this->task->forceFill(['completed_at' => '2026-10-01 10:00:00', 'status_id' => TaskStatus::idFor('done'), 'estimate_minutes' => 120])->save();
        $this->actingAs($this->lead)->post(route('workspaces.report-packs.run', [$this->ws, $pack]))->assertRedirect();

        $file = Attachment::query()->where('attachable_type', $this->ws->getMorphClass())->where('attachable_id', $this->ws->id)->firstOrFail();
        $this->assertSame('application/pdf', $file->mime);
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('local')->get($file->key));
        $this->assertSame(1, $pack->fresh()?->runs);
        $this->assertSame(1, InboxNotification::query()->where('notifiable_id', $this->member->id)->where('kind', 'status')->count());

        $data = app(ReportPacks::class)->data($pack, $this->ws, CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-10-14'));
        $this->assertSame('BRW-5', $data['completed'][0]['key']);
        $this->assertSame(120, $data['completed'][0]['estimate']);
    }

    public function test_due_packs_are_queued_daily_and_disabled_ones_skipped()
    {
        Queue::fake();
        $due = ReportPack::query()->create(['workspace_id' => $this->ws->id, 'name' => 'Weekly', 'sections' => ['open'], 'frequency' => 'weekly', 'day' => 4, 'recipient_ids' => [], 'enabled' => true, 'next_run_on' => '2026-10-15']);
        ReportPack::query()->create(['workspace_id' => $this->ws->id, 'name' => 'Off', 'sections' => ['open'], 'frequency' => 'weekly', 'day' => 4, 'recipient_ids' => [], 'enabled' => false, 'next_run_on' => '2026-10-15']);

        $this->artisan('reports:packs')->assertSuccessful();

        Queue::assertPushed(GenerateReportPack::class, 1);
        Queue::assertPushed(GenerateReportPack::class, fn (GenerateReportPack $job) => $job->packId === $due->id);
    }

    // E-52 -----------------------------------------------------------------

    public function test_a_document_fills_its_fields_and_is_saved_to_the_project()
    {
        $project = Project::factory()->create(['workspace_id' => $this->ws->id, 'name' => 'Website *relaunch*', 'due_on' => '2026-12-01']);
        $this->task->forceFill(['project_id' => $project->id])->save();
        $template = DocumentTemplate::query()->create(['name' => 'Creative brief', 'kind' => 'brief', 'body' => "# Brief for {{client.name}}\n\nProject {{project.name}}, due {{project.due_on}}.\n\n{{tasks_table}}\n\n{{nonsense}}", 'version' => 1]);

        $merged = app(DocumentMerger::class)->merge($template->body, $this->ws, $project, $this->lead);
        $this->assertStringContainsString('# Brief for Brewhouse', $merged['markdown']);
        $this->assertStringContainsString('Website \*relaunch\*', $merged['markdown']); // values are text, not Markdown
        $this->assertStringContainsString('December 1, 2026', $merged['markdown']);
        $this->assertStringContainsString('| BRW-5 | Landing page |', $merged['markdown']);
        $this->assertSame(['nonsense'], $merged['unknown']);

        $this->actingAs($this->member)->post(route('workspaces.projects.documents.store', [$this->ws, $project]), ['template_id' => $template->id])->assertRedirect();
        $pdf = Attachment::query()->where('attachable_type', $project->getMorphClass())->where('attachable_id', $project->id)->firstOrFail();
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('local')->get($pdf->key));
        $this->assertSame(1, $template->fresh()?->uses);

        $outsider = User::factory()->withRole('member')->create();
        $this->actingAs($outsider)->post(route('workspaces.projects.documents.store', [$this->ws, $project]), ['template_id' => $template->id])->assertForbidden();
    }

    // E-55 -----------------------------------------------------------------

    public function test_admins_version_document_templates_and_duplicate_workflows()
    {
        $admin = User::factory()->withRole('super-admin')->create();
        $this->actingAs($this->lead)->post(route('gallery.documents.store'), ['name' => 'SOW', 'kind' => 'sow', 'body' => 'v1'])->assertForbidden();
        $this->actingAs($admin)->post(route('gallery.documents.store'), ['name' => 'SOW', 'kind' => 'sow', 'body' => 'v1'])->assertRedirect();
        $doc = DocumentTemplate::query()->firstOrFail();
        $this->actingAs($admin)->put(route('gallery.documents.update', $doc), ['name' => 'SOW', 'kind' => 'sow', 'body' => 'v2 text'])->assertRedirect();
        $this->assertSame([2, 'v1'], [$doc->fresh()?->version, $doc->versions()->value('body')]);

        $flow = WorkflowTemplate::query()->create(['name' => 'Launch', 'slug' => 'launch', 'is_active' => true]);
        $flow->steps()->create(['position' => 0, 'title' => 'Plan', 'offset_days' => 0, 'depends_on_previous' => false, 'checklist' => ['Scope agreed']]);
        $this->actingAs($admin)->post(route('gallery.workflows.duplicate', $flow))->assertRedirect();
        $copy = WorkflowTemplate::query()->where('name', 'Launch (copy)')->firstOrFail();
        $this->assertSame(['Scope agreed'], $copy->steps()->firstOrFail()->checklist);

        $this->actingAs($this->member)->post(route('workspaces.templates.apply', [$this->ws, $flow]), ['start_on' => '2026-10-19'])->assertRedirect();
        $this->assertSame(1, $flow->fresh()?->uses);
    }

    public function test_leads_copy_forms_and_packs_to_another_client_they_lead()
    {
        $other = Workspace::factory()->create(['name' => 'Acme']);
        $other->members()->attach($this->lead->id, ['role' => Workspace::ROLE_LEAD]);
        $third = Workspace::factory()->create(['name' => 'Not mine']);
        $form = Form::query()->create(['workspace_id' => $this->ws->id, 'name' => 'Intake', 'draft' => ['sections' => []],
            'output' => ['type' => 'task', 'title' => 'x', 'map' => ['custom.budget' => 'budget'], 'notify_user_ids' => [$this->lead->id]], 'created_by' => $this->lead->id]);
        $pack = ReportPack::query()->create(['workspace_id' => $this->ws->id, 'name' => 'Weekly', 'sections' => ['open'], 'frequency' => 'weekly', 'day' => 1, 'recipient_ids' => [$this->lead->id], 'enabled' => true, 'next_run_on' => '2026-10-19']);

        $this->actingAs($this->lead)->get(route('gallery.index'))
            ->assertInertia(fn (Assert $page) => $page->component('templates/Gallery')->has('forms', 1)->has('packs', 1)->has('clients', 2));

        $this->actingAs($this->lead)->post(route('gallery.forms.duplicate', $form), ['workspace_id' => $third->id])->assertForbidden();
        $this->actingAs($this->lead)->post(route('gallery.forms.duplicate', $form), ['workspace_id' => $other->id])->assertRedirect();
        $copy = Form::query()->where('workspace_id', $other->id)->firstOrFail();
        $this->assertSame([[], [], null], [$copy->output['map'], $copy->output['notify_user_ids'], $copy->current_version_id]);

        $this->actingAs($this->lead)->post(route('gallery.packs.duplicate', $pack), ['workspace_id' => $other->id])->assertRedirect();
        $packCopy = ReportPack::query()->where('workspace_id', $other->id)->firstOrFail();
        $this->assertFalse($packCopy->enabled);
        $this->assertSame([], $packCopy->recipient_ids);
        $this->actingAs($this->member)->post(route('gallery.packs.duplicate', $pack), ['workspace_id' => $this->ws->id])->assertForbidden();
    }
}
