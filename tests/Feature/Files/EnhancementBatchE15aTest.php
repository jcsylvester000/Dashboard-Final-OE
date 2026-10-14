<?php

namespace Tests\Feature\Files;

use App\Domain\Files\AttachmentService;
use App\Domain\Files\Video\S3Client;
use App\Domain\Files\Video\VideoProcessor;
use App\Domain\System\HealthChecks;
use App\Jobs\ProcessVideo;
use App\Models\Attachment;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * Enhancement batch E15a (decisions D-01, D-02): direct resumable video uploads (E-11),
 * Range playback (E-12) and poster / details / web proxy processing (E-13).
 */
class EnhancementBatchE15aTest extends TestCase
{
    use RefreshDatabase;

    private const MB = 1024 * 1024;

    private Workspace $ws;

    private User $editor;

    private User $lead;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        config(['video.driver' => 'local', 'video.part_mb' => 5, 'video.quota_gb' => 1]);
        $this->travelTo('2026-10-15 09:00:00');
        $this->ws = Workspace::factory()->create();
        $this->editor = User::factory()->withRole('member')->create();
        $this->lead = User::factory()->withRole('member')->create();
        $this->ws->members()->attach([
            $this->editor->id => ['role' => Workspace::ROLE_MEMBER],
            $this->lead->id => ['role' => Workspace::ROLE_LEAD],
        ]);
        $this->task = Task::factory()->create(['workspace_id' => $this->ws->id, 'assignee_id' => $this->editor->id, 'title' => 'Reels cut']);
    }

    // S3 signer (D-01) ------------------------------------------------------

    public function test_presigned_urls_match_the_aws_signature_v4_test_vector()
    {
        // AWS S3 API reference, "Authenticating Requests: Using Query Parameters" example.
        $s3 = new S3Client('https://s3.amazonaws.com', 'us-east-1', 'examplebucket', 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', pathStyle: false);
        $url = $s3->presign('GET', 'test.txt', 86400, [], gmmktime(0, 0, 0, 5, 24, 2013));

        $this->assertStringStartsWith('https://examplebucket.s3.amazonaws.com/test.txt?', $url);
        $this->assertStringEndsWith('&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404', $url);
        $this->assertStringContainsString('X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request', $url);
    }

    public function test_multipart_calls_are_signed_and_errors_are_caught()
    {
        Http::fake([
            '*uploads=*' => Http::response('<InitiateMultipartUploadResult><UploadId>abc-123</UploadId></InitiateMultipartUploadResult>'),
            '*uploadId=good*' => Http::response('<CompleteMultipartUploadResult><ETag>"x"</ETag></CompleteMultipartUploadResult>'),
            '*uploadId=bad*' => Http::response('<Error><Code>InvalidPart</Code><Message>One or more parts could not be found.</Message></Error>'),
        ]);
        $s3 = new S3Client('https://acct.r2.cloudflarestorage.com', 'auto', 'oe-videos', 'KEY', 'SECRET');

        $this->assertSame('abc-123', $s3->createMultipart('videos/1/a b.mp4', 'video/mp4'));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && str_starts_with($r->url(), 'https://acct.r2.cloudflarestorage.com/oe-videos/videos/1/a%20b.mp4?uploads=')
            && str_starts_with($r->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=KEY/20261015/auto/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature='));

        $s3->completeMultipart('videos/1/a.mp4', 'good', [['part' => 2, 'etag' => 'b'], ['part' => 1, 'etag' => '"a"']]);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'uploadId=good')
            && $r->body() === '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>"a"</ETag></Part><Part><PartNumber>2</PartNumber><ETag>"b"</ETag></Part></CompleteMultipartUpload>');

        $this->expectException(RuntimeException::class);
        $s3->completeMultipart('videos/1/a.mp4', 'bad', [['part' => 1, 'etag' => 'a']]);
    }

    public function test_part_and_playback_links_are_presigned_and_short_lived()
    {
        $s3 = new S3Client('https://acct.r2.cloudflarestorage.com', 'auto', 'oe-videos', 'KEY', 'SECRET');
        $url = $s3->presign('PUT', 'videos/1/x.mp4', 900, ['partNumber' => '3', 'uploadId' => 'u/1']);

        $this->assertStringStartsWith('https://acct.r2.cloudflarestorage.com/oe-videos/videos/1/x.mp4?', $url);
        $this->assertStringContainsString('partNumber=3', $url);
        $this->assertStringContainsString('uploadId=u%2F1', $url);
        $this->assertStringContainsString('X-Amz-Expires=900', $url);
    }

    // E-11 direct upload (local driver, same flow) --------------------------

    public function test_a_video_uploads_in_parts_and_becomes_the_latest_file()
    {
        Queue::fake();
        $bytes = str_repeat('a', 5 * self::MB).str_repeat('b', 1024);

        $id = $this->upload($bytes, 'Final cut.mov');

        $a = Attachment::query()->findOrFail($id);
        $this->assertSame(['video-local', null, true, 'processing', 'video/quicktime', strlen($bytes)], [$a->disk, $a->upload_state, $a->is_latest, $a->media_status, $a->mime, $a->size]);
        $this->assertSame($bytes, Storage::disk('local')->get($a->key));
        $this->assertFalse(Storage::disk('local')->exists('video-parts/'.$a->id)); // parts cleaned up
        Queue::assertPushedOn('media', ProcessVideo::class, fn (ProcessVideo $job) => $job->attachmentId === $id);
    }

    public function test_unfinished_uploads_are_hidden_and_only_the_uploader_can_continue()
    {
        $start = $this->start(6 * self::MB, 'cut.mp4');
        $this->assertSame(2, $start['parts']);
        $this->assertSame(5 * self::MB, $start['part_size']);

        $this->actingAs($this->editor)->get(route('workspaces.tasks.show', [$this->ws, $this->task]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('panels', fn (Assert $r) => $r->has('attachments', 0)));

        $this->actingAs($this->lead)->postJson(route('video-uploads.parts', $start['id']), ['parts' => [1]])->assertNotFound();
        $this->actingAs($this->lead)->deleteJson(route('video-uploads.abort', $start['id']))->assertNotFound();
        $this->actingAs($this->editor)->postJson(route('video-uploads.parts', $start['id']), ['parts' => [3]])->assertUnprocessable(); // out of range

        $this->actingAs($this->editor)->deleteJson(route('video-uploads.abort', $start['id']))->assertOk();
        $this->assertSame(0, Attachment::withTrashed()->count());
    }

    public function test_type_size_and_quota_are_checked_before_the_first_byte()
    {
        $this->actingAs($this->editor)->postJson(route('video-uploads.start'), [
            'attachable_type' => 'task', 'attachable_id' => $this->task->id, 'name' => 'virus.exe', 'size' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        config(['video.max_gb' => 0.001]); // ~1 MB
        $this->actingAs($this->editor)->postJson(route('video-uploads.start'), [
            'attachable_type' => 'task', 'attachable_id' => $this->task->id, 'name' => 'big.mp4', 'size' => 2 * self::MB,
        ])->assertUnprocessable();

        config(['video.max_gb' => 5, 'video.quota_gb' => 0.01]); // ~10 MB per client
        $this->start(6 * self::MB, 'one.mp4');
        $this->actingAs($this->editor)->postJson(route('video-uploads.start'), [
            'attachable_type' => 'task', 'attachable_id' => $this->task->id, 'name' => 'two.mp4', 'size' => 6 * self::MB,
        ])->assertUnprocessable()->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'Not enough video space'));

        $outsider = User::factory()->withRole('member')->create();
        $this->actingAs($outsider)->postJson(route('video-uploads.start'), [
            'attachable_type' => 'task', 'attachable_id' => $this->task->id, 'name' => 'x.mp4', 'size' => 10,
        ])->assertForbidden();
    }

    public function test_damaged_parts_and_size_mismatches_are_refused()
    {
        $start = $this->start(5 * self::MB + 1024, 'cut.mp4');
        $etag1 = $this->putPart($start['id'], 1, str_repeat('a', 5 * self::MB));
        $etag2 = $this->putPart($start['id'], 2, str_repeat('b', 10)); // shorter than declared

        $this->actingAs($this->editor)->postJson(route('video-uploads.complete', $start['id']), ['parts' => [['part' => 1, 'etag' => $etag1], ['part' => 2, 'etag' => 'wrong']]])
            ->assertUnprocessable();

        $start2 = $this->start(5 * self::MB + 1024, 'cut2.mp4');
        $e1 = $this->putPart($start2['id'], 1, str_repeat('a', 5 * self::MB));
        $e2 = $this->putPart($start2['id'], 2, str_repeat('b', 10));
        $this->actingAs($this->editor)->postJson(route('video-uploads.complete', $start2['id']), ['parts' => [['part' => 1, 'etag' => $e1], ['part' => 2, 'etag' => $e2]]])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertNull(Attachment::withTrashed()->find($start2['id'])); // quota freed
        $this->assertNotSame('', $etag2);
    }

    public function test_a_new_cut_becomes_version_two()
    {
        Queue::fake();
        $v1 = $this->upload(str_repeat('a', 1024), 'cut.mp4');
        $v2 = $this->upload(str_repeat('b', 2048), 'cut-v2.mp4', $v1);

        $this->assertFalse(Attachment::query()->findOrFail($v1)->is_latest);
        $second = Attachment::query()->findOrFail($v2);
        $this->assertSame([2, $v1, true], [$second->version, $second->version_root_id, $second->is_latest]);
    }

    public function test_abandoned_uploads_are_pruned()
    {
        $start = $this->start(1024, 'cut.mp4');
        $this->travel(25)->hours();

        $this->artisan('video:prune-uploads')->assertSuccessful();

        $this->assertNull(Attachment::withTrashed()->find($start['id']));
    }

    // E-12 Range playback ----------------------------------------------------

    public function test_videos_and_files_answer_range_requests_with_206()
    {
        Queue::fake();
        $id = $this->upload(str_repeat('0123456789', 100), 'cut.mp4');
        $a = Attachment::query()->findOrFail($id);
        $url = app(AttachmentService::class)->url($a);

        $this->actingAs($this->lead)->get($url, ['Range' => 'bytes=0-9'])
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-9/1000')
            ->assertHeader('Accept-Ranges', 'bytes');
        $full = $this->actingAs($this->lead)->get($url)->assertOk();
        $this->assertSame(1000, strlen((string) $full->streamedContent()));

        $outsider = User::factory()->withRole('member')->create();
        $this->actingAs($outsider)->get($url)->assertForbidden();
    }

    // E-13 processing --------------------------------------------------------

    public function test_processing_reads_details_makes_a_poster_and_a_web_copy()
    {
        Queue::fake();
        $id = $this->upload(str_repeat('v', 2048), 'Final.mov');
        $this->fakeTools(['codec_name' => 'prores', 'pix_fmt' => 'yuv422p10le', 'width' => 3840, 'height' => 2160, 'avg_frame_rate' => '25/1', 'tags' => ['rotate' => '90']], 'mov,mp4,m4a,3gp,3g2,mj2', 'pcm_s16le');

        (new ProcessVideo($id))->handle(app(VideoProcessor::class));

        $a = Attachment::query()->findOrFail($id);
        $this->assertSame('ready', $a->media_status);
        $this->assertSame(['duration_ms' => 12500, 'width' => 2160, 'height' => 3840, 'fps' => 25.0, 'video_codec' => 'prores', 'audio_codec' => 'pcm_s16le'], $a->media);
        $this->assertNotNull($a->thumbnail_key);
        $this->assertStringEndsWith('.jpg', (string) $a->thumbnail_key);
        $this->assertTrue(Storage::disk('local')->exists((string) $a->proxy_key));
        Process::assertRan(fn (PendingProcess $p) => in_array('libx264', (array) $p->command, true) && in_array("scale=-2:'min(1080,ih)'", (array) $p->command, true));

        // The player gets the web copy as MP4; Download still gives the original.
        $files = app(AttachmentService::class);
        $play = $this->actingAs($this->lead)->get($files->url($a))->assertOk();
        $this->assertSame('video/mp4', $play->headers->get('Content-Type'));
        $this->assertSame('PROXY', $play->streamedContent());
        $this->assertSame(str_repeat('v', 2048), $this->actingAs($this->lead)->get($files->url($a, download: true))->streamedContent());
        $poster = $this->actingAs($this->lead)->get($files->url($a, thumbnail: true))->assertOk();
        $this->assertSame('image/jpeg', $poster->headers->get('Content-Type'));

        $this->actingAs($this->lead)->get(route('workspaces.tasks.show', [$this->ws, $this->task]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('panels', fn (Assert $r) => $r
                ->where('attachments.0.video.status', 'ready')
                ->where('attachments.0.video.duration_ms', 12500)
                ->where('attachments.0.thumb_url', fn ($u) => is_string($u))));
    }

    public function test_web_safe_files_are_only_remuxed()
    {
        Queue::fake();
        $id = $this->upload(str_repeat('v', 2048), 'social.mp4');
        $this->fakeTools(['codec_name' => 'h264', 'pix_fmt' => 'yuv420p', 'width' => 1080, 'height' => 1920, 'avg_frame_rate' => '30000/1001'], 'mov,mp4,m4a,3gp,3g2,mj2', 'aac');

        (new ProcessVideo($id))->handle(app(VideoProcessor::class));

        $this->assertSame(29.97, Attachment::query()->findOrFail($id)->media['fps'] ?? null);
        Process::assertRan(fn (PendingProcess $p) => in_array('copy', (array) $p->command, true) && in_array('+faststart', (array) $p->command, true));
        Process::assertDidntRun(fn (PendingProcess $p) => in_array('libx264', (array) $p->command, true));
    }

    public function test_a_file_that_is_not_a_video_fails_clearly_and_stays_downloadable()
    {
        Queue::fake();
        $id = $this->upload('not really a video', 'broken.mp4');
        Process::fake(['*' => Process::result(output: '', errorOutput: 'Invalid data found', exitCode: 1)]);

        (new ProcessVideo($id))->handle(app(VideoProcessor::class));

        $a = Attachment::query()->findOrFail($id);
        $this->assertSame(['failed', 'Not a readable video file.'], [$a->media_status, $a->media_error]);
        $this->assertSame('not really a video', $this->actingAs($this->lead)->get(app(AttachmentService::class)->url($a, download: true))->streamedContent());
    }

    public function test_deleting_a_video_removes_the_original_and_the_web_copy()
    {
        Queue::fake();
        $id = $this->upload(str_repeat('v', 2048), 'cut.mov');
        $this->fakeTools(['codec_name' => 'prores', 'pix_fmt' => 'yuv422p10le', 'width' => 1920, 'height' => 1080, 'avg_frame_rate' => '24/1'], 'mov', null);
        (new ProcessVideo($id))->handle(app(VideoProcessor::class));
        $a = Attachment::query()->findOrFail($id);

        $this->actingAs($this->editor)->delete(route('attachments.destroy', $a))->assertRedirect();

        $this->assertFalse(Storage::disk('local')->exists($a->key));
        $this->assertFalse(Storage::disk('local')->exists((string) $a->proxy_key));
        $this->assertFalse(Storage::disk('local')->exists((string) $a->thumbnail_key));
    }

    public function test_health_page_reports_video_setup()
    {
        Process::fake(['*' => Process::result(output: "ffmpeg version 6.1\nbuilt with gcc")]);

        $row = collect(app(HealthChecks::class)->run())->firstWhere('key', 'video');

        $this->assertSame(['ok', 'Server disk', 'ffmpeg version 6.1'], [$row['status'], $row['value'], $row['detail']]);
    }

    // helpers -----------------------------------------------------------------

    /**
     * @return array{id: int, part_size: int, parts: int}
     */
    private function start(int $size, string $name, ?int $versionOf = null): array
    {
        return $this->actingAs($this->editor)->postJson(route('video-uploads.start'), [
            'attachable_type' => 'task', 'attachable_id' => $this->task->id, 'name' => $name, 'size' => $size, 'version_of' => $versionOf,
        ])->assertCreated()->json();
    }

    private function putPart(int $id, int $part, string $bytes): string
    {
        $urls = $this->actingAs($this->editor)->postJson(route('video-uploads.parts', $id), ['parts' => [$part]])->assertOk()->json('urls');
        $response = $this->actingAs($this->editor)->call('PUT', $urls[$part], [], [], [], ['CONTENT_LENGTH' => (string) strlen($bytes), 'CONTENT_TYPE' => 'application/octet-stream'], $bytes)
            ->assertOk();

        return trim((string) $response->headers->get('ETag'), '"');
    }

    private function upload(string $bytes, string $name, ?int $versionOf = null): int
    {
        $start = $this->start(strlen($bytes), $name, $versionOf);
        $parts = [];
        foreach (str_split($bytes, $start['part_size']) as $i => $chunk) {
            $parts[] = ['part' => $i + 1, 'etag' => $this->putPart($start['id'], $i + 1, $chunk)];
        }
        $this->actingAs($this->editor)->postJson(route('video-uploads.complete', $start['id']), ['parts' => $parts])->assertOk();

        return $start['id'];
    }

    /**
     * ffprobe answers with this stream; ffmpeg "writes" its output file.
     *
     * @param  array<string, mixed>  $video
     */
    private function fakeTools(array $video, string $container, ?string $audio): void
    {
        $streams = [['codec_type' => 'video', ...$video]];
        if ($audio !== null) {
            $streams[] = ['codec_type' => 'audio', 'codec_name' => $audio];
        }
        Process::fake(function (PendingProcess $process) use ($streams, $container) {
            $cmd = (array) $process->command;
            if (str_contains((string) $cmd[0], 'ffprobe')) {
                return Process::result((string) json_encode(['streams' => $streams, 'format' => ['duration' => '12.500', 'format_name' => $container]]));
            }
            $target = (string) end($cmd);
            file_put_contents($target, str_ends_with($target, '.jpg') ? 'JPEG' : 'PROXY');

            return Process::result();
        });
    }
}
