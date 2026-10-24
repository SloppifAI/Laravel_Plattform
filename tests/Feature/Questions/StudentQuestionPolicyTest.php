<?php

namespace Tests\Feature\Questions;

use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\StudentQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentQuestionPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_question_is_visible_only_to_its_student_the_lessons_teacher_and_its_schools_administration(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $school = $schoolClass->school;
        $lesson = Lesson::factory()->for($schoolClass)->create();
        $student = User::factory()->student($schoolClass)->create();
        $question = StudentQuestion::factory()->for($student, 'student')->for($lesson)->create();

        $this->assertTrue($student->can('view', $question));
        $this->assertTrue($lesson->teacher->can('view', $question));
        $this->assertTrue(User::factory()->admin()->for($school)->create()->can('view', $question));

        $this->assertFalse(User::factory()->student($schoolClass)->create()->can('view', $question), 'classmate');
        $this->assertFalse(User::factory()->teacher()->for($school)->create()->can('view', $question), 'colleague');
        $this->assertFalse(User::factory()->admin()->create()->can('view', $question), 'administration of another school');
        $this->assertFalse(User::factory()->teacher()->create()->can('view', $question), 'teacher of another school');
        $this->assertFalse(User::factory()->operator()->create()->can('view', $question), 'operator');
        $this->assertFalse(User::factory()->operator()->create()->can('viewAny', StudentQuestion::class));
    }

    public function test_the_operator_gets_no_questions_from_the_scope(): void
    {
        StudentQuestion::factory()->create();

        $this->assertSame(0, StudentQuestion::query()->managedBy(User::factory()->operator()->create())->count());
        $this->assertSame(0, StudentQuestion::query()->managedBy(User::factory()->student()->create())->count());
    }

    public function test_only_those_who_manage_the_lesson_answer_and_only_while_the_question_is_open(): void
    {
        $question = StudentQuestion::factory()->for(User::factory()->student(), 'student')->create();
        $teacher = $question->lesson->teacher;

        $this->assertTrue($teacher->can('answer', $question));
        $this->assertTrue(User::factory()->admin()->for($question->lesson->schoolClass->school)->create()->can('answer', $question));
        $this->assertFalse($question->student->can('answer', $question));
        $this->assertFalse(User::factory()->teacher()->create()->can('answer', $question));

        $question->update(['answer' => 'Erledigt.', 'answered_at' => now(), 'answered_by' => $teacher->id]);

        $this->assertFalse($teacher->can('answer', $question->refresh()));
    }

    public function test_only_the_student_marks_an_answer_as_read_and_nobody_changes_or_deletes_a_question(): void
    {
        $question = StudentQuestion::factory()->for(User::factory()->student(), 'student')->answered()->create();

        $this->assertTrue($question->student->can('markAsRead', $question));
        $this->assertFalse($question->lesson->teacher->can('markAsRead', $question));
        $this->assertFalse($question->student->can('update', $question));
        $this->assertFalse($question->student->can('delete', $question));
        $this->assertFalse($question->lesson->teacher->can('delete', $question));
    }

    public function test_asking_needs_the_students_own_session_about_a_lesson(): void
    {
        $student = User::factory()->student()->create();
        $aboutLesson = ExplanationSession::factory()->for($student, 'student')->for(Lesson::factory()->publishedFor([$student]))->create();
        $freeTopic = ExplanationSession::factory()->for($student, 'student')->create();

        $this->assertTrue($student->can('createFor', [StudentQuestion::class, $aboutLesson]));
        $this->assertFalse($student->can('createFor', [StudentQuestion::class, $freeTopic]));
        $this->assertFalse(User::factory()->student()->create()->can('createFor', [StudentQuestion::class, $aboutLesson]));
        $this->assertFalse($aboutLesson->lesson->teacher->can('createFor', [StudentQuestion::class, $aboutLesson]));
    }

    public function test_asking_needs_the_lesson_to_still_be_published_for_the_student(): void
    {
        $student = User::factory()->student()->create();
        $lesson = Lesson::factory()->publishedFor([$student])->create();
        $session = ExplanationSession::factory()->for($student, 'student')->for($lesson)->create();

        $this->assertTrue($student->can('createFor', [StudentQuestion::class, $session]));

        $lesson->students()->detach($student);
        $this->assertFalse($student->can('createFor', [StudentQuestion::class, $session]), 'student no longer assigned');

        $lesson->students()->attach($student);
        $lesson->update(['published_at' => null]);
        $this->assertFalse($student->can('createFor', [StudentQuestion::class, $session]), 'lesson taken back');
    }

    public function test_deleting_the_lesson_deletes_its_questions_so_none_is_left_waiting_for_a_teacher(): void
    {
        $question = StudentQuestion::factory()->for(User::factory()->student(), 'student')->create();
        $otherLessonsQuestion = StudentQuestion::factory()->for(User::factory()->student(), 'student')->create();

        $question->lesson->delete();

        $this->assertModelMissing($question);
        $this->assertModelExists($otherLessonsQuestion);
    }
}
