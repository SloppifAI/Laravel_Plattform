<?php

namespace Tests\Feature\Consents;

use App\Filament\Pages\ClassConsents;
use App\Filament\Pages\MyRecordingConsent;
use App\Filament\Pages\RecordingSettings;
use App\Models\RecordingConsent;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\RecordingConsentGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The in-app consent pages in both languages. The suite runs in English (phpunit.xml), so German is the one to prove
 * here; the parents' page, the letters and the consent form text stay German in both and are not part of this.
 */
class ConsentPagesLocaleTest extends TestCase
{
    use CreatesConsentFixtures;
    use RefreshDatabase;

    private const array FORBIDDEN_WORDS = [
        'en' => '/\b(record\w*|transcri\w*|monitor\w*|listen\w* in)\b/i',
        'de' => '/aufnehm|aufnahm|aufgenommen|mitschnitt|abhör|überwach|transkript/iu',
    ];

    /**
     * @return array{0: User, 1: User, 2: User, 3: SchoolClass}
     */
    private function people(): array
    {
        $school = $this->schoolWithRecordings(requireStudentConsent: true);
        $form = $this->publishedForm($school);
        [$schoolClass, $students, $teacher] = $this->classWithStudents($school, 2);
        RecordingConsent::factory()->forForm($form)->studentConfirmed()->create(['student_id' => $students->first()->getKey()]);

        return [User::factory()->for($school)->admin()->create(), $teacher, $students->first(), $schoolClass];
    }

    private function visibleText(string $html): string
    {
        return html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', '', $html)));
    }

    public function test_the_pages_speak_german_with_the_german_default(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        [$admin, $teacher, $student, $schoolClass] = $this->people();

        $this->actingAs($admin)->get(RecordingSettings::getUrl())->assertOk()
            ->assertSee('Tuto-Mikro & Einwilligungen')
            ->assertSee('Voraussetzungen')
            ->assertSee('Datenschutz-Folgenabschätzung durchgeführt')
            ->assertDontSee('Requirements');

        $this->actingAs($teacher)->get(ClassConsents::getUrl(['klasse' => $schoolClass->getKey()]))->assertOk()
            ->assertSee('Elternbriefe erzeugen')
            ->assertSee('Papier-Einwilligung erfassen')
            ->assertSee('1 von 2 Einwilligungen vollständig')
            ->assertSee('Noch kein Brief erzeugt')
            ->assertDontSee('Create parent letters');

        $this->actingAs($student)->get(MyRecordingConsent::getUrl())->assertOk()
            ->assertSee('Tuto-Mikro im Unterricht')
            ->assertSee('Dein Einverständnis')
            ->assertDontSee('Your agreement');
    }

    public function test_the_refusal_reasons_of_the_gate_and_the_status_labels_follow_the_language(): void
    {
        [, $teacher, , $schoolClass] = $this->people();

        app()->setLocale('de');
        $result = RecordingConsentGate::check($schoolClass, $schoolClass->students, $teacher);

        $this->assertContains('Für 1 von 2 anwesenden Schüler:innen liegt keine gültige Einwilligung vor.', $result->reasons);

        app()->setLocale('en');
        $result = RecordingConsentGate::check($schoolClass, $schoolClass->students, $teacher);

        $this->assertContains('Valid consent is missing for 1 of 2 students present.', $result->reasons);
    }

    public function test_the_pages_never_use_the_forbidden_words_in_either_language(): void
    {
        [$admin, $teacher, $student, $schoolClass] = $this->people();

        foreach (self::FORBIDDEN_WORDS as $locale => $forbidden) {
            config(['lernplattform.locale.default' => $locale]);

            $pages = [
                'settings' => $this->actingAs($admin)->get(RecordingSettings::getUrl())->assertOk()->getContent(),
                'class list' => $this->actingAs($teacher)->get(ClassConsents::getUrl(['klasse' => $schoolClass->getKey()]))->assertOk()->getContent(),
                'student page' => $this->actingAs($student)->get(MyRecordingConsent::getUrl())->assertOk()->getContent(),
            ];

            foreach ($pages as $name => $html) {
                $this->assertSame(1, preg_match('/<main\b.*<\/main>/s', $html, $main), $name);
                $this->assertDoesNotMatchRegularExpression($forbidden, $this->visibleText($main[0]), "{$name} ({$locale})");
            }
        }
    }
}
