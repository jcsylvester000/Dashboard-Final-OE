<?php

namespace Tests\Feature\Workspaces;

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
    }

    private function memberOf(Workspace $workspace, string $role = Workspace::ROLE_MEMBER): User
    {
        $user = User::factory()->withRole('member')->create();
        $workspace->members()->attach($user->id, ['role' => $role]);

        return $user;
    }

    public function test_members_only_see_their_own_workspaces()
    {
        $mine = Workspace::factory()->create(['name' => 'Mine Co']);
        $other = Workspace::factory()->create(['name' => 'Other Co']);
        $user = $this->memberOf($mine);

        $this->actingAs($user)->get(route('workspaces.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('workspaces/Index')
                ->has('workspaces', 1)
                ->where('workspaces.0.name', 'Mine Co'));

        $this->actingAs($user)->get(route('workspaces.show', $other))->assertForbidden();
        $this->actingAs($user)->get(route('workspaces.projects.index', $other))->assertForbidden();
        $this->actingAs($user)->get(route('workspaces.members.index', $other))->assertForbidden();
    }

    public function test_the_switcher_only_lists_visible_workspaces()
    {
        $mine = Workspace::factory()->create();
        Workspace::factory()->create();
        $user = $this->memberOf($mine);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->has('workspaceNav.items', 1));
    }

    public function test_view_all_permission_gives_read_only_access()
    {
        $workspace = Workspace::factory()->create();
        $finance = User::factory()->withRole('finance')->create(); // has workspaces.view-all

        $this->actingAs($finance)->get(route('workspaces.show', $workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('workspace.myRole', 'guest')
                ->where('workspace.can.contribute', false));

        $this->actingAs($finance)->post(route('workspaces.projects.store', $workspace), [
            'name' => 'Nope', 'type' => 'project', 'status' => 'planning',
        ])->assertForbidden();
    }

    public function test_only_workspace_managers_can_create_workspaces_and_become_owner()
    {
        $member = User::factory()->withRole('member')->create();
        $this->actingAs($member)->post(route('workspaces.store'), ['name' => 'X', 'color' => 'blue'])
            ->assertForbidden();

        $admin = User::factory()->withRole('admin')->create();
        $this->actingAs($admin)->post(route('workspaces.store'), [
            'name' => 'Acme Realty', 'color' => 'blue', 'industry' => 'Real estate',
        ])->assertRedirect();

        $workspace = Workspace::where('name', 'Acme Realty')->firstOrFail();
        $this->assertSame('acme-realty', $workspace->slug);
        $this->assertSame('owner', $workspace->members()->whereKey($admin->id)->first()?->getRelation('membership')->getAttribute('role'));
    }

    public function test_guests_cannot_create_projects_but_members_can()
    {
        $workspace = Workspace::factory()->create();
        $guest = $this->memberOf($workspace, Workspace::ROLE_GUEST);
        $member = $this->memberOf($workspace);

        $payload = ['name' => 'Spring campaign', 'type' => 'campaign', 'status' => 'planning'];

        $this->actingAs($guest)->post(route('workspaces.projects.store', $workspace), $payload)->assertForbidden();
        $this->actingAs($member)->post(route('workspaces.projects.store', $workspace), $payload)->assertRedirect();

        $this->assertDatabaseHas('projects', ['workspace_id' => $workspace->id, 'name' => 'Spring campaign', 'type' => 'campaign']);
    }

    public function test_only_owners_manage_members()
    {
        $workspace = Workspace::factory()->create();
        $owner = $this->memberOf($workspace, Workspace::ROLE_OWNER);
        $lead = $this->memberOf($workspace, Workspace::ROLE_LEAD);
        $newbie = User::factory()->withRole('member')->create();

        $this->actingAs($lead)->post(route('workspaces.members.store', $workspace), [
            'user_id' => $newbie->id, 'role' => 'member',
        ])->assertForbidden();

        $this->actingAs($owner)->post(route('workspaces.members.store', $workspace), [
            'user_id' => $newbie->id, 'role' => 'member',
        ])->assertRedirect();

        $this->assertDatabaseHas('workspace_user', ['workspace_id' => $workspace->id, 'user_id' => $newbie->id, 'role' => 'member']);
    }

    public function test_the_last_owner_cannot_be_removed_or_demoted()
    {
        $workspace = Workspace::factory()->create();
        $owner = $this->memberOf($workspace, Workspace::ROLE_OWNER);

        $this->actingAs($owner)
            ->delete(route('workspaces.members.destroy', [$workspace, $owner]))
            ->assertSessionHasErrors('role');

        $this->actingAs($owner)
            ->put(route('workspaces.members.update', [$workspace, $owner]), ['role' => 'member'])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseHas('workspace_user', ['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'role' => 'owner']);
    }

    public function test_removing_a_member_also_removes_them_from_projects()
    {
        $workspace = Workspace::factory()->create();
        $owner = $this->memberOf($workspace, Workspace::ROLE_OWNER);
        $member = $this->memberOf($workspace);
        $project = $workspace->projects()->create(['name' => 'Site', 'type' => 'project', 'status' => 'active', 'lead_user_id' => $member->id]);
        $project->members()->attach($member->id);

        $this->actingAs($owner)->delete(route('workspaces.members.destroy', [$workspace, $member]))->assertRedirect();

        $this->assertDatabaseMissing('project_user', ['project_id' => $project->id, 'user_id' => $member->id]);
        $this->assertNull($project->fresh()->lead_user_id);
    }

    public function test_archived_workspaces_are_read_only()
    {
        $workspace = Workspace::factory()->create(['status' => 'archived']);
        $member = $this->memberOf($workspace);

        $this->actingAs($member)->get(route('workspaces.show', $workspace))->assertOk();
        $this->actingAs($member)->post(route('workspaces.projects.store', $workspace), [
            'name' => 'Late', 'type' => 'project', 'status' => 'planning',
        ])->assertForbidden();
    }
}
