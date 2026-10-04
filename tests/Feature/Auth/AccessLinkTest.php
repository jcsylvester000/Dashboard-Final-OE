<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\AccessLinkService;
use App\Models\User;
use App\Models\UserAccessLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessLinkTest extends TestCase
{
    use RefreshDatabase;

    private function issue(User $user, string $purpose = UserAccessLink::PURPOSE_SETUP): string
    {
        $url = app(AccessLinkService::class)->issue($user, $purpose)['url'];

        return Str::afterLast($url, '/access/');
    }

    public function test_only_a_hash_of_the_token_is_stored()
    {
        $user = User::factory()->create();
        $token = $this->issue($user);

        $this->assertDatabaseMissing('user_access_links', ['token_hash' => $token]);
        $this->assertDatabaseHas('user_access_links', ['token_hash' => hash('sha256', $token)]);
    }

    public function test_a_valid_link_shows_the_set_password_page()
    {
        $user = User::factory()->create();
        $token = $this->issue($user);

        $this->get(route('access-link.show', $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('auth/AccessLink')
                ->where('valid', true)
                ->where('email', $user->email));
    }

    public function test_the_link_sets_the_password_and_works_only_once()
    {
        $user = User::factory()->create();
        $token = $this->issue($user);

        $this->post(route('access-link.store', $token), [
            'password' => 'New-Password-123!',
            'password_confirmation' => 'New-Password-123!',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('New-Password-123!', $user->refresh()->password));

        // Second use is refused and the password does not change.
        $this->post(route('access-link.store', $token), [
            'password' => 'Another-Password-456!',
            'password_confirmation' => 'Another-Password-456!',
        ])->assertRedirect(route('access-link.show', $token));

        $this->assertTrue(Hash::check('New-Password-123!', $user->refresh()->password));
        $this->get(route('access-link.show', $token))
            ->assertInertia(fn ($page) => $page->where('valid', false));
    }

    public function test_expired_links_are_rejected()
    {
        $user = User::factory()->create();
        $token = $this->issue($user, UserAccessLink::PURPOSE_RESET);

        $this->travel(25)->hours();

        $this->get(route('access-link.show', $token))
            ->assertInertia(fn ($page) => $page->where('valid', false));
    }

    public function test_issuing_a_new_link_revokes_the_previous_one()
    {
        $user = User::factory()->create();
        $first = $this->issue($user);
        $this->issue($user);

        $this->get(route('access-link.show', $first))
            ->assertInertia(fn ($page) => $page->where('valid', false));
    }

    public function test_links_for_deactivated_users_do_not_work()
    {
        $user = User::factory()->create();
        $token = $this->issue($user);
        $user->forceFill(['is_active' => false])->save();

        $this->get(route('access-link.show', $token))
            ->assertInertia(fn ($page) => $page->where('valid', false));
    }

    public function test_garbage_tokens_are_rejected()
    {
        $this->get(route('access-link.show', 'not-a-real-token'))
            ->assertInertia(fn ($page) => $page->where('valid', false));
    }
}
