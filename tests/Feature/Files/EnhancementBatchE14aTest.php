<?php

namespace Tests\Feature\Files;

use App\Domain\Files\FeedbackSheet;
use App\Models\ActivityLog;
use App\Models\Annotation;
use App\Models\Attachment;
use App\Models\InboxNotification;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Enhancement batch E14a: client review link without login (E-08) and the feedback sheet
 * PDF (E-09).
 */
class EnhancementBatchE14aTest extends TestCase
{
    use RefreshDatabase;

    /** A real 1x1 PNG (no GD needed). */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Workspace $ws;

    private User $designer;

    private User $lead;

    private Task $task;

    private Attachment $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        $this->travelTo('2026-10-15 09:00:00');
        $this->ws = Workspace::factory()->create();
        $this->designer = User::factory()->withRole('member')->create(['name' => 'Bea Villanueva']);
        $this->lead = User::factory()->withRole('member')->create(['name' => 'Jasmine Torres']);
        $this->ws->members()->attach([
            $this->designer->id => ['role' => Workspace::ROLE_MEMBER],
            $this->lead->id => ['role' => Workspace::ROLE_LEAD],
        ]);
        $this->task = Task::factory()->create(['workspace_id' => $this->ws->id, 'assignee_id' => $this->designer->id, 'title' => 'Holiday ad']);
        $this->actingAs($this->designer)->post(route('attachments.store'), [
            'attachable_type' => 'task', 'attachable_id' => $this->task->id,
            'file' => UploadedFile::fake()->createWithContent('hero.png', (string) base64_decode(self::PNG)),
        ])->assertSessionHasNoErrors();
        $this->file = Attachment::query()->firstOrFail();
    }

    private function share(): string
    {
        $this->actingAs($this->lead)->post(route('attachments.client-link', $this->file), ['expires_days' => 14])->assertSessionHasNoErrors();

        return (string) $this->file->fresh()?->review_token;
    }

    public function test_only_approvers_share_and_the_page_works_logged_out()
    {
        $this->actingAs($this->designer)->post(route('attachments.client-link', $this->file))->assertForbidden(); // the assignee can't
        $token = $this->share();
        $this->assertSame(40, strlen($token));
        $this->assertTrue(ActivityLog::query()->where('action', 'file.client-link-created')->exists());

        auth()->logout();
        $page = $this->get(route('client-review.show', $token))->assertOk()
            ->assertSee('hero.png')->assertSee('Your name')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('noindex', (string) $page->headers->get('X-Robots-Tag'));
        $this->assertSame(1, $this->file->fresh()?->review_views);
        $this->get(route('client-review.file', $token))->assertOk();
        $this->get(route('client-review.show', str_repeat('x', 40)))->assertNotFound();
    }

    public function test_the_client_comments_and_decides_and_never_sees_internal_notes()
    {
        $token = $this->share();
        // An internal pin the client must never see.
        $this->actingAs($this->lead)->postJson(route('attachments.annotations.store', $this->file), ['body' => 'Internal: logo too small, cost overrun', 'x' => 0.1, 'y' => 0.1])
            ->assertCreated();

        auth()->logout();
        $this->post(route('client-review.comment', $token), ['body' => 'Hi'])->assertForbidden(); // name first
        $this->post(route('client-review.name', $token), ['name' => 'Maria Santos'])->assertRedirect();
        $this->post(route('client-review.comment', $token), ['body' => 'Make the price bigger', 'x' => 0.5, 'y' => 0.4])->assertRedirect();
        $pin = Annotation::query()->where('client_name', 'Maria Santos')->firstOrFail();
        $this->assertNull($pin->user_id);
        $this->assertSame(2, $pin->number);

        // Staff are told, and see it marked as the client's.
        $this->assertTrue(InboxNotification::query()->where('notifiable_id', $this->lead->id)->where('kind', 'comment')->exists());
        $this->assertTrue(InboxNotification::query()->where('notifiable_id', $this->designer->id)->where('kind', 'comment')->exists());
        $this->actingAs($this->lead)->getJson(route('attachments.annotations.index', $this->file))
            ->assertJsonPath('pins.1.is_client', true)->assertJsonPath('pins.1.author', 'Maria Santos');
        $this->actingAs($this->lead)->postJson(route('attachments.annotations.store', $this->file), ['body' => 'Done in v2', 'parent_id' => $pin->id])->assertCreated();

        auth()->logout();
        $this->get(route('client-review.show', $token))->assertOk()
            ->assertSee('Make the price bigger')->assertSee('Done in v2')
            ->assertDontSee('Internal: logo too small')->assertDontSee('Jasmine Torres');
        // A reply can't target an internal thread.
        $internal = Annotation::query()->whereNull('client_name')->whereNull('parent_id')->firstOrFail();
        $this->post(route('client-review.comment', $token), ['body' => 'Peek', 'parent_id' => $internal->id])->assertSessionHasErrors('body');

        $this->post(route('client-review.decide', $token), ['decision' => 'changes'])->assertSessionHasErrors('note');
        $this->post(route('client-review.decide', $token), ['decision' => 'approve'])->assertRedirect();
        $file = $this->file->fresh();
        $this->assertNotNull($file?->approved_at);
        $this->assertSame('Maria Santos', $file?->client_decision_by);
        $this->assertTrue(ActivityLog::query()->where('action', 'file.client-approved')->exists());
        $this->assertTrue(InboxNotification::query()->where('notifiable_id', $this->lead->id)->where('kind', 'review')->exists());
    }

    public function test_revoked_and_expired_links_are_gone()
    {
        $token = $this->share();
        $this->actingAs($this->lead)->delete(route('attachments.client-unlink', $this->file))->assertSessionHasNoErrors();
        auth()->logout();
        $this->get(route('client-review.show', $token))->assertNotFound();

        $token = $this->share();
        auth()->logout();
        $this->travel(15)->days();
        $this->get(route('client-review.show', $token))->assertNotFound();
    }

    public function test_feedback_sheet_pdf_lists_every_pin()
    {
        $this->actingAs($this->lead)->postJson(route('attachments.annotations.store', $this->file), ['body' => 'Move the logo', 'x' => 0.25, 'y' => 0.75])->assertCreated();
        $response = $this->actingAs($this->designer)->get(route('attachments.feedback-sheet', $this->file))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $svg = app(FeedbackSheet::class)->svg(Annotation::query()->get()->all(), 400, 200);
        $this->assertStringContainsString('<circle cx="100.0" cy="150.0"', $svg, 'pin at 25% / 75%');
        $this->assertStringContainsString('>1</text>', $svg);

        $outsider = User::factory()->withRole('member')->create();
        $this->actingAs($outsider)->get(route('attachments.feedback-sheet', $this->file))->assertForbidden();
    }
}
