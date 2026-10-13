<?php

namespace Tests\Feature\Files;

use App\Domain\Files\AttachmentService;
use App\Domain\Files\DriveService;
use App\Domain\Files\UploadThing\UploadThingClient;
use App\Models\Attachment;
use App\Models\DriveFile;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Team file drive on UploadThing (all HTTP faked): presigned uploads, quota
 * (100 MB members / 300 MB admins), sharing, lock, public links.
 */
class DriveTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private User $admin;

    /** Flip to make UploadThing answer 404 for the uploaded file. */
    private bool $fileMissing = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);

        config(['services.uploadthing.token' => base64_encode((string) json_encode(['apiKey' => 'sk_test_fake', 'appId' => 'app123', 'regions' => ['sea1']]))]);
        $this->app->forgetInstance(UploadThingClient::class);
        // Inertia's SSR gateway also uses the HTTP client; with stray requests blocked it
        // would throw while rendering pages, so render client-side only in these tests.
        config(['inertia.ssr.enabled' => false, 'services.uploadthing.acl' => 'private']);
        Http::preventStrayRequests();
        Http::fake([
            'api.uploadthing.com/v7/prepareUpload' => Http::response(['key' => 'KEY123', 'url' => 'https://sea1.ingest.uploadthing.com/KEY123?signature=x']),
            'api.uploadthing.com/v6/requestFileAccess' => Http::response(['ufsUrl' => 'https://app123.ufs.sh/f/KEY123?sig=1', 'url' => 'https://utfs.io/f/KEY123']),
            'app123.ufs.sh/*' => fn () => $this->fileMissing
                ? Http::response('', 404)
                : Http::response('', 200, ['Content-Length' => '2048', 'Content-Type' => 'image/png']),
            'api.uploadthing.com/v6/deleteFiles' => Http::response(['success' => true, 'deletedCount' => 1]),
        ]);

        $this->member = User::factory()->withRole('member')->create();
        $this->admin = User::factory()->withRole('admin')->create();
    }

    private function upload(User $user, string $name = 'logo.png', int $size = 2048): DriveFile
    {
        $id = $this->actingAs($user)->postJson(route('drive.prepare'), ['name' => $name, 'size' => $size, 'type' => 'image/png'])
            ->assertOk()
            ->assertJsonPath('url', 'https://sea1.ingest.uploadthing.com/KEY123?signature=x')
            ->json('id');

        $this->actingAs($user)->postJson(route('drive.complete', $id))->assertOk()->assertJsonPath('status', 'ready');

        return DriveFile::findOrFail($id);
    }

    public function test_upload_flow_reserves_quota_then_confirms_with_uploadthing()
    {
        $file = $this->upload($this->member);

        $this->assertSame('KEY123', $file->storage_key);
        $this->assertSame('ready', $file->status);
        $this->assertSame('private', $file->visibility);
        $this->assertSame(2048, $file->size);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v7/prepareUpload')
            && $r->header('x-uploadthing-api-key')[0] === 'sk_test_fake'
            && $r['fileName'] === 'logo.png' && $r['fileSize'] === 2048 && $r['acl'] === 'private');

        $this->actingAs($this->member)->get(route('drive.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('files/Index')
                ->where('configured', true)
                ->where('quota.used', 2048)
                ->where('quota.limit', 100 * DriveService::MB)
                ->has('files', 1)
                ->where('files.0.name', 'logo.png'));
    }

    public function test_members_get_100_mb_and_admins_300_mb()
    {
        $this->actingAs($this->member)->postJson(route('drive.prepare'), ['name' => 'big.mp4', 'size' => 101 * DriveService::MB])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->actingAs($this->admin)->postJson(route('drive.prepare'), ['name' => 'big.mp4', 'size' => 101 * DriveService::MB])->assertOk();
        $this->actingAs($this->admin)->postJson(route('drive.prepare'), ['name' => 'huge.mp4', 'size' => 200 * DriveService::MB])
            ->assertStatus(422); // 101 + 200 > 300 (pending uploads hold their space)

        $this->assertSame(300 * DriveService::MB, app(DriveService::class)->quotaBytes($this->admin));
    }

    public function test_disallowed_types_are_refused_before_anything_is_uploaded()
    {
        $this->actingAs($this->member)->postJson(route('drive.prepare'), ['name' => 'setup.exe', 'size' => 100])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        Http::assertNothingSent();
        $this->assertSame(0, DriveFile::count());
    }

    public function test_only_the_owner_can_complete_and_missing_files_stay_pending()
    {
        $this->fileMissing = true;
        $id = $this->actingAs($this->member)->postJson(route('drive.prepare'), ['name' => 'a.pdf', 'size' => 10])->json('id');

        $this->actingAs($this->admin)->postJson(route('drive.complete', $id))->assertForbidden();
        $this->actingAs($this->member)->postJson(route('drive.complete', $id))->assertStatus(422);
        $this->assertSame('pending', DriveFile::findOrFail($id)->status);
    }

    public function test_visibility_controls_who_can_open_a_file()
    {
        $file = DriveFile::factory()->create(['owner_id' => $this->member->id]);
        $other = User::factory()->withRole('member')->create();
        $ws = Workspace::factory()->create();
        $ws->members()->attach($other->id, ['role' => Workspace::ROLE_MEMBER]);

        $this->actingAs($other)->get(route('drive.open', $file))->assertForbidden();
        $this->actingAs($this->admin)->get(route('drive.open', $file))->assertRedirect('https://app123.ufs.sh/f/KEY123?sig=1');

        $file->update(['visibility' => 'team']);
        $this->actingAs($other)->get(route('drive.open', $file))->assertRedirect();

        // Client team: only that workspace's members.
        $this->actingAs($this->member)->put(route('drive.update', $file), ['visibility' => 'workspace', 'workspace_id' => $ws->id])
            ->assertForbidden(); // the owner isn't on that workspace
        $ws->members()->attach($this->member->id, ['role' => Workspace::ROLE_MEMBER]);
        $this->actingAs($this->member)->put(route('drive.update', $file), ['visibility' => 'workspace', 'workspace_id' => $ws->id])
            ->assertSessionHasNoErrors();
        $outsider = User::factory()->withRole('member')->create();
        $this->actingAs($outsider)->get(route('drive.open', $file))->assertForbidden();
        $this->actingAs($other)->get(route('drive.open', $file))->assertRedirect();

        $this->actingAs($other)->get(route('drive.index', ['tab' => 'shared']))
            ->assertInertia(fn (Assert $page) => $page->has('shared', 1)->where('shared.0.can_manage', false)->where('shared.0.share_url', null));
    }

    public function test_public_links_work_without_login_expire_and_are_revoked()
    {
        $file = DriveFile::factory()->create(['owner_id' => $this->member->id, 'name' => 'Proof v2.png', 'mime' => 'image/png']);

        $this->actingAs($this->member)->put(route('drive.update', $file), ['visibility' => 'public', 'expires_in_days' => 7])->assertSessionHasNoErrors();
        $token = (string) $file->fresh()->share_token;
        $this->assertSame(40, strlen($token));

        auth()->logout();
        $this->get(route('share.show', $token))->assertOk()->assertSee('Proof v2.png')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get(route('share.file', ['token' => $token, 'download' => 1]))->assertRedirect('https://app123.ufs.sh/f/KEY123?sig=1');
        $this->assertSame(1, $file->fresh()->share_downloads);

        $this->travel(8)->days();
        $this->get(route('share.show', $token))->assertNotFound();
        $this->travelBack();

        // Making it private again kills the link.
        $this->actingAs($this->member)->put(route('drive.update', $file), ['visibility' => 'private'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->get(route('share.show', $token))->assertNotFound();
        $this->get(route('share.show', str_repeat('a', 40)))->assertNotFound();
    }

    public function test_locked_files_cannot_be_deleted_renamed_or_reshared()
    {
        $file = DriveFile::factory()->create(['owner_id' => $this->member->id]);

        $this->actingAs($this->member)->post(route('drive.lock', $file), ['locked' => true])->assertRedirect();
        $this->actingAs($this->member)->delete(route('drive.destroy', $file))->assertSessionHasErrors('file');
        $this->actingAs($this->member)->put(route('drive.update', $file), ['visibility' => 'public'])->assertSessionHasErrors('file');
        $this->actingAs($this->member)->put(route('drive.update', $file), ['name' => 'x.pdf'])->assertSessionHasErrors('file');
        $this->assertNotSoftDeleted($file);

        // Someone else can't unlock it; the owner can, then delete.
        $other = User::factory()->withRole('member')->create();
        $this->actingAs($other)->post(route('drive.lock', $file), ['locked' => false])->assertForbidden();
        $this->actingAs($this->member)->post(route('drive.lock', $file), ['locked' => false]);
        $this->actingAs($this->member)->delete(route('drive.destroy', $file))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($file);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v6/deleteFiles') && $r['fileKeys'] === [$file->storage_key]);
    }

    public function test_abandoned_uploads_are_pruned_and_free_their_quota()
    {
        DriveFile::factory()->pending()->create(['owner_id' => $this->member->id, 'size' => 50 * DriveService::MB, 'created_at' => now()->subHours(3)]);
        $this->assertSame(0, app(DriveService::class)->usedBytes($this->member));

        $this->artisan('drive:prune')->assertSuccessful();
        $this->assertSame(0, DriveFile::withTrashed()->count());
    }

    public function test_token_parsing_and_unconfigured_storage()
    {
        $this->assertNull(UploadThingClient::parseToken('not-a-token'));
        $this->assertSame('app123', UploadThingClient::parseToken((string) config('services.uploadthing.token'))['appId'] ?? null);

        config(['services.uploadthing.token' => null]);
        $this->app->forgetInstance(UploadThingClient::class);
        $this->app->forgetInstance(DriveService::class);

        $this->actingAs($this->member)->postJson(route('drive.prepare'), ['name' => 'a.pdf', 'size' => 10])->assertStatus(503);
        $this->actingAs($this->member)->get(route('drive.index'))->assertInertia(fn (Assert $page) => $page->where('configured', false));
    }

    public function test_a_failed_browser_upload_releases_its_space_at_once()
    {
        $id = $this->actingAs($this->member)->postJson(route('drive.prepare'), ['name' => 'a.pdf', 'size' => 5 * DriveService::MB])->json('id');
        $this->assertSame(5 * DriveService::MB, app(DriveService::class)->usedBytes($this->member));

        $this->actingAs($this->admin)->postJson(route('drive.cancel', $id))->assertForbidden();
        $this->actingAs($this->member)->postJson(route('drive.cancel', $id))->assertOk();

        $this->assertSame(0, app(DriveService::class)->usedBytes($this->member));
        $this->assertSame(0, DriveFile::withTrashed()->count());
    }

    public function test_an_empty_acl_setting_uses_the_app_default()
    {
        config(['services.uploadthing.acl' => '']);
        $this->actingAs($this->member)->postJson(route('drive.prepare'), ['name' => 'a.pdf', 'size' => 10])->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v7/prepareUpload') && ! isset($r['acl']));
    }

    public function test_a_drive_file_can_be_attached_to_a_task_without_copying()
    {
        $ws = Workspace::factory()->create();
        $ws->members()->attach($this->member->id, ['role' => Workspace::ROLE_MEMBER]);
        $task = Task::factory()->create(['workspace_id' => $ws->id]);
        $file = DriveFile::factory()->create(['owner_id' => $this->member->id, 'name' => 'Logo final.png', 'mime' => 'image/png']);

        $this->actingAs($this->member)->post(route('attachments.from-drive'), ['attachable_type' => 'task', 'attachable_id' => $task->id, 'drive_file_id' => $file->id])
            ->assertSessionHasNoErrors();
        $attachment = Attachment::firstOrFail();
        $this->assertSame('uploadthing', $attachment->disk);
        $this->assertSame($file->storage_key, $attachment->key);
        $this->assertSame($file->id, $attachment->drive_file_id);

        // Opening it goes straight to a short-lived UploadThing link.
        $this->actingAs($this->member)->get(app(AttachmentService::class)->url($attachment))->assertRedirect('https://app123.ufs.sh/f/KEY123?sig=1');

        // The drive file can't be deleted while attached; removing the attachment keeps the bytes.
        $this->actingAs($this->member)->delete(route('drive.destroy', $file))->assertSessionHasErrors('file');
        $this->actingAs($this->member)->delete(route('attachments.destroy', $attachment))->assertRedirect();
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/v6/deleteFiles'));
        $this->actingAs($this->member)->delete(route('drive.destroy', $file))->assertSessionHasNoErrors();

        // Someone else's private file can't be attached.
        $other = DriveFile::factory()->create();
        $this->actingAs($this->member)->post(route('attachments.from-drive'), ['attachable_type' => 'task', 'attachable_id' => $task->id, 'drive_file_id' => $other->id])
            ->assertForbidden();
    }
}
