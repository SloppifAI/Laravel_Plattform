<?php

namespace Tests\Feature\Substitutions;

use App\Filament\Pages\QuestionInbox;
use App\Models\Answer;
use App\Models\ClassSubstitution;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\StudentQuestion;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A substitute who set up lessons during the period keeps their drafts, but the students' individual results of those
 * lessons are only theirs while they teach the class.
 */
class SubstitutionResultsAccessTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $leader;

    private User $substitute;

    private SchoolClass $schoolClass;

    private User $student;

    private Lesson $lesson;

    private Submission $submission;

    private Answer $answer;

    private ExplanationSession $session;

    private StudentQuestion $question;

    private ClassSubstitution $substitution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->leader = User::factory()->teacher()->for($this->school)->create();
        $this->substitute = User::factory()->teacher()->for($this->school)->create();
        $this->schoolClass = SchoolClass::factory()->for($this->school)->create(['teacher_id' => $this->leader->id]);
        $this->student = User::factory()->student($this->schoolClass)->create(['name' => 'Mia Hoffmann']);
        $this->substitution = ClassSubstitution::factory()->for($this->schoolClass)->create([
            'substitute_id' => $this->substitute->id,
            'granted_by' => $this->leader->id,
            'starts_on' => today(),
            'ends_on' => today()->addDays(2),
        ]);

        $this->lesson = Lesson::factory()->for($this->schoolClass)->for($this->substitute, 'teacher')->publishedFor()->create();
        $this->submission = Submission::factory()->for($this->lesson)->for($this->student, 'student')->evaluated()->create();
        $this->answer = Answer::factory()->for($this->submission)->for(Question::factory()->for($this->lesson))->create();
        $this->session = ExplanationSession::factory()->for($this->student, 'student')->create(['lesson_id' => $this->lesson->id]);
        $this->question = StudentQuestion::factory()->create(['student_id' => $this->student->id, 'lesson_id' => $this->lesson->id]);
    }

    public function test_during_the_period_the_substitute_reads_the_results_of_their_lessons(): void
    {
        $this->assertResultsVisibleTo($this->substitute);
    }

    public function test_after_the_period_the_substitute_keeps_the_drafts_but_not_the_students_results(): void
    {
        $this->travel(5)->days();

        $this->assertResultsHiddenFrom($this->substitute);
        $this->assertTrue($this->substitute->can('update', $this->lesson));
        $this->assertTrue($this->substitute->can('view', $this->lesson));
    }

    public function test_ending_the_substitution_early_closes_the_results_at_once(): void
    {
        $this->assertResultsVisibleTo($this->substitute);

        $this->substitution->end();

        $this->assertResultsHiddenFrom($this->substitute->fresh());
    }

    public function test_a_new_substitution_opens_the_results_again(): void
    {
        $this->travel(5)->days();
        $this->assertResultsHiddenFrom($this->substitute);

        ClassSubstitution::factory()->for($this->schoolClass)->create([
            'substitute_id' => $this->substitute->id,
            'starts_on' => today(),
            'ends_on' => today()->addDay(),
        ]);

        $this->assertResultsVisibleTo($this->substitute);
    }

    public function test_a_substitution_ended_on_its_first_day_closes_the_results_too(): void
    {
        $this->substitution->update(['starts_on' => today(), 'ends_on' => today()->addDays(5)]);

        $this->substitution->end();

        $this->assertModelExists($this->substitution);
        $this->assertResultsHiddenFrom($this->substitute->fresh());
    }

    public function test_a_teacher_who_is_assigned_to_the_class_for_good_keeps_the_results_after_a_substitution(): void
    {
        $this->travel(5)->days();
        $this->assertResultsHiddenFrom($this->substitute);

        $this->schoolClass->teachers()->attach($this->substitute);

        $this->assertResultsVisibleTo($this->substitute);
    }

    public function test_a_substitution_in_another_class_or_of_another_teacher_changes_nothing(): void
    {
        $this->travel(5)->days();
        $colleague = User::factory()->teacher()->for($this->school)->create();
        $this->lesson->update(['teacher_id' => $colleague->id]);
        ClassSubstitution::factory()->for(SchoolClass::factory()->for($this->school)->create())->create([
            'substitute_id' => $colleague->id,
            'starts_on' => today()->subDays(9),
            'ends_on' => today()->subDays(5),
        ]);

        $this->assertResultsVisibleTo($colleague);
    }

    public function test_the_administration_and_the_klassenleitung_as_lesson_teacher_are_not_affected(): void
    {
        $admin = User::factory()->admin()->for($this->school)->create();
        $this->lesson->update(['teacher_id' => $this->leader->id]);
        $this->travel(5)->days();

        $this->assertResultsVisibleTo($admin);
        $this->assertResultsVisibleTo($this->leader);
    }

    public function test_teachers_who_did_not_hold_the_lesson_never_read_its_results(): void
    {
        $this->assertResultsHiddenFrom($this->leader);
        $this->assertResultsHiddenFrom(User::factory()->teacher()->for($this->school)->create());
        $this->assertResultsHiddenFrom(User::factory()->operator()->create());
    }

    public function test_the_question_inbox_lists_questions_only_while_the_teacher_teaches_the_class(): void
    {
        $this->assertSame([$this->question->id], StudentQuestion::query()->managedBy($this->substitute)->pluck('id')->all());
        Livewire::actingAs($this->substitute)->test(QuestionInbox::class)->assertSee($this->question->question);

        $this->travel(5)->days();

        $this->assertSame([], StudentQuestion::query()->managedBy($this->substitute)->pluck('id')->all());
        Livewire::actingAs($this->substitute)->test(QuestionInbox::class)->assertDontSee($this->question->question);
    }

    private function assertResultsVisibleTo(User $user): void
    {
        $this->assertTrue($user->can('viewResults', $this->lesson), 'lesson results');
        $this->assertTrue($user->can('view', $this->submission), 'submission');
        $this->assertTrue($user->can('delete', $this->submission), 'delete submission');
        $this->assertTrue($user->can('view', $this->answer), 'answer');
        $this->assertTrue($user->can('view', $this->session), 'session');
        $this->assertTrue($user->can('view', $this->question), 'question');
        $this->assertTrue($user->can('answer', $this->question), 'answer the question');
    }

    private function assertResultsHiddenFrom(User $user): void
    {
        $this->assertFalse($user->can('viewResults', $this->lesson), 'lesson results');
        $this->assertFalse($user->can('view', $this->submission), 'submission');
        $this->assertFalse($user->can('delete', $this->submission), 'delete submission');
        $this->assertFalse($user->can('view', $this->answer), 'answer');
        $this->assertFalse($user->can('view', $this->session), 'session');
        $this->assertFalse($user->can('view', $this->question), 'question');
        $this->assertFalse($user->can('answer', $this->question), 'answer the question');
    }
}
