<?php

namespace Tests\Feature\Work;

use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Finding R-10: one bulk action sends each person one grouped alert, not one per task.
 */
class BulkAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_reassign_sends_one_grouped_alert_and_single_edits_stay_single()
    {
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        $ws = Workspace::factory()->create(['key' => 'BRW']);
        $jasmine = User::factory()->withRole('member')->create(['name' => 'Jasmine']);
        $nico = User::factory()->withRole('member')->create(['name' => 'Nico']);
        $ws->members()->attach([$jasmine->id => ['role' => 'lead'], $nico->id => ['role' => 'member']]);
        $tasks = Task::factory()->count(4)->create(['workspace_id' => $ws->id]);

        $this->actingAs($jasmine)->post(route('workspaces.tasks.bulk', $ws), [
            'ids' => $tasks->pluck('id')->all(), 'action' => 'update', 'assignee_id' => (string) $nico->id,
        ])->assertSessionHasNoErrors();

        $alerts = DB::table('notifications')->where('notifiable_id', $nico->id)->where('kind', 'assigned')->get();
        $this->assertCount(1, $alerts);
        $data = json_decode($alerts[0]->data, true);
        $this->assertSame('Jasmine assigned you 4 tasks', $data['title']);
        $this->assertStringContainsString('and 1 more', $data['body']);
        $this->assertStringContainsString('assignee=me', $data['url']);

        // A normal single edit is unchanged.
        $one = Task::factory()->create(['workspace_id' => $ws->id]);
        $this->actingAs($jasmine)->put(route('workspaces.tasks.update', [$ws, $one]), ['assignee_id' => $nico->id]);
        // Notification ids are UUIDs (not ordered), so look for the alert by content.
        $titles = DB::table('notifications')->where('notifiable_id', $nico->id)->pluck('data')->map(fn ($d) => json_decode((string) $d, true)['title'])->all();
        $this->assertContains('Jasmine assigned you a task', $titles);
        $this->assertSame(2, DB::table('notifications')->where('notifiable_id', $nico->id)->count());

        // A one-task bulk action reads like a single edit.
        $two = Task::factory()->create(['workspace_id' => $ws->id]);
        $this->actingAs($jasmine)->post(route('workspaces.tasks.bulk', $ws), ['ids' => [$two->id], 'action' => 'update', 'assignee_id' => (string) $nico->id]);
        $this->assertSame(3, DB::table('notifications')->where('notifiable_id', $nico->id)->count());
    }
}
