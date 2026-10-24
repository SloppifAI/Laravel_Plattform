<?php

namespace Tests\Feature\I18n;

use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocaleSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_switches_the_language_for_the_session_and_returns_to_the_page(): void
    {
        $this->from('/login')->get(route('locale.switch', 'en'))
            ->assertRedirect('/login')
            ->assertSessionHas('locale', 'en');
    }

    public function test_only_available_languages_are_accepted(): void
    {
        $this->get('/sprache/fr')->assertNotFound()->assertSessionMissing('locale');
        $this->get('/sprache/EN')->assertNotFound();
    }

    public function test_a_foreign_referer_cannot_send_anybody_to_another_host(): void
    {
        $this->from('https://evil.test/phish')->get(route('locale.switch', 'en'))->assertRedirect('/phish');
    }

    #[DataProvider('refererPathsThatLookLikeAnotherHost')]
    public function test_a_referer_path_cannot_become_a_scheme_relative_redirect(string $referer): void
    {
        $response = $this->from($referer)->get(route('locale.switch', 'en'))->assertRedirect('/attacker.example/x');

        $this->assertSame(url('/attacker.example/x'), $response->headers->get('Location'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refererPathsThatLookLikeAnotherHost(): array
    {
        return [
            'foreign host, double slash' => ['https://evil.test//attacker.example/x'],
            'own host, double slash' => ['http://localhost//attacker.example/x'],
            'backslash' => ['https://evil.test/\attacker.example/x'],
        ];
    }

    public function test_the_query_of_the_previous_page_survives_the_switch(): void
    {
        $this->from('/klassenbuch?klasse=7&tableSearch=abc')->get(route('locale.switch', 'en'))
            ->assertRedirect('/klassenbuch?klasse=7&tableSearch=abc');
    }

    public function test_the_user_menu_offers_the_other_language_and_the_switch_persists_to_the_user(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        $teacher = User::factory()->teacher()->create();

        $this->actingAs($teacher)->get('/')
            ->assertSee('Sprache: English')
            ->assertSee(route('locale.switch', 'en'), false);

        $this->actingAs($teacher)->get(route('locale.switch', 'en'))->assertRedirect();
        $this->assertSame('en', $teacher->fresh()->locale);

        $this->actingAs($teacher->fresh())->get('/')
            ->assertSee('Language: Deutsch')
            ->assertSee(route('locale.switch', 'de'), false);

        $this->actingAs($teacher)->get(route('locale.switch', 'de'));
        $this->assertSame('de', $teacher->fresh()->locale);
    }

    public function test_the_profile_page_saves_the_language_of_the_user(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        app()->setLocale('de');
        $teacher = User::factory()->teacher()->create();
        $this->actingAs($teacher);

        Livewire::test(EditProfile::class)
            ->assertFormSet(['locale' => 'de'])
            ->fillForm(['locale' => 'en'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertSame('en', $teacher->fresh()->locale);
    }

    public function test_the_profile_page_rejects_a_language_that_is_not_offered(): void
    {
        $teacher = User::factory()->teacher()->create();
        $this->actingAs($teacher);

        Livewire::test(EditProfile::class)
            ->fillForm(['locale' => 'fr'])
            ->call('save')
            ->assertHasFormErrors(['locale']);

        $this->assertNull($teacher->fresh()->locale);
    }
}
