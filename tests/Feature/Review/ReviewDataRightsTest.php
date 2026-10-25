<?php

namespace Tests\Feature\Review;

use App\Filament\Pages\PrivacyDocuments;
use App\Models\Lesson;
use App\Models\School;
use App\Models\User;
use App\ReviewRating;
use App\Support\PersonalDataExport;
use App\Support\ReviewSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Heute wiederholen" and the data rights: the rows go with their lesson and student (AC-6), the student's data export lists
 * them (AC-7), and the privacy texts say what is stored.
 */
class ReviewDataRightsTest extends TestCase
{
    use CreatesReviewFixtures;
    use RefreshDatabase;

    // ---- AC-6: retention ---------------------------------------------------------------------------------------------

    public function test_the_rows_go_with_their_lesson_a_deleted_question_and_the_student(): void
    {
        $student = $this->newStudent();
        $lesson = Lesson::factory()->for($student->schoolClass)->publishedFor([$student])->create();
        $first = $this->itemFor($student, lesson: $lesson);
        $second = $this->itemFor($student, lesson: $lesson);
        $other = $this->itemFor($student);

        $second->question->delete();

        $this->assertModelMissing($second);
        $this->assertModelExists($first);

        $lesson->delete();

        $this->assertModelMissing($first);
        $this->assertModelExists($other);

        $student->delete();

        $this->assertModelMissing($other);
        $this->assertDatabaseEmpty('review_items');
    }

    public function test_the_nightly_cleanup_of_learning_data_takes_the_rows_of_an_old_lesson_with_it(): void
    {
        Storage::fake('local');
        config([
            'lernplattform.retention.school_years_kept' => 1,
            'lernplattform.retention.school_year_starts' => '08-01',
            'lernplattform.retention.one_year_rule_from' => null,
        ]);
        $this->travelTo('2026-08-01');
        $student = $this->newStudent();
        $lastYear = Lesson::factory()->for($student->schoolClass)->publishedFor([$student])->create(['held_on' => '2026-07-31', 'due_on' => '2026-07-31', 'created_at' => '2026-07-31', 'updated_at' => '2026-07-31']);
        $thisYear = Lesson::factory()->for($student->schoolClass)->publishedFor([$student])->create(['held_on' => '2026-08-01']);
        $old = $this->itemFor($student, lesson: $lastYear);
        $current = $this->itemFor($student, lesson: $thisYear);

        $this->artisan('lernplattform:prune-learning-data')->assertSuccessful();

        $this->assertModelMissing($lastYear);
        $this->assertModelMissing($old);
        $this->assertModelExists($thisYear);
        $this->assertModelExists($current);
    }

    // ---- AC-7: the data export ---------------------------------------------------------------------------------------

