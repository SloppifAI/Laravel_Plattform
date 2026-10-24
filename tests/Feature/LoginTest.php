<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_asset_urls_use_https_behind_a_tls_terminating_proxy(): void
    {
        $this->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/login')
            ->assertOk()
            ->assertSee('src="https://', false)
            ->assertDontSee('src="http://', false);
    }

    public function test_the_seeded_default_user_can_log_in_with_username(): void
    {
        $this->seed(DatabaseSeeder::class);

        Livewire::test(Login::class)
            ->fillForm(['username' => 'hackathon', 'password' => 'hackathon2026'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticated();
        $this->get('/')->assertOk()->assertSee('Mein Lernbereich');
    }

    public function test_the_login_form_is_prefilled_with_the_demo_credentials(): void
    {
        Livewire::test(Login::class)
            ->assertFormSet(['username' => 'hackathon', 'password' => 'hackathon2026']);
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $this->seed(DatabaseSeeder::class);

        Livewire::test(Login::class)
            ->fillForm(['username' => 'hackathon', 'password' => 'falsch'])
            ->call('authenticate')
            ->assertHasFormErrors(['username']);

        $this->assertGuest();
    }
}
