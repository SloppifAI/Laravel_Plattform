<?php

namespace Tests\Feature\PrivacyDocuments;

use App\Ai\Agents\HomeworkDrafter;
use App\Ai\LessonAssistant;
use App\Filament\NavigationGroup;
use App\Filament\Pages\AiSettings;
use App\Filament\Pages\PrivacyAndAi;
use App\Filament\Pages\PrivacyDocuments;
use App\Filament\Pages\RecordingSettings;
use App\Filament\Pages\RecordLesson;
use App\Filament\Resources\ExplanationSessions\Pages\ListExplanationSessions;
use App\Models\ClassBookEntry;
use App\Models\ClassSubstitution;
use App\Models\ConsentForm;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use App\Support\PersonalDataExport;
use App\Support\RecordingConsentGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ai\EnablesSchoolAi;
use Tests\Feature\Consents\CreatesConsentFixtures;
use Tests\TestCase;

/**
 * The privacy templates of a school. The statements they make about the platform are backed here by tests against the
 * code: when the code changes, a test fails and the text has to follow.
 */
class PrivacyDocumentsTest extends TestCase
{
    use CreatesConsentFixtures;
    use EnablesSchoolAi;
    use RefreshDatabase;

    /**
     * Every template with a heading and phrases that must stay in it.
     *
     * @return array<string, array{string, string, list<string>}>
     */
    public static function documents(): array
    {
        return [
            'Checkliste' => ['checkliste', 'Checkliste für die Einführung', ['Teil A: Einsatz von Tuto', 'Teil B: Zusätzlich vor den KI-Funktionen', 'Teil C: Zusätzlich vor dem Tuto-Mikro', 'Auftragsverarbeitungsvertrag abgeschlossen', 'Auftragsverarbeitung mit dem KI-Anbieter geklärt', 'Regeln für die Vertretung festgelegt', 'Personalrat beteiligt', 'Datenschutz-Folgenabschätzung durchgeführt', 'Gesperrte Fächer festgelegt', 'Einwilligungen aller Personen im Raum', 'Löschfristen festgelegt', 'Ansprechpartner']],
            'Folgenabschätzung' => ['dsfa', 'Datenschutz-Folgenabschätzung (Art. 35 DSGVO)', ['Systematische Beschreibung der Verarbeitung', 'Was an die KI geht', 'Ablauf mit dem Tuto-Mikro', 'Notwendigkeit und Verhältnismäßigkeit', 'Verhaltensanpassung durch Überwachungsdruck', 'Missbrauch und Weitergabe von Ton oder Notizen', 'Wiedererkennung trotz Pseudonymisierung', 'Fehler und Verzerrungen der KI', 'Übermittlung an den KI-Anbieter', 'Zu weiter Zugriff innerhalb der Schule', 'Klassenbuch', 'Benachrichtigungen', 'Maßnahmen der Plattform', 'Was die Plattform nicht leistet', 'Trennung der Schulen', 'Stellungnahme der oder des Datenschutzbeauftragten', 'Konsultation der Aufsichtsbehörde']],
            'Verzeichnis' => ['verzeichnis', 'Eintrag für das Verzeichnis von Verarbeitungstätigkeiten (Art. 30 DSGVO)', ['A: Nutzung von Tuto im Unterricht', 'B: KI-Funktionen (optional)', 'C: Tuto-Mikro im Unterricht (optional)', 'Zwecke', 'Betroffene Personen', 'Datenkategorien', 'Empfänger', 'Übermittlung in Drittländer', 'Löschfristen', 'Technische und organisatorische Maßnahmen']],
            'Elterninformation' => ['elterninformation', 'Elterninformation zu Tuto (Art. 13 DSGVO)', ['Wer ist verantwortlich?', 'Welche Daten werden verarbeitet?', 'Künstliche Intelligenz', 'Tuto-Mikro: Ton im Unterricht', 'Freiwillig und nur mit Einwilligung', 'Was mit dem Ton geschieht', 'Ihre Rechte und die Rechte Ihres Kindes', 'Widerruf einer erteilten Einwilligung', 'Beschwerde bei einer Aufsichtsbehörde']],
            'Einwilligung' => ['einwilligung', 'Einwilligung zum Tuto-Mikro im Unterricht (Muster)', [ConsentForm::PURPOSES['audio'], ConsentForm::PURPOSES['transcript'], ConsentForm::PURPOSES['ai_analysis'], 'Die Einwilligung ist freiwillig', 'jederzeit ohne Angabe von Gründen widerrufen', 'Variante für Lehrkräfte und weitere anwesende Personen']],
        ];
    }

    private function schoolAdmin(?School $school = null): User
    {
        return User::factory()->for($school ?? School::factory()->create(['name' => 'Goethe-Gymnasium', 'school_number' => '111111', 'city' => 'Kassel', 'contact_email' => 'sekretariat@goethe.example.test']))->admin()->create();
    }

