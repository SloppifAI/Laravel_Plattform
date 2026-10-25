<?php

namespace Tests\Feature\LessonStarter;

use App\Filament\Pages\LessonStarter;
use App\Models\Answer;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LessonStarterPageTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private User $teacher;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolClass = SchoolClass::factory()->create(['name' => '7b']);
        $this->teacher = $this->schoolClass->teacher;
        $this->lesson = Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')
            ->create(['subject' => 'Biologie', 'topic' => 'Fotosynthese', 'held_on' => '2026-10-01']);

        $weak = Question::factory()->for($this->lesson)->create([
            'prompt' => 'Warum brauchen Pflanzen Licht?',
            'expected_answer' => 'Licht liefert die Energie für die Zuckerherstellung.',
            'position' => 1,
        ]);
        $partly = Question::factory()->for($this->lesson)->create([
            'prompt' => 'Wo findet die Fotosynthese statt?',
            'expected_answer' => 'In den Chloroplasten.',
            'position' => 2,
        ]);
        $understood = Question::factory()->for($this->lesson)->create([
            'prompt' => 'Was entsteht als Abfallprodukt?',
            'expected_answer' => 'Sauerstoff.',
            'position' => 3,
        ]);

        foreach (['Mia Hoffmann' => [20, 60, 95], 'Ben Schneider' => [30, 70, 90], 'Lena Becker' => [25, 65, 100]] as $name => [$weakScore, $partlyScore, $understoodScore]) {
            $submission = Submission::factory()->for($this->lesson)
                ->for(User::factory()->student($this->schoolClass)->create(['name' => $name]), 'student')
                ->evaluated(55)
                ->create(['gaps' => ['Chlorophyll verwechselt']]);
            Answer::factory()->for($submission)->for($weak)->create(['score' => $weakScore]);
            Answer::factory()->for($submission)->for($partly)->create(['score' => $partlyScore]);
            Answer::factory()->for($submission)->for($understood)->create(['score' => $understoodScore]);
        }
    }

    public function test_the_board_shows_the_weakest_points_with_class_wide_numbers_only(): void
    {
        $this->actingAs($this->teacher)
            ->get($this->url())
            ->assertOk()
            ->assertSeeInOrder(['Starter for the next lesson', 'Fotosynthese', 'Biologie · 7b · Lesson of 01.10.2026', 'These points are worth repeating', 'Warum brauchen Pflanzen Licht?', 'On average 25 %', '3 of 3 answers below 50 %', 'Wo findet die Fotosynthese statt?', 'On average 65 %', '0 of 3 answers below 50 %', 'Chlorophyll verwechselt', 'Named as a gap in 3 of 3 submissions'])
            ->assertSee(['Who can explain this?', 'Full screen', 'Print', 'No names and no individual results are shown.'])
            // The page title says "Starter for the next lesson"; the board under it must not repeat it word for word.
            ->assertSeeText('From the lesson')
            ->assertDontSee('Was entsteht als Abfallprodukt?')
            ->assertDontSee(['Mia', 'Hoffmann', 'Ben', 'Schneider', 'Lena', 'Becker']);
    }

    public function test_the_footer_names_how_many_of_the_assigned_students_handed_in(): void
    {
        $this->lesson->students()->sync($this->schoolClass->students()->pluck('id'));
        $assigned = $this->lesson->students()->count();

        $this->actingAs($this->teacher)->get($this->url())->assertSee("3 of {$assigned} students have handed in and been evaluated.");
    }

    public function test_the_solution_hints_are_hidden_until_the_teacher_reveals_them(): void
    {
        $solution = 'Licht liefert die Energie für die Zuckerherstellung.';

        $this->actingAs($this->teacher)->get($this->url())->assertDontSee([$solution, 'Hide solution hints']);

        Livewire::actingAs($this->teacher)
            ->test(LessonStarter::class, ['lesson' => $this->lesson])
            ->assertSee('Show solution hints')
            ->assertDontSee($solution)
            ->toggle('showSolutions')
            ->assertSee(['Hide solution hints', 'Solution hint', $solution, 'In den Chloroplasten.'])
            ->toggle('showSolutions')
            ->assertDontSee($solution);
    }

    public function test_a_lesson_without_evaluated_homework_shows_a_friendly_empty_state(): void
    {
        $lesson = Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')->create(['topic' => 'Zellatmung']);

        $this->actingAs($this->teacher)
            ->get($this->url($lesson))
            ->assertOk()
            ->assertSee(['Zellatmung', 'Nothing evaluated yet'])
            ->assertDontSee('Who can explain this?')
            ->assertDontSee('solution hints');
    }

    public function test_with_fewer_than_three_evaluated_submissions_no_numbers_or_gaps_are_shown(): void
    {
        $lesson = $this->lessonWithEvaluatedSubmissions(2, 20, ['Zellwand vergessen']);

        $this->actingAs($this->teacher)
            ->get($this->url($lesson))
            ->assertOk()
            ->assertSee(['Too few submissions for a class starter yet', 'So far there are 2 evaluated submissions.'])
            ->assertDontSee(['Who can explain this?', 'On average', 'Zellwand vergessen', 'solution hints', 'Warum brauchen Pflanzen Licht?']);

        $single = $this->lessonWithEvaluatedSubmissions(1, 20, ['Zellwand vergessen']);

        $this->actingAs($this->teacher)->get($this->url($single))->assertSee('So far there is 1 evaluated submission.')->assertDontSee(['On average', 'Zellwand vergessen']);
    }

    public function test_a_class_that_understood_everything_gets_no_repeat_points(): void
    {
        $lesson = $this->lessonWithEvaluatedSubmissions(4, 95);

        $this->actingAs($this->teacher)
            ->get($this->url($lesson))
            ->assertOk()
            ->assertSee('Nothing that needs to be repeated')
            ->assertDontSee(['Who can explain this?', 'These points are worth repeating', 'On average']);
    }

    public function test_a_foerderaufgabe_has_no_board(): void
    {
        $practice = $this->lessonWithEvaluatedSubmissions(3, 20);
        $practice->forceFill(['practice_competency_id' => Competency::factory()->create()->id])->save();

        $this->actingAs($this->teacher)->get($this->url($practice))->assertNotFound();
        Livewire::actingAs($this->teacher)->test(LessonStarter::class, ['lesson' => $practice])->assertNotFound();
    }

    public function test_only_users_who_may_update_the_lesson_can_open_it(): void
    {
        $sameSchoolAdmin = User::factory()->admin()->for($this->schoolClass->school)->create();
        $otherTeacherOfSchool = User::factory()->teacher()->for($this->schoolClass->school)->create();
        $otherSchoolAdmin = User::factory()->admin()->create();
        $otherSchoolTeacher = User::factory()->teacher()->create();
        $student = $this->schoolClass->students()->first();

        foreach ([$this->teacher, $sameSchoolAdmin] as $allowed) {
            $this->actingAs($allowed)->get($this->url())->assertOk();
        }

        foreach ([$otherTeacherOfSchool, $otherSchoolAdmin, $otherSchoolTeacher, $student, User::factory()->operator()->create()] as $forbidden) {
            $this->actingAs($forbidden)->get($this->url())->assertForbidden();
        }

        Livewire::actingAs($student)->test(LessonStarter::class, ['lesson' => $this->lesson])->assertForbidden();
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get($this->url())->assertRedirect();
    }

    private function url(?Lesson $lesson = null): string
    {
        return LessonStarter::getUrl(['lesson' => $lesson ?? $this->lesson]);
    }

    /**
     * A lesson of the teacher with one question whose answers all got the given score, and the given gaps.
     *
     * @param  list<string>  $gaps
     */
    private function lessonWithEvaluatedSubmissions(int $count, int $score, array $gaps = []): Lesson
    {
        $lesson = Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')->create(['topic' => 'Wiederholung']);
        $question = Question::factory()->for($lesson)->create(['prompt' => 'Warum brauchen Pflanzen Licht?']);

        foreach (range(1, $count) as $ignored) {
            $submission = Submission::factory()->for($lesson)->for(User::factory()->student($this->schoolClass), 'student')->evaluated(55)->create(['gaps' => $gaps]);
            Answer::factory()->for($submission)->for($question)->create(['score' => $score]);
        }

        return $lesson;
    }
}
