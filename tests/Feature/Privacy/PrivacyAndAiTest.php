<?php

namespace Tests\Feature\Privacy;

use App\Ai\LessonAssistant;
use App\Ai\RecordingAnalyst;
use App\Ai\Transcriber;
use App\Filament\Pages\AiSettings;
use App\Filament\Pages\PrivacyAndAi;
use App\Filament\Pages\PrivacyDocuments;
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

    /**
     * What a reader sees of the page content: no scripts (the dictation code names SpeechRecognition's `transcript`) and no
     * markup, and without the name of the environment variable of the speech model.
     */
    private function visibleText(string $html): string
    {
        $main = (string) preg_replace('~<(script|style)\b.*?</\1>~si', '', (string) strstr($html, '<main'));

        return str_replace('AI_TRANSCRIPTION_MODEL', '', strip_tags($main));
    }

    #[DataProvider('roles')]
    public function test_every_role_can_open_the_page(Role $role): void
    {
        $this->actingAs($this->userWith($role))
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('Privacy & AI')
            ->assertSee('What Tuto stores')
            ->assertSee('Tuto does not store any audio')
            ->assertSee('Who sees this?')
            ->assertSee('Class register')
            ->assertSee('not a grade')
            ->assertSee('school office');
    }

    /**
     * The UI speaks of the Tuto-Mikro and its notes, never of recordings, and the page shows no eye or microphone icons.
     */
    #[DataProvider('roles')]
    public function test_the_page_keeps_the_wording_of_the_board(Role $role): void
    {
        $text = $this->visibleText($this->actingAs($this->userWith($role))->get('/datenschutz')->assertOk()->getContent());

        foreach (['recording', 'recorded', 'transcript', 'monitor', 'listen in', 'Anthropic', 'Claude'] as $word) {
            $this->assertFalse(mb_stripos($text, $word) !== false, "The page says “{$word}”.");
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
            ->assertSee('Every school on its own')
            ->assertSee('Schools see nothing of each other')
            ->assertSee(Role::Operator->getLabel())
            ->assertSee(Role::Admin->getLabel())
            ->assertSee(Role::Teacher->getLabel())
            ->assertSee(Role::Student->getLabel())
            ->assertSee(Role::Operator->getDescription())
            ->assertSee('School administration (own school)')
            ->assertSee('Teachers of the class')
            ->assertSee('Platform administration (school administrations only)')
            ->assertSee('Platform administration (all schools)')
            ->assertSee('School and registration')
            ->assertSee('results of its school');
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
            ->assertSee('Draft homework')
            ->assertSee('Draft practice task')
            ->assertSee('Assess a submission')
            ->assertSee('Levels of a question')
            ->assertSee('Plain language')
            ->assertSee('Tuto-Mikro: speech recognition')
            ->assertSee('Tuto-Mikro: summary')
            ->assertSee('Usernames, email addresses, account identifiers')
            // The homework draft also sends the questions the teacher wrote or edited (SdkLessonAssistant::draftHomework).
            ->assertSee('the questions the teacher wrote or edited themselves (pseudonymised, so the AI does not repeat them)');
    }

    #[DataProvider('roles')]
    public function test_the_ai_is_described_as_switched_off_while_the_school_has_not_opted_in(Role $role): void
    {
        $this->preventRealAiCalls();
        config(['lernplattform.ai.enabled' => $role === Role::Operator ? false : true]);

        $this->actingAs($this->userWith($role))
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('AI features turned off')
            ->assertSee('demo logic')
            ->assertSee('Google Gemini')
            ->assertDontSee('Pseudonymised texts and worksheets of your school go to')
            ->assertDontSee('The platform switch is on.');
    }

    public function test_the_switched_on_ai_names_provider_and_model_without_sending_a_request(): void
    {
        $this->preventRealAiCalls();
        Http::fake();
        $school = $this->enableAiFor(School::factory()->create());

        $this->actingAs(User::factory()->for($school)->teacher()->create())
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('AI features turned on')
            ->assertSee('Google Gemini')
            ->assertSee('gemini-test-model')
            ->assertSee('Usernames, email addresses, account identifiers')
            ->assertDontSee('AI features turned off');

        // Another school of the same platform has not opted in.
        $this->actingAs(User::factory()->teacher()->create())->get('/datenschutz')->assertSee('AI features turned off');

        Http::assertNothingSent();
    }

    public function test_the_operator_gets_the_ai_settings_of_the_platform(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('For the platform administration')
            ->assertSee('applies to all schools')
            ->assertSee('AI_ENABLED=true')
            ->assertSee('AI_PROVIDER=gemini')
            ->assertSee('GEMINI_API_KEY')
            ->assertSee('AI_MODEL')
            ->assertSee('AI_TRANSCRIPTION_MODEL')
            ->assertSee('ollama')
            ->assertSee('turns the AI features on itself')
            ->assertSee('not legal advice')
            ->assertDontSee('ANTHROPIC')
            ->assertDontSee('For the school administration');
    }

    public function test_the_school_administration_gets_its_checklist_and_the_way_to_its_switch(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('For the school administration')
            ->assertSee('You decide under “AI features”')
            ->assertSee('href="'.AiSettings::getUrl().'"', false)
            ->assertSee('not legal advice')
            ->assertDontSee('For the platform administration')
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
            ->assertDontSee('For the school administration')
            ->assertDontSee('For the platform administration')
            ->assertDontSee('GEMINI_API_KEY')
            ->assertDontSee('Checklist before turning on');
    }

    public function test_only_students_get_the_tips_for_their_own_data(): void
    {
        $this->actingAs(User::factory()->student()->create())
            ->get('/datenschutz')
            ->assertSee('Tips for you')
            ->assertSee('You can ask your teacher at any time');

        $this->actingAs(User::factory()->teacher()->create())
            ->get('/datenschutz')
            ->assertDontSee('Tips for you');
    }

    public function test_students_are_warned_that_texts_reach_the_ai_once_their_school_switched_it_on(): void
    {
        $this->preventRealAiCalls();
        $school = School::factory()->create();
        $student = User::factory()->student(SchoolClass::factory()->create(['school_id' => $school->getKey()]))->create();

        $this->actingAs($student)->get('/datenschutz')->assertDontSee('sent to the AI for evaluation');

        $this->enableAiFor($school);
        $this->actingAs($student->fresh())->get('/datenschutz')->assertSee('sent to the AI for evaluation');
    }

    public function test_the_stated_score_ranges_match_the_understanding_levels(): void
    {
        $this->assertSame(UnderstandingLevel::Understood, UnderstandingLevel::fromScore(75));
        $this->assertSame(UnderstandingLevel::Partly, UnderstandingLevel::fromScore(74));
        $this->assertSame(UnderstandingLevel::Partly, UnderstandingLevel::fromScore(50));
        $this->assertSame(UnderstandingLevel::NeedsSupport, UnderstandingLevel::fromScore(49));

        $this->actingAs(User::factory()->teacher()->create())
            ->get('/datenschutz')
            ->assertSee('75 or more out of 100')
            ->assertSee('50 to 74 out of 100')
            ->assertSee('under 50 out of 100')
            ->assertSee(UnderstandingLevel::NeedsSupport->getLabel());
    }

    /**
     * The suite runs in English; German is the platform default, so every role still gets the German page.
     */
    public function test_the_page_reads_german_when_german_is_the_default(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        app()->setLocale('de');

        $student = $this->actingAs(User::factory()->student()->create())->get('/datenschutz')->assertOk();

        foreach (['Mitschnitt', 'Aufnahme', 'aufnehmen', 'abhören', 'überwach', 'Transkript', 'Lernplattform speichert'] as $word) {
            $this->assertFalse(mb_stripos($this->visibleText($student->getContent()), $word) !== false, "Die Seite sagt „{$word}“.");
        }

        $student
            ->assertSee('Datenschutz & KI')
            ->assertSee('Was Tuto speichert, wer es sieht und was die KI damit macht.')
            ->assertSee('Tuto speichert keinen Ton')
            ->assertSee('Jede Schule für sich')
            ->assertSee('Schuladministration (eigene Schule)')
            ->assertSee('ab 75 von 100')
            ->assertSee('Tipps für dich')
            ->assertSee('die Fragen, die die Lehrkraft selbst geschrieben oder bearbeitet hat (pseudonymisiert, damit die KI sie nicht wiederholt)')
            ->assertDontSee('Privacy & AI')
            ->assertDontSee('Every school on its own');

        $this->actingAs(User::factory()->admin()->create())
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('KI-Funktionen ausgeschaltet')
            ->assertSee('Für die Schuladministration')
            ->assertSee('Checkliste vor dem Einschalten')
            ->assertSee('keine Rechtsberatung')
            ->assertDontSee('AI features turned off');
    }

    public function test_the_retention_phrase_follows_the_language_of_the_page(): void
    {
        config(['lernplattform.retention.school_years_kept' => 3]);

        $this->assertStringContainsString('after 3 school years (the current one included)', PrivacyDocuments::retentionText('en'));
        $this->assertStringContainsString('nach 3 Schuljahren (das laufende eingerechnet)', PrivacyDocuments::retentionText());

        $this->actingAs(User::factory()->teacher()->create())
            ->get('/datenschutz')
            ->assertSee('after 3 school years (the current one included)');
    }
}
