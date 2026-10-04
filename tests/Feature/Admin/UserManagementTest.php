<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\User;
use App\Models\UserAccessLink;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
    }

    private function admin(): User
    {
        return User::factory()->withRole('admin')->create();
    }

    public function test_members_cannot_open_the_admin_area()
    {
        $member = User::factory()->withRole('member')->create();

        $this->actingAs($member)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($member)->get(route('admin.users.create'))->assertForbidden();
        $this->actingAs($member)->post(route('admin.users.store'), [])->assertForbidden();
        $this->actingAs($member)->get(route('admin.roles.index'))->assertForbidden();
        $this->actingAs($member)->get(route('admin.activity.index'))->assertForbidden();
    }

    public function test_dept_leads_can_view_but_not_manage_members()
    {
        $lead = User::factory()->withRole('dept-lead')->create();
        $other = User::factory()->withRole('member')->create();

        $this->actingAs($lead)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($lead)->get(route('admin.users.edit', $other))->assertForbidden();
    }

    public function test_admin_creates_a_member_and_receives_a_one_time_setup_link()
    {
        $admin = $this->admin();
        $seo = Department::where('slug', 'seo')->firstOrFail();
        $marketing = Department::where('slug', 'marketing')->firstOrFail();

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Ana Reyes',
            'email' => 'Ana@Agency.test',
            'title' => 'SEO Specialist',
            'role' => 'member',
            'primary_department_id' => $seo->id,
            'department_ids' => [$marketing->id],
        ]);

        $user = User::where('email', 'ana@agency.test')->firstOrFail();
        $response->assertRedirect(route('admin.users.edit', $user));
        $response->assertSessionHas('issuedSecret', fn (array $s) => $s['purpose'] === 'setup'
            && str_contains($s['value'], '/access/'));

        $this->assertTrue($user->hasRole('member'));
        $this->assertEqualsCanonicalizing([$seo->id, $marketing->id], $user->departments()->pluck('departments.id')->all());
        $this->assertSame(1, $user->accessLinks()->count());
        $this->assertTrue(ActivityLog::where('action', 'user.created')->where('subject_id', $user->id)->exists());
    }

    public function test_admins_cannot_grant_super_admin()
    {
        $this->actingAs($this->admin())->post(route('admin.users.store'), [
            'name' => 'Sneaky',
            'email' => 'sneaky@agency.test',
            'role' => 'super-admin',
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@agency.test']);
    }

    public function test_admins_cannot_grant_a_role_with_permissions_they_lack()
    {
        // Admin has no billing.manage, so cannot create a Finance user...
        $this->actingAs($this->admin())->post(route('admin.users.store'), [
            'name' => 'Money Person',
            'email' => 'money@agency.test',
            'role' => 'finance',
        ])->assertSessionHasErrors('role');

        // ...nor take over an existing Finance account.
        $finance = User::factory()->withRole('finance')->create();
        $this->actingAs($this->admin())
            ->post(route('admin.users.temporary-password', $finance))
            ->assertForbidden();
    }

    public function test_admins_cannot_edit_a_super_admin()
    {
        $super = User::factory()->withRole('super-admin')->create();

        $this->actingAs($this->admin())->get(route('admin.users.edit', $super))->assertForbidden();
        $this->actingAs($this->admin())->post(route('admin.users.deactivate', $super))->assertForbidden();
    }

    public function test_deactivating_signs_the_member_out_and_revokes_links()
    {
        $admin = $this->admin();
        $member = User::factory()->withRole('member')->create();
        DB::table('sessions')->insert([
            'id' => 'member-session', 'user_id' => $member->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => '', 'last_activity' => time(),
        ]);
        $member->accessLinks()->create([
            'purpose' => UserAccessLink::PURPOSE_RESET,
            'token_hash' => hash('sha256', 'x'),
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($admin)->post(route('admin.users.deactivate', $member))->assertRedirect();

        $member->refresh();
        $this->assertFalse($member->is_active);
        $this->assertNotNull($member->deactivated_at);
        $this->assertDatabaseMissing('sessions', ['id' => 'member-session']);
        $this->assertSame(0, $member->accessLinks()->open()->count());
    }

    public function test_nobody_can_deactivate_themselves()
    {
        $super = User::factory()->withRole('super-admin')->create();

        $this->actingAs($super)->post(route('admin.users.deactivate', $super))->assertStatus(422);
        $this->assertTrue($super->refresh()->is_active);
    }

    public function test_temporary_password_forces_a_change_at_next_login()
    {
        $member = User::factory()->withRole('member')->create();

        $response = $this->actingAs($this->admin())
            ->post(route('admin.users.temporary-password', $member));

        $response->assertSessionHas('issuedSecret', fn (array $s) => $s['type'] === 'password');
        $temporary = session('issuedSecret')['value'];

        $member->refresh();
        $this->assertTrue($member->must_change_password);
        $this->assertTrue(Hash::check($temporary, $member->password));
    }

    public function test_admin_can_reset_two_factor()
    {
        $member = User::factory()->withRole('member')->withTwoFactor()->create();

        $this->actingAs($this->admin())->post(route('admin.users.reset-two-factor', $member))->assertRedirect();

        $this->assertNull($member->refresh()->two_factor_confirmed_at);
    }

    public function test_admins_cannot_change_their_own_role()
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'member',
        ])->assertSessionHasErrors('role');

        $this->assertTrue($admin->refresh()->hasRole('admin'));
    }

    public function test_the_user_list_can_be_filtered()
    {
        $admin = $this->admin();
        User::factory()->withRole('member')->create(['name' => 'Findable Person']);
        User::factory()->withRole('member')->inactive()->create(['name' => 'Gone Person']);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['search' => 'findable', 'status' => 'active']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/users/Index')
                ->has('users.data', 1)
                ->where('users.data.0.name', 'Findable Person'));
    }
}
