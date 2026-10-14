<?php

namespace Tests\Feature\Work;

use App\Domain\Billing\WorkReport;
use App\Domain\Files\AttachmentService;
use App\Domain\Settings\CompanyProfile;
use App\Domain\Work\LinkPreview;
use App\Models\Attachment;
use App\Models\Department;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\TaskCitation;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Enhancement batch E14b: log viewer (E-23), sprint release notes (E-27),
 * link capture citations (E-29) and charts in PDFs (E-50).
 */
class EnhancementBatchE14bTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $lead;

    private User $member;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        $this->travelTo('2026-10-15 09:00:00');
        $this->ws = Workspace::factory()->create(['key' => 'RSN']);
        $this->lead = User::factory()->withRole('member')->create();
        $this->member = User::factory()->withRole('member')->create();
        $this->ws->members()->attach([
            $this->lead->id => ['role' => Workspace::ROLE_LEAD],
            $this->member->id => ['role' => Workspace::ROLE_MEMBER],
        ]);
        $this->task = Task::factory()->create(['workspace_id' => $this->ws->id, 'assignee_id' => $this->member->id, 'title' => 'Competitor pricing scan']);
        // Every host resolves to a public address unless a test says otherwise.
        LinkPreview::$resolver = fn (string $host): array => ['93.184.216.34'];
    }

    protected function tearDown(): void
    {
        LinkPreview::$resolver = null;
        parent::tearDown();
    }

    // E-29 -----------------------------------------------------------------

    public function test_a_pasted_link_becomes_a_source_with_title_and_site()
    {
        Http::fake(['example.com/*' => Http::response('<html><head><title> Pricing &amp; plans </title><meta property="og:site_name" content="Example Co"></head></html>')]);

        $this->actingAs($this->member)->post(route('workspaces.tasks.citations.store', [$this->ws, $this->task]), ['url' => 'https://www.example.com/pricing'])
            ->assertSessionHasNoErrors();

        $c = TaskCitation::query()->firstOrFail();
        $this->assertSame(['Pricing & plans', 'Example Co', $this->task->id], [$c->title, $c->site, $c->task_id]);
        $this->assertSame('2026-10-15', $c->accessed_at->toDateString());
        $this->assertStringStartsWith('https://web.archive.org/', $c->archiveUrl());

        $this->actingAs($this->member)->get(route('workspaces.tasks.show', [$this->ws, $this->task]))
            ->assertInertia(fn ($page) => $page->where('task.citations.0.title', 'Pricing & plans'));
    }

    public function test_private_addresses_are_never_fetched_and_bad_urls_are_refused()
    {
        Http::fake();
        LinkPreview::$resolver = fn (string $host): array => $host === 'intranet.local' ? ['10.0.0.5'] : ['93.184.216.34'];

        $this->actingAs($this->member)->post(route('workspaces.tasks.citations.store', [$this->ws, $this->task]), ['url' => 'http://intranet.local/admin'])
            ->assertSessionHasNoErrors();
        Http::assertNothingSent();
        $this->assertNull(TaskCitation::query()->firstOrFail()->title); // saved, but no title

        $this->actingAs($this->member)->post(route('workspaces.tasks.citations.store', [$this->ws, $this->task]), ['url' => 'file:///etc/passwd'])
            ->assertSessionHasErrors('url');
        $this->actingAs($this->member)->post(route('workspaces.tasks.citations.store', [$this->ws, $this->task]), ['url' => 'http://127.0.0.1/'])
            ->assertSessionHasNoErrors();
        Http::assertNothingSent();
    }

    public function test_redirects_into_a_private_network_are_stopped()
    {
        LinkPreview::$resolver = fn (string $host): array => $host === 'evil.example' ? ['93.184.216.34'] : ['192.168.1.1'];
        Http::fake(['evil.example/*' => Http::response('', 302, ['Location' => 'http://router.lan/'])]);

        $meta = app(LinkPreview::class)->fetch('https://evil.example/go');

        $this->assertSame(['title' => null, 'site' => 'evil.example'], $meta);
        Http::assertSentCount(1);
    }

    public function test_outsiders_cannot_add_or_remove_sources()
    {
        Http::fake();
        $outsider = User::factory()->withRole('member')->create();
        $this->actingAs($outsider)->post(route('workspaces.tasks.citations.store', [$this->ws, $this->task]), ['url' => 'https://example.com'])
            ->assertForbidden();

        $c = TaskCitation::query()->create(['task_id' => $this->task->id, 'url' => 'https://example.com', 'accessed_at' => now(), 'created_by' => $this->member->id]);
        $other = Task::factory()->create(['workspace_id' => $this->ws->id]);
        $this->actingAs($this->member)->delete(route('workspaces.tasks.citations.destroy', [$this->ws, $other, $c]))->assertNotFound();
        $this->actingAs($this->member)->delete(route('workspaces.tasks.citations.destroy', [$this->ws, $this->task, $c]))->assertRedirect();
        $this->assertSame(0, TaskCitation::query()->count());
    }

    // E-29 + E-50: work report ------------------------------------------------

    public function test_work_report_lists_sources_and_charts_hours_by_department()
    {
        $research = Department::query()->where('slug', 'research')->firstOrFail();
        $seo = Department::query()->where('slug', 'seo')->firstOrFail();
        $this->task->forceFill(['department_id' => $research->id])->save();
        $seoTask = Task::factory()->create(['workspace_id' => $this->ws->id, 'department_id' => $seo->id, 'title' => 'Fix titles']);
        TimeEntry::create(['user_id' => $this->member->id, 'workspace_id' => $this->ws->id, 'task_id' => $this->task->id, 'entry_date' => '2026-10-10', 'minutes' => 120, 'is_billable' => true]);
        TimeEntry::create(['user_id' => $this->member->id, 'workspace_id' => $this->ws->id, 'task_id' => $seoTask->id, 'entry_date' => '2026-10-11', 'minutes' => 60, 'is_billable' => true]);
        TaskCitation::query()->create(['task_id' => $this->task->id, 'url' => 'https://example.com/report', 'title' => 'Market report 2026', 'site' => 'example.com', 'accessed_at' => now(), 'created_by' => $this->member->id]);

        $report = app(WorkReport::class)->forPeriod($this->ws->id, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

        $this->assertCount(1, $report['sources']);
        $this->assertSame(['Competitor pricing scan', 'Market report 2026', '2026-10-15'], [$report['sources'][0]['task'], $report['sources'][0]['title'], $report['sources'][0]['accessed_on']]);
        $this->assertCount(2, $report['departments']);

        $html = view('pdf.work-report', [
            'meta' => ['title' => 'Work report', 'client' => 'Client', 'period' => 'Oct 2026', 'ref' => 'Test'],
            'company' => app(CompanyProfile::class)->get(),
            'logo' => null,
            'report' => $report,
            'draft' => false,
        ])->render();
        $this->assertStringContainsString('Hours by department</th>', $html);
        $this->assertStringContainsString('width: 100%', $html); // the busiest department fills the bar
        $this->assertStringContainsString('width: 50%', $html);
        $this->assertStringContainsString('Sources', $html);
        $this->assertStringContainsString('Market report 2026', $html);
    }

    // E-27 -----------------------------------------------------------------

    public function test_closing_a_sprint_drafts_grouped_release_notes()
    {
        $sprint = $this->finishedSprint();

        $notes = (string) $sprint->release_notes;
        $this->assertStringContainsString('# Sprint 9 release notes', $notes);
        $this->assertStringContainsString("## New and improved\n- GCash checkout (RSN-", $notes);
        $this->assertStringContainsString("## Fixed\n- Cart total rounding (RSN-", $notes);
        $this->assertStringNotContainsString('Unfinished work', $notes);

        $this->actingAs($this->member)->get(route('workspaces.sprints.release-notes', [$this->ws, $sprint]))
            ->assertInertia(fn ($page) => $page->component('sprints/ReleaseNotes')->where('canEdit', false));
    }

    public function test_leads_edit_share_and_revoke_release_notes()
    {
        $sprint = $this->finishedSprint();

        $this->actingAs($this->member)->put(route('workspaces.sprints.release-notes.update', [$this->ws, $sprint]), ['notes' => 'x'])->assertForbidden();
        $this->actingAs($this->lead)->put(route('workspaces.sprints.release-notes.update', [$this->ws, $sprint]), ['notes' => "# Sprint 9\n\n<script>alert(1)</script> Faster checkout"])
            ->assertSessionHasNoErrors();

        $pdf = $this->actingAs($this->member)->get(route('workspaces.sprints.release-notes.pdf', [$this->ws, $sprint]))->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());

        $this->actingAs($this->lead)->post(route('workspaces.sprints.release-notes.share', [$this->ws, $sprint]))->assertRedirect();
        $token = (string) $sprint->fresh()?->release_notes_token;
        $this->assertSame(40, strlen($token));

        auth()->logout();
        $page = $this->get(route('release-notes.shared', $token))->assertOk()
            ->assertSee('Faster checkout')->assertDontSee('<script>alert(1)</script>', false)
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('noindex', (string) $page->headers->get('X-Robots-Tag'));

        $this->actingAs($this->lead)->delete(route('workspaces.sprints.release-notes.unshare', [$this->ws, $sprint]))->assertRedirect();
        auth()->logout();
        $this->get(route('release-notes.shared', $token))->assertNotFound();
        $this->get(route('release-notes.shared', 'short'))->assertNotFound();
    }

    public function test_redraft_rebuilds_from_done_tasks()
    {
        $sprint = $this->finishedSprint();
        $this->actingAs($this->lead)->put(route('workspaces.sprints.release-notes.update', [$this->ws, $sprint]), ['notes' => 'Hand-written'])->assertSessionHasNoErrors();

        $this->actingAs($this->lead)->post(route('workspaces.sprints.release-notes.redraft', [$this->ws, $sprint]))->assertRedirect();

        $this->assertStringContainsString('GCash checkout', (string) $sprint->fresh()?->release_notes);
    }

    // E-23 -----------------------------------------------------------------

    public function test_logs_upload_and_open_as_plain_text_for_members_only()
    {
        $this->actingAs($this->member)->post(route('attachments.store'), [
            'attachable_type' => 'task', 'attachable_id' => $this->task->id,
            'file' => UploadedFile::fake()->createWithContent('worker.log', "INFO start\nERROR boom <b>x</b>\n"),
        ])->assertSessionHasNoErrors();
        $file = Attachment::query()->firstOrFail();
        $this->assertTrue($file->isText());

        $this->actingAs($this->member)->get(route('workspaces.tasks.show', [$this->ws, $this->task]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('panels', fn (Assert $reload) => $reload
                ->where('attachments.0.text_url', fn ($u) => is_string($u) && str_contains($u, 'text=1'))));

        $url = app(AttachmentService::class)->url($file, text: true);
        $res = $this->actingAs($this->member)->get($url)->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Truncated', '0');
        $this->assertStringStartsWith('text/plain', (string) $res->headers->get('Content-Type'));
        $this->assertSame("INFO start\nERROR boom <b>x</b>\n", $res->getContent());

        $outsider = User::factory()->withRole('member')->create();
        $this->actingAs($outsider)->get($url)->assertForbidden();
    }

    public function test_images_never_get_a_text_link()
    {
        $this->actingAs($this->member)->post(route('attachments.store'), [
            'attachable_type' => 'task', 'attachable_id' => $this->task->id,
            'file' => UploadedFile::fake()->createWithContent('notes.json', '{"a":1}'),
        ])->assertSessionHasNoErrors();
        $json = Attachment::query()->firstOrFail();
        $this->assertTrue($json->isText());

        $image = (new Attachment)->forceFill(['original_name' => 'hero.png', 'mime' => 'image/png']);
        $this->assertFalse($image->isText());
    }

    private function finishedSprint(): Sprint
    {
        $sprint = Sprint::factory()->create(['workspace_id' => $this->ws->id, 'name' => 'Sprint 9', 'goal' => 'Pay with GCash']);
        $story = Task::factory()->create(['workspace_id' => $this->ws->id, 'sprint_id' => $sprint->id, 'type' => 'story', 'title' => 'GCash checkout', 'story_points' => 5]);
        $bug = Task::factory()->create(['workspace_id' => $this->ws->id, 'sprint_id' => $sprint->id, 'type' => 'bug', 'title' => 'Cart total rounding', 'story_points' => 2]);
        Task::factory()->create(['workspace_id' => $this->ws->id, 'sprint_id' => $sprint->id, 'type' => 'task', 'title' => 'Unfinished work', 'story_points' => 3]);
        $this->actingAs($this->lead)->post(route('workspaces.sprints.start', [$this->ws, $sprint]))->assertSessionHasNoErrors();
        foreach ([$story, $bug] as $t) {
            $this->actingAs($this->lead)->put(route('workspaces.tasks.update', [$this->ws, $t]), ['status_id' => TaskStatus::idFor('done')])
                ->assertSessionHasNoErrors();
        }
        $this->actingAs($this->lead)->post(route('workspaces.sprints.close', [$this->ws, $sprint]))->assertSessionHasNoErrors();

        return $sprint->fresh() ?? $sprint;
    }
}
