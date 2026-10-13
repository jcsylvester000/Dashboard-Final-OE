<?php

namespace Tests\Feature\Admin;

use App\Models\SavedView;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Build 8e / brutal scenario H5: Data Privacy Act export and erasure for a team member.
 */
class PersonalDataTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $bea;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);

        $this->admin = User::factory()->withRole('admin')->create();
        $this->bea = User::factory()->withRole('member')->create(['name' => 'Bea Villanueva', 'email' => 'bea@example.test', 'title' => 'Designer']);
        $this->ws = Workspace::factory()->create(['name' => 'Brewhouse']);
        $this->ws->members()->attach($this->bea->id, ['role' => 'member']);
    }

    public function test_export_contains_the_persons_data_and_is_logged()
    {
        $task = Task::factory()->create(['workspace_id' => $this->ws->id, 'assignee_id' => $this->bea->id, 'title' => 'Holiday ad set']);
        TimeEntry::create(['user_id' => $this->bea->id, 'workspace_id' => $this->ws->id, 'task_id' => $task->id, 'entry_date' => '2026-10-01', 'minutes' => 90, 'is_billable' => true]);

        $response = $this->actingAs($this->admin)->get(route('admin.users.export-data', $this->bea))->assertOk();

        $this->assertStringContainsString('attachment; filename="personal-data-'.$this->bea->id, (string) $response->headers->get('Content-Disposition'));
        $response->assertJsonPath('profile.email', 'bea@example.test')
            ->assertJsonPath('client_memberships.0.client', 'Brewhouse')
            ->assertJsonPath('tasks_assigned.0.title', 'Holiday ad set')
            ->assertJsonPath('time_entries.0.minutes', 90);
        $this->assertDatabaseHas('activity_log', ['action' => 'user.data-exported', 'subject_id' => $this->bea->id, 'actor_id' => $this->admin->id]);

        $this->actingAs($this->bea)->get(route('admin.users.export-data', $this->bea))->assertForbidden();
    }

    public function test_anonymise_needs_a_deactivated_account_and_the_typed_name()
    {
        $this->actingAs($this->admin)->post(route('admin.users.anonymise', $this->bea), ['confirm_name' => 'Bea Villanueva'])
            ->assertSessionHasErrors('user');

        $this->bea->forceFill(['is_active' => false])->save();
        $this->actingAs($this->admin)->post(route('admin.users.anonymise', $this->bea), ['confirm_name' => 'Bea'])
            ->assertSessionHasErrors('confirm_name');

        $this->assertSame('Bea Villanueva', $this->bea->fresh()->name);
    }

    public function test_anonymise_erases_identity_but_keeps_work_records()
    {
        $task = Task::factory()->create(['workspace_id' => $this->ws->id, 'assignee_id' => $this->bea->id]);
        $entry = TimeEntry::create(['user_id' => $this->bea->id, 'workspace_id' => $this->ws->id, 'task_id' => $task->id, 'entry_date' => '2026-10-01', 'minutes' => 60, 'is_billable' => true]);
        SavedView::create(['workspace_id' => $this->ws->id, 'user_id' => $this->bea->id, 'name' => 'Mine', 'page' => 'list', 'filters' => [], 'shared' => false]);
        DB::table('activity_log')->insert(['actor_id' => $this->bea->id, 'action' => 'auth.login', 'ip_address' => '203.0.113.9', 'user_agent' => 'Firefox', 'created_at' => now()]);
        $this->bea->forceFill(['is_active' => false])->save();

        $this->actingAs($this->admin)->post(route('admin.users.anonymise', $this->bea), ['confirm_name' => 'Bea Villanueva'])
            ->assertSessionHasNoErrors();

        $bea = $this->bea->fresh();
        $this->assertSame('Former member #'.$bea->id, $bea->name);
        $this->assertSame('former-'.$bea->id.'@anonymised.invalid', $bea->email);
        $this->assertNull($bea->title);
        $this->assertSame(0, SavedView::query()->count());
        $this->assertDatabaseMissing('activity_log', ['actor_id' => $bea->id, 'ip_address' => '203.0.113.9']);
        $this->assertSame($bea->id, $entry->fresh()->user_id);                 // time kept for billing
        $this->assertSame($bea->id, $task->fresh()->assignee_id);
        $this->assertDatabaseHas('activity_log', ['action' => 'user.anonymised', 'subject_id' => $bea->id]);

        // An anonymised account can't come back.
        $this->actingAs($this->admin)->post(route('admin.users.reactivate', $bea))->assertStatus(422);
    }
}
