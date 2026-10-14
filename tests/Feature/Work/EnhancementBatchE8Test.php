<?php

namespace Tests\Feature\Work;

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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Enhancement batch E8: pinned comment threads (E-04) and vector drawings (E-05) on a file
 * version, stored as fractions of the image and never changing the file.
 */
class EnhancementBatchE8Test extends TestCase
{
    use RefreshDatabase;

    /** A real 1x1 PNG (no GD needed). */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Workspace $ws;

    private User $designer;

    private User $lead;

    private User $guest;

    private Task $task;

    private Attachment $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');

        $this->ws = Workspace::factory()->create();
        $this->designer = User::factory()->withRole('member')->create(['name' => 'Bea Villanueva']);
        $this->lead = User::factory()->withRole('member')->create(['name' => 'Jasmine Torres']);
        $this->guest = User::factory()->withRole('member')->create();
        $this->ws->members()->attach([
            $this->designer->id => ['role' => Workspace::ROLE_MEMBER],
            $this->lead->id => ['role' => Workspace::ROLE_LEAD],
            $this->guest->id => ['role' => Workspace::ROLE_GUEST],
        ]);
        $this->task = Task::factory()->create(['workspace_id' => $this->ws->id, 'assignee_id' => $this->designer->id]);
        $this->actingAs($this->designer)->post(route('attachments.store'), [
            'attachable_type' => 'task', 'attachable_id' => $this->task->id,
            'file' => UploadedFile::fake()->createWithContent('hero.png', (string) base64_decode(self::PNG)),
        ])->assertSessionHasNoErrors();
        $this->file = Attachment::query()->firstOrFail();
    }

    private function pin(User $user, array $data)
    {
        return $this->actingAs($user)->postJson(route('attachments.annotations.store', $this->file), $data);
    }

    public function test_pins_with_drawings_are_numbered_threads_and_the_file_is_unchanged()
    {
        $before = hash('sha256', (string) Storage::disk('local')->get($this->file->key));

        $this->pin($this->lead, [
            'body' => 'Logo bigger here @[Bea Villanueva](user:'.$this->designer->id.')',
            'x' => 0.25, 'y' => 0.4, 'color' => '#D55E00',
            'shapes' => [
                ['type' => 'rect', 'color' => '#D55E00', 'points' => [[0.1, 0.1], [0.4, 0.3]]],
                ['type' => 'pen', 'color' => '#0072B2', 'width' => 4, 'points' => [[0.5, 0.5], [0.51, 0.52], [0.53, 0.55]]],
                ['type' => 'text', 'color' => '#000000', 'points' => [[0.6, 0.7]], 'text' => 'Bigger'],
            ],
        ])->assertCreated()->assertJsonPath('pins.0.number', 1)->assertJsonPath('pins.0.x', 0.25)->assertJsonCount(3, 'pins.0.shapes');

        $this->pin($this->designer, ['body' => 'General note on the file'])->assertCreated()->assertJsonPath('pins.1.number', 2)->assertJsonPath('pins.1.x', null);
        $first = Annotation::query()->where('number', 1)->firstOrFail();
        $this->pin($this->designer, ['body' => 'Done in v2', 'parent_id' => $first->id])->assertCreated()->assertJsonPath('pins.0.replies.0.body', 'Done in v2');

        // The mention reached Bea; the file bytes did not change.
        $this->assertSame(1, InboxNotification::where('notifiable_id', $this->designer->id)->where('kind', 'mentioned')->count());
        $this->assertSame($before, hash('sha256', (string) Storage::disk('local')->get($this->file->key)));

        // Anyone in the workspace sees them; the badge counts open pins.
        $this->actingAs($this->guest)->getJson(route('attachments.annotations.index', $this->file))->assertOk()->assertJsonCount(2, 'pins')->assertJsonPath('can_write', false);
        $this->actingAs($this->lead)->get(route('workspaces.tasks.show', [$this->ws, $this->task]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('panels', fn (Assert $reload) => $reload->where('attachments.0.open_pins', 2)));
    }

    public function test_bad_input_and_outsiders_are_refused()
    {
        $this->pin($this->lead, ['body' => 'x', 'x' => 1.4, 'y' => 0.2])->assertUnprocessable();
        $this->pin($this->lead, ['body' => 'x', 'x' => 0.4, 'y' => 0.2, 'color' => 'red'])->assertUnprocessable();
        $this->pin($this->lead, ['body' => 'x', 'shapes' => [['type' => 'star', 'color' => '#000000', 'points' => [[0.1, 0.1]]]]])->assertUnprocessable();
        $first = Annotation::query()->create(['attachment_id' => $this->file->id, 'user_id' => $this->lead->id, 'number' => 1, 'body' => 'Pin']);
        $this->pin($this->lead, ['body' => 'reply', 'parent_id' => $first->id, 'x' => 0.1, 'y' => 0.1])->assertUnprocessable();

        $this->pin($this->guest, ['body' => 'Guests only look'])->assertForbidden();
        $stranger = User::factory()->withRole('member')->create();
        $this->actingAs($stranger)->getJson(route('attachments.annotations.index', $this->file))->assertForbidden();
    }

    public function test_resolve_edit_and_delete_rules()
    {
        $this->pin($this->lead, ['body' => 'Fix the crop', 'x' => 0.5, 'y' => 0.5]);
        $pin = Annotation::query()->firstOrFail();

        $this->actingAs($this->designer)->patchJson(route('annotations.update', $pin), ['resolved' => true])->assertOk()->assertJsonPath('pins.0.resolved', true);
        $this->actingAs($this->designer)->patchJson(route('annotations.update', $pin), ['body' => 'Changed'])->assertForbidden(); // only the author edits
        $this->actingAs($this->lead)->patchJson(route('annotations.update', $pin), ['body' => 'Fix the crop on the left'])->assertOk();

        $this->pin($this->designer, ['body' => 'Mine', 'x' => 0.1, 'y' => 0.1]);
        $mine = Annotation::query()->where('body', 'Mine')->firstOrFail();
        $this->actingAs($this->designer)->deleteJson(route('annotations.destroy', $pin))->assertForbidden(); // not mine, not a lead
        $this->actingAs($this->designer)->deleteJson(route('annotations.destroy', $mine))->assertOk();
        $this->actingAs($this->lead)->deleteJson(route('annotations.destroy', $pin))->assertOk()->assertJsonCount(0, 'pins');
    }
}
