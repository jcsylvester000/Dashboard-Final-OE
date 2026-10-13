<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResetLinkCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prints_a_working_reset_link_and_refuses_unknown_or_inactive_accounts()
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->withRole('admin')->create(['email' => 'admin@overeasy.local']);

        $this->artisan('user:reset-link', ['email' => 'ADMIN@overeasy.local'])->assertSuccessful();
        $this->assertSame(1, $admin->accessLinks()->open()->count());

        $this->artisan('user:reset-link', ['email' => 'nobody@overeasy.local'])->assertFailed();

        $admin->forceFill(['is_active' => false])->save();
        $this->artisan('user:reset-link', ['email' => 'admin@overeasy.local'])->assertFailed();
    }

    public function test_dev_admin_sets_the_local_password_and_is_off_without_one()
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->withRole('admin')->create(['email' => 'admin@overeasy.local', 'must_change_password' => true]);

        config(['app.dev_admin.password' => '']);
        $this->artisan('dev:admin')->assertFailed();

        config(['app.dev_admin.password' => 'local-dev-secret-1']);
        $this->artisan('dev:admin')->assertSuccessful();
        $this->assertTrue(Hash::check('local-dev-secret-1', $admin->fresh()->password));
        $this->assertFalse($admin->fresh()->must_change_password);
    }
}
