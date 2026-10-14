<?php

namespace Tests\Feature\Work;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
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
 * Enhancement batch E4: file versions (E-02), one decision per version + Done gate (E-06),
 * pasted screenshots (E-10), Markdown/code (E-20), bug reports (E-21).
 */
class EnhancementBatchE4Test extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $designer;

    private User $lead;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');

        $this->ws = Workspace::factory()->create();
        $this->designer = User::factory()->withRole('member')->create(['name' => 'Bea Villanueva']);
        $this->lead = User::factory()->withRole('member')->create(['name' => 'Jasmine Torres']);
        $this->ws->members()->attach([$this->designer->id => ['role' => Workspace::ROLE_MEMBER], $this->lead->id => ['role' => Workspace::ROLE_LEAD]]);
        $this->task = Task::factory()->create(['workspace_id' => $this->ws->id, 'assignee_id' => $this->designer->id]);
    }

    private function upload(string $name, ?int $versionOf = null)
    {
        return $this->actingAs($this->designer)->post(route('attachments.store'), array_filter([
            'attachable_type' => 'task', 'attachable_id' => $this->task->id, 'version_of' => $versionOf,
            'file' => UploadedFile::fake()->create($name, 20, 'application/pdf'),
        ], fn ($v) => $v !== null));
    }

    private function filesProp(): array
    {
        $rows = [];
        $this->actingAs($this->lead)->get(route('workspaces.tasks.show', [$this->ws, $this->task]))
            // A full closure: an arrow fn would capture $rows by value (W22 trap).
            ->assertInertia(function (Assert $page) use (&$rows) {
                $page->loadDeferredProps('panels', function (Assert $reload) use (&$rows) {
                    $rows = $reload->toArray()['props']['attachments'];
                });
            });

        return $rows;
    }

    public function test_new_versions_stack_under_one_file_and_deleting_the_latest_restores_the_previous()
    {
        $this->upload('Ad v1.pdf')->assertSessionHasNoErrors();
        $v1 = Attachment::query()->firstOrFail();
        $this->upload('Ad v2.pdf', $v1->id)->assertSessionHasNoErrors();
        $v2 = Attachment::query()->latest('id')->firstOrFail();

        $this->assertSame(2, $v2->version);
        $this->assertSame($v1->id, $v2->version_root_id);
        $this->assertFalse((bool) $v1->fresh()?->is_latest);

        $rows = $this->filesProp();
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['version']);
        $this->assertSame(1, $rows[0]['versions'][0]['version']);

        // A version of a file on another task is refused.
        $other = Task::factory()->create(['workspace_id' => $this->ws->id]);
        $this->actingAs($this->designer)->post(route('attachments.store'), ['attachable_type' => 'task', 'attachable_id' => $other->id, 'version_of' => $v1->id,
            'file' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')])->assertSessionHasErrors('version_of');

        $this->actingAs($this->designer)->delete(route('attachments.destroy', $v2))->assertRedirect();
        $this->assertTrue((bool) $v1->fresh()?->is_latest);
    }

    public function test_decisions_are_per_version_and_changes_send_the_task_back()
    {
        $this->task->update(['status_id' => TaskStatus::idFor('in-review')]);
        $this->upload('Ad v1.pdf');
        $v1 = Attachment::query()->firstOrFail();
        $this->upload('Ad v2.pdf', $v1->id);
        $v2 = Attachment::query()->latest('id')->firstOrFail();

        $this->actingAs($this->designer)->post(route('attachments.review', $v2), ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($this->lead)->post(route('attachments.review', $v1), ['decision' => 'approve'])->assertSessionHasErrors('decision');
        $this->actingAs($this->lead)->post(route('attachments.review', $v2), ['decision' => 'changes'])->assertSessionHasErrors('note');

        $this->actingAs($this->lead)->post(route('attachments.review', $v2), ['decision' => 'changes', 'note' => 'Logo bigger'])->assertSessionHasNoErrors();
        $this->assertSame('changes', $v2->fresh()?->review_status);
        $this->assertSame(TaskStatus::idFor('in-progress'), $this->task->fresh()?->status_id);
        $this->assertStringContainsString('Changes requested on Ad v2.pdf v2: Logo bigger', (string) Comment::query()->latest('id')->value('body'));

        $this->actingAs($this->lead)->post(route('attachments.review', $v2), ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertNotNull($v2->fresh()?->approved_at);
        $this->assertNull($v1->fresh()?->approved_at, 'approving v2 does not approve v1');
    }

    public function test_review_required_projects_need_every_latest_version_approved_before_done()
    {
        $project = Project::factory()->create(['workspace_id' => $this->ws->id, 'review_required' => true]);
        $this->task->update(['project_id' => $project->id, 'status_id' => TaskStatus::idFor('in-review')]);
        $this->upload('Ad.pdf');
        $file = Attachment::query()->firstOrFail();

        $this->actingAs($this->lead)->post(route('workspaces.tasks.review', [$this->ws, $this->task]), ['decision' => 'approve'])
            ->assertSessionHasErrors('status_id');
        $this->actingAs($this->lead)->post(route('attachments.review', $file), ['decision' => 'approve']);
        $this->actingAs($this->lead)->post(route('workspaces.tasks.review', [$this->ws, $this->task]), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($this->task->fresh()?->completed_at);
    }

    public function test_pasted_screenshots_upload_as_json_and_render_inside_markdown_comments()
    {
        $res = $this->actingAs($this->designer)->postJson(route('attachments.store'), [
            'attachable_type' => 'task', 'attachable_id' => $this->task->id,
            'file' => UploadedFile::fake()->create('screenshot.pdf', 10, 'application/pdf'),
        ])->assertOk();
        $token = (string) $res->json('token');
        $this->assertMatchesRegularExpression('/^\[\[file:\d+\]\]$/', $token);

        $body = "Steps:\n\n```php\necho '<b>hi</b>';\n```\n\n<script>alert(1)</script> [bad](javascript:alert(1)) @[{$this->lead->name}](user:{$this->lead->id})\n\n{$token}";
        $this->actingAs($this->designer)->post(route('workspaces.tasks.comments.store', [$this->ws, $this->task]), ['body' => $body])->assertSessionHasNoErrors();
        $this->task->update(['description' => '# Brief'."\n\n- one\n- two"]);

        $this->actingAs($this->lead)->get(route('workspaces.tasks.show', [$this->ws, $this->task]))
            ->assertInertia(function (Assert $page) {
                $props = $page->toArray()['props'];
                $html = $props['task']['comments'][0]['body_html'];
                $this->assertStringContainsString('<pre><code class="language-php">', $html);
                $this->assertStringContainsString('&lt;script&gt;', $html);
                $this->assertStringNotContainsString('href="javascript:', $html); // left as plain text, never a link
                $this->assertStringContainsString('>@'.$this->lead->name.'</span>', $html);
                $this->assertStringContainsString('class="oe-file"', $html);
                $this->assertStringContainsString('<h1>Brief</h1>', $props['task']['description_html']);
                $this->assertStringContainsString('<li>one</li>', $props['task']['description_html']);
            });
    }

    public function test_bug_tasks_take_a_structured_report_and_are_flagged_until_they_have_one()
    {
        $this->task->delete();
        $bug = Task::factory()->create(['workspace_id' => $this->ws->id, 'type' => 'bug']);
        $this->actingAs($this->lead)->get(route('workspaces.tasks.board', $this->ws))
            ->assertInertia(fn (Assert $page) => $page->where('tasks.0.bug_report_missing', true));

        $this->actingAs($this->designer)->put(route('workspaces.tasks.update', [$this->ws, $bug]), ['bug_report' => ['steps' => '1. Open cart']])
            ->assertSessionHasErrors(['bug_report.expected', 'bug_report.actual']);
        $this->actingAs($this->designer)->put(route('workspaces.tasks.update', [$this->ws, $bug]), ['bug_report' => [
            'steps' => '1. Open cart', 'expected' => 'Total shows', 'actual' => '500 error', 'environment' => 'Chrome 141', 'severity' => 'high',
        ]])->assertSessionHasNoErrors();

        $this->assertSame('500 error', $bug->fresh()?->bug_report['actual'] ?? null);
        $this->actingAs($this->lead)->get(route('workspaces.tasks.board', $this->ws))
            ->assertInertia(fn (Assert $page) => $page->where('tasks.0.bug_report_missing', false));
    }
}