    public function test_the_data_export_lists_the_revision_data_of_the_student_without_the_solution(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        app()->setLocale('de');
        $this->travelTo('2026-10-20 09:30:00');
        $student = $this->newStudent();
        $lesson = Lesson::factory()->for($student->schoolClass)->publishedFor([$student])->create(['subject' => 'Biologie', 'topic' => 'Zellen', 'held_on' => '2026-10-01']);
        $item = $this->itemFor($student, ['prompt' => 'Was macht der Zellkern?', 'expected_answer' => 'LOESUNG-GEHEIM-4711'], ['box' => 2, 'due_on' => '2026-10-20'], $lesson);
        $rated = $this->itemFor($student, ['prompt' => 'Was ist ein Chloroplast?', 'expected_answer' => 'LOESUNG-GEHEIM-0815'], ['box' => 1, 'due_on' => '2026-10-20'], $lesson);
        ReviewSchedule::rate($rated, ReviewRating::Hard);
        ReviewSchedule::rate($item, ReviewRating::Knew);
        $classmate = User::factory()->student($student->schoolClass)->create();
        $this->itemFor($classmate, ['prompt' => 'Frage-der-Mitschuelerin']);

        $export = PersonalDataExport::for($student);

        $this->assertSame([
            [
                'fach' => 'Biologie',
                'thema' => 'Zellen',
                'frage' => 'Was macht der Zellkern?',
                'box' => 3,
                'faellig_am' => '2026-10-27',
                'zuletzt_wiederholt_am' => '2026-10-20T09:30:00+00:00',
                'anzahl_sofort_gewusst' => 1,
                'anzahl_mit_muehe' => 0,
                'anzahl_nicht_gewusst' => 0,
            ],
            [
                'fach' => 'Biologie',
                'thema' => 'Zellen',
                'frage' => 'Was ist ein Chloroplast?',
                'box' => 1,
                'faellig_am' => '2026-10-21',
                'zuletzt_wiederholt_am' => '2026-10-20T09:30:00+00:00',
                'anzahl_sofort_gewusst' => 0,
                'anzahl_mit_muehe' => 1,
                'anzahl_nicht_gewusst' => 0,
            ],
        ], $export['wiederholungsfragen']);

        $json = json_encode($export, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach (['LOESUNG-GEHEIM', 'expected_answer', 'Frage-der-Mitschuelerin'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_the_export_of_a_student_has_an_empty_section_and_nobody_else_gets_it(): void
    {
        $student = $this->newStudent();
        $teacher = $student->schoolClass->teacher;
        $admin = User::factory()->admin()->for($student->school)->create();
        $this->itemFor($student, ['prompt' => 'Frage-des-Schuelers']);

        $this->assertSame([], PersonalDataExport::for($this->newStudent())['wiederholungsfragen']);
        $this->assertCount(1, PersonalDataExport::for($student)['wiederholungsfragen']);
        $this->assertCount(1, PersonalDataExport::for($student, $student)['wiederholungsfragen']);

        // The administration may ask for the export of a student of its school, a teacher for one of the class: never with this section.
        foreach ([$admin, $teacher] as $viewer) {
            $export = PersonalDataExport::for($student, $viewer);

            $this->assertArrayNotHasKey('wiederholungsfragen', $export);
            $this->assertStringNotContainsString('Frage-des-Schuelers', json_encode($export, JSON_UNESCAPED_UNICODE));
        }

        foreach ([$teacher, $admin, User::factory()->operator()->create()] as $user) {
            $this->assertArrayNotHasKey('wiederholungsfragen', PersonalDataExport::for($user));
        }
    }

    // ---- The privacy texts -------------------------------------------------------------------------------------------

    public function test_the_privacy_policy_says_what_is_kept_for_revising_and_who_sees_it(): void
    {
        $this->withoutVite();

        $text = html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($this->get(route('legal.privacy'))->assertOk()->getContent())));

        foreach ([
            '„Heute wiederholen“ (Schüler:innen):',
            'jede beantwortete Verständnis- und Anwendungsfrage (Reflexionsfragen nicht)',
            'drei Zähler, wie oft sich die Schüler:in mit „Sofort gewusst“, „Mit Mühe“ oder „Nicht gewusst“ eingeschätzt hat',
            'Nicht gespeichert werden die Antwort, die beim Wiederholen aus dem Kopf gegeben wird (das Feld bleibt im Browser), einzelne Versuche, Antwortzeiten und Serien.',
            'Der Plan ist fest und kommt ohne KI aus: Eine Frage kommt je nach Box nach 1, 3, 7 und 21 Tagen wieder',
            'höchstens 5 Fragen am Tag werden gestellt',
            'keinen Elterngesprächs-Bericht, und außer der Schüler:in sieht sie niemand.',
            'Zu einer Frage zeigt die Plattform die Lösung (den Erwartungshorizont der Lehrkraft) erst, wenn die Abgabe der Schüler:in ausgewertet und die Frist der Hausaufgabe vorbei ist; wird eine Abgabe zur Überarbeitung zurückgegeben, werden die Wiederholungsdaten zu dieser Hausaufgabe gelöscht.',
            '„Heute wiederholen“ (Wiederholungsfragen und Selbsteinschätzung)',
            'Mit dem Konto einer Schüler:in gehen auch ihre Wiederholungsfragen („Heute wiederholen“).',
            'Die Wiederholungsfragen („Heute wiederholen“) hängen an den Fragen der Stunde und verschwinden mit ihr.',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $text);
        }
    }

    public function test_the_privacy_policy_follows_the_configured_days_and_limit(): void
    {
        $this->withoutVite();
        config(['lernplattform.review.intervals' => [2, 4], 'lernplattform.review.daily_limit' => 8]);

        $text = html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($this->get(route('legal.privacy'))->assertOk()->getContent())));

        $this->assertStringContainsString('(1 bis 3)', $text);
        $this->assertStringContainsString('nach 2 und 4 Tagen wieder', $text);
        $this->assertStringContainsString('höchstens 8 Fragen am Tag werden gestellt', $text);
    }

