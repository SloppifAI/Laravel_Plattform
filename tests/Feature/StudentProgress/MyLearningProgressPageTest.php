<?php

namespace Tests\Feature\StudentProgress;

use App\Filament\Pages\ExplainIt;
use App\Filament\Pages\MyHomework;
use App\Filament\Pages\MyLearningProgress;
use App\Filament\Pages\ParentReport;
use App\Filament\Pages\WorkOnHomework;
use App\Models\Competency;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyLearningProgressPageTest extends TestCase
{
    use CreatesLearningResults;
    use RefreshDatabase;

    public function test_only_students_can_open_the_page(): void
    {
        $this->actingAs(User::factory()->student(SchoolClass::factory()->create())->create())
            ->get(MyLearningProgress::getUrl())
            ->assertOk()
            ->assertSee('Mein Lernstand');

        $this->actingAs(User::factory()->teacher()->create())->get(MyLearningProgress::getUrl())->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(MyLearningProgress::getUrl())->assertForbidden();
    }

    public function test_competencies_are_shown_in_their_sections_with_a_shortened_description(): void
    {
        $student = User::factory()->student(SchoolClass::factory()->create())->create();
        $homework = $this->evaluatedHomework($student, 'Photosynthese');

        $this->answer($homework, Competency::factory()->create(['name' => 'Fachbegriffe erklären', 'description' => str_repeat('Das ist eine lange Beschreibung. ', 8).'ENDE']), 90);
        $this->answer($homework, Competency::factory()->create(['name' => 'Versuche planen']), 30);
        $this->answer($homework, Competency::factory()->create(['name' => 'Diagramme lesen']), 60);

        $this->actingAs($student)
            ->get(MyLearningProgress::getUrl())
            ->assertOk()
            ->assertSee('keine Noten')
            ->assertSeeInOrder([
                'Das kannst du schon gut', 'Fachbegriffe erklären', 'Gut verstanden',
                'Hier lohnt sich Üben', 'Versuche planen', '30 %', 'Noch üben',
                'Fast geschafft', 'Diagramme lesen', '60 %', 'Teilweise verstanden', 'Thema Tuto erklären', 'zu Diagramme lesen',
            ])
            // Cut at a word boundary, never in the middle of a word.
            ->assertSee('Beschreibung. Das ist...')
            ->assertDontSee('Das ist ein...')
            ->assertDontSee('ENDE')
            // Teacher language and the red warning do not belong on the student's page.
            ->assertDontSee('Förderbedarf');
    }

    public function test_practice_shows_lessons_gaps_and_the_next_steps_for_each_lesson(): void
    {
        $student = User::factory()->student(SchoolClass::factory()->create())->create();
        $weak = Competency::factory()->create(['name' => 'Versuche planen']);
        $strong = Competency::factory()->create();

        $photosynthesis = $this->evaluatedHomework($student, 'Photosynthese', '2026-10-01', ['Wie Licht in Energie umgewandelt wird']);
        $fractions = $this->evaluatedHomework($student, 'Bruchrechnung', '2026-10-10', ['Brüche gleichnamig machen']);
        $cells = $this->evaluatedHomework($student, 'Zellaufbau', '2026-10-15', ['Aufgaben der Zellkern-Organelle']);
        $this->answer($photosynthesis, $weak, 30);
        $this->answer($fractions, $weak, 20);
        $this->answer($cells, $weak, 90);
        $this->answer($cells, $strong, 85);

        $this->actingAs($student)
            ->get(MyLearningProgress::getUrl())
            ->assertOk()
            ->assertSeeInOrder(['Bei diesen Hausaufgaben hat es noch gehakt', 'Biologie:', 'Bruchrechnung', '10.10.2026', 'Photosynthese', '01.10.2026'])
            // The gaps belong to the homework, not to one competency, so the heading says so.
            ->assertSee('Das kannst du dir in diesen Hausaufgaben noch einmal anschauen')
            ->assertSee('Wie Licht in Energie umgewandelt wird')
            ->assertSee('Brüche gleichnamig machen')
            ->assertSee('Ergebnis ansehen')
            ->assertSee('Thema Tuto erklären')
            // Every link says where it leads, also for screen readers.
            ->assertSee('Ergebnis ansehen<span class="sr-only"> zu Photosynthese</span>', false)
            ->assertSee('Thema Tuto erklären<span class="sr-only"> zu Bruchrechnung</span>', false)
            ->assertSee(WorkOnHomework::getUrl(['lesson' => $photosynthesis->lesson_id]), false)
            ->assertSee(ExplainIt::getUrl(['lesson' => $photosynthesis->lesson_id]), false)
            ->assertSee(WorkOnHomework::getUrl(['lesson' => $fractions->lesson_id]), false)
            ->assertSee(ExplainIt::getUrl(['lesson' => $fractions->lesson_id]), false)
            // The lesson in which the competency went well offers no practice link.
            ->assertDontSee(WorkOnHomework::getUrl(['lesson' => $cells->lesson_id]), false)
            ->assertDontSee(ExplainIt::getUrl(['lesson' => $cells->lesson_id]), false)
            ->assertDontSee('Aufgaben der Zellkern-Organelle');
    }

    public function test_average_trend_and_number_of_evaluated_homework_are_shown(): void
    {
        $student = User::factory()->student(SchoolClass::factory()->create())->create();
        $this->evaluatedHomework($student, 'Photosynthese', '2026-10-01', understandingScore: 40);
        $this->evaluatedHomework($student, 'Zellaufbau', '2026-10-10', understandingScore: 80);

        $this->actingAs($student)
            ->get(MyLearningProgress::getUrl())
            ->assertOk()
            ->assertSeeInOrder(['Dein Verständnis im Durchschnitt', '60 %', 'Teilweise verstanden', 'Entwicklung', 'Es geht aufwärts', 'Bisher bewertet', '2 Hausaufgaben']);
    }

    public function test_the_trend_is_the_one_the_parent_report_shows_for_the_same_homework(): void
    {
        $student = User::factory()->student(SchoolClass::factory()->create())->create();
        // Lesson order 40, 80, 60, evaluated in the opposite order: the lesson order decides, the middle score counts as newer.
        $this->evaluatedHomework($student, 'Photosynthese', '2026-10-01', understandingScore: 40)->update(['evaluated_at' => now()->subDay()]);
        $this->evaluatedHomework($student, 'Zellaufbau', '2026-10-10', understandingScore: 80)->update(['evaluated_at' => now()->subDays(2)]);
        $this->evaluatedHomework($student, 'Atmung', '2026-10-20', understandingScore: 60)->update(['evaluated_at' => now()->subDays(3)]);

        $this->actingAs($student)
            ->get(MyLearningProgress::getUrl())
            ->assertOk()
            ->assertSee('Es geht aufwärts');

        $this->actingAs(User::factory()->admin()->create())
            ->get(ParentReport::getUrl(['student' => $student]))
            ->assertOk()
            ->assertSee('Steigend: Die späteren Hausaufgaben liegen im Mittel 30 Punkte über den früheren.');
    }

    public function test_withdrawn_homework_does_not_count_and_its_competency_still_offers_a_way_to_start(): void
    {
        $student = User::factory()->student(SchoolClass::factory()->create())->create();
        $kept = $this->evaluatedHomework($student, 'Photosynthese', '2026-10-01', understandingScore: 80);
        $withdrawn = $this->evaluatedHomework($student, 'Bruchrechnung', '2026-10-10', understandingScore: 40);
        $this->answer($kept, Competency::factory()->create(), 90);
        $this->answer($withdrawn, Competency::factory()->create(['name' => 'Brüche kürzen']), 20);
        $withdrawn->lesson->students()->detach($student);

        $this->actingAs($student)
            ->get(MyLearningProgress::getUrl())
            ->assertOk()
            ->assertSeeInOrder(['Dein Verständnis im Durchschnitt', '80 %', 'Bisher bewertet', '1 Hausaufgabe'])
            ->assertSeeInOrder(['Hier lohnt sich Üben', 'Brüche kürzen', 'Thema Tuto erklären', 'zu Brüche kürzen'])
            ->assertDontSee('Bei diesen Hausaufgaben hat es noch gehakt')
            ->assertDontSee('Bruchrechnung')
            ->assertDontSee(WorkOnHomework::getUrl(['lesson' => $withdrawn->lesson_id]), false);
    }

    public function test_a_student_whose_competencies_are_all_good_gets_a_positive_message(): void
    {
        $student = User::factory()->student(SchoolClass::factory()->create())->create();
        $this->answer($this->evaluatedHomework($student, 'Photosynthese'), Competency::factory()->create(), 90);

        $this->actingAs($student)
            ->get(MyLearningProgress::getUrl())
            ->assertOk()
            ->assertSee('Im Moment läuft alles richtig gut.')
            ->assertDontSee('Fast geschafft');
    }

    public function test_answers_of_classmates_never_influence_or_appear_on_the_page(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        [$student, $classmate] = User::factory()->student($schoolClass)->count(2)->create();
        $shared = Competency::factory()->create(['name' => 'Fachbegriffe erklären']);

        $this->answer($this->evaluatedHomework($student, 'Photosynthese'), $shared, 90);
        $classmateHomework = $this->evaluatedHomework($classmate, 'Klassenarbeit der anderen', gaps: ['Fremde Lücke'], understandingScore: 10);
        $this->answer($classmateHomework, $shared, 5);
        $this->answer($classmateHomework, Competency::factory()->create(['name' => 'Nur bei der Mitschülerin']), 5);

        $this->actingAs($student)
            ->get(MyLearningProgress::getUrl())
            ->assertOk()
            ->assertSeeInOrder(['Das kannst du schon gut', 'Fachbegriffe erklären', 'Gut verstanden'])
            ->assertSee('Im Moment läuft alles richtig gut.')
            ->assertSeeInOrder(['Bisher bewertet', '1 Hausaufgabe'])
            ->assertDontSee('Nur bei der Mitschülerin')
            ->assertDontSee('Klassenarbeit der anderen')
            ->assertDontSee('Fremde Lücke')
            ->assertDontSee(WorkOnHomework::getUrl(['lesson' => $classmateHomework->lesson_id]), false)
            ->assertDontSee('Klassendurchschnitt');
    }

    public function test_friendly_empty_state_without_evaluated_homework(): void
    {
        $this->actingAs(User::factory()->student(SchoolClass::factory()->create())->create())
            ->get(MyLearningProgress::getUrl())
            ->assertOk()
            ->assertSee('Hier gibt es noch nichts zu sehen')
            ->assertSee('Zu meinen Hausaufgaben')
            ->assertSee(MyHomework::getUrl(), false)
            ->assertDontSee('Das kannst du schon gut')
            ->assertDontSee('Dein Verständnis im Durchschnitt');
    }
}
