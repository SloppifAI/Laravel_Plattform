<?php

namespace Tests\Feature\I18n;

use App\ClassBookStatus;
use App\ConsentStatus;
use App\Filament\NavigationGroup;
use App\Filament\Pages\Auth\Login;
use App\Models\User;
use App\QuestionLevel;
use App\QuestionType;
use App\Role;
use App\UnderstandingLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The core and auth area in both languages: login, registration, password reset, the sidebar groups, the language
 * switch of the guest pages and the labels of the enums.
 */
class CoreAndAuthLanguageTest extends TestCase
{
    use RefreshDatabase;

    private function germanIsTheDefault(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        app()->setLocale('de');
    }

    public function test_the_auth_pages_render_in_english(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSeeInOrder(['<title>', 'Sign in', '</title>'], false)
            ->assertSee('Welcome back!')
            ->assertSee('Forgot your password?')
            ->assertSee('Demo accounts')
            ->assertSee('Legal notice')
            ->assertDontSee('Willkommen zurück!')
            ->assertDontSee('Passwort vergessen?');

        $this->get(route('filament.lernplattform.auth.register'))
            ->assertOk()
            ->assertSee('Register with class code')
            ->assertSee('Repeat password')
            ->assertSee('Create account')
            ->assertDontSee('Konto erstellen');

        $this->get(route('filament.lernplattform.auth.register-school'))
            ->assertOk()
            ->assertSee('Register a new school')
            ->assertSee('Your school')
            ->assertSee('First school administration account');

        $this->get(route('filament.lernplattform.auth.password-reset.request'))
            ->assertOk()
            ->assertSee('Forgot your password?')
            ->assertSee('Send reset link')
            ->assertSee('Back to sign in');
    }

