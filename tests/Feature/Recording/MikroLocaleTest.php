<?php

namespace Tests\Feature\Recording;

use App\Filament\Pages\MikroBoard;
use App\Models\LessonRecording;
use App\Models\RecordingConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tuto-Mikro in both languages. The suite runs in English (phpunit.xml), so German is the one to prove here. The
 * wording rules hold in both: never "record", "transcript", "monitor" or "listen in" in English, never "aufnehmen",
 * "Aufnahme", "Mitschnitt", "abhören", "überwachen" or "Transkript" in German.
 */
class MikroLocaleTest extends TestCase
{
    use RecordingFixtures;
    use RefreshDatabase;

    private const array FORBIDDEN_WORDS = [
        'en' => '/\b(record\w*|transcri\w*|monitor\w*|listen\w* in)\b/i',
        'de' => '/aufnehm|aufnahm|aufgenommen|mitschnitt|abhör|überwach|transkript/iu',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLesson();
    }

    private function pageHtml(): string
    {
        return $this->actingAs($this->teacher)->get($this->pageUrl())->assertOk()->getContent();
    }

    private function boardHtml(): string
    {
        return $this->actingAs($this->teacher)->get(MikroBoard::getUrl(['lesson' => $this->lesson]))->assertOk()->getContent();
    }

    public function test_the_page_and_the_texts_of_its_script_speak_german_with_the_german_default(): void
    {
        config(['lernplattform.locale.default' => 'de']);

        $html = $this->pageHtml();

        foreach (['Wer ist heute da?', 'Notizen aus der Stunde', 'Zusammenfassung erstellen', 'Mikro an', 'Mikro aus', 'Beamer-Anzeige öffnen', 'Nur dein Mikrofon.'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $this->assertStringContainsString('Alle 3 anwesenden Schüler:innen haben eingewilligt (Fassung '.$this->form->version.' der Einwilligungserklärung). Du kannst das Mikro einschalten.', $html);
        $this->assertStringNotContainsString('Who is here today?', $html);

        $texts = $this->micTexts($html);

        $this->assertSame('Mikro an – Tuto schreibt mit', $texts['barOn']);
        $this->assertSame('Mikro aus – du schreibst die Notizen selbst', $texts['barManual']);
        $this->assertSame('Das Mikrofon wurde getrennt oder gesperrt. Das Mikro ist aus.', $texts['micDisconnected']);
    }

    public function test_the_projector_view_speaks_german_with_the_german_default(): void
    {
        config(['lernplattform.locale.default' => 'de']);

        $this->actingAs($this->teacher)->get(MikroBoard::getUrl(['lesson' => $this->lesson]))
            ->assertOk()
            ->assertSee('Tuto-Mikro am Beamer')
            ->assertSee('Mikro an')
            ->assertSee('Mikro aus')
            ->assertSee('Einen Moment …')
            ->assertSee('Vollbild')
            ->assertDontSee('Full screen');
    }

    public function test_the_json_messages_follow_the_language_of_the_teacher_not_the_platform_default(): void
    {
        config(['lernplattform.locale.default' => 'en']);
        $this->teacher->update(['locale' => 'de']);

        $this->startSession(attendanceConfirmed: false)->assertUnprocessable()
            ->assertJsonPath('message', 'Bestätige, dass nur die angehakten Schüler:innen im Raum sind.');

        RecordingConsent::query()->where('student_id', $this->students[0]->id)->delete();

        $this->startSession()->assertConflict()
            ->assertJsonPath('message', 'Für 1 von 3 anwesenden Schüler:innen liegt keine gültige Einwilligung vor.');

        config(['lernplattform.locale.default' => 'de']);
        $this->teacher->update(['locale' => 'en']);

        $this->startSession(attendanceConfirmed: false)->assertUnprocessable()
            ->assertJsonPath('message', 'Confirm that only the ticked students are in the room.');
    }

    public function test_a_finished_session_answers_in_the_language_of_the_teacher(): void
    {
        $recording = $this->startedSession();
        $recording->update(['finished_at' => now(), 'duration_seconds' => 60]);
        $this->teacher->update(['locale' => 'de']);

        $this->actingAs($this->teacher)->postJson(route('recordings.check', $recording))
            ->assertConflict()
            ->assertJsonPath('message', 'Das Mikro ist schon aus.');
    }

    public function test_the_refusal_reasons_of_the_gate_come_in_the_active_language(): void
    {
        $this->switchRecordings($this->schoolClass->school, false);

        app()->setLocale('de');
        $this->assertContains('Tuto-Mikro ist für deine Schule nicht freigeschaltet.', LessonRecording::consentCheck($this->lesson, $this->teacher, $this->students)->reasons);

        app()->setLocale('en');
        $this->assertContains('Tuto-Mikro is not enabled for your school.', LessonRecording::consentCheck($this->lesson, $this->teacher, $this->students)->reasons);
    }

    public function test_the_pages_never_use_the_forbidden_words_in_either_language(): void
    {
        $states = [
            'switch on, nothing missing' => fn () => null,
            'a consent missing' => fn () => RecordingConsent::query()->where('student_id', $this->students[0]->id)->delete(),
            'sessions listed' => fn () => LessonRecording::factory()->for($this->lesson)->presentStudents($this->students)->finished(2700)->create(['audio_sent_to_ai' => true]),
            'the school ai on' => fn () => $this->enableAi(),
            'the school switch off' => fn () => $this->switchRecordings($this->schoolClass->school, false),
        ];

        foreach (self::FORBIDDEN_WORDS as $locale => $forbidden) {
            config(['lernplattform.locale.default' => $locale]);

            foreach ($states as $state => $arrange) {
                $arrange();
                $html = $this->pageHtml();

                $this->assertSame(1, preg_match('/<main\b.*<\/main>/s', $html, $main));
                $this->assertDoesNotMatchRegularExpression($forbidden, $this->visibleText($main[0]), "page ({$locale}, {$state})");

                // The script's own sentences travel in the component config, which visibleText() strips with the attribute.
                foreach ($this->micTexts($html) as $key => $text) {
                    $this->assertDoesNotMatchRegularExpression($forbidden, $text, "script text {$key} ({$locale}, {$state})");
                }

                $this->assertDoesNotMatchRegularExpression($forbidden, $this->visibleText($this->boardHtml()), "projector view ({$locale}, {$state})");
            }
        }
    }
}
