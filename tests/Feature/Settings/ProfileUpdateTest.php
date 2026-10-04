<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    }

    public function test_name_and_title_can_be_updated()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'title' => 'SEO Specialist',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('Test User', $user->name);
        $this->assertSame('SEO Specialist', $user->title);
    }

    public function test_members_cannot_change_their_own_email()
    {
        $user = User::factory()->create(['email' => 'original@agency.test']);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'changed@agency.test',
        ]);

        $this->assertSame('original@agency.test', $user->refresh()->email);
    }

    public function test_members_cannot_delete_their_own_account()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertStatus(405);

        $this->assertNotNull($user->fresh());
    }
}
