<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_the_dashboard()
    {
        $this->get(route('home'))->assertRedirect('/dashboard');
    }

    public function test_guests_are_sent_to_login_from_the_dashboard()
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_security_headers_are_present()
    {
        $this->get(route('login'))
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }
}
