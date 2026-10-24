<?php

namespace Tests\Feature\Questions;

use App\Filament\Pages\QuestionInbox;
use App\Models\Lesson;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\StudentQuestion;
use App\Models\User;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class QuestionInboxTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $teacher;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->teacher = User::factory()->teacher()->for($this->school)->create();
        $this->lesson = Lesson::factory()
            ->for(SchoolClass::factory()->for($this->school))
            ->for($this->teacher, 'teacher')
            ->publishedFor([])
            ->create();
    }

    public function test_only_teachers_and_the_administration_open_the_inbox(): void
    {
        $this->actingAs($this->teacher)->get('/rueckfragen')->assertOk()->assertSee('Questions for the teacher');
        $this->actingAs(User::factory()->admin()->for($this->school)->create())->get('/rueckfragen')->assertOk();
        $this->actingAs(User::factory()->student()->create())->get('/rueckfragen')->assertForbidden();
        $this->actingAs(User::factory()->operator()->create())->get('/rueckfragen')->assertForbidden();
    }

    public function test_a_teacher_sees_only_questions_about_their_own_lessons(): void
    {
        $own = $this->question();
        $colleagueLesson = Lesson::factory()->for($this->lesson->schoolClass)->for(User::factory()->teacher()->for($this->school), 'teacher')->create();
        $colleagues = StudentQuestion::factory()->for($colleagueLesson)->create();
        $otherSchool = StudentQuestion::factory()->create();
        $withoutLesson = StudentQuestion::factory()->create(['lesson_id' => null]);

        Livewire::actingAs($this->teacher)
            ->test(QuestionInbox::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$colleagues, $otherSchool, $withoutLesson])
            ->assertSee($own->question);
    }

    public function test_the_administration_sees_all_questions_of_its_own_school_only(): void
    {
        $admin = User::factory()->admin()->for($this->school)->create();
        $own = $this->question();
        $colleagueLesson = Lesson::factory()->for($this->lesson->schoolClass)->for(User::factory()->teacher()->for($this->school), 'teacher')->create();
        $colleagues = StudentQuestion::factory()->for($colleagueLesson)->create();
        $otherSchool = StudentQuestion::factory()->create();

        Livewire::actingAs($admin)
            ->test(QuestionInbox::class)
            ->assertCanSeeTableRecords([$own, $colleagues])
            ->assertCanNotSeeTableRecords([$otherSchool]);
    }

    public function test_open_questions_come_before_answered_ones(): void
    {
        $answered = $this->question(['answer' => 'Schon beantwortet.', 'answered_at' => now(), 'answered_by' => $this->teacher->id]);
        $open = $this->question();
        $markedDone = $this->question(['answered_at' => now(), 'answered_by' => $this->teacher->id]);

        Livewire::actingAs($this->teacher)
            ->test(QuestionInbox::class)
            ->assertCanSeeTableRecords([$open, $markedDone, $answered], inOrder: true)
            ->assertSee(['Open', 'Answered', 'Done']);
    }

    public function test_answering_stores_the_answer_and_makes_it_new_for_the_student(): void
    {
        $question = $this->question();

        Livewire::actingAs($this->teacher)
            ->test(QuestionInbox::class)
            ->callAction(TestAction::make('answer')->table($question), ['answer' => '  Chlorophyll absorbiert rotes und blaues Licht.  '])
            ->assertHasNoActionErrors()
            ->assertNotified('Answer sent');

        $question->refresh();
        $this->assertSame('Chlorophyll absorbiert rotes und blaues Licht.', $question->answer);
        $this->assertTrue($question->answeredBy->is($this->teacher));
        $this->assertNotNull($question->answered_at);
        $this->assertTrue($question->hasUnreadAnswer());
        $this->assertFalse($question->isOpen());
    }

    public function test_an_answer_is_required_and_an_answered_question_cannot_be_answered_again(): void
    {
        $open = $this->question();
        $answered = $this->question(['answer' => 'Schon beantwortet.', 'answered_at' => now(), 'answered_by' => $this->teacher->id]);

        Livewire::actingAs($this->teacher)
            ->test(QuestionInbox::class)
            ->callAction(TestAction::make('answer')->table($open), ['answer' => ''])
            ->assertHasActionErrors(['answer' => 'required']);

        Livewire::actingAs($this->teacher)
            ->test(QuestionInbox::class)
            ->assertActionVisible(TestAction::make('answer')->table($open))
            ->assertActionHidden(TestAction::make('answer')->table($answered))
            ->assertActionHidden(TestAction::make('markDone')->table($answered));

        $this->assertNull($open->refresh()->answer);
    }

    public function test_marking_a_question_done_closes_it_without_an_answer(): void
    {
        $question = $this->question();

        Livewire::actingAs($this->teacher)
            ->test(QuestionInbox::class)
            ->callAction(TestAction::make('markDone')->table($question))
            ->assertNotified('Question marked as done');

        $question->refresh();
        $this->assertNull($question->answer);
        $this->assertTrue($question->answeredBy->is($this->teacher));
        $this->assertTrue($question->wasMarkedDone());
        $this->assertFalse($question->isOpen());
    }

    public function test_a_teacher_closes_several_open_questions_at_once_but_only_their_own_open_ones(): void
    {
        $first = $this->question();
        $second = $this->question();
        $answered = $this->question();
        $answered->update(['answer' => 'Schon beantwortet.', 'answered_by' => $this->teacher->id, 'answered_at' => now()->subDay()]);
        $colleagues = StudentQuestion::factory()
            ->for(Lesson::factory()->for($this->lesson->schoolClass)->for(User::factory()->teacher()->for($this->school), 'teacher'))
            ->create();

        Livewire::actingAs($this->teacher)
            ->test(QuestionInbox::class)
            ->selectTableRecords([$first->getKey(), $second->getKey(), $answered->getKey(), $colleagues->getKey()])
            ->callAction(TestAction::make('markAllDone')->table()->bulk());

        $this->assertTrue($first->refresh()->wasMarkedDone());
        $this->assertTrue($second->refresh()->wasMarkedDone());
        $this->assertTrue($first->answeredBy->is($this->teacher));
        $this->assertSame('Schon beantwortet.', $answered->refresh()->answer);
        $this->assertTrue($colleagues->refresh()->isOpen(), 'a colleague\'s question stays untouched');
    }

    public function test_the_administration_may_answer_questions_of_its_school(): void
    {
        $admin = User::factory()->admin()->for($this->school)->create();
        $question = $this->question();

        Livewire::actingAs($admin)
            ->test(QuestionInbox::class)
            ->callAction(TestAction::make('answer')->table($question), ['answer' => 'Antwort der Schulleitung.'])
            ->assertHasNoActionErrors();

        $this->assertTrue($question->refresh()->answeredBy->is($admin));
    }

    public function test_a_colleague_or_a_foreign_administration_can_neither_see_nor_answer_a_question(): void
    {
        $question = $this->question();
        $colleague = User::factory()->teacher()->for($this->school)->create();
        $foreignAdmin = User::factory()->admin()->create();

        foreach ([$colleague, $foreignAdmin] as $outsider) {
            $page = Livewire::actingAs($outsider)->test(QuestionInbox::class)->assertCanNotSeeTableRecords([$question]);

            try {
                $page->callAction(TestAction::make('answer')->table($question), ['answer' => 'Reingeschmuggelt']);
                $this->fail('The question must not be part of the table.');
            } catch (ActionNotResolvableException) {
                $this->assertNull($question->refresh()->answer);
            }

            $this->assertFalse($outsider->can('answer', $question));
            $this->assertNull(StudentQuestion::query()->managedBy($outsider)->first());
        }

        $this->assertTrue($question->refresh()->isOpen());
    }

    public function test_the_navigation_badge_counts_the_open_questions_of_a_teacher(): void
    {
        $this->question();
        $this->question();
        $this->question(['answered_at' => now(), 'answered_by' => $this->teacher->id]);
        StudentQuestion::factory()->create();

        $this->actingAs($this->teacher);
        $this->assertSame('2', QuestionInbox::getNavigationBadge());

        $this->actingAs(User::factory()->teacher()->for($this->school)->create());
        $this->assertNull(QuestionInbox::getNavigationBadge());

        $this->actingAs(User::factory()->admin()->for($this->school)->create());
        $this->assertNull(QuestionInbox::getNavigationBadge());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function question(array $attributes = []): StudentQuestion
    {
        return StudentQuestion::factory()
            ->for(User::factory()->student($this->lesson->schoolClass), 'student')
            ->for($this->lesson)
            ->create($attributes);
    }
}
