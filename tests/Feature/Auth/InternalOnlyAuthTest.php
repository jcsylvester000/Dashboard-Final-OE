<?php

namespace Tests\Feature\Auth;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The app is internal-only: no self-registration, no email flows,
 * and deactivated accounts cannot get in.
 */
class InternalOnlyAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_does_not_exist()
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_self_service_password_reset_does_not_exist()
    {
        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password', ['email' => 'a@b.test'])->assertNotFound();
    }

    public function test_deactivated_users_cannot_log_in()
    {
        $user = User::factory()->inactive()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_session_is_ended_when_the_account_is_deactivated()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $user->forceFill(['is_active' => false])->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_login_is_case_insensitive_and_records_last_login()
    {
        $user = User::factory()->create(['email' => 'member@agency.test']);

        $this->post(route('login.store'), [
            'email' => 'Member@Agency.test',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->last_login_at);
        $this->assertTrue(ActivityLog::where('action', 'auth.login')->where('actor_id', $user->id)->exists());
    }

    public function test_failed_logins_are_audited_without_the_password()
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password-123',
        ]);

        $log = ActivityLog::where('action', 'auth.failed')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('wrong-password-123', json_encode($log->properties));
    }
}