    public function test_the_auth_pages_render_in_german(): void
    {
        $this->germanIsTheDefault();

        $this->get('/login')
            ->assertOk()
            ->assertSee('Willkommen zurück!')
            ->assertSee('Mit Klassencode registrieren')
            ->assertSee('Passwort vergessen?')
            ->assertSee('Impressum')
            ->assertDontSee('Welcome back!');

        $this->get(route('filament.lernplattform.auth.register'))
            ->assertOk()
            ->assertSee('Klassencode')
            ->assertSee('Konto erstellen');

        $this->get(route('filament.lernplattform.auth.register-school'))
            ->assertOk()
            ->assertSee('Neue Schule registrieren')
            ->assertSee('Erster Zugang der Schuladministration');

        $this->get(route('filament.lernplattform.auth.password-reset.request'))
            ->assertOk()
            ->assertSee('Link zum Zurücksetzen senden')
            ->assertSee('Zurück zur Anmeldung');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function guestPages(): array
    {
        return [
            'login' => ['filament.lernplattform.auth.login'],
            'student registration' => ['filament.lernplattform.auth.register'],
            'school registration' => ['filament.lernplattform.auth.register-school'],
            'password reset' => ['filament.lernplattform.auth.password-reset.request'],
        ];
    }

    #[DataProvider('guestPages')]
    public function test_the_guest_pages_offer_both_languages_and_mark_the_current_one(string $routeName): void
    {
        $html = $this->get(route($routeName))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('locale.switch', 'de').'"', $html);
        $this->assertStringNotContainsString('href="'.route('locale.switch', 'en').'"', $html);
        $this->assertMatchesRegularExpression('/<a [^>]*hreflang="de"[^>]*>Deutsch<\/a>/', $html);
        $this->assertMatchesRegularExpression('/<span [^>]*aria-current="true"[^>]*>English<\/span>/', $html);

        $this->germanIsTheDefault();

        $html = $this->get(route($routeName))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('locale.switch', 'en').'"', $html);
        $this->assertStringNotContainsString('href="'.route('locale.switch', 'de').'"', $html);
        $this->assertMatchesRegularExpression('/<a [^>]*hreflang="en"[^>]*>English<\/a>/', $html);
        $this->assertMatchesRegularExpression('/<span [^>]*aria-current="true"[^>]*>Deutsch<\/span>/', $html);
    }

    public function test_the_language_chosen_on_the_login_page_applies_to_the_next_pages(): void
    {
        $this->from('/login')->get(route('locale.switch', 'de'))->assertRedirect('/login');

        $this->get('/login')->assertOk()->assertSee('Willkommen zurück!')->assertDontSee('Welcome back!');
        $this->get(route('filament.lernplattform.auth.register'))->assertOk()->assertSee('Konto erstellen');
    }

    public function test_signed_in_pages_keep_the_language_switch_in_the_user_menu_only(): void
    {
        $this->actingAs(User::factory()->teacher()->create())
            ->get('/')
            ->assertOk()
            ->assertSee(route('locale.switch', 'de'), false)
            ->assertDontSee('hreflang="de"', false);
    }

    public function test_the_sidebar_groups_are_translated_and_keep_their_order(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/')->assertOk()->assertSeeInOrder([
            '<span class="fi-sidebar-group-label">',
            'Teaching',
            '<span class="fi-sidebar-group-label">',
            'Administration',
        ], false);

        $this->assertSame(
            ['Platform', 'Teaching', 'Learning', 'Administration'],
            array_map(fn (NavigationGroup $group): string => $group->getLabel(), NavigationGroup::cases()),
        );

        $admin->update(['locale' => 'de']);

        $this->actingAs($admin->fresh())->get('/')->assertOk()->assertSeeInOrder([
            '<span class="fi-sidebar-group-label">',
            'Unterricht',
            '<span class="fi-sidebar-group-label">',
            'Verwaltung',
        ], false);
    }

    public function test_the_administration_widgets_follow_the_language(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/')->assertOk()->assertSee('Submissions this week')->assertDontSee('Abgaben diese Woche');

        $admin->update(['locale' => 'de']);

        $this->actingAs($admin->fresh())->get('/')->assertOk()->assertSee('Abgaben diese Woche')->assertDontSee('Submissions this week');
    }

    public function test_the_enum_labels_follow_the_language(): void
    {
        $labels = fn (): array => [
            Role::Teacher->getLabel(),
            Role::Student->getLabel(),
            ConsentStatus::Revoked->getLabel(),
            ClassBookStatus::Confirmed->getLabel(),
            QuestionLevel::Basis->getLabel(),
            QuestionType::Application->getLabel(),
            UnderstandingLevel::NeedsSupport->getLabel(),
        ];

        app()->setLocale('en');
        $this->assertSame(['Teacher', 'Student', 'Revoked', 'Confirmed', 'Starter', 'Application', 'Needs support'], $labels());

        app()->setLocale('de');
        $this->assertSame(['Lehrkraft', 'Schüler:in', 'Widerrufen', 'Bestätigt', 'Einstieg', 'Anwendung', 'Förderbedarf'], $labels());
    }

    public function test_every_enum_text_has_an_english_translation(): void
    {
        $cases = [...Role::cases(), ...ConsentStatus::cases(), ...ClassBookStatus::cases(), ...QuestionLevel::cases(), ...QuestionType::cases(), ...UnderstandingLevel::cases()];
        $identicalInBothLanguages = ['Standard'];

        app()->setLocale('de');
        $german = array_map(fn (object $case): array => array_filter([
            $case->getLabel(),
            method_exists($case, 'getDescription') ? $case->getDescription() : null,
            method_exists($case, 'getReason') ? $case->getReason() : null,
        ]), $cases);

        app()->setLocale('en');

        foreach ($cases as $index => $case) {
            $english = array_filter([
                $case->getLabel(),
                method_exists($case, 'getDescription') ? $case->getDescription() : null,
                method_exists($case, 'getReason') ? $case->getReason() : null,
            ]);

            foreach ($german[$index] as $position => $text) {
                if (! in_array($text, $identicalInBothLanguages, true)) {
                    $this->assertNotSame($text, $english[$position], $case::class.'::'.$case->name.' is not translated: '.$text);
                }
            }
        }
    }

    public function test_the_blocked_school_messages_follow_the_language(): void
    {
        app()->setLocale('en');
        $this->assertSame('Your school is currently blocked. Please contact the platform administration.', Login::blockedSchoolMessage());

        app()->setLocale('de');
        $this->assertSame('Deine Schule ist derzeit gesperrt. Bitte wende dich an die Plattform-Administration.', Login::blockedSchoolMessage());
    }
}
