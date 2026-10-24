<?php

namespace Tests\Feature\StudentProgress;

use App\Filament\Pages\ExplainIt;
use App\Filament\Pages\MyHomework;
use App\Filament\Pages\MyLearningProgress;
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
                'Hier lohnt sich Üben', 'Versuche planen', 'Förderbedarf',
                'Fast geschafft', 'Diagramme lesen', 'Teilweise verstanden',
            ])
            ->assertDontSee('ENDE');
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
            ->assertSeeInOrder(['Hier ist es dir aufgefallen', 'Biologie:', 'Bruchrechnung', '10.10.2026', 'Photosynthese', '01.10.2026'])
            ->assertSee('Darauf solltest du noch einmal schauen')
            ->assertSee('Wie Licht in Energie umgewandelt wird')
            ->assertSee('Brüche gleichnamig machen')
            ->assertSee('Ergebnis ansehen')
            ->assertSee('Mit Tuto üben')
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
        $this->evaluatedHomework($student, 'Photosynthese', understandingScore: 40)->update(['evaluated_at' => now()->subDays(3)]);
        $this->evaluatedHomework($student, 'Zellaufbau', understandingScore: 80)->update(['evaluated_at' => now()->subDay()]);

        $this->actingAs($student)
            ->get(MyLearningProgress::getUrl())
            ->assertOk()
            ->assertSeeInOrder(['Dein Verständnis im Durchschnitt', '60', 'Teilweise verstanden', 'Entwicklung', 'Es geht aufwärts', 'Das beruht auf', '2 bewerteten Hausaufgaben']);
    }

    public function test_a_student_whose_competencies_are_all_good_gets_a_positive_message(): void
    {
        $student = User::factory()->student(SchoolClass::factory()->create())->create();
        $this->answer($this->evaluatedHomework($student, 'Photosynthese'), Competency::factory()->create(), 90);

        $this->actingAs($student)
            ->get(MyLearningProgress::getUrl())
            ->assertOk()
            ->assertSee('Im Moment musst du nichts dringend üben.')
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
            ->assertSee('Im Moment musst du nichts dringend üben.')
            ->assertSee('1 bewerteter Hausaufgabe')
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
