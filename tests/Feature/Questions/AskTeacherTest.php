<?php

namespace Tests\Feature\Questions;

use App\Filament\Pages\ExplainIt;
use App\Models\ClassSubstitution;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\StudentQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AskTeacherTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_asks_the_teacher_from_a_session_about_a_lesson(): void
    {
        $student = User::factory()->student()->create();
        $lesson = Lesson::factory()->publishedFor([$student])->create();
        $session = ExplanationSession::factory()->for($student, 'student')->for($lesson)->create();

        Livewire::actingAs($student)
            ->test(ExplainIt::class)
            ->call('openSession', $session->id)
            ->assertSee('Question for my teacher')
            ->callAction('askTeacher', ['question' => '  Warum ist Chlorophyll grün?  '])
            ->assertHasNoActionErrors()
            ->assertNotified('Your question has reached your teacher')
            ->assertSee('You asked your teacher a question about this.');

        $question = StudentQuestion::sole();
        $this->assertTrue($question->student->is($student));
        $this->assertTrue($question->lesson->is($lesson));
        $this->assertTrue($question->explanationSession->is($session));
        $this->assertSame('Warum ist Chlorophyll grün?', $question->question);
        $this->assertTrue($question->isOpen());
        $this->assertNull($question->read_at);
    }

    public function test_a_finished_session_still_lets_the_student_ask(): void
    {
        $student = User::factory()->student()->create();
        $lesson = Lesson::factory()->publishedFor([$student])->create();
        $session = ExplanationSession::factory()->for($student, 'student')->for($lesson)->finished()->create();

        Livewire::actingAs($student)
            ->test(ExplainIt::class)
            ->call('openSession', $session->id)
            ->callAction('askTeacher', ['question' => 'Das habe ich nicht verstanden.'])
            ->assertHasNoActionErrors();

        $this->assertSame(1, $session->studentQuestions()->count());
    }

    public function test_a_session_on_a_free_topic_has_no_teacher_to_ask(): void
    {
        $student = User::factory()->student()->create();
        $session = ExplanationSession::factory()->for($student, 'student')->create(['lesson_id' => null]);

        Livewire::actingAs($student)
            ->test(ExplainIt::class)
            ->call('openSession', $session->id)
            ->assertDontSee('Question for my teacher')
            ->assertSee('You can only ask your teacher questions about topics from your homework')
            ->callAction('askTeacher', ['question' => 'Reingeschmuggelt'])
            ->assertForbidden();

        $this->assertDatabaseCount(StudentQuestion::class, 0);
    }

    public function test_a_foreign_session_cannot_be_used_to_ask_a_question(): void
    {
        $foreignSession = ExplanationSession::factory()
            ->for(User::factory()->student(), 'student')
            ->for(Lesson::factory()->publishedFor([]))
            ->create();

        Livewire::actingAs(User::factory()->student()->create())
            ->test(ExplainIt::class)
            ->set('sessionId', $foreignSession->id)
            ->callAction('askTeacher', ['question' => 'Reingeschmuggelt'])
            ->assertNotFound();

        $this->assertDatabaseCount(StudentQuestion::class, 0);
    }

    public function test_a_student_cannot_flood_the_teacher_with_open_questions_about_one_lesson(): void
    {
        $student = User::factory()->student()->create();
        $lesson = Lesson::factory()->publishedFor([$student])->create();
        $otherLesson = Lesson::factory()->publishedFor([$student])->create();
        $session = ExplanationSession::factory()->for($student, 'student')->for($lesson)->create();
        $otherSession = ExplanationSession::factory()->for($student, 'student')->for($otherLesson)->create();
        StudentQuestion::factory()->count(StudentQuestion::MAX_OPEN_PER_LESSON)->for($student, 'student')->for($lesson)->create();

        Livewire::actingAs($student)
            ->test(ExplainIt::class)
            ->call('openSession', $session->id)
            ->callAction('askTeacher', ['question' => 'Noch eine Frage.'])
            ->assertNotified('You already have '.StudentQuestion::MAX_OPEN_PER_LESSON.' open questions about this lesson');

        $this->assertSame(StudentQuestion::MAX_OPEN_PER_LESSON, StudentQuestion::query()->where('lesson_id', $lesson->id)->count());

        Livewire::actingAs($student)
            ->test(ExplainIt::class)
            ->call('openSession', $otherSession->id)
            ->callAction('askTeacher', ['question' => 'Eine Frage zu einer anderen Stunde.'])
            ->assertNotified('Your question has reached your teacher');

        $this->assertSame(1, StudentQuestion::query()->where('lesson_id', $otherLesson->id)->count());

        // An answered question no longer counts as open.
        StudentQuestion::query()->where('lesson_id', $lesson->id)->first()->update(['answered_at' => now()]);

        Livewire::actingAs($student)
            ->test(ExplainIt::class)
            ->call('openSession', $session->id)
            ->callAction('askTeacher', ['question' => 'Jetzt wieder.'])
            ->assertNotified('Your question has reached your teacher');

        $this->assertSame(StudentQuestion::MAX_OPEN_PER_LESSON + 1, StudentQuestion::query()->where('lesson_id', $lesson->id)->count());
    }

    public function test_a_lesson_that_is_no_longer_assigned_to_the_student_cannot_be_asked_about(): void
    {
        $student = User::factory()->student()->create();
        $lesson = Lesson::factory()->publishedFor([$student])->create();
        $session = ExplanationSession::factory()->for($student, 'student')->for($lesson)->create();

        $lesson->students()->detach($student);

        Livewire::actingAs($student)
            ->test(ExplainIt::class)
            ->call('openSession', $session->id)
            ->assertDontSee('Question for my teacher')
            ->assertSee('This lesson is no longer part of your homework')
            ->callAction('askTeacher', ['question' => 'Reingeschmuggelt'])
            ->assertForbidden();

        $this->assertDatabaseCount(StudentQuestion::class, 0);

        $lesson->students()->attach($student);
        $lesson->update(['published_at' => null]);

        Livewire::actingAs($student)
            ->test(ExplainIt::class)
            ->call('openSession', $session->id)
            ->callAction('askTeacher', ['question' => 'Reingeschmuggelt'])
            ->assertForbidden();

        $this->assertDatabaseCount(StudentQuestion::class, 0);
    }

    public function test_a_lesson_whose_substitute_teacher_lost_access_cannot_be_asked_about(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $student = User::factory()->student($schoolClass)->create();
        $substitute = User::factory()->teacher()->for($schoolClass->school)->create();
        ClassSubstitution::factory()->ended()->for($schoolClass, 'schoolClass')->create(['substitute_id' => $substitute->id]);
        $lesson = Lesson::factory()->for($schoolClass)->for($substitute, 'teacher')->publishedFor([$student])->create();
        $session = ExplanationSession::factory()->for($student, 'student')->for($lesson)->create();

        Livewire::actingAs($student)
            ->test(ExplainIt::class)
            ->call('openSession', $session->id)
            ->assertDontSee('Question for my teacher')
            ->assertSee('It is best to ask your form teacher')
            ->callAction('askTeacher', ['question' => 'Reingeschmuggelt'])
            ->assertForbidden();

        $this->assertDatabaseCount(StudentQuestion::class, 0);
    }

    public function test_the_question_is_required_and_limited_in_length(): void
    {
        $student = User::factory()->student()->create();
        $session = ExplanationSession::factory()->for($student, 'student')->for(Lesson::factory()->publishedFor([$student]))->create();

        foreach ([['', 'required'], [str_repeat('a', 2001), 'max']] as [$question, $rule]) {
            Livewire::actingAs($student)
                ->test(ExplainIt::class)
                ->call('openSession', $session->id)
                ->callAction('askTeacher', ['question' => $question])
                ->assertHasActionErrors(['question' => $rule]);
        }

        $this->assertDatabaseCount(StudentQuestion::class, 0);
    }
}
