<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_links_to_registration_and_password_reset(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee(route('filament.lernplattform.auth.register'), false)
            ->assertSee('Noch kein Konto?')
            ->assertSee('Mit Klassencode registrieren')
            ->assertSee(route('filament.lernplattform.auth.password-reset.request'), false);
    }

    public function test_the_login_page_links_to_the_school_registration(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Neue Schule?')
            ->assertSee('Schule registrieren')
            ->assertSee(route('filament.lernplattform.auth.register-school'), false);
    }

    public function test_the_student_registration_page_links_to_the_school_registration(): void
    {
        $this->get(route('filament.lernplattform.auth.register'))
            ->assertOk()
            ->assertSee('Neue Schule?')
            ->assertSee(route('filament.lernplattform.auth.register-school'), false);
    }

    public function test_the_forgot_password_link_is_german_and_reachable_with_the_tab_key(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<a\b([^>]*)>(?:\s|<span[^>]*>)*Passwort vergessen\?/', $html, $link));
        $this->assertStringContainsString(route('filament.lernplattform.auth.password-reset.request'), $link[1]);
        $this->assertStringNotContainsString('tabindex', $link[1]);
    }

    public function test_the_pages_behind_those_links_open_for_guests(): void
    {
        $this->get(route('filament.lernplattform.auth.register'))->assertOk();
        $this->get(route('filament.lernplattform.auth.register-school'))->assertOk();
        $this->get(route('filament.lernplattform.auth.password-reset.request'))->assertOk();
    }
}
