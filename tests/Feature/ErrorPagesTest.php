<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_page_links_back_to_the_start_page(): void
    {
        $this->get('/gibt-es-nicht')
            ->assertNotFound()
            ->assertSee('Not Found')
            ->assertSee('Back to the home page')
            ->assertSee('href="'.url('/').'"', escape: false);
    }

    public function test_a_forbidden_page_links_back_to_the_start_page(): void
    {
        $this->actingAs(User::factory()->student()->create())
            ->get(UserResource::getUrl())
            ->assertForbidden()
            ->assertSee('Back to the home page');
    }

    public function test_an_altered_password_reset_link_says_what_to_do_instead_of_invalid_signature(): void
    {
        $url = route('filament.lernplattform.auth.password-reset.reset', ['email' => 'a@b.de', 'token' => 'abc']);

        $this->get($url)
            ->assertForbidden()
            ->assertSee('This link is not valid. Request a new one on the sign-in page under “Forgot your password?”.')
            ->assertDontSee('Invalid signature.');

        config(['lernplattform.locale.default' => 'de']);
        app()->setLocale('de');

        $this->get($url)
            ->assertForbidden()
            ->assertSee('Dieser Link ist ungültig. Fordere auf der Anmeldeseite unter „Passwort vergessen?“ einen neuen Link an.')
            ->assertDontSee('Invalid signature.');
    }

    public function test_the_error_page_speaks_german_in_the_german_interface(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        app()->setLocale('de');

        $this->get('/gibt-es-nicht')
            ->assertNotFound()
            ->assertSee('Seite nicht gefunden')
            ->assertSee('Zurück zur Startseite')
            ->assertSee('<html lang="de">', escape: false);
    }
}
