<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesAndDepartmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
    }

    public function test_seeders_create_the_five_departments_and_roles()
    {
        $this->assertEqualsCanonicalizing(
            ['development', 'research', 'product', 'marketing', 'seo'],
            Department::pluck('slug')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['super-admin', 'admin', 'finance', 'dept-lead', 'member', 'viewer'],
            Role::pluck('name')->all(),
        );
    }

    public function test_only_super_admin_can_edit_role_permissions_by_default()
    {
        $admin = User::factory()->withRole('admin')->create();
        $super = User::factory()->withRole('super-admin')->create();
        $member = Role::findByName('member');

        $this->actingAs($admin)->get(route('admin.roles.index'))->assertForbidden();

        $this->actingAs($super)
            ->put(route('admin.roles.update', $member), ['permissions' => ['users.view']])
            ->assertRedirect();

        $this->assertTrue($member->fresh()->hasPermissionTo('users.view'));
    }

    public function test_the_super_admin_role_is_locked()
    {
        $super = User::factory()->withRole('super-admin')->create();

        $this->actingAs($super)
            ->put(route('admin.roles.update', Role::findByName('super-admin')), ['permissions' => []])
            ->assertForbidden();
    }

    public function test_unknown_permissions_are_rejected()
    {
        $super = User::factory()->withRole('super-admin')->create();

        $this->actingAs($super)
            ->put(route('admin.roles.update', Role::findByName('member')), ['permissions' => ['delete.everything']])
            ->assertSessionHasErrors('permissions.0');
    }

    public function test_admin_can_add_and_edit_departments()
    {
        $admin = User::factory()->withRole('admin')->create();

        $this->actingAs($admin)->post(route('admin.departments.store'), [
            'name' => 'Design',
            'color' => 'cyan',
        ])->assertRedirect();

        $design = Department::where('slug', 'design')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.departments.update', $design), [
            'name' => 'Design & UX',
            'color' => 'orange',
            'lead_user_id' => $admin->id,
        ])->assertRedirect();

        $design->refresh();
        $this->assertSame('Design & UX', $design->name);
        $this->assertSame($admin->id, $design->lead_user_id);
    }

    public function test_activity_log_rows_cannot_be_changed_or_deleted()
    {
        $log = ActivityLog::create(['action' => 'test.event']);

        $this->expectException(LogicException::class);
        $log->update(['action' => 'tampered']);
    }
}
