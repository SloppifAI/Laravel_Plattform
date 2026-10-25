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
            ->assertSee('My learning progress');

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
            ->assertSee('not grades')
            ->assertSeeInOrder([
                'What you are already good at', 'Fachbegriffe erklären', 'Well understood',
                'Practising pays off here', 'Versuche planen', '30 %', 'Keep practising',
                'Almost there', 'Diagramme lesen', '60 %', 'Partly understood', 'Explain a topic to Tuto', 'for Diagramme lesen',
            ])
            // Cut at a word boundary, never in the middle of a word.
            ->assertSee('Beschreibung. Das ist...')
            ->assertDontSee('Das ist ein...')
            ->assertDontSeeText('ENDE')
            // The whole description stays readable in the tooltip.
            ->assertSee('ENDE', false)
            // Teacher language and the red warning do not belong on the student's page.
            ->assertDontSee('Needs support');
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
            ->assertSeeInOrder(['You still had some trouble with these homework assignments', 'Biologie:', 'Bruchrechnung', '10.10.2026', 'Photosynthese', '01.10.2026'])
            // The gaps belong to the homework, not to one competency, so the heading says so.
            ->assertSee('Take another look at this in your homework')
            ->assertSee('Wie Licht in Energie umgewandelt wird')
            ->assertSee('Brüche gleichnamig machen')
            ->assertSee('View result')
            ->assertSee('Explain a topic to Tuto')
            // Every link says where it leads, also for screen readers.
            ->assertSee('View result<span class="sr-only"> for Photosynthese</span>', false)
            ->assertSee('Explain a topic to Tuto<span class="sr-only"> for Bruchrechnung</span>', false)
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
            ->assertSeeInOrder(['Your understanding on average', '60 %', 'Partly understood', 'Progress', 'Things are looking up', 'Scored so far', '2 homework assignments']);
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
            ->assertSee('Things are looking up');

        $this->actingAs(User::factory()->admin()->for($student->school)->create())
            ->get(ParentReport::getUrl(['student' => $student]))
            ->assertOk()
            ->assertSee('Rising: The later homework is on average 30 points above the earlier homework.');
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
            ->assertSeeInOrder(['Your understanding on average', '80 %', 'Scored so far', '1 homework assignment'])
            ->assertSeeInOrder(['Practising pays off here', 'Brüche kürzen', 'Explain a topic to Tuto', 'for Brüche kürzen'])
            ->assertDontSee('You still had some trouble with these homework assignments')
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
            ->assertSee('Everything is going really well at the moment.')
            ->assertDontSee('Almost there');
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
            ->assertSeeInOrder(['What you are already good at', 'Fachbegriffe erklären', 'Well understood'])
            ->assertSee('Everything is going really well at the moment.')
            ->assertSeeInOrder(['Scored so far', '1 homework assignment'])
            ->assertDontSee('Nur bei der Mitschülerin')
            ->assertDontSee('Klassenarbeit der anderen')
            ->assertDontSee('Fremde Lücke')
            ->assertDontSee(WorkOnHomework::getUrl(['lesson' => $classmateHomework->lesson_id]), false)
            ->assertDontSee('Class average');
    }

    public function test_friendly_empty_state_without_evaluated_homework(): void
    {
        $this->actingAs(User::factory()->student(SchoolClass::factory()->create())->create())
            ->get(MyLearningProgress::getUrl())
            ->assertOk()
            ->assertSee('Nothing to see here yet')
            ->assertSee('To my homework')
            ->assertSee(MyHomework::getUrl(), false)
            ->assertDontSee('What you are already good at')
            ->assertDontSee('Your understanding on average');
    }
}
