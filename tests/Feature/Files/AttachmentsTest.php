<?php

namespace Tests\Feature\Files;

use App\Domain\Files\AttachmentService;
use App\Domain\Settings\AppSettings;
use App\Models\Attachment;
use App\Models\Project;
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
 * P8: attachments - server-side type/size checks, sanitised names, private
 * storage, signed expiring links, permissions, admin settings.
 */
class AttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $member;

    private User $colleague;

    private User $lead;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');

        $this->ws = Workspace::factory()->create();
        $this->member = User::factory()->withRole('member')->create();
        $this->colleague = User::factory()->withRole('member')->create();
        $this->lead = User::factory()->withRole('member')->create();
        $this->ws->members()->attach([
            $this->member->id => ['role' => Workspace::ROLE_MEMBER],
            $this->colleague->id => ['role' => Workspace::ROLE_MEMBER],
            $this->lead->id => ['role' => Workspace::ROLE_LEAD],
        ]);
        $this->task = Task::factory()->create(['workspace_id' => $this->ws->id]);
    }

    private function upload(User $user, UploadedFile $file, string $type = 'task', ?int $id = null)
    {
        return $this->actingAs($user)->post(route('attachments.store'), [
            'attachable_type' => $type,
            'attachable_id' => $id ?? $this->task->id,
            'file' => $file,
        ]);
    }

    private function pdf(string $name = 'Brief.pdf', int $kb = 20): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    public function test_upload_is_stored_privately_with_a_sanitised_name()
    {
        $this->upload($this->member, $this->pdf('../../Q4 Brief (final)!!.pdf'))->assertSessionHasNoErrors()->assertRedirect();

        $a = Attachment::firstOrFail();
        $this->assertSame('Q4 Brief final.pdf', $a->original_name);
        $this->assertSame('application/pdf', $a->mime);
        $this->assertSame($this->ws->id, $a->workspace_id);
        $this->assertSame('task', $a->attachable_type);
        $this->assertStringStartsWith('attachments/'.$this->ws->id.'/', $a->key);
        $this->assertStringNotContainsString('Brief', $a->key);
        Storage::disk('local')->assertExists($a->key);
    }

    public function test_images_get_a_webp_thumbnail_when_gd_supports_it()
    {
        if (! function_exists('imagepng')) {
            $this->markTestSkipped('PHP GD extension not enabled (enable extension=gd in php.ini for thumbnails).');
        }

        $this->upload($this->member, UploadedFile::fake()->image('photo.png', 1200, 800))->assertSessionHasNoErrors();

        $a = Attachment::firstOrFail();
        if (function_exists('imagewebp')) {
            $this->assertNotNull($a->thumbnail_key);
            Storage::disk('local')->assertExists((string) $a->thumbnail_key);
        } else {
            $this->assertNull($a->thumbnail_key);
        }
    }

    public function test_disallowed_types_and_oversized_files_are_rejected()
    {
        $this->upload($this->member, UploadedFile::fake()->create('setup.exe', 10, 'application/x-msdownload'))
            ->assertSessionHasErrors('file');

        app(AppSettings::class)->set('files.max_mb', 1);
        $this->upload($this->member, $this->pdf('big.pdf', 2048))->assertSessionHasErrors('file');

        // An admin can turn a whole group off.
        app(AppSettings::class)->set('files.groups', ['images']);
        $this->upload($this->member, $this->pdf())->assertSessionHasErrors('file');

        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_people_outside_the_workspace_cannot_upload_or_download()
    {
        $outsider = User::factory()->withRole('member')->create();
        $this->upload($outsider, $this->pdf())->assertForbidden();

        $this->upload($this->member, $this->pdf());
        $url = app(AttachmentService::class)->url(Attachment::firstOrFail());

        $this->actingAs($outsider)->get($url)->assertForbidden();
        $this->actingAs($this->colleague)->get($url)->assertOk();
    }

    public function test_links_are_signed_and_expire()
    {
        $this->upload($this->member, $this->pdf());
        $a = Attachment::firstOrFail();
        $service = app(AttachmentService::class);

        $this->actingAs($this->member)->get($service->url($a))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('attachment;', (string) $this->actingAs($this->member)->get($service->url($a, download: true))->headers->get('Content-Disposition'));

        // Unsigned or tampered links are refused.
        $this->actingAs($this->member)->get(route('files.show', $a))->assertForbidden();
        $this->actingAs($this->member)->get($service->url($a).'x')->assertForbidden();

        $expired = $service->url($a);
        $this->travel(11)->minutes();
        $this->actingAs($this->member)->get($expired)->assertForbidden();
    }

    public function test_only_the_uploader_or_a_lead_can_delete()
    {
        $this->upload($this->member, $this->pdf());
        $a = Attachment::firstOrFail();

        $this->actingAs($this->colleague)->delete(route('attachments.destroy', $a))->assertForbidden();
        $this->actingAs($this->lead)->delete(route('attachments.destroy', $a))->assertRedirect();

        $this->assertSoftDeleted($a);
        Storage::disk('local')->assertMissing($a->key);
    }

    public function test_task_and_project_pages_list_their_files()
    {
        $project = Project::factory()->create(['workspace_id' => $this->ws->id]);
        $this->upload($this->member, $this->pdf('Task file.pdf'));
        $this->upload($this->member, $this->pdf('Project file.pdf'), 'project', $project->id);

        $this->actingAs($this->colleague)->get(route('workspaces.tasks.show', [$this->ws, $this->task]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('attachments', 1)
                ->where('attachments.0.name', 'Task file.pdf')
                ->where('attachments.0.can_delete', false)
                ->where('fileLimits.max_mb', 25));

        $this->actingAs($this->member)->get(route('workspaces.projects.show', [$this->ws, $project]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('attachments', 1)
                ->where('attachments.0.name', 'Project file.pdf')
                ->where('attachments.0.can_delete', true));
    }

    public function test_admins_set_the_size_limit_and_types()
    {
        $this->actingAs($this->member)->get(route('admin.files.edit'))->assertForbidden();

        $admin = User::factory()->withRole('admin')->create();
        $this->actingAs($admin)->put(route('admin.files.update'), ['max_mb' => 50, 'groups' => ['images', 'pdf', 'video']])
            ->assertSessionHasNoErrors();

        $service = app(AttachmentService::class);
        $this->assertSame(50, $service->maxMb());
        $this->assertContains('mp4', $service->allowedExtensions());
        $this->assertNotContains('docx', $service->allowedExtensions());

        $this->actingAs($admin)->put(route('admin.files.update'), ['max_mb' => 50, 'groups' => ['exe']])
            ->assertSessionHasErrors('groups.0');
    }
}
