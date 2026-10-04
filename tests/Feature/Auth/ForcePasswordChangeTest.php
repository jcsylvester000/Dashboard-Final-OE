<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ForcePasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_with_a_temporary_password_are_sent_to_the_change_screen()
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('password.force.edit'));
    }

    public function test_changing_the_password_clears_the_flag()
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)
            ->put(route('password.force.update'), [
                'current_password' => 'password',
                'password' => 'Brand-New-Pass-1!',
                'password_confirmation' => 'Brand-New-Pass-1!',
            ])
            ->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('Brand-New-Pass-1!', $user->password));
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_the_new_password_must_differ_from_the_temporary_one()
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)
            ->put(route('password.force.update'), [
                'current_password' => 'password',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors('password');
    }
}
