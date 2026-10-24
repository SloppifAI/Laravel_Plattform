<?php

namespace Tests\Feature\Lessons;

use App\Filament\Pages\LessonStarter;
use App\Filament\Resources\Lessons\Pages\CreateLesson;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Models\Answer;
use App\Models\ClassSubstitution;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\Support\LessonReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Zum Einstieg wiederholen" on the lesson form: the weakest points of the previous lesson of the same class and
 * subject, only from lessons the acting user may review.
 */
class FollowUpPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private SchoolClass $schoolClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->teacher()->create();
        $this->schoolClass = SchoolClass::factory()->for($this->teacher, 'teacher')->create();
    }

    public function test_the_panel_shows_the_points_of_the_previous_lesson_of_the_same_class_and_subject(): void
    {
        $previous = $this->reviewedLesson($this->teacher, $this->schoolClass, 'Biologie', '2026-10-01', 'Was ist Mitose?');
        $this->reviewedLesson($this->teacher, $this->schoolClass, 'Biologie', '2026-09-20', 'Was ist ein Zellkern?');
        $this->actingAs($this->teacher);

        Livewire::test(CreateLesson::class)
            ->fillForm(['school_class_id' => $this->schoolClass->id, 'subject' => 'Biologie', 'held_on' => '2026-10-08'])
            ->assertSee('Repeat at the start')
            ->assertSee('Was ist Mitose?')
            ->assertSee('On average 30 %, 3 of 3 answers below 50 %')
            ->assertSee(LessonStarter::getUrl(['lesson' => $previous]))
            ->assertDontSee('Was ist ein Zellkern?');
    }

    public function test_the_panel_stays_hidden_when_nothing_matches(): void
    {
        $this->reviewedLesson($this->teacher, $this->schoolClass, 'Biologie', '2026-10-01', 'Was ist Mitose?');
        $otherClass = SchoolClass::factory()->for($this->teacher, 'teacher')->create();
        $this->actingAs($this->teacher);

        $form = Livewire::test(CreateLesson::class);

        // Class, subject and day are all needed, and the previous lesson must lie before the day.
        $form->assertDontSee('Repeat at the start');

        foreach ([
            ['school_class_id' => $otherClass->id, 'subject' => 'Biologie', 'held_on' => '2026-10-08'],
            ['school_class_id' => $this->schoolClass->id, 'subject' => 'Chemie', 'held_on' => '2026-10-08'],
            ['school_class_id' => $this->schoolClass->id, 'subject' => 'Biologie', 'held_on' => '2026-10-01'],
        ] as $state) {
            $form->fillForm($state)->assertDontSee('Repeat at the start');
        }
    }

    public function test_the_panel_stays_hidden_while_the_previous_lesson_has_no_points(): void
    {
        $lesson = Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')->create(['subject' => 'Biologie', 'held_on' => '2026-10-01']);
        $question = Question::factory()->for($lesson)->create();

        // One evaluated submission is below the group size: nothing is reported yet.
        $submission = Submission::factory()->for($lesson)->for(User::factory()->student($this->schoolClass), 'student')->evaluated(40)->create();
        Answer::factory()->for($submission)->for($question)->create(['score' => 20]);
        $this->actingAs($this->teacher);

        Livewire::test(CreateLesson::class)
            ->fillForm(['school_class_id' => $this->schoolClass->id, 'subject' => 'Biologie', 'held_on' => '2026-10-08'])
            ->assertDontSee('Repeat at the start');
    }

    public function test_a_teacher_does_not_see_the_lessons_of_colleagues_but_the_administration_does(): void
    {
        $colleague = User::factory()->teacher()->for($this->teacher->school)->create();
        $this->reviewedLesson($colleague, $this->schoolClass, 'Biologie', '2026-10-01', 'Frage der Kollegin');
        $formData = ['school_class_id' => $this->schoolClass->id, 'subject' => 'Biologie', 'held_on' => '2026-10-08'];

        $this->actingAs($this->teacher);
        Livewire::test(CreateLesson::class)->fillForm($formData)->assertDontSee('Frage der Kollegin');

        $this->actingAs(User::factory()->admin()->for($this->teacher->school)->create());
        Livewire::test(CreateLesson::class)->fillForm($formData)->assertSee('Frage der Kollegin');
    }

    public function test_a_substitute_whose_period_is_over_gets_no_results_through_the_panel(): void
    {
        $substitute = User::factory()->teacher()->for($this->teacher->school)->create();
        $substitution = ClassSubstitution::factory()->for($this->schoolClass)->create(['substitute_id' => $substitute->id]);
        $previous = $this->reviewedLesson($substitute, $this->schoolClass, 'Biologie', '2026-10-01', 'Was ist Mitose?');
        $draft = Lesson::factory()->for($this->schoolClass)->for($substitute, 'teacher')->create(['subject' => 'Biologie', 'held_on' => '2026-10-08']);
        $formData = ['school_class_id' => $this->schoolClass->id, 'subject' => 'Biologie', 'held_on' => '2026-10-08'];
        $this->actingAs($substitute);

        // In the period the panel is there, with the link to the starter board.
        Livewire::test(EditLesson::class, ['record' => $draft->getRouteKey()])->assertSee('Was ist Mitose?')->assertSee(LessonStarter::getUrl(['lesson' => $previous]));

        $substitution->end();

        // Afterwards neither the lesson page nor a new lesson in that class shows what the class got wrong (the board itself answers 403).
        Livewire::test(EditLesson::class, ['record' => $draft->getRouteKey()])->assertDontSee('Repeat at the start')->assertDontSee('Was ist Mitose?');
        Livewire::test(CreateLesson::class)->fillForm($formData)->assertDontSee('Repeat at the start')->assertDontSee('Was ist Mitose?');
        $this->get(LessonStarter::getUrl(['lesson' => $previous]))->assertForbidden();
    }

    public function test_a_class_of_another_school_stays_out_even_with_a_forged_class_id(): void
    {
        $foreignTeacher = User::factory()->teacher()->create();
        $foreignClass = SchoolClass::factory()->for($foreignTeacher, 'teacher')->create();
        $this->reviewedLesson($foreignTeacher, $foreignClass, 'Biologie', '2026-10-01', 'Frage der fremden Schule');
        $this->actingAs($this->teacher);

        Livewire::test(CreateLesson::class)
            ->fillForm(['school_class_id' => $foreignClass->id, 'subject' => 'Biologie', 'held_on' => '2026-10-08'])
            ->assertDontSee('Frage der fremden Schule');

        $this->actingAs(User::factory()->admin()->for($this->teacher->school)->create());

        Livewire::test(CreateLesson::class)
            ->fillForm(['school_class_id' => $foreignClass->id, 'subject' => 'Biologie', 'held_on' => '2026-10-08'])
            ->assertDontSee('Frage der fremden Schule');
    }

    public function test_when_editing_a_lesson_the_previous_one_shows_but_never_the_lesson_itself(): void
    {
        $previous = $this->reviewedLesson($this->teacher, $this->schoolClass, 'Biologie', '2026-10-01', 'Frage der ersten Stunde');
        $current = $this->reviewedLesson($this->teacher, $this->schoolClass, 'Biologie', '2026-10-08', 'Frage der zweiten Stunde');
        $this->actingAs($this->teacher);

        Livewire::test(EditLesson::class, ['record' => $current->getRouteKey()])
            ->assertSee('Repeat at the start')
            ->assertSee('Frage der ersten Stunde')
            ->assertSee(LessonStarter::getUrl(['lesson' => $previous]))
            ->assertDontSee('Frage der zweiten Stunde');

        // Even when its day is moved behind its own: the lesson is no previous lesson of itself.
        Livewire::test(EditLesson::class, ['record' => $previous->getRouteKey()])
            ->assertDontSee('Repeat at the start')
            ->fillForm(['held_on' => '2026-10-20'])
            ->assertSee('Frage der zweiten Stunde')
            ->assertDontSee('Frage der ersten Stunde');
    }

    /**
     * A lesson of the class with three evaluated submissions that answered the question badly.
     */
    private function reviewedLesson(User $teacher, SchoolClass $schoolClass, string $subject, string $heldOn, string $prompt): Lesson
    {
        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->create(['subject' => $subject, 'held_on' => $heldOn]);
        $question = Question::factory()->for($lesson)->create(['prompt' => $prompt, 'position' => 1]);

        foreach (range(1, LessonReview::MIN_GROUP) as $ignored) {
            $submission = Submission::factory()->for($lesson)->for(User::factory()->student($schoolClass), 'student')->evaluated(40)->create();
            Answer::factory()->for($submission)->for($question)->create(['score' => 30]);
        }

        return $lesson;
    }
}
