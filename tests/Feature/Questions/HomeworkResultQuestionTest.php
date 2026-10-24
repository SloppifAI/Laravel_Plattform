<?php

namespace Tests\Feature\Questions;

use App\Filament\Pages\MyQuestions;
use App\Filament\Pages\QuestionInbox;
use App\Filament\Pages\WorkOnHomework;
use App\Models\ClassSubstitution;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\StudentQuestion;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class HomeworkResultQuestionTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $schoolClass = SchoolClass::factory()->create();
        $this->student = User::factory()->student($schoolClass)->create();
        $this->lesson = Lesson::factory()->for($schoolClass)->publishedFor([$this->student])->create(['topic' => 'Fotosynthese']);
    }

    public function test_a_student_asks_the_teacher_from_the_result_page(): void
    {
        $this->handIn();

        Livewire::actingAs($this->student)
            ->test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->assertSee('Question for my teacher')
            ->callAction('askTeacher', ['question' => '  Warum ist Chlorophyll grün?  '])
            ->assertHasNoActionErrors()
            ->assertNotified('Your question has reached your teacher');

        $question = StudentQuestion::sole();
        $this->assertTrue($question->student->is($this->student));
        $this->assertTrue($question->lesson->is($this->lesson));
        $this->assertNull($question->explanation_session_id);
        $this->assertSame('Warum ist Chlorophyll grün?', $question->question);
        $this->assertTrue($question->isOpen());
    }

    public function test_the_question_reaches_the_inbox_of_the_teacher_and_the_list_of_the_student(): void
    {
        $this->handIn();

        Livewire::actingAs($this->student)
            ->test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->callAction('askTeacher', ['question' => 'Warum ist Chlorophyll grün?']);

        $question = StudentQuestion::sole();

        Livewire::actingAs($this->lesson->teacher)
            ->test(QuestionInbox::class)
            ->assertCanSeeTableRecords([$question])
            ->assertSee(['Warum ist Chlorophyll grün?', 'Fotosynthese']);

        Livewire::actingAs($this->student)
            ->test(MyQuestions::class)
            ->assertCanSeeTableRecords([$question])
            ->assertSee(['Warum ist Chlorophyll grün?', 'Fotosynthese']);

        Livewire::actingAs(User::factory()->teacher()->for($this->lesson->schoolClass->school)->create())
            ->test(QuestionInbox::class)
            ->assertCanNotSeeTableRecords([$question]);
    }

    public function test_the_question_is_only_offered_on_an_evaluated_result_page(): void
    {
        $this->assertAskingIsRefused();

        $this->handIn(evaluated: false);

        $this->assertAskingIsRefused();
        $this->assertDatabaseCount(StudentQuestion::class, 0);
    }

    public function test_the_open_questions_per_lesson_are_limited(): void
    {
        $this->handIn();
        StudentQuestion::factory()->count(StudentQuestion::MAX_OPEN_PER_LESSON)->for($this->student, 'student')->for($this->lesson)->create();

        Livewire::actingAs($this->student)
            ->test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->callAction('askTeacher', ['question' => 'Noch eine Frage.'])
            ->assertNotified('You already have '.StudentQuestion::MAX_OPEN_PER_LESSON.' open questions about this lesson');

        $this->assertSame(StudentQuestion::MAX_OPEN_PER_LESSON, StudentQuestion::query()->count());

        StudentQuestion::query()->first()->update(['answered_at' => now()]);

        Livewire::actingAs($this->student)
            ->test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->callAction('askTeacher', ['question' => 'Jetzt wieder.'])
            ->assertNotified('Your question has reached your teacher');

        $this->assertSame(StudentQuestion::MAX_OPEN_PER_LESSON + 1, StudentQuestion::query()->count());
    }

    public function test_a_lesson_taken_back_while_the_question_is_open_cannot_be_asked_about(): void
    {
        $this->handIn();
        $beforeReassignment = $this->pageWithOpenQuestionModal();
        $beforeTakingBack = $this->pageWithOpenQuestionModal();

        $this->lesson->students()->detach($this->student);
        $beforeReassignment->call('callMountedAction')->assertForbidden();

        $this->lesson->students()->attach($this->student);
        $this->lesson->update(['published_at' => null]);
        $beforeTakingBack->call('callMountedAction')->assertForbidden();

        $this->assertDatabaseCount(StudentQuestion::class, 0);
    }

    public function test_a_lesson_whose_substitute_teacher_lost_access_cannot_be_asked_about(): void
    {
        $substitute = User::factory()->teacher()->for($this->lesson->schoolClass->school)->create();
        $substitution = ClassSubstitution::factory()->for($this->lesson->schoolClass, 'schoolClass')->create(['substitute_id' => $substitute->id]);
        $this->lesson->update(['teacher_id' => $substitute->id]);
        $this->handIn();

        // While the substitution runs, the substitute reads and answers the questions.
        Livewire::actingAs($this->student)
            ->test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->assertSee('Question for my teacher');

        $substitution->update(['starts_on' => today()->subDays(10), 'ends_on' => today()->subDays(3)]);

        // Nobody but the administration would see the question: the action is hidden, a crafted request gets nowhere.
        $this->assertAskingIsRefused();
        $this->assertDatabaseCount(StudentQuestion::class, 0);
    }

    public function test_the_policy_allows_only_students_the_lesson_is_published_for(): void
    {
        $classmate = User::factory()->student($this->lesson->schoolClass)->create();
        $foreignStudent = User::factory()->student()->create();
        $draft = Lesson::factory()->for($this->lesson->schoolClass)->create();
        $draft->students()->attach($this->student);

        $this->assertTrue($this->student->can('createForLesson', [StudentQuestion::class, $this->lesson]));
        $this->assertFalse($this->student->can('createForLesson', [StudentQuestion::class, $draft]));
        $this->assertFalse($classmate->can('createForLesson', [StudentQuestion::class, $this->lesson]));
        $this->assertFalse($foreignStudent->can('createForLesson', [StudentQuestion::class, $this->lesson]));
        $this->assertFalse($this->lesson->teacher->can('createForLesson', [StudentQuestion::class, $this->lesson]));
    }

    public function test_the_question_is_required_and_limited_in_length(): void
    {
        $this->handIn();

        foreach ([['', 'required'], [str_repeat('a', 2001), 'max']] as [$question, $rule]) {
            Livewire::actingAs($this->student)
                ->test(WorkOnHomework::class, ['lesson' => $this->lesson])
                ->callAction('askTeacher', ['question' => $question])
                ->assertHasActionErrors(['question' => $rule]);
        }

        $this->assertDatabaseCount(StudentQuestion::class, 0);
    }

    /**
     * The action is hidden then; a crafted request that mounts it anyway gets nowhere.
     */
    private function assertAskingIsRefused(): void
    {
        Livewire::actingAs($this->student)
            ->test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->assertDontSee('Question for my teacher')
            ->set('mountedActions', [['name' => 'askTeacher', 'arguments' => [], 'context' => [], 'data' => ['question' => 'Zu früh.']]])
            ->call('callMountedAction');
    }

    /**
     * The result page with the modal of "Question for my teacher" open and filled, as the student would have left it.
     */
    private function pageWithOpenQuestionModal(): Testable
    {
        return Livewire::actingAs($this->student)
            ->test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->set('mountedActions', [['name' => 'askTeacher', 'arguments' => [], 'context' => [], 'data' => ['question' => 'Reingeschmuggelt']]]);
    }

    private function handIn(bool $evaluated = true): Submission
    {
        $factory = Submission::factory()->for($this->lesson)->for($this->student, 'student');

        return ($evaluated ? $factory->evaluated() : $factory)->create();
    }
}