    public function test_the_record_of_processing_names_the_revision_data(): void
    {
        $admin = User::factory()->for(School::factory()->create())->admin()->create();
        $this->actingAs($admin);

        $html = $this->get(PrivacyDocuments::getUrl(['document' => 'verzeichnis']))->assertOk()->getContent();
        $article = Str::before(Str::after($html, '<article'), '</article>');
        $text = html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($article)));

        foreach ([
            'Wiederholen früherer Fragen in Abständen („Heute wiederholen“)',
            'Wiederholungsplan zu früheren Fragen („Heute wiederholen“: Verweis auf die Frage, Box 1 bis 5, Datum der nächsten Wiederholung, Zähler der Selbsteinschätzungen; keine eigenen Antworten, keine Antwortzeiten)',
            'Wiederholungsplänen',
            'Den Wiederholungsplan („Heute wiederholen“) und die Selbsteinschätzungen sieht nur die Schüler:in selbst, weder Lehrkräfte noch die Schuladministration.',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $text);
        }
    }

    public function test_the_parent_information_and_the_impact_assessment_name_the_revision_data(): void
    {
        $this->actingAs(User::factory()->for(School::factory()->create())->admin()->create());

        $parents = $this->privacyDocumentText('elterninformation');

        foreach ([
            'Wenn Ihr Kind „Heute wiederholen“ nutzt: je Frage aus früheren Hausaufgaben ein Wiederholungsplan mit der Box (1 bis 5)',
            'drei Zählern, wie oft sich Ihr Kind mit „Sofort gewusst“, „Mit Mühe“ oder „Nicht gewusst“ eingeschätzt hat',
            'Die eigene Antwort beim Wiederholen, einzelne Versuche und Antwortzeiten werden nicht gespeichert.',
            'keine Note und fließt weder in den Verständniswert noch in einen Bericht ein',
            'Die Lösung sieht Ihr Kind dort erst, wenn seine Abgabe ausgewertet und die Frist der Hausaufgabe vorbei ist.',
            'Dasselbe gilt für den Wiederholungsplan in „Heute wiederholen“: Lehrkräfte sehen ihn nicht.',
            'mit drei Ausnahmen: Notizen und Nachweise des Tuto-Mikro (siehe Abschnitt 7), Gespräche in „Erklär’s mir“ zu einem frei gewählten Thema und der Wiederholungsplan in „Heute wiederholen“.',
            'Unterrichtsstunden mit Abgaben, Antworten, Wiederholungsplänen und Rückfragen',
            'Rückfragen, die Wiederholungsfragen, die Fehlzeiten im Klassenbuch',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $parents);
        }

        $this->assertStringNotContainsString('mit zwei Ausnahmen', $parents);

        $assessment = $this->privacyDocumentText('dsfa');

        foreach ([
            'Wiederholungsplan („Heute wiederholen“)',
            'Je Schüler:in und Frage aus einer ausgewerteten Hausaufgabe oder Förderaufgabe: Verweis auf die Frage, Box 1 bis 5',
            'Nur die Schüler:in selbst. Lehrkräfte, Schuladministration und Plattform-Administration sehen weder den Plan noch die Selbsteinschätzungen.',
            'Die Lösung (Erwartungshorizont) zeigt Tuto erst, wenn die Abgabe der Schüler:in ausgewertet und die Frist der Hausaufgabe vorbei ist.',
            'wenn die Abgabe zur Überarbeitung zurückgegeben wird',
            'Den Erwartungshorizont sehen sie erst in „Heute wiederholen“, wenn ihre Abgabe ausgewertet und die Frist der Hausaufgabe vorbei ist.',
            'Bei Schüler:innen außerdem: Hausaufgaben, Abgaben mit Stufe, Gespräche, Rückfragen, Wiederholungsfragen, Fehlzeiten',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $assessment);
        }
    }

    /**
     * The text of one of the school's privacy documents, whitespace collapsed.
     */
    private function privacyDocumentText(string $document): string
    {
        $html = $this->get(PrivacyDocuments::getUrl(['document' => $document]))->assertOk()->getContent();

        return html_entity_decode(preg_replace('/\s+/', ' ', strip_tags(Str::before(Str::after($html, '<article'), '</article>'))));
    }
}