    /**
     * The template itself: the page chrome around it (menu, avatar) is not part of what a school prints.
     */
    private function articleOf(string $html): string
    {
        return Str::before(Str::after($html, '<article'), '</article>');
    }

    #[DataProvider('documents')]
    public function test_the_administration_gets_every_template_with_the_data_of_its_school(string $key, string $heading, array $phrases): void
    {
        $school = School::factory()->create(['name' => 'Goethe-Gymnasium', 'school_number' => '111111', 'city' => 'Kassel', 'state' => 'Hessen', 'contact_email' => 'sekretariat@goethe.example.test']);
        $otherSchool = School::factory()->create(['name' => 'Schiller-Realschule']);
        $this->actingAs($this->schoolAdmin($school));

        $response = $this->get(PrivacyDocuments::getUrl(['document' => $key]))
            ->assertOk()
            ->assertSee('Datenschutz-Unterlagen')
            ->assertSee($heading)
            ->assertSee('Goethe-Gymnasium')
            ->assertDontSee($otherSchool->name);

        foreach ($phrases as $phrase) {
            $response->assertSee($phrase);
        }
    }

    #[DataProvider('documents')]
    public function test_every_template_says_it_is_only_a_template_and_can_be_printed(string $key): void
    {
        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl(['document' => $key]))
            ->assertOk()
            ->assertSee('Vorlage – an die Gegebenheiten der Schule anpassen, keine Rechtsberatung')
            ->assertSee('Vorlage drucken')
            ->assertSee('window.print()', false)
            ->assertSee('@media print', false);
    }

    /**
     * The product is Tuto, the provider Google Gemini (config/lernplattform.php), and the former recording mode with
     * stored audio is gone: no template may still speak of Anthropic, of an "Aufnahme-Modul" or of audio files.
     */
    #[DataProvider('documents')]
    public function test_every_template_names_tuto_and_google_and_nothing_of_the_former_setup(string $key): void
    {
        $this->assertSame('gemini', config('lernplattform.ai.provider'));
        $this->actingAs($this->schoolAdmin());

        $article = $this->articleOf($this->get(PrivacyDocuments::getUrl(['document' => $key]))->assertOk()->getContent());

        $this->assertStringContainsString('Datenschutz bei Tuto', $article);
        $this->assertStringContainsString('Google', $article);

        foreach (['Anthropic', 'Claude', 'Aufnahme-Modul', 'Aufnahmen &amp; Einwilligungen', 'Audiodatei', 'Server-Transkription'] as $outdated) {
            $this->assertStringNotContainsString($outdated, $article);
        }
    }

    public function test_the_master_data_of_the_school_and_the_operator_are_prefilled(): void
    {
        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl(['document' => 'verzeichnis']))
            ->assertOk()
            ->assertSeeInOrder(['Schule', 'Goethe-Gymnasium', 'Schulnummer', '111111', 'Ort, Bundesland', 'Kassel', 'Kontakt-E-Mail', 'sekretariat@goethe.example.test'])
            ->assertSee('Softwareentwicklung Sebastian Bleuel')
            ->assertSee('Ortesweg 10')
            ->assertSee('36145 Hofbieber')
            ->assertSee('info@sebastian-bleuel.de');
    }

    public function test_missing_master_data_becomes_a_line_to_fill_in(): void
    {
        $school = School::factory()->create(['school_number' => null, 'school_type' => null]);

        $this->actingAs($this->schoolAdmin($school));

        $html = $this->get(PrivacyDocuments::getUrl(['document' => 'verzeichnis']))->assertOk()->getContent();

        $this->assertStringContainsString('<span class="pd-blank"><span class="sr-only">bitte ausfüllen</span></span>', $this->articleOf($html));
    }

    public function test_the_open_template_is_chosen_by_the_url_and_unknown_values_show_the_checklist(): void
    {
        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl())->assertOk()->assertSee('Checkliste für die Einführung');
        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk()->assertSee('Datenschutz-Folgenabschätzung (Art. 35 DSGVO)')->assertDontSee('Teil A: Einsatz von Tuto');
        $this->get(PrivacyDocuments::getUrl(['document' => '../../.env']))->assertOk()->assertSee('Checkliste für die Einführung');
    }

    public function test_the_menu_links_to_every_template_and_marks_the_open_one(): void
    {
        $this->actingAs($this->schoolAdmin());

        $response = $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk();

        foreach (array_keys(self::documents()) as $label) {
            $response->assertSee($label);
        }

        foreach (['checkliste', 'dsfa', 'verzeichnis', 'elterninformation', 'einwilligung'] as $key) {
            $response->assertSee('href="'.PrivacyDocuments::getUrl(['document' => $key]).'"', false);
        }

        $response->assertSee('aria-current="page"', false);
    }

    public function test_the_texts_follow_the_real_settings_of_the_platform(): void
    {
        $this->preventRealAiCalls();
        config(['lernplattform.retention.school_years_kept' => 3, 'lernplattform.recordings.session_keep_days' => 30]);
        $admin = $this->schoolAdmin();
        $this->actingAs($admin);

        // The platform switch alone sends nothing: the school has not opted in.
        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))
            ->assertOk()
            ->assertSee('nach 3 Schuljahren (das laufende eingerechnet)')
            ->assertSee('30 Tage')
            ->assertSee('Ausgeschaltet: keine Daten gehen an einen KI-Anbieter');

        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))
            ->assertOk()
            ->assertSee('Derzeit sind die KI-Funktionen an unserer Schule ausgeschaltet')
            ->assertSee('Er wird nach 30 Tagen gelöscht');

        $this->enableAiFor($admin->school);

        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))
            ->assertOk()
            ->assertSee('Eingeschaltet: '.PrivacyDocuments::AI_DATA.' gehen an Google Gemini');

        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))
            ->assertOk()
            ->assertSee('Anbieter ist Google Gemini')
            ->assertSee('Dafür werden '.PrivacyDocuments::AI_DATA.' an den Anbieter übermittelt');

        config(['lernplattform.ai.enabled' => false]);

        $this->get(PrivacyDocuments::getUrl(['document' => 'einwilligung']))->assertOk()->assertSee('derzeit ausgeschaltet');
    }

    /**
     * One school year by default, with the transition the accepted agreements require (PruneLearningData).
     */
    public function test_the_retention_follows_the_one_year_rule_and_its_transition(): void
    {
        config(['lernplattform.retention.school_years_kept' => 1, 'lernplattform.retention.one_year_rule_from' => now()->addYear()->toDateString()]);

        $this->assertStringContainsString('zum Ende des Schuljahres, also zu Beginn des nächsten Schuljahres am 01.08.', PrivacyDocuments::retentionText());
        $this->assertStringContainsString('Übergang: bis zum '.now()->addYear()->format('d.m.Y').' bleiben noch zwei Schuljahre gespeichert', PrivacyDocuments::retentionText());

        config(['lernplattform.retention.one_year_rule_from' => now()->subDay()->toDateString()]);
        $this->assertStringNotContainsString('Übergang', PrivacyDocuments::retentionText());

        $this->actingAs($this->schoolAdmin());
        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))->assertOk()
            ->assertSee('löscht Tuto automatisch zum Ende des Schuljahres');
    }

    public function test_the_consent_names_the_three_purposes_and_the_parent_information_uses_sie(): void
    {
        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl(['document' => 'einwilligung']))
            ->assertOk()
            ->assertSeeInOrder(['1. '.ConsentForm::PURPOSES['audio'], '2. '.ConsentForm::PURPOSES['transcript'], '3. '.ConsentForm::PURPOSES['ai_analysis'], 'Ja, ich willige ein', 'Nein, ich willige nicht ein'])
            ->assertSee('Wer nicht einwilligt, hat keine Nachteile')
            ->assertSee('KI-Anbieter:</strong> Google Gemini', false)
            ->assertSee('Unterschrift Erziehungsberechtigte(r)');

        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))
            ->assertOk()
            ->assertSee('Sehr geehrte Eltern und Erziehungsberechtigte')
            ->assertSee('Sie können die Einwilligung jederzeit')
            ->assertDontSee('Du darfst');
    }

    #[DataProvider('documents')]
    public function test_nothing_in_a_template_loads_from_an_external_host(string $key): void
    {
        $this->actingAs($this->schoolAdmin());

        $html = $this->get(PrivacyDocuments::getUrl(['document' => $key]))->assertOk()->getContent();
        // The inline icons carry the SVG namespace, which names no host to load from.
        // Links to the platform's own pages (the agreement, the settings) are fine and come as absolute URLs.
        $article = str_replace(url('/'), '', preg_replace('~xmlns="[^"]*"~', '', $this->articleOf($html)));

        $this->assertDoesNotMatchRegularExpression('~https?://~i', $article, 'The template must not link or load anything from outside.');
        $this->assertDoesNotMatchRegularExpression('~<(img|script|iframe|link|object|embed|video|audio|source)\b~i', $article);
        $this->assertDoesNotMatchRegularExpression('~url\(|@import~i', $article);

        $style = Str::before(Str::after($html, '/* The templates: one calm reading column'), '</style>');
        $this->assertDoesNotMatchRegularExpression('~https?://|url\(|@import~i', $style, 'The print and layout styles of the templates are self-contained.');
    }

    public function test_the_checklist_shows_the_agreement_ai_and_tuto_mikro_status_of_the_school_with_links(): void
    {
        $this->preventRealAiCalls();
        $school = School::factory()->create();
        $admin = $this->schoolAdmin($school);
        $this->actingAs($admin);

        $this->get(PrivacyDocuments::getUrl(['document' => 'checkliste']))
            ->assertOk()
            ->assertSee('In der Plattform noch nicht akzeptiert.')
            ->assertSee('KI-Funktionen sind für diese Schule ausgeschaltet.')
            ->assertSee('Tuto-Mikro ist für diese Schule ausgeschaltet.')
            ->assertSee('Zu „Tuto-Mikro & Einwilligungen“')
            ->assertSee('href="'.route('legal.dpa').'"', false)
            ->assertSee('href="'.AiSettings::getUrl().'"', false)
            ->assertSee('href="'.RecordingSettings::getUrl().'"', false);

        $school->acceptDpa($admin);
        $school->forceFill(['recordings_enabled_at' => now(), 'recording_checklist' => array_fill_keys(School::RECORDING_CHECKLIST, true)])->save();
        $this->enableAiFor($school);
        $this->actingAs($admin->fresh());

        $this->get(PrivacyDocuments::getUrl(['document' => 'checkliste']))
            ->assertOk()
            ->assertSee('In der Plattform akzeptiert am '.now()->format('d.m.Y').'.')
            ->assertSee('KI-Funktionen sind für diese Schule eingeschaltet (Anbieter: Google Gemini).')
            ->assertSee('Tuto-Mikro ist für diese Schule eingeschaltet.');
    }

    /**
     * "Vor jeder Anfrage an die KI ersetzt Tuto die Namen", "Arbeitsblatt-Dateien gehen unverändert", "Nichts geht an
     * einen KI-Anbieter, solange die Schule die KI-Funktionen nicht eingeschaltet hat": checked against the lesson AI.
     */
    public function test_the_ai_statements_say_what_reaches_the_provider(): void
    {
        $this->preventRealAiCalls();
        Storage::fake('local');
        Storage::disk('local')->put('worksheets/foto.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $school = School::factory()->create();
        [$schoolClass, $students, $teacher] = $this->classWithStudents($school, 1);
        $student = $students->first();
        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->create([
            'notes' => "{$student->name} hat das Experiment erklärt, {$teacher->name} hat ergänzt.",
            'attachments' => ['worksheets/foto.png'],
        ]);
        HomeworkDrafter::fake();

        app(LessonAssistant::class)->draftHomework($lesson);
        HomeworkDrafter::assertNeverPrompted();

        $this->enableAiFor($school);
        HomeworkDrafter::fake([['summary' => 'Zusammenfassung.', 'questions' => [['type' => 'comprehension', 'prompt' => 'Was?', 'expected_answer' => 'Das.', 'competency_id' => null]]]]);

        app(LessonAssistant::class)->draftHomework($lesson->fresh());

        HomeworkDrafter::assertPrompted(function (AgentPrompt $prompt) use ($student, $teacher): bool {
            $this->assertStringNotContainsString($student->name, $prompt->prompt);
            $this->assertStringNotContainsString($teacher->name, $prompt->prompt);
            $this->assertStringNotContainsString($student->username, $prompt->prompt);
            $this->assertStringContainsString('Schüler:in A', $prompt->prompt);
            $this->assertCount(1, $prompt->attachments);

            return true;
        });

        $this->actingAs($this->schoolAdmin($school));

        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk()
            ->assertSee('Vor jeder Anfrage an die KI ersetzt Tuto die Namen der Schüler:innen der Klasse und der Lehrkräfte der Klasse')
            ->assertSee('Arbeitsblatt-Dateien (Fotos, PDF) gehen unverändert an den Anbieter')
            ->assertSee('Die Ton-Abschnitte des Tuto-Mikro gehen unverändert zur Spracherkennung')
            ->assertSee('Nichts geht an einen KI-Anbieter, solange der Schalter der Plattform aus ist oder die Schuladministration die KI-Funktionen nicht selbst eingeschaltet hat');

        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))->assertOk()
            ->assertSee('Vor jeder Übermittlung ersetzt Tuto die Namen')
            ->assertSee('Namen auf Fotos oder PDF-Arbeitsblättern werden aber nicht entfernt');
    }

    /**
     * What the templates claim about the consent check is what starting the Tuto-Mikro does: the server refuses the
     * start while a ticked student has no valid consent, and with the school's AI on it asks for the AI purpose as well.
     */
    public function test_the_tuto_mikro_starts_only_with_the_consent_of_every_ticked_student(): void
    {
        Storage::fake('local');
        $school = $this->schoolWithRecordings();
        $form = $this->publishedForm($school);
        [$schoolClass, $students, $teacher] = $this->classWithStudents($school);
        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->create(['subject' => 'Biologie']);
        $start = fn () => $this->actingAs($teacher)->postJson(route('recordings.store', $lesson), ['present' => $students->modelKeys(), 'attendance_confirmed' => true]);

        $start()->assertConflict();
        $this->assertSame(0, LessonRecording::query()->count());

        $this->consentAll($students, $form);
        $start()->assertCreated();
        $this->assertNotNull(LessonRecording::query()->sole()->attendance_confirmed_at);

        $this->assertSame(['audio', 'transcript'], LessonRecording::purposesFor($school));
        $this->preventRealAiCalls();
        $this->assertSame(['audio', 'transcript', 'ai_analysis'], LessonRecording::purposesFor($this->enableAiFor($school)));

        $this->actingAs($this->schoolAdmin($school));

        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk()
            ->assertSee('Der Server startet das Tuto-Mikro nur, wenn für jede angehakte Schüler:in eine gültige Einwilligung zur aktuellen Fassung vorliegt')
            ->assertSee('bei eingeschalteten KI-Funktionen, auch die Spracherkennung und Zusammenfassung durch den KI-Anbieter')
            ->assertSee('eine eigene Anwesenheitserfassung hat die Plattform nicht');

        $this->get(PrivacyDocuments::getUrl(['document' => 'checkliste']))->assertOk()
            ->assertSee('Die Plattform startet das Tuto-Mikro nur, wenn für alle Schüler:innen, die die Lehrkraft als anwesend anhakt, eine gültige Einwilligung der Eltern vorliegt');

        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))->assertOk()
            ->assertSee('Tuto startet das Mikro nur, wenn für alle angehakten Schüler:innen eine gültige Einwilligung vorliegt');
    }

    public function test_the_tuto_mikro_stays_off_in_a_school_that_has_not_switched_it_on_and_in_blocked_subjects(): void
    {
        Storage::fake('local');
        $school = School::factory()->create();
        [$schoolClass, $students, $teacher] = $this->classWithStudents($school);
        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->create(['subject' => 'Biologie']);

        $this->actingAs($teacher)->postJson(route('recordings.store', $lesson), ['present' => $students->modelKeys(), 'attendance_confirmed' => true])->assertForbidden();

        $religion = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->create(['subject' => 'Religion']);
        $this->assertSame(School::DEFAULT_MIKRO_BLOCKED_SUBJECTS, $school->mikroBlockedSubjects());
        $this->assertNotNull(RecordingConsentGate::lessonRefusal($religion));

        $this->actingAs($this->schoolAdmin($school));

        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk()
            ->assertSee('vorher lässt die Plattform das Tuto-Mikro nicht starten')
            ->assertSee('derzeit: '.implode(', ', School::DEFAULT_MIKRO_BLOCKED_SUBJECTS));

        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))->assertOk()
            ->assertSee('In den Fächern '.implode(', ', School::DEFAULT_MIKRO_BLOCKED_SUBJECTS).' bleibt es aus');
    }

    /**
     * "Tuto speichert keinen Ton": the session row holds the proof the templates list, and nothing else.
     */
    public function test_no_sound_is_stored_and_the_session_keeps_only_the_named_proof(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'lesson_id', 'teacher_id', 'started_at', 'finished_at', 'duration_seconds', 'present_student_ids', 'attendance_confirmed_at', 'consent_form_version', 'purposes', 'audio_sent_to_ai', 'created_at', 'updated_at'],
            Schema::getColumnListing('lesson_recordings'),
            'A new column of the Tuto-Mikro session has to be named in the privacy templates first.',
        );

        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk()
            ->assertSee('Es gibt keine Tondatei')
            ->assertSee('Lehrkraft, Beginn, Ende, Dauer, angehakte Schüler:innen, Zeitpunkt der Bestätigung der Anwesenheit, Fassung der Einwilligung, geprüfte Zwecke und ob Ton-Abschnitte an den KI-Anbieter gingen');

        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))->assertOk()->assertSee('Tuto speichert keinen Ton');
        $this->get(PrivacyDocuments::getUrl(['document' => 'einwilligung']))->assertOk()->assertSee('Der Ton wird nicht gespeichert.');
    }

    /**
     * The templates do not credit the platform with a reading step or an attendance check it does not enforce.
     */
    public function test_reading_the_notes_is_an_instruction_and_not_a_platform_control(): void
    {
        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk()
            ->assertSee('Sie erzwingt nicht, dass die Lehrkraft die Notizen aus der Stunde vor der Zusammenfassung liest');

        $this->get(PrivacyDocuments::getUrl(['document' => 'checkliste']))->assertOk()
            ->assertSee('Die Plattform erzwingt das Lesen und die Richtigkeit der Liste nicht');
    }

    /**
     * Text carried from the Tuto-Mikro into the notes is stored with the names replaced and follows the notes' deadline.
     */
    public function test_the_templates_name_what_happens_to_text_carried_into_the_notes(): void
    {
        config(['lernplattform.retention.school_years_kept' => 2]);
        $school = $this->schoolWithRecordings();
        [$schoolClass, $students, $teacher] = $this->classWithStudents($school, 1);
        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->create(['subject' => 'Biologie', 'notes' => '']);

        Livewire::actingAs($teacher)->test(RecordLesson::class, ['lesson' => $lesson])->call('applyToNotes', $students->first()->name.' hat gefragt.');

        $this->assertStringNotContainsString($students->first()->name, (string) $lesson->fresh()->notes);
        $this->assertStringContainsString('Schüler:in A hat gefragt.', (string) $lesson->fresh()->notes);

        $this->actingAs($this->schoolAdmin($school));

        $this->get(PrivacyDocuments::getUrl(['document' => 'einwilligung']))->assertOk()
            ->assertSee('Übernommener Text wird mit ersetzten Namen gespeichert und nach 2 Schuljahren');

        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))->assertOk()
            ->assertSee('wird dort mit ersetzten Namen gespeichert und folgt den Fristen der Notizen');

        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk()
            ->assertSee('geht beim Hausaufgaben-Entwurf pseudonymisiert an den KI-Anbieter, auch wenn er aus einer Sitzung ohne KI stammt');
    }

    public function test_the_special_categories_of_article_9_have_their_own_basis_and_explicit_consent(): void
    {
        $this->actingAs($this->schoolAdmin());

        $consent = $this->get(PrivacyDocuments::getUrl(['document' => 'einwilligung']))->assertOk()->getContent();
        $this->assertGreaterThanOrEqual(2, substr_count($consent, 'darin willige ich ausdrücklich ein (Art. 9 Abs. 2 lit. a DSGVO)'), 'Both variants, for parents and for staff, carry the explicit consent.');

        $this->get(PrivacyDocuments::getUrl(['document' => 'verzeichnis']))->assertOk()->assertSee('ausdrückliche Einwilligung nach Art. 9 Abs. 2 lit. a DSGVO');
        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))->assertOk()->assertSee('Art. 9 Abs. 2 lit. a DSGVO');
        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk()
            ->assertSee('Besondere Kategorien personenbezogener Daten möglich')
            ->assertSee('Art. 9 Abs. 2 lit. a');
    }

    public function test_the_parent_information_names_the_hosting_recipients_and_a_period_for_server_logs(): void
    {
        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))->assertOk()
            ->assertSee('technische Dienstleister als Unterauftragsverarbeiter')
            ->assertSee('Technische Protokolle des Servers')
            ->assertSee('auch beim Aufruf des persönlichen Einwilligungslinks')
            ->assertSee('löscht der Betreiber nach');

        $this->get(PrivacyDocuments::getUrl(['document' => 'verzeichnis']))->assertOk()
            ->assertSee('Server-Logfiles und Fehlerprotokoll: Frist des Betreibers');
    }

    /**
     * The access and deletion sentences of the templates describe the policies as they are.
     */
    public function test_the_access_and_deletion_statements_match_the_policies(): void
    {
        Storage::fake('local');
        $school = $this->schoolWithRecordings();
        [$schoolClass, $students, $teacher] = $this->classWithStudents($school);
        $admin = $this->schoolAdmin($school);
        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->create();
        $recording = LessonRecording::factory()->for($lesson)->create(['teacher_id' => $teacher->getKey()]);

        // The session proof belongs to the lesson's teacher; the administration does not get it, and nobody deletes a single one.
        $this->assertTrue($teacher->can('create', [LessonRecording::class, $lesson]));
        $this->assertFalse($admin->can('create', [LessonRecording::class, $lesson]));
        $this->assertFalse($teacher->can('delete', $recording));
        $this->assertFalse($admin->can('delete', $recording));

        // The way out the templates name: the administration deletes the lesson, the proof goes with it.
        $this->assertTrue($admin->can('delete', $lesson));
        $lesson->delete();
        $this->assertDatabaseMissing('lesson_recordings', ['id' => $recording->getKey()]);

        // "Erklär's mir" on a freely chosen topic is private to the student, also towards teacher and administration.
        $session = ExplanationSession::factory()->create(['student_id' => $students->first()->getKey(), 'lesson_id' => null]);
        $this->assertTrue($students->first()->can('view', $session));
        $this->assertFalse($teacher->can('view', $session));
        $this->assertFalse($admin->can('view', $session));

        // The Klassenbuch: teachers of the class and the administration, a substitute only while the substitution lasts, never students.
        $substitution = ClassSubstitution::factory()->create(['school_class_id' => $schoolClass->getKey()]);
        $this->assertTrue($teacher->can('accessClass', [ClassBookEntry::class, $schoolClass]));
        $this->assertTrue($admin->can('accessClass', [ClassBookEntry::class, $schoolClass]));
        $this->assertTrue($substitution->substitute->can('accessClass', [ClassBookEntry::class, $schoolClass]));
        $this->assertFalse($students->first()->can('accessClass', [ClassBookEntry::class, $schoolClass]));
        $substitution->end();
        $this->assertFalse($substitution->substitute->fresh()->can('accessClass', [ClassBookEntry::class, $schoolClass->fresh()]));

        $this->actingAs($admin);

        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))->assertOk()
            ->assertDontSee('kann alle Daten der Schule einsehen')
            ->assertSee('mit zwei Ausnahmen: Notizen und Nachweise des Tuto-Mikro')
            ->assertSee('zu einem frei gewählten Thema sieht nur Ihr Kind')
            ->assertSee('Ihr Kind sieht die eigenen Daten, aber nicht das Klassenbuch');

        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk()
            ->assertSee('Den Nachweis sieht nur die Lehrkraft der Stunde')
            ->assertSee('Einen einzelnen Nachweis einer Tuto-Mikro-Sitzung kann über die Plattform niemand löschen')
            ->assertSee('Gespräche zu einem frei gewählten Thema: nur die Schüler:in selbst')
            ->assertSee('eine Vertretung, solange sie dauert, mit allen Einträgen und dem Export');
    }

    /**
     * The substitution and the bell: the texts take the list of what a substitution grants and the deadlines from the code.
     */
    public function test_the_substitution_and_notification_statements_follow_the_code(): void
    {
        config(['lernplattform.notifications.keep_read_days' => 7, 'lernplattform.notifications.keep_days' => 30, 'lernplattform.substitutions.max_days' => 45]);
        $this->actingAs($this->schoolAdmin());

        foreach (['checkliste', 'dsfa', 'verzeichnis', 'elterninformation'] as $key) {
            $this->get(PrivacyDocuments::getUrl(['document' => $key]))->assertOk()->assertSee('Stunden anlegen, die Lernstände der Klasse sehen, das Klassenbuch mit allen Fehlzeiten lesen und exportieren, Einwilligungen der Eltern erfassen und Tuto-Mikro nutzen');
        }

        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk()
            ->assertSee('7 Tage nach dem Lesen, spätestens 30 Tage nach dem Erstellen')
            ->assertSee('höchstens 45 Tage');

        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))->assertOk()
            ->assertSee('7 Tage nach dem Lesen gelöscht, spätestens nach 30 Tagen');
    }

    /**
     * "Jede Person kann ihre Daten im Profil herunterladen": the sections the DSFA and the parent information name are in
     * the export of a student and of a teacher, even while they are empty.
     */
    public function test_the_data_export_holds_what_the_templates_name(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $studentExport = PersonalDataExport::for(User::factory()->student($schoolClass)->create());
        $teacherExport = PersonalDataExport::for(User::factory()->teacher()->for($schoolClass->school)->create());

        foreach ([$studentExport, $teacherExport] as $export) {
            $this->assertArrayHasKey('sprache', $export['konto']);
            $this->assertArrayHasKey('benachrichtigungen', $export);
        }

        foreach (['zugewiesene_hausaufgaben', 'abgaben', 'erklaer_mir_gespraeche', 'rueckfragen', 'klassenbuch_fehlzeiten', 'einwilligung_tuto_mikro', 'tuto_mikro_anwesenheit'] as $section) {
            $this->assertArrayHasKey($section, $studentExport);
        }

        foreach (['klassenleitung', 'zugeordnete_klassen', 'vertretungen', 'unterrichtsstunden', 'beantwortete_rueckfragen', 'klassenbuch_eintraege', 'tuto_mikro_sitzungen'] as $section) {
            $this->assertArrayHasKey($section, $teacherExport);
        }

        $this->actingAs($this->schoolAdmin());

        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk()
            ->assertSee('Das Konto mit der gewählten Sprache und die Benachrichtigungen gehören immer dazu')
            ->assertSee('Bei Schüler:innen außerdem: Hausaufgaben, Abgaben mit Stufe, Gespräche, Rückfragen, Fehlzeiten im Klassenbuch, die Einwilligung zum Tuto-Mikro und die eigene Anwesenheit in Sitzungen')
            ->assertSee('Bei Lehrkräften: Klassen, Vertretungen, Unterrichtsstunden, beantwortete Rückfragen, Klassenbuch-Einträge und Tuto-Mikro-Sitzungen');

        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))->assertOk()
            ->assertSee('das Konto, die Abgaben, Gespräche und Rückfragen, die Fehlzeiten im Klassenbuch, die Einwilligung zum Tuto-Mikro, die eigene Anwesenheit in Sitzungen und die Hinweise in der Glocke');
    }

    /**
     * "Nach dem letzten Tag endet der Zugriff von selbst, auch auf die Ergebnisse der Stunden, die die Vertretung gehalten
     * hat": the "Erklär's mir" conversations of a substitute's lessons leave the list and the view with the substitution.
     */
    public function test_a_substitute_sees_the_conversations_of_their_lessons_only_while_the_substitution_lasts(): void
    {
        $school = School::factory()->create();
        [$schoolClass, $students] = $this->classWithStudents($school, 1);
        $substitute = User::factory()->teacher()->for($school)->create();
        $substitution = ClassSubstitution::factory()->for($schoolClass)->create(['substitute_id' => $substitute->getKey(), 'starts_on' => today(), 'ends_on' => today()->addDays(2)]);
        $lesson = Lesson::factory()->for($schoolClass)->for($substitute, 'teacher')->create();
        $session = ExplanationSession::factory()->create(['student_id' => $students->first()->getKey(), 'lesson_id' => $lesson->getKey()]);

        $this->assertTrue($substitute->can('view', $session));
        Livewire::actingAs($substitute)->test(ListExplanationSessions::class)->assertCanSeeTableRecords([$session]);

        $substitution->end();
        $substitute = $substitute->fresh();

        $this->assertFalse($substitute->can('view', $session));
        Livewire::actingAs($substitute)->test(ListExplanationSessions::class)->assertCanNotSeeTableRecords([$session]);

        $this->actingAs($this->schoolAdmin($school));

        $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk()
            ->assertSee('die Lehrkraft der Stunde (eine Vertretung nur, solange sie dauert) und die Schuladministration. Gespräche zu einem frei gewählten Thema')
            ->assertSee('Nach dem letzten Tag endet der Zugriff von selbst, auch auf die Ergebnisse der Stunden, die die Vertretung gehalten hat');

        $this->get(PrivacyDocuments::getUrl(['document' => 'elterninformation']))->assertOk()
            ->assertSee('Danach endet der Zugriff von selbst, auch auf Abgaben, Gespräche und Rückfragen zu den Stunden, die sie in der Klasse gehalten hat');

        $this->get(PrivacyAndAi::getUrl())->assertOk()
            ->assertSee('danach endet der Zugriff von selbst, auch auf die Ergebnisse der Stunden, die sie gehalten hat');
    }

    /**
     * Browsers leave backgrounds out of a printout by default, so a ticked box must be drawn with borders.
     */
    public function test_a_ticked_box_does_not_depend_on_a_background_when_printed(): void
    {
        $this->actingAs($this->schoolAdmin());

        $html = $this->get(PrivacyDocuments::getUrl(['document' => 'dsfa']))->assertOk()->getContent();
        $style = Str::before(Str::after($html, '/* The templates: one calm reading column'), '</style>');

        $this->assertStringContainsString('.pd-check.pd-on::after', $style);
        $this->assertStringNotContainsString('linear-gradient', $style);
        $this->assertStringContainsString('trifft zu', $html);
    }

    public function test_the_operator_sees_the_templates_without_data_of_any_school(): void
    {
        $school = School::factory()->create(['name' => 'Goethe-Gymnasium']);
        $this->actingAs(User::factory()->operator()->create());

        $response = $this->get(PrivacyDocuments::getUrl(['document' => 'verzeichnis']))
            ->assertOk()
            ->assertSee('Du siehst die Vorlagen ohne Angaben zu einer Schule')
            ->assertSee('Name der Schule')
            ->assertDontSee('Stand in der Plattform')
            ->assertSee('Softwareentwicklung Sebastian Bleuel')
            ->assertDontSee($school->name);

        $this->assertStringContainsString('pd-blank', $this->articleOf($response->getContent()));
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function rolesWithoutAccess(): array
    {
        return [
            'Lehrkraft' => [Role::Teacher],
            'Schüler:in' => [Role::Student],
        ];
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_teachers_and_students_get_no_access(Role $role): void
    {
        $user = User::factory()->{$role->value}()->create();
        $this->actingAs($user);

        $this->assertFalse(PrivacyDocuments::canAccess());

        foreach (['checkliste', 'dsfa', 'verzeichnis', 'elterninformation', 'einwilligung'] as $key) {
            $this->get(PrivacyDocuments::getUrl(['document' => $key]))->assertForbidden();
        }
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get(PrivacyDocuments::getUrl())->assertRedirect();
    }

    public function test_the_page_sits_in_the_menu_group_of_the_role(): void
    {
        $this->actingAs($this->schoolAdmin());
        $this->assertSame(NavigationGroup::Verwaltung, PrivacyDocuments::getNavigationGroup());
        $this->assertTrue(PrivacyDocuments::canAccess());

        $this->actingAs(User::factory()->operator()->create());
        $this->assertSame(NavigationGroup::Plattform, PrivacyDocuments::getNavigationGroup());
        $this->assertTrue(PrivacyDocuments::canAccess());
    }
}
