<?php

namespace Tests\Feature\Files;

use App\Domain\Files\Video\Captions;
use App\Domain\Files\Video\DeliverySpecs;
use App\Models\Annotation;
use App\Models\Attachment;
use App\Models\AttachmentCaption;
use App\Models\DeliverySpec;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Enhancement batch E15b: review player data (E-14), timecoded comments (E-15) and
 * drawings (E-16), comments carried to the next cut (E-17), delivery spec checks (E-18)
 * and caption tracks (E-19).
 */
class EnhancementBatchE15bTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $editor;

    private User $lead;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        config(['video.driver' => 'local', 'video.part_mb' => 5]);
        $this->travelTo('2026-10-15 09:00:00');
        $this->ws = Workspace::factory()->create();
        $this->editor = User::factory()->withRole('member')->create();
        $this->lead = User::factory()->withRole('member')->create();
        $this->ws->members()->attach([
            $this->editor->id => ['role' => Workspace::ROLE_MEMBER],
            $this->lead->id => ['role' => Workspace::ROLE_LEAD],
        ]);
        $this->task = Task::factory()->create([
            'workspace_id' => $this->ws->id, 'assignee_id' => $this->editor->id, 'title' => 'Reels cut',
            'work_details' => ['channel' => 'Instagram, Facebook', 'deliverable_type' => 'Video'],
        ]);
    }

    // E-15 / E-16 ----------------------------------------------------------

    public function test_comments_on_a_video_carry_their_frame_or_range_and_drawings()
    {
        $video = $this->video();

        $this->actingAs($this->lead)->postJson(route('attachments.annotations.store', $video), [
            'body' => 'Logo pops in a frame late', 'time_ms' => 35480, 'x' => 0.5, 'y' => 0.2,
            'color' => '#E69F00', 'shapes' => [['type' => 'arrow', 'color' => '#E69F00', 'points' => [[0.1, 0.1], [0.4, 0.2]]]],
        ])->assertCreated()
            ->assertJsonPath('pins.0.time_ms', 35480)
            ->assertJsonPath('pins.0.end_ms', null)
            ->assertJsonPath('pins.0.shapes.0.type', 'arrow');

        $this->actingAs($this->lead)->postJson(route('attachments.annotations.store', $video), [
            'body' => 'Music too loud here', 'time_ms' => 10000, 'end_ms' => 14000,
        ])->assertCreated()->assertJsonPath('pins.1.end_ms', 14000);

        // A range must end after it starts; replies carry no timecode.
        $this->actingAs($this->lead)->postJson(route('attachments.annotations.store', $video), ['body' => 'x', 'time_ms' => 5000, 'end_ms' => 4000])
            ->assertUnprocessable()->assertJsonValidationErrors('end_ms');
        $first = Annotation::query()->where('attachment_id', $video->id)->orderBy('id')->firstOrFail();
        $this->actingAs($this->lead)->postJson(route('attachments.annotations.store', $video), ['body' => 'Fixed?', 'parent_id' => $first->id, 'time_ms' => 1])
            ->assertUnprocessable();
    }

    public function test_timecodes_are_refused_on_images()
    {
        Storage::disk('local')->put('attachments/x.png', 'png');
        $image = Attachment::create([
            'workspace_id' => $this->ws->id, 'attachable_type' => $this->task->getMorphClass(), 'attachable_id' => $this->task->id,
            'uploaded_by' => $this->editor->id, 'disk' => 'local', 'key' => 'attachments/x.png', 'original_name' => 'x.png', 'mime' => 'image/png', 'size' => 3,
        ]);

        $this->actingAs($this->lead)->postJson(route('attachments.annotations.store', $image), ['body' => 'Here', 'time_ms' => 1000])
            ->assertUnprocessable()->assertJsonValidationErrors('time_ms');
    }

    // E-17 ------------------------------------------------------------------

    public function test_open_comments_carry_over_to_the_next_cut()
    {
        Queue::fake();
        $v1 = $this->upload(str_repeat('a', 1024), 'cut.mp4');
        foreach ([['Too dark', 1000], ['Typo in title', 4000], ['Already fixed', 6000]] as [$body, $ms]) {
            $this->actingAs($this->lead)->postJson(route('attachments.annotations.store', $v1), ['body' => $body, 'time_ms' => $ms])->assertCreated();
        }
        Annotation::query()->where('body', 'Already fixed')->update(['resolved_at' => now()]);

        $v2 = $this->upload(str_repeat('b', 2048), 'cut-v2.mp4', $v1);

        $this->actingAs($this->lead)->getJson(route('attachments.annotations.index', $v2))
            ->assertOk()
            ->assertJsonCount(2, 'pins')
            ->assertJsonPath('pins.0.body', 'Too dark')
            ->assertJsonPath('pins.0.time_ms', 1000)
            ->assertJsonPath('pins.0.carried_from_version', 1)
            ->assertJsonPath('pins.1.number', 2);
        // The old cut keeps its own threads.
        $this->assertSame(3, Annotation::query()->where('attachment_id', $v1)->count());
    }

    // E-19 ------------------------------------------------------------------

    public function test_srt_and_vtt_files_become_clean_webvtt()
    {
        $srt = "1\r\n00:00:01,000 --> 00:00:02,500\r\nHello\r\n\r\n2\r\n00:00:03,000 --> 00:00:04,000\r\nTwo\r\nlines\r\n";
        $this->assertSame([
            'vtt' => "WEBVTT\n\n00:00:01.000 --> 00:00:02.500\nHello\n\n00:00:03.000 --> 00:00:04.000\nTwo\nlines\n",
            'cues' => 2,
        ], Captions::toVtt($srt));

        $vtt = "WEBVTT\n\nNOTE written by Ana\n\nintro\n00:05.000 --> 00:07.000 line:90%\nHi\n";
        $this->assertSame("WEBVTT\n\n00:00:05.000 --> 00:00:07.000 line:90%\nHi\n", Captions::toVtt($vtt)['vtt']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the cue ends before it starts');
        Captions::toVtt("1\n00:00:05,000 --> 00:00:04,000\nBackwards\n");
    }

    public function test_caption_tracks_upload_play_and_delete()
    {
        $video = $this->video();
        $srt = "1\n00:00:01,000 --> 00:00:02,000\nHello\n";

        $res = $this->actingAs($this->editor)->post(route('attachments.captions.store', $video), [
            'file' => UploadedFile::fake()->createWithContent('cut.srt', $srt), 'label' => 'English', 'language' => 'en',
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('captions.0.label', 'English')
            ->assertJsonPath('captions.0.cues', 1);

        $url = (string) $res->json('captions.0.url');
        $track = $this->actingAs($this->lead)->get($url)->assertOk();
        $this->assertStringStartsWith('text/vtt', (string) $track->headers->get('Content-Type'));
        $this->assertSame("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHello\n", $track->streamedContent());

        // Bad file: refused with the line to fix; not a caption file at all; not a video.
        $this->actingAs($this->editor)->post(route('attachments.captions.store', $video), [
            'file' => UploadedFile::fake()->createWithContent('bad.vtt', "WEBVTT\n\n00:00:01.000 -> 00:00:02.000\nHi\n"),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('errors.file.0', fn ($m) => str_starts_with($m, 'Line'));
        $this->actingAs($this->editor)->post(route('attachments.captions.store', $video), [
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'hello'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $outsider = User::factory()->withRole('member')->create();
        $this->actingAs($outsider)->get($url)->assertForbidden();
        $this->actingAs($outsider)->post(route('attachments.captions.store', $video), ['file' => UploadedFile::fake()->createWithContent('a.srt', $srt)], ['Accept' => 'application/json'])
            ->assertForbidden();

        $caption = AttachmentCaption::query()->firstOrFail();
        $this->actingAs($this->editor)->deleteJson(route('captions.destroy', $caption))->assertOk()->assertJsonCount(0, 'captions');
        $this->assertFalse(Storage::disk('local')->exists($caption->key));
    }

    // E-18 ------------------------------------------------------------------

    public function test_only_leads_manage_delivery_specs()
    {
        $body = ['name' => 'Instagram Reels', 'channel' => 'Instagram', 'deliverable' => 'Video', 'aspect' => '9:16', 'min_width' => 1080, 'min_height' => 1920, 'max_duration_s' => 60, 'captions_required' => true];

        $this->actingAs($this->editor)->post(route('workspaces.delivery-specs.store', $this->ws), $body)->assertForbidden();
        $this->actingAs($this->lead)->post(route('workspaces.delivery-specs.store', $this->ws), [...$body, 'aspect' => 'tall'])->assertSessionHasErrors('aspect');
        $this->actingAs($this->lead)->post(route('workspaces.delivery-specs.store', $this->ws), $body)->assertSessionHasNoErrors();
        $spec = DeliverySpec::query()->firstOrFail();
        $this->assertTrue($spec->captions_required);

        $this->actingAs($this->lead)->put(route('workspaces.delivery-specs.update', [$this->ws, $spec]), [...$body, 'max_duration_s' => 90])->assertSessionHasNoErrors();
        $this->assertSame(90, $spec->fresh()?->max_duration_s);

        $this->actingAs($this->lead)->get(route('workspaces.edit', $this->ws))
            ->assertInertia(fn (Assert $page) => $page->where('deliverySpecs.0.name', 'Instagram Reels'));

        $this->actingAs($this->lead)->delete(route('workspaces.delivery-specs.destroy', [$this->ws, $spec]))->assertRedirect();
        $this->assertSame(0, DeliverySpec::query()->count());
    }

    public function test_a_wrong_export_is_flagged_before_review()
    {
        $reels = $this->spec(['name' => 'Instagram Reels', 'channel' => 'Instagram', 'deliverable' => 'Video', 'aspect' => '9:16', 'min_width' => 1080, 'min_height' => 1920, 'max_duration_s' => 60, 'max_size_mb' => 1, 'captions_required' => true]);
        $this->spec(['name' => 'TikTok', 'channel' => 'TikTok', 'aspect' => '9:16']); // not one of the task's channels
        $this->video(['width' => 1920, 'height' => 1080, 'duration_ms' => 72000], 3 * 1024 * 1024);

        $this->actingAs($this->lead)->get(route('workspaces.tasks.show', [$this->ws, $this->task]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('panels', fn (Assert $r) => $r
                ->has('attachments.0.video.spec_checks', 1)
                ->where('attachments.0.video.spec_checks.0.spec', 'Instagram Reels')
                ->where('attachments.0.video.spec_checks.0.pass', false)
                ->where('attachments.0.video.spec_checks.0.issues', [
                    'Aspect 16:9, expected 9:16',
                    'Size 1920×1080, expected at least 1080×1920',
                    'Length 1:12, max 1:00',
                    'File 3 MB, max 1 MB',
                    'Captions missing',
                ])));

        $this->assertSame($reels->id, app(DeliverySpecs::class)->forTask($this->task)->first()?->id);
    }

    public function test_a_right_export_passes_and_a_chosen_spec_wins()
    {
        $this->spec(['name' => 'Instagram Reels', 'channel' => 'Instagram', 'aspect' => '9:16', 'min_width' => 1080, 'min_height' => 1920, 'captions_required' => true]);
        $square = $this->spec(['name' => 'Square post', 'aspect' => '1:1']);
        $video = $this->video(['width' => 1080, 'height' => 1920, 'duration_ms' => 30000]);
        AttachmentCaption::query()->create(['attachment_id' => $video->id, 'label' => 'English', 'key' => 'captions/x.vtt', 'cues' => 3]);

        $this->actingAs($this->lead)->get(route('workspaces.tasks.show', [$this->ws, $this->task]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('panels', fn (Assert $r) => $r
                ->where('attachments.0.video.spec_checks.0.pass', true)
                ->where('attachments.0.video.captions.0.label', 'English')));

        $this->actingAs($this->editor)->put(route('workspaces.tasks.delivery-spec', [$this->ws, $this->task]), ['delivery_spec_id' => $square->id])
            ->assertSessionHasNoErrors();
        $foreign = DeliverySpec::query()->create(['workspace_id' => Workspace::factory()->create()->id, 'name' => 'Other client']);
        $this->actingAs($this->editor)->put(route('workspaces.tasks.delivery-spec', [$this->ws, $this->task]), ['delivery_spec_id' => $foreign->id])
            ->assertSessionHasErrors('delivery_spec_id');

        $this->actingAs($this->lead)->get(route('workspaces.tasks.show', [$this->ws, $this->task]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('task.delivery_spec_id', $square->id)
                ->loadDeferredProps('panels', fn (Assert $r) => $r
                    ->has('attachments.0.video.spec_checks', 1)
                    ->where('attachments.0.video.spec_checks.0.issues', ['Aspect 9:16, expected 1:1'])));
    }

    public function test_aspect_labels_read_like_editors_say_them()
    {
        $this->assertSame('9:16', DeliverySpecs::label(1080, 1920));
        $this->assertSame('16:9', DeliverySpecs::label(1280, 720));
        $this->assertSame('4:5', DeliverySpecs::label(1080, 1350));
        $this->assertSame('10:7', DeliverySpecs::label(1000, 700));
    }

    // helpers -----------------------------------------------------------------

    /**
     * @param  array<string, int>  $media
     */
    private function video(array $media = ['width' => 1920, 'height' => 1080, 'duration_ms' => 10000], int $size = 1000): Attachment
    {
        return Attachment::create([
            'workspace_id' => $this->ws->id, 'attachable_type' => $this->task->getMorphClass(), 'attachable_id' => $this->task->id,
            'uploaded_by' => $this->editor->id, 'disk' => 'video-local', 'key' => 'videos/'.uniqid().'.mp4', 'original_name' => 'cut.mp4',
            'mime' => 'video/mp4', 'size' => $size, 'media_status' => 'ready', 'media' => [...$media, 'fps' => 25.0, 'video_codec' => 'h264', 'audio_codec' => 'aac'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function spec(array $attributes): DeliverySpec
    {
        return DeliverySpec::query()->create([...$attributes, 'workspace_id' => $this->ws->id]);
    }

    private function upload(string $bytes, string $name, ?int $versionOf = null): int
    {
        $start = $this->actingAs($this->editor)->postJson(route('video-uploads.start'), [
            'attachable_type' => 'task', 'attachable_id' => $this->task->id, 'name' => $name, 'size' => strlen($bytes), 'version_of' => $versionOf,
        ])->assertCreated()->json();
        $urls = $this->actingAs($this->editor)->postJson(route('video-uploads.parts', $start['id']), ['parts' => [1]])->json('urls');
        $etag = trim((string) $this->actingAs($this->editor)->call('PUT', $urls[1], [], [], [], ['CONTENT_LENGTH' => (string) strlen($bytes)], $bytes)
            ->assertOk()->headers->get('ETag'), '"');
        $this->actingAs($this->editor)->postJson(route('video-uploads.complete', $start['id']), ['parts' => [['part' => 1, 'etag' => $etag]]])->assertOk();

        return (int) $start['id'];
    }
}
