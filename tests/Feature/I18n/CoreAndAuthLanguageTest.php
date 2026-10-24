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
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup as FilamentNavigationGroup;
use Filament\Navigation\NavigationManager;
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

    public function test_the_profile_page_follows_the_language_of_the_account(): void
    {
        $teacher = User::factory()->teacher()->create(['locale' => 'de']);

        $this->actingAs($teacher)->get('/profile')
            ->assertOk()
            ->assertSee('Mein Profil')
            ->assertSee('Meine Daten herunterladen')
            ->assertSee('Lehrkraft')
            ->assertDontSee('Download my data');

        $teacher->update(['locale' => 'en']);

        $this->actingAs($teacher->fresh())->get('/profile')
            ->assertOk()
            ->assertSee('My profile')
            ->assertSee('Download my data')
            ->assertSee('Teacher')
            ->assertDontSee('Meine Daten herunterladen');
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

    /**
     * Group label => number of entries, in sidebar order, for whoever is signed in right now.
     *
     * @return array<string, int>
     */
    private function sidebarOutline(): array
    {
        app()->forgetInstance(NavigationManager::class);

        return collect(Filament::getNavigation())
            ->mapWithKeys(fn (FilamentNavigationGroup $group): array => [(string) $group->getLabel() => count($group->getItems())])
            ->all();
    }

    /**
     * @return array<string, array{callable(): User}>
     */
    public static function everyRole(): array
    {
        return [
            'operator' => [fn (): User => User::factory()->operator()->create()],
            'school administration' => [fn (): User => User::factory()->admin()->create()],
            'teacher' => [fn (): User => User::factory()->teacher()->create()],
            'student' => [fn (): User => User::factory()->student()->create()],
        ];
    }

    #[DataProvider('everyRole')]
    public function test_every_sidebar_entry_stays_in_its_group_in_both_languages(callable $makeUser): void
    {
        $this->actingAs($makeUser());

        app()->setLocale('en');
        $english = $this->sidebarOutline();

        app()->setLocale('de');
        $german = $this->sidebarOutline();

        $this->assertNotEmpty($english);
        $this->assertSame(array_values($english), array_values($german), 'An entry changed its group with the language.');

        // No stray group: every labelled group is a case of the enum (the home page has the unlabelled one).
        $caseLabels = fn (): array => array_map(fn (NavigationGroup $group): string => $group->getLabel(), NavigationGroup::cases());

        $this->assertEmpty(array_diff(array_filter(array_keys($german)), $caseLabels()));
        app()->setLocale('en');
        $this->assertEmpty(array_diff(array_filter(array_keys($english)), $caseLabels()));
    }

    public function test_the_administration_widgets_follow_the_language(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/')->assertOk()->assertSee('Submissions this week')->assertDontSee('Abgaben diese Woche')
            // The shortcuts are found through the translated name of the group, so an empty list would mean the match broke.
            ->assertSee('Manage users and classes')
            ->assertDontSee('No administration areas are available to you right now.');

        $admin->update(['locale' => 'de']);

        $this->actingAs($admin->fresh())->get('/')->assertOk()->assertSee('Abgaben diese Woche')->assertDontSee('Submissions this week')
            ->assertSee('Benutzer und Klassen pflegen')
            ->assertDontSee('Für dich sind gerade keine Verwaltungsbereiche freigeschaltet.');
    }

    public function test_the_platform_administration_widgets_follow_the_language(): void
    {
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)->get('/')
            ->assertOk()
            ->assertSee('Latest registrations')
            ->assertSee('Look after the platform')
            ->assertSee('Competency framework')
            ->assertDontSee('Neueste Registrierungen');

        $operator->update(['locale' => 'de']);

        $this->actingAs($operator->fresh())->get('/')
            ->assertOk()
            ->assertSee('Neueste Registrierungen')
            ->assertSee('Plattform betreuen')
            ->assertSee('Kompetenzraster')
            ->assertDontSee('Latest registrations');
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
