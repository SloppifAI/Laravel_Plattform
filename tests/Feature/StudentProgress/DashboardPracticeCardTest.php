<?php

namespace Tests\Feature\StudentProgress;

use App\Filament\Pages\ExplainIt;
use App\Filament\Pages\MyLearningProgress;
use App\Filament\Widgets\StudentLearningWidget;
use App\Models\Competency;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardPracticeCardTest extends TestCase
{
    use CreatesLearningResults;
    use RefreshDatabase;

    public function test_the_card_shows_the_three_weakest_competencies_with_one_next_step_each_and_links_to_the_page(): void
    {
        $student = User::factory()->student(SchoolClass::factory()->create())->create();

        $lessons = [];
        foreach (['Eins' => 10, 'Zwei' => 20, 'Drei' => 30, 'Vier' => 40] as $number => $score) {
            $homework = $this->evaluatedHomework($student, "Thema {$number}");
            $this->answer($homework, Competency::factory()->create(['name' => "Kompetenz {$number}"]), $score);
            $lessons[$number] = $homework->lesson_id;
        }

        $this->actingAs($student);

        Livewire::test(StudentLearningWidget::class)
            ->assertSeeInOrder(['Hier lohnt sich Üben', 'Biologie: Thema Eins', 'Kompetenz Eins', 'Thema Tuto erklären', 'zu Kompetenz Eins', 'Thema Zwei', 'Kompetenz Zwei', 'Thema Drei', 'Kompetenz Drei'])
            ->assertSee(ExplainIt::getUrl(['lesson' => $lessons['Eins']]), false)
            ->assertSee(ExplainIt::getUrl(['lesson' => $lessons['Zwei']]), false)
            ->assertSee(ExplainIt::getUrl(['lesson' => $lessons['Drei']]), false)
            ->assertSee(MyLearningProgress::getUrl(), false)
            ->assertDontSee('Kompetenz Vier')
            ->assertDontSee(ExplainIt::getUrl(['lesson' => $lessons['Vier']]), false);

        $this->get('/')->assertOk()->assertSee('Hier lohnt sich Üben')->assertSee('Kompetenz Drei');
    }

    public function test_the_card_is_encouraging_without_practice_needs(): void
    {
        $student = User::factory()->student(SchoolClass::factory()->create())->create();
        $this->actingAs($student);

        // Nothing evaluated yet is neutral, not the green "well done" box.
        Livewire::test(StudentLearningWidget::class)
            ->assertSee('Hier lohnt sich Üben')
            ->assertSee('Sobald deine Hausaufgaben bewertet sind')
            ->assertDontSee('Gut gemacht!');

        $this->answer($this->evaluatedHomework($student, 'Photosynthese'), Competency::factory()->create(), 90);

        Livewire::test(StudentLearningWidget::class)
            ->assertSee('Gut gemacht!')
            ->assertSee('Im Moment läuft alles richtig gut.')
            ->assertDontSee('Sobald deine Hausaufgaben bewertet sind')
            ->assertDontSee('zu Kompetenz');
    }

    public function test_students_never_see_foerderbedarf_or_a_red_warning_and_scores_carry_their_unit(): void
    {
        $student = User::factory()->student(SchoolClass::factory()->create())->create();
        $this->answer($this->evaluatedHomework($student, 'Photosynthese', understandingScore: 30), Competency::factory()->create(), 30);
        $this->actingAs($student);

        Livewire::test(StudentLearningWidget::class)
            ->assertSee('30 %')
            ->assertSee('Noch üben')
            ->assertDontSee('Förderbedarf')
            ->assertDontSee('danger');
    }

    public function test_a_submitted_homework_that_is_not_evaluated_yet_is_not_asked_for_again(): void
    {
        $student = User::factory()->student(SchoolClass::factory()->create())->create();
        $this->evaluatedHomework($student, 'Photosynthese')->update(['evaluated_at' => null, 'understanding_score' => null]);
        $this->actingAs($student);

        Livewire::test(StudentLearningWidget::class)
            ->assertSee('Deine Hausaufgabe ist abgegeben. Die Auswertung kommt später.')
            ->assertDontSee('Gib deine erste Hausaufgabe ab');
    }

    public function test_withdrawn_homework_does_not_count_towards_the_average(): void
    {
        $student = User::factory()->student(SchoolClass::factory()->create())->create();
        $this->evaluatedHomework($student, 'Photosynthese', understandingScore: 80);
        $this->evaluatedHomework($student, 'Bruchrechnung', understandingScore: 40)->lesson->students()->detach($student);
        $this->actingAs($student);

        Livewire::test(StudentLearningWidget::class)
            ->assertSeeInOrder(['80', '%', 'Durchschnitt aus 1 Hausaufgabe'])
            ->assertDontSee('Durchschnitt aus 2');
    }

    public function test_the_card_never_shows_competencies_of_classmates(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        [$student, $classmate] = User::factory()->student($schoolClass)->count(2)->create();
        $this->answer($this->evaluatedHomework($classmate, 'Thema der anderen'), Competency::factory()->create(['name' => 'Nur bei der Mitschülerin']), 5);

        $this->actingAs($student);

        Livewire::test(StudentLearningWidget::class)
            ->assertSee('Sobald deine Hausaufgaben bewertet sind')
            ->assertDontSee('Nur bei der Mitschülerin')
            ->assertDontSee('Thema der anderen');
    }
}
