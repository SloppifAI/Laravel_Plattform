<?php

namespace Tests\Feature\I18n;

use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LocaleResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_locale_of_the_signed_in_user_wins_over_session_and_default(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        $teacher = User::factory()->teacher()->create(['locale' => 'en']);

        $this->actingAs($teacher)->withSession(['locale' => 'de'])->get('/')->assertOk();

        $this->assertSame('en', app()->getLocale());
    }

    public function test_the_session_locale_applies_when_the_user_has_chosen_none(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        $teacher = User::factory()->teacher()->create(['locale' => null]);

        $this->actingAs($teacher)->withSession(['locale' => 'en'])->get('/')->assertOk();

        $this->assertSame('en', app()->getLocale());
    }

    public function test_guests_get_the_session_locale_on_the_login_page(): void
    {
        config(['lernplattform.locale.default' => 'de']);

        $this->withSession(['locale' => 'en'])->get('/login')->assertOk();

        $this->assertSame('en', app()->getLocale());
    }

    public function test_the_configured_default_applies_without_any_choice(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        app()->setLocale('en');

        $this->get('/login')->assertOk();

        $this->assertSame('de', app()->getLocale());
    }

    public function test_a_locale_that_is_not_offered_is_ignored(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        $teacher = User::factory()->teacher()->create(['locale' => 'fr']);

        $this->actingAs($teacher)->withSession(['locale' => 'es'])->get('/')->assertOk();

        $this->assertSame('de', app()->getLocale());
        $this->assertNull($teacher->preferredLocale());
    }

    public function test_filament_speaks_german_by_default_and_english_when_chosen(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        $teacher = User::factory()->teacher()->create();

        $this->actingAs($teacher)->get('/')->assertSee('Abmelden')->assertDontSee('Sign out');

        $teacher->update(['locale' => 'en']);

        $this->actingAs($teacher)->get('/')->assertSee('Sign out')->assertDontSee('Abmelden');
    }

    public function test_the_middleware_is_persistent_so_livewire_requests_use_it_too(): void
    {
        $this->get('/login')->assertOk();

        $this->assertContains(SetLocale::class, Livewire::getPersistentMiddleware());
    }
}
