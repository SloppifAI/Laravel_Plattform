<?php

namespace Tests\Feature\Privacy;

use App\Ai\LessonAssistant;
use App\Ai\RecordingAnalyst;
use App\Ai\Transcriber;
use App\Filament\Pages\AiSettings;
use App\Filament\Pages\PrivacyAndAi;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use App\UnderstandingLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\Feature\Ai\EnablesSchoolAi;
use Tests\TestCase;

class PrivacyAndAiTest extends TestCase
{
    use EnablesSchoolAi;
    use RefreshDatabase;

    /**
     * @return array<string, array{Role}>
     */
    public static function roles(): array
    {
        return [
            'Plattform-Administration' => [Role::Operator],
            'Schuladministration' => [Role::Admin],
            'Lehrkraft' => [Role::Teacher],
            'Schüler:in' => [Role::Student],
        ];
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function rolesWithoutAdministration(): array
    {
        return [
            'Lehrkraft' => [Role::Teacher],
            'Schüler:in' => [Role::Student],
        ];
    }

    private function userWith(Role $role): User
    {
        return User::factory()->{$role->value}()->create();
    }

    #[DataProvider('roles')]
    public function test_every_role_can_open_the_page(Role $role): void
    {
        $this->actingAs($this->userWith($role))
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('Datenschutz & KI')
            ->assertSee('Was Tuto speichert')
            ->assertSee('Tuto speichert keinen Ton')
            ->assertSee('Wer sieht das?')
            ->assertSee('Klassenbuch')
            ->assertSee('keine Note')
            ->assertSee('Schulverwaltung');
    }

    /**
     * The UI speaks of the Tuto-Mikro and its notes, never of recordings, and the page shows no eye or microphone icons.
     */
    #[DataProvider('roles')]
    public function test_the_page_keeps_the_wording_of_the_board(Role $role): void
    {
        $html = $this->actingAs($this->userWith($role))->get('/datenschutz')->assertOk()->getContent();
        $main = (string) strstr($html, '<main');

        foreach (['Mitschnitt', 'Aufnahme', 'aufnehmen', 'abhören', 'überwach', 'Transkript', 'Lernplattform speichert', 'Anthropic', 'Claude'] as $word) {
            $this->assertFalse(mb_stripos($main, $word) !== false, "The page says „{$word}“.");
        }

        // Icons render as inline SVG, so the sources are checked for their names.
        foreach ([resource_path('views/filament/pages/privacy-and-ai.blade.php'), app_path('Filament/Pages/PrivacyAndAi.php')] as $source) {
            $this->assertDoesNotMatchRegularExpression('~heroicon-[oms]-(eye|microphone|speaker)|Heroicon::\w*(Eye|Microphone|Speaker)~', (string) file_get_contents($source));
        }
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get('/datenschutz')->assertRedirect();
    }

    public function test_the_page_has_no_navigation_group_and_sits_at_the_end(): void
    {
        $this->assertNull(PrivacyAndAi::getNavigationGroup());
        $this->assertGreaterThanOrEqual(100, PrivacyAndAi::getNavigationSort());
    }

    #[DataProvider('roles')]
    public function test_every_role_sees_the_role_list_and_that_schools_are_separate(Role $role): void
    {
        $this->actingAs($this->userWith($role))
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('Jede Schule für sich')
            ->assertSee('Schulen sehen nichts voneinander')
            ->assertSee(Role::Operator->getLabel())
            ->assertSee(Role::Admin->getLabel())
            ->assertSee(Role::Teacher->getLabel())
            ->assertSee(Role::Student->getLabel())
            ->assertSee(Role::Operator->getDescription())
            ->assertSee('Schuladministration (eigene Schule)')
            ->assertSee('Lehrkräfte der Klasse')
            ->assertSee('Plattform-Administration (nur Schuladministrationen)')
            ->assertSee('Plattform-Administration (alle Schulen)')
            ->assertSee('Schule und Registrierung')
            ->assertSee('Ergebnisse ihrer Schule');
    }

    /**
     * Every call the platform can make to an AI provider is a method of LessonAssistant, Transcriber or RecordingAnalyst;
     * the page names each of them with what goes out.
     */
    public function test_the_page_lists_every_ai_function(): void
    {
        $methods = collect([LessonAssistant::class, Transcriber::class, RecordingAnalyst::class])
            ->flatMap(fn (string $interface): array => array_map(
                fn (ReflectionMethod $method): string => (new ReflectionClass($interface))->getShortName().'::'.$method->getName(),
                (new ReflectionClass($interface))->getMethods(),
            ))
            ->sort()
            ->values()
            ->all();

        $this->assertSame($methods, collect(PrivacyAndAi::aiFunctions())->keys()->sort()->values()->all(), 'Every AI call needs an entry on the privacy page.');

        $response = $this->actingAs(User::factory()->student()->create())->get('/datenschutz')->assertOk();

        foreach (PrivacyAndAi::aiFunctions() as $function) {
            $response->assertSee($function['title'])->assertSee($function['sent'])->assertSee($function['notSent'])->assertSee($function['result']);
        }

        $response
            ->assertSee('Hausaufgabe entwerfen')
            ->assertSee('Förderaufgabe entwerfen')
            ->assertSee('Abgabe einschätzen')
            ->assertSee('Stufen einer Frage')
            ->assertSee('Einfache Sprache')
            ->assertSee('Tuto-Mikro: Spracherkennung')
            ->assertSee('Tuto-Mikro: Zusammenfassung')
            ->assertSee('Benutzernamen, E-Mail-Adressen, Konto-Kennungen');
    }

    #[DataProvider('roles')]
    public function test_the_ai_is_described_as_switched_off_while_the_school_has_not_opted_in(Role $role): void
    {
        $this->preventRealAiCalls();
        config(['lernplattform.ai.enabled' => $role === Role::Operator ? false : true]);

        $this->actingAs($this->userWith($role))
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('KI-Funktionen ausgeschaltet')
            ->assertSee('Demo-Logik')
            ->assertSee('Google Gemini')
            ->assertDontSee('Pseudonymisierte Texte und Arbeitsblätter deiner Schule gehen an')
            ->assertDontSee('Der Schalter der Plattform ist an.');
    }

    public function test_the_switched_on_ai_names_provider_and_model_without_sending_a_request(): void
    {
        $this->preventRealAiCalls();
        Http::fake();
        $school = $this->enableAiFor(School::factory()->create());

        $this->actingAs(User::factory()->for($school)->teacher()->create())
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('KI-Funktionen eingeschaltet')
            ->assertSee('Google Gemini')
            ->assertSee('gemini-test-model')
            ->assertSee('Benutzernamen, E-Mail-Adressen, Konto-Kennungen')
            ->assertDontSee('KI-Funktionen ausgeschaltet');

        // Another school of the same platform has not opted in.
        $this->actingAs(User::factory()->teacher()->create())->get('/datenschutz')->assertSee('KI-Funktionen ausgeschaltet');

        Http::assertNothingSent();
    }

    public function test_the_operator_gets_the_ai_settings_of_the_platform(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('Für die Plattform-Administration')
            ->assertSee('für alle Schulen')
            ->assertSee('AI_ENABLED=true')
            ->assertSee('AI_PROVIDER=gemini')
            ->assertSee('GEMINI_API_KEY')
            ->assertSee('AI_MODEL')
            ->assertSee('AI_TRANSCRIPTION_MODEL')
            ->assertSee('ollama')
            ->assertSee('selbst einschaltet')
            ->assertSee('keine Rechtsberatung')
            ->assertDontSee('ANTHROPIC')
            ->assertDontSee('Für die Schuladministration');
    }

    public function test_the_school_administration_gets_its_checklist_and_the_way_to_its_switch(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('Für die Schuladministration')
            ->assertSee('entscheidest du unter „KI-Funktionen“')
            ->assertSee('href="'.AiSettings::getUrl().'"', false)
            ->assertSee('keine Rechtsberatung')
            ->assertDontSee('Für die Plattform-Administration')
            ->assertDontSee('AI_ENABLED=true')
            ->assertDontSee('GEMINI_API_KEY');

        foreach (AiSettings::checklistItems() as $item) {
            $response->assertSee($item['label']);
        }
    }

    #[DataProvider('rolesWithoutAdministration')]
    public function test_the_administration_sections_are_hidden_from_other_roles(Role $role): void
    {
        $this->actingAs($this->userWith($role))
            ->get('/datenschutz')
            ->assertOk()
            ->assertDontSee('Für die Schuladministration')
            ->assertDontSee('Für die Plattform-Administration')
            ->assertDontSee('GEMINI_API_KEY')
            ->assertDontSee('Checkliste vor dem Einschalten');
    }

    public function test_only_students_get_the_tips_for_their_own_data(): void
    {
        $this->actingAs(User::factory()->student()->create())
            ->get('/datenschutz')
            ->assertSee('Tipps für dich')
            ->assertSee('Du darfst deine Lehrkraft jederzeit fragen');

        $this->actingAs(User::factory()->teacher()->create())
            ->get('/datenschutz')
            ->assertDontSee('Tipps für dich');
    }

    public function test_students_are_warned_that_texts_reach_the_ai_once_their_school_switched_it_on(): void
    {
        $this->preventRealAiCalls();
        $school = School::factory()->create();
        $student = User::factory()->student(SchoolClass::factory()->create(['school_id' => $school->getKey()]))->create();

        $this->actingAs($student)->get('/datenschutz')->assertDontSee('zur Auswertung an die KI gesendet');

        $this->enableAiFor($school);
        $this->actingAs($student->fresh())->get('/datenschutz')->assertSee('zur Auswertung an die KI gesendet');
    }

    public function test_the_stated_score_ranges_match_the_understanding_levels(): void
    {
        $this->assertSame(UnderstandingLevel::Understood, UnderstandingLevel::fromScore(75));
        $this->assertSame(UnderstandingLevel::Partly, UnderstandingLevel::fromScore(74));
        $this->assertSame(UnderstandingLevel::Partly, UnderstandingLevel::fromScore(50));
        $this->assertSame(UnderstandingLevel::NeedsSupport, UnderstandingLevel::fromScore(49));

        $this->actingAs(User::factory()->teacher()->create())
            ->get('/datenschutz')
            ->assertSee('ab 75 von 100')
            ->assertSee('50 bis 74 von 100')
            ->assertSee('unter 50 von 100')
            ->assertSee(UnderstandingLevel::NeedsSupport->getLabel());
    }
}
