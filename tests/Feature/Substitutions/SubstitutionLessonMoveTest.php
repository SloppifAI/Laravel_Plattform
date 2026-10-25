<?php

namespace Tests\Feature\Substitutions;

use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Models\ClassSubstitution;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * LessonPolicy::viewResults() looks for the substitution in the class the lesson sits in, so a lesson must not leave
 * its class once students have used it: a substitute whose period is over could otherwise move it to a class they
 * teach and read the covered class's results again.
 */
class SubstitutionLessonMoveTest extends TestCase
{
    use RefreshDatabase;

    private User $substitute;

    private SchoolClass $coveredClass;

    private SchoolClass $ownClass;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $school = School::factory()->create();
        $leader = User::factory()->teacher()->for($school)->create();
        $this->substitute = User::factory()->teacher()->for($school)->create();
        $this->coveredClass = SchoolClass::factory()->for($school)->create(['teacher_id' => $leader->id]);
        $this->ownClass = SchoolClass::factory()->for($school)->create(['teacher_id' => $this->substitute->id]);
        $this->student = User::factory()->student($this->coveredClass)->create(['name' => 'Mia Hoffmann']);
        ClassSubstitution::factory()->for($this->coveredClass)->create([
            'substitute_id' => $this->substitute->id,
            'granted_by' => $leader->id,
            'starts_on' => today(),
            'ends_on' => today()->addDays(2),
        ]);
    }

    public function test_an_expired_substitute_cannot_move_a_lesson_with_hand_ins_to_their_own_class(): void
    {
        $lesson = Lesson::factory()->for($this->coveredClass)->for($this->substitute, 'teacher')->publishedFor()->create();
        $submission = Submission::factory()->for($lesson)->for($this->student, 'student')->evaluated()->create();
        $session = ExplanationSession::factory()->for($this->student, 'student')->create(['lesson_id' => $lesson->id]);
        $this->travel(5)->days();

        Livewire::actingAs($this->substitute)->test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->assertFormFieldIsDisabled('school_class_id')
            ->fillForm(['school_class_id' => $this->ownClass->id])
            ->call('save');

        $this->assertResultsHidden($lesson->refresh(), $submission, $session);
    }

    public function test_the_backstop_refuses_a_forged_class_change_of_a_lesson_with_hand_ins(): void
    {
        $lesson = Lesson::factory()->for($this->coveredClass)->for($this->substitute, 'teacher')->publishedFor()->create();
        Submission::factory()->for($lesson)->for($this->student, 'student')->create();
        $this->travel(5)->days();
        $edit = Livewire::actingAs($this->substitute)->test(EditLesson::class, ['record' => $lesson->getRouteKey()])->instance();

        $this->expectException(AuthorizationException::class);

        (fn (array $data) => $this->mutateFormDataBeforeSave($data))->call($edit, ['school_class_id' => $this->ownClass->id]);
    }

    public function test_withdrawing_the_release_does_not_free_a_lesson_the_students_already_used(): void
    {
        $lesson = Lesson::factory()->for($this->coveredClass)->for($this->substitute, 'teacher')->publishedFor()->create();
        $session = ExplanationSession::factory()->for($this->student, 'student')->create(['lesson_id' => $lesson->id]);
        $this->travel(5)->days();

        Livewire::actingAs($this->substitute)->test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->callAction('withdraw')
            ->fillForm(['school_class_id' => $this->ownClass->id])
            ->call('save');

        $lesson->refresh();
        $this->assertFalse($lesson->isPublished());
        $this->assertTrue($lesson->schoolClass->is($this->coveredClass));
        $this->assertFalse($this->substitute->can('view', $session));
    }

    private function assertResultsHidden(Lesson $lesson, Submission $submission, ExplanationSession $session): void
    {
        $this->assertTrue($lesson->schoolClass->is($this->coveredClass), 'the lesson stays in the covered class');
        $this->assertFalse($this->substitute->can('viewResults', $lesson), 'lesson results');
        $this->assertFalse($this->substitute->can('view', $submission), 'submission');
        $this->assertFalse($this->substitute->can('view', $session), 'session');
    }
}
