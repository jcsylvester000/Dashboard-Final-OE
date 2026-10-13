<?php

namespace Tests\Feature\Work;

use App\Domain\Files\AttachmentService;
use App\Domain\Files\UploadThing\UploadThingClient;
use App\Models\DriveFile;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Findings batches 8-9: task files on UploadThing only when private (R-03/D-06), drive files
 * streamed when public-by-URL (D-06), board column cap (B-21), time follows the task (S3-18),
 * client status page wording (R-22), custom fields + status names (B-07).
 */
class FindingsBatchEightNineTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $lead;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        $this->travelTo('2026-10-13 09:00:00');

        $this->ws = Workspace::factory()->create();
        $this->lead = User::factory()->withRole('member')->create();
        $this->member = User::factory()->withRole('member')->create(['name' => 'Bea Villanueva']);
        $this->ws->members()->attach([$this->lead->id => ['role' => Workspace::ROLE_LEAD], $this->member->id => ['role' => Workspace::ROLE_MEMBER]]);
    }

    private function fakeUploadThing(string $acl): void
    {
        config([
            'services.uploadthing.token' => base64_encode((string) json_encode(['apiKey' => 'sk_test_fake', 'appId' => 'app123', 'regions' => ['sea1']])),
            'services.uploadthing.acl' => $acl,
            'inertia.ssr.enabled' => false,
        ]);
        $this->app->forgetInstance(UploadThingClient::class);
        Http::preventStrayRequests();
        Http::fake([
            'api.uploadthing.com/v7/prepareUpload' => Http::response(['key' => 'ATTKEY', 'url' => 'https://sea1.ingest.uploadthing.com/ATTKEY?signature=x']),
            'sea1.ingest.uploadthing.com/*' => Http::response(['ok' => true]),
            'api.uploadthing.com/v6/requestFileAccess' => Http::response(['ufsUrl' => 'https://app123.ufs.sh/f/KEY123?sig=1']),
            'app123.ufs.sh/*' => Http::response('FILEBYTES', 200),
        ]);
    }

    public function test_task_files_go_to_uploadthing_only_when_files_there_are_private()
    {
        $task = Task::factory()->create(['workspace_id' => $this->ws->id]);

        config(['files.driver' => 'auto']);
        $this->fakeUploadThing('');
        $local = app(AttachmentService::class)->attach($task, $this->ws->id, UploadedFile::fake()->create('brief.pdf', 4, 'application/pdf'), $this->lead);
        $this->assertSame('local', $local->disk, 'public-by-URL plan: keep client files on the private disk');

        $this->fakeUploadThing('private');
        $remote = app(AttachmentService::class)->attach($task, $this->ws->id, UploadedFile::fake()->create('contract.pdf', 4, 'application/pdf'), $this->lead);
        $this->assertSame('uploadthing', $remote->disk);
        $this->assertSame('ATTKEY', $remote->key);

        // Opening it hands out a short-lived link.
        $this->actingAs($this->member)->get(app(AttachmentService::class)->url($remote))
            ->assertRedirect('https://app123.ufs.sh/f/KEY123?sig=1');
    }

    public function test_public_by_url_drive_files_are_streamed_so_the_permanent_url_never_reaches_the_browser()
    {
        $this->fakeUploadThing('');
        $file = DriveFile::factory()->create(['owner_id' => $this->member->id, 'name' => 'Ad.png', 'mime' => 'image/png', 'size' => 9]);

        $res = $this->actingAs($this->member)->get(route('drive.open', $file));
        $res->assertOk()->assertHeader('Content-Security-Policy', 'sandbox')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('FILEBYTES', $res->streamedContent());
        $this->assertStringNotContainsString('ufs.sh', (string) $res->headers->get('Location'));

        // Someone without access still can't open it.
        $this->actingAs($this->lead)->get(route('drive.open', $file))->assertForbidden();
    }

    public function test_board_ships_a_capped_number_of_cards_per_column_with_totals()
    {
        Task::factory()->count(60)->create(['workspace_id' => $this->ws->id, 'status_id' => TaskStatus::idFor('todo')]);
        Task::factory()->count(3)->create(['workspace_id' => $this->ws->id, 'status_id' => TaskStatus::idFor('in-progress')]);
        $todo = TaskStatus::idFor('todo');

        $this->actingAs($this->member)->get(route('workspaces.tasks.board', $this->ws))
            ->assertInertia(fn ($page) => $page->has('tasks', 53)->where("columnTotals.$todo", 60)->where('perColumn', 50));

        $this->actingAs($this->member)->get(route('workspaces.tasks.board', [$this->ws, 'per_column' => 150]))
            ->assertInertia(fn ($page) => $page->has('tasks', 63));
    }

    public function test_moving_a_task_to_another_project_moves_its_unbilled_time_only()
    {
        $old = Project::factory()->create(['workspace_id' => $this->ws->id]);
        $new = Project::factory()->create(['workspace_id' => $this->ws->id]);
        $task = Task::factory()->create(['workspace_id' => $this->ws->id, 'project_id' => $old->id]);
        $base = ['user_id' => $this->member->id, 'workspace_id' => $this->ws->id, 'task_id' => $task->id, 'project_id' => $old->id, 'entry_date' => '2026-10-05', 'is_billable' => true];
        $open = TimeEntry::create([...$base, 'minutes' => 90]);
        $billed = TimeEntry::create([...$base, 'minutes' => 60]);
        $billed->forceFill(['locked_at' => now()])->save();

        $this->actingAs($this->lead)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['project_id' => $new->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data.toast', fn ($t) => str_contains($t['message'], '1h 30m') && str_contains($t['message'], '1h already billed'));

        $this->assertSame($new->id, $open->fresh()?->project_id);
        $this->assertSame($old->id, $billed->fresh()?->project_id);
    }

    public function test_client_status_page_uses_simple_wording_unless_the_lead_picks_detailed()
    {
        $project = Project::factory()->create(['workspace_id' => $this->ws->id, 'status_token' => str_repeat('k', 40)]);
        Task::factory()->create(['workspace_id' => $this->ws->id, 'project_id' => $project->id, 'title' => 'Banner set', 'due_on' => '2026-10-10', 'status_id' => TaskStatus::idFor('in-review')]);
        $this->ws->forceFill(['status_labels' => [(string) TaskStatus::idFor('in-review') => 'With client']])->save();

        $this->get(route('project-status.show', str_repeat('k', 40)))
            ->assertOk()->assertSee('Banner set')->assertSee('In progress')->assertDontSee('was due')->assertDontSee('In Review');

        $this->actingAs($this->member)->put(route('workspaces.projects.status-detail', [$this->ws, $project]), ['status_detail' => 'detailed'])->assertForbidden();
        $this->actingAs($this->lead)->put(route('workspaces.projects.status-detail', [$this->ws, $project]), ['status_detail' => 'detailed'])->assertSessionHasNoErrors();
        auth()->logout();

        $this->get(route('project-status.show', str_repeat('k', 40)))->assertSee('With client')->assertSee('was due');
    }

    public function test_leads_define_custom_fields_and_status_names_and_tasks_validate_values()
    {
        $this->actingAs($this->member)->put(route('workspaces.task-fields.update', $this->ws), ['fields' => []])->assertForbidden();

        $this->actingAs($this->lead)->put(route('workspaces.task-fields.update', $this->ws), ['fields' => [
            ['label' => 'Ad budget', 'type' => 'number'],
            ['label' => 'Branch', 'type' => 'select', 'options' => ['Makati', 'BGC', '']],
            ['label' => 'Client approved', 'type' => 'checkbox'],
        ]])->assertSessionHasNoErrors();
        $fields = $this->ws->fresh()?->custom_fields ?? [];
        $this->assertSame(['ad_budget', 'branch', 'client_approved'], array_column($fields, 'key'));
        $this->assertSame(['Makati', 'BGC'], $fields[1]['options'] ?? null);

        // Renaming keeps the key (and so the saved values).
        $this->actingAs($this->lead)->put(route('workspaces.task-fields.update', $this->ws), ['fields' => [
            ['key' => 'ad_budget', 'label' => 'Media budget', 'type' => 'number'],
            ['key' => 'branch', 'label' => 'Branch', 'type' => 'select'],
        ]])->assertSessionHasErrors('fields.1.options');

        $task = Task::factory()->create(['workspace_id' => $this->ws->id]);
        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['custom_values' => ['ad_budget' => '15000', 'branch' => 'BGC', 'client_approved' => 1]])
            ->assertSessionHasNoErrors();
        $this->assertSame('BGC', $task->fresh()?->custom_values['branch'] ?? null);
        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['custom_values' => ['branch' => 'Cebu']])
            ->assertSessionHasErrors('custom_values.branch');
        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['custom_values' => ['secret' => 'x']])
            ->assertSessionHasErrors('custom_values');

        // Status names show on the board and task rows for this client only.
        $review = TaskStatus::idFor('in-review');
        $this->actingAs($this->lead)->put(route('workspaces.status-labels.update', $this->ws), ['labels' => [(string) $review => 'With client', '999' => 'Nope']])
            ->assertSessionHasNoErrors();
        $this->assertSame([(string) $review => 'With client'], $this->ws->fresh()?->status_labels);
        $task->forceFill(['status_id' => $review])->save();
        $this->actingAs($this->member)->get(route('workspaces.tasks.board', $this->ws))
            ->assertInertia(fn ($page) => $page->where('statuses.3.name', 'With client')->where('tasks.0.status.name', 'With client'));
        $this->actingAs($this->member)->get(route('workspaces.tasks.show', [$this->ws, $task]))
            ->assertInertia(fn ($page) => $page->has('customFields', 3)->where('task.custom_values.ad_budget', '15000'));
    }
}
