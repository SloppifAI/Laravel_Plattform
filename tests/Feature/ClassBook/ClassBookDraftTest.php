<?php

namespace Tests\Feature\ClassBook;

use App\Models\ClassBookEntry;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Models\Question;
use App\Models\RecordingConsent;
use App\Support\ClassBookDraft;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassBookDraftTest extends TestCase
{
    use CreatesClassBookFixtures;
    use RefreshDatabase;

    public function test_the_draft_takes_over_class_teacher_date_subject_and_topic_and_uses_the_summary_as_content(): void
    {
        [$schoolClass, , $teacher] = $this->classWithStudents();
        $lesson = $this->lessonOf($schoolClass, $teacher, [
            'subject' => 'Biologie',
            'topic' => 'Das Herz',
            'held_on' => '2026-10-05',
            'summary' => "  Das Herz pumpt Blut durch den Körper.\nEs hat vier Kammern.  ",
        ]);

        $entry = ClassBookDraft::createFor($lesson);

        $this->assertTrue($entry->wasRecentlyCreated);
        $this->assertSame($schoolClass->getKey(), $entry->school_class_id);
        $this->assertSame($lesson->getKey(), $entry->lesson_id);
        $this->assertSame($teacher->getKey(), $entry->teacher_id);
        $this->assertSame('2026-10-05', $entry->held_on->toDateString());
        $this->assertSame('Biologie', $entry->subject);
        $this->assertSame('Das Herz', $entry->topic);
        $this->assertSame("Das Herz pumpt Blut durch den Körper.\nEs hat vier Kammern.", $entry->content);
        $this->assertNull($entry->period);
        $this->assertNull($entry->homework);
        $this->assertSame([], $entry->absent_student_ids);
        $this->assertNull($entry->confirmed_at);
        $this->assertFalse($entry->isConfirmed());
    }

    public function test_without_a_summary_the_content_is_the_first_three_sentences_of_the_notes(): void
    {
        [$schoolClass, , $teacher] = $this->classWithStudents();
        $lesson = $this->lessonOf($schoolClass, $teacher, [
            'summary' => null,
            'notes' => "Wir haben den Blutkreislauf besprochen.\n\nDas Herz pumpt!   Warum schlägt es schneller? Beim Sport. Danach gab es Übungen.",
        ]);

        $this->assertSame(
            'Wir haben den Blutkreislauf besprochen. Das Herz pumpt! Warum schlägt es schneller?',
            ClassBookDraft::createFor($lesson)->content,
        );
    }

    public function test_notes_without_sentence_ends_are_cut_to_a_reasonable_length_and_empty_notes_give_empty_content(): void
    {
        [$schoolClass, , $teacher] = $this->classWithStudents();

        $long = ClassBookDraft::createFor($this->lessonOf($schoolClass, $teacher, ['summary' => '', 'notes' => str_repeat('wort ', 200)]));
        $empty = ClassBookDraft::createFor($this->lessonOf($schoolClass, $teacher, ['summary' => null, 'notes' => '']));

        $this->assertLessThanOrEqual(403, mb_strlen($long->content));
        $this->assertStringEndsWith('...', $long->content);
        $this->assertSame('', $empty->content);
    }

    public function test_released_homework_becomes_the_number_of_questions_and_the_due_date(): void
    {
        [$schoolClass, $students, $teacher] = $this->classWithStudents();
        $lesson = Lesson::factory()->publishedFor($students)->create(['school_class_id' => $schoolClass->getKey(), 'teacher_id' => $teacher->getKey(), 'due_on' => '2026-10-12']);
        Question::factory()->count(3)->for($lesson)->create();

        $this->assertSame('Homework in Tuto: 3 questions, due 12.10.2026.', ClassBookDraft::createFor($lesson)->homework);
    }

    public function test_the_homework_text_copes_with_one_question_and_with_a_missing_due_date_or_questions(): void
    {
        [$schoolClass, $students, $teacher] = $this->classWithStudents();
        $single = Lesson::factory()->publishedFor($students)->create(['school_class_id' => $schoolClass->getKey(), 'teacher_id' => $teacher->getKey(), 'due_on' => null]);
        Question::factory()->for($single)->create();
        $bare = Lesson::factory()->publishedFor($students)->create(['school_class_id' => $schoolClass->getKey(), 'teacher_id' => $teacher->getKey(), 'due_on' => null]);

        $this->assertSame('Homework in Tuto: 1 question.', ClassBookDraft::createFor($single)->homework);
        $this->assertSame('Homework in Tuto.', ClassBookDraft::createFor($bare)->homework);
    }

    public function test_a_lesson_without_released_homework_and_a_foerderaufgabe_leave_the_homework_empty(): void
    {
        [$schoolClass, $students, $teacher] = $this->classWithStudents();
        $unpublished = $this->lessonOf($schoolClass, $teacher);
        Question::factory()->count(2)->for($unpublished)->create();
        $practice = Lesson::factory()->publishedFor([$students->first()])->create([
            'school_class_id' => $schoolClass->getKey(),
            'teacher_id' => $teacher->getKey(),
            'practice_competency_id' => Competency::factory(),
        ]);

        $this->assertNull(ClassBookDraft::createFor($unpublished)->homework);
        $this->assertNull(ClassBookDraft::createFor($practice)->homework);
    }

    public function test_absences_are_the_students_missing_from_the_latest_confirmed_attendance(): void
    {
        [$schoolClass, $students, $teacher] = $this->classWithStudents(4);
        [$anna, $ben, $clara, $dora] = $students;
        $lesson = $this->lessonOf($schoolClass, $teacher);
        $this->giveConsent($schoolClass, $students);
        LessonRecording::factory()->for($lesson)->presentStudents([$anna, $ben, $clara])->startedDaysAgo(2)->create();
        LessonRecording::factory()->for($lesson)->presentStudents([$anna, $ben])->create();

        $this->assertEqualsCanonicalizing([$clara->getKey(), $dora->getKey()], ClassBookDraft::createFor($lesson)->absent_student_ids);
    }

    public function test_a_student_without_a_valid_consent_is_never_marked_absent(): void
    {
        [$schoolClass, $students, $teacher] = $this->classWithStudents(5);
        [$anna, $ben, $noConsent, $revoked, $absent] = $students;
        $lesson = $this->lessonOf($schoolClass, $teacher);
        $this->giveConsent($schoolClass, [$anna, $ben, $absent]);
        $this->giveConsent($schoolClass, [$revoked]);
        RecordingConsent::query()->where('student_id', $revoked->getKey())->update(['revoked_at' => now()]);
        // Tuto-Mikro refused these two, so the teacher had to leave them off the list, in the room or not.
        LessonRecording::factory()->for($lesson)->presentStudents([$anna, $ben])->create();

        $this->assertSame([$absent->getKey()], ClassBookDraft::createFor($lesson)->absent_student_ids);
        $this->assertNotContains($noConsent->getKey(), $lesson->classBookEntry->absent_student_ids);
    }

    public function test_when_nobody_of_the_rest_has_a_consent_nobody_is_marked_absent(): void
    {
        [$schoolClass, $students, $teacher] = $this->classWithStudents(3);
        $lesson = $this->lessonOf($schoolClass, $teacher);
        $this->giveConsent($schoolClass, [$students->first()]);
        LessonRecording::factory()->for($lesson)->presentStudents([$students->first()])->create();

        $this->assertSame([], ClassBookDraft::createFor($lesson)->absent_student_ids);
    }

    public function test_without_a_usable_attendance_nobody_is_marked_absent(): void
    {
        [$schoolClass, $students, $teacher] = $this->classWithStudents(3);
        $noSession = $this->lessonOf($schoolClass, $teacher);
        $unconfirmed = $this->lessonOf($schoolClass, $teacher);
        LessonRecording::factory()->for($unconfirmed)->presentStudents([$students->first()])->create(['attendance_confirmed_at' => null]);
        $emptyList = $this->lessonOf($schoolClass, $teacher);
        LessonRecording::factory()->for($emptyList)->presentStudents([])->create();

        $this->assertSame([], ClassBookDraft::createFor($noSession)->absent_student_ids);
        $this->assertSame([], ClassBookDraft::createFor($unconfirmed)->absent_student_ids);
        $this->assertSame([], ClassBookDraft::createFor($emptyList)->absent_student_ids);
    }

    public function test_a_session_of_another_lesson_does_not_count(): void
    {
        [$schoolClass, $students, $teacher] = $this->classWithStudents(3);
        $lesson = $this->lessonOf($schoolClass, $teacher);
        LessonRecording::factory()->for($this->lessonOf($schoolClass, $teacher))->presentStudents([$students->first()])->create();

        $this->assertSame([], ClassBookDraft::createFor($lesson)->absent_student_ids);
    }

    public function test_a_lesson_gets_one_entry_only(): void
    {
        [$schoolClass, , $teacher] = $this->classWithStudents();
        $lesson = $this->lessonOf($schoolClass, $teacher);

        $first = ClassBookDraft::createFor($lesson);
        $first->update(['content' => 'Von Hand geändert']);
        $second = ClassBookDraft::createFor($lesson);

        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertTrue($second->is($first));
        $this->assertSame('Von Hand geändert', $second->content);
        $this->assertSame(1, ClassBookEntry::query()->count());
        $this->assertTrue($lesson->classBookEntry->is($first));
    }

    public function test_the_database_refuses_a_second_entry_for_the_same_lesson(): void
    {
        $lesson = Lesson::factory()->create();
        ClassBookEntry::factory()->create(['school_class_id' => $lesson->school_class_id, 'teacher_id' => $lesson->teacher_id, 'lesson_id' => $lesson->getKey()]);

        $this->expectException(UniqueConstraintViolationException::class);

        ClassBookEntry::factory()->create(['school_class_id' => $lesson->school_class_id, 'teacher_id' => $lesson->teacher_id, 'lesson_id' => $lesson->getKey()]);
    }

    public function test_the_entry_outlives_a_deleted_lesson(): void
    {
        [$schoolClass, , $teacher] = $this->classWithStudents();
        $lesson = $this->lessonOf($schoolClass, $teacher);
        $entry = ClassBookDraft::createFor($lesson);

        $lesson->delete();

        $this->assertNull($entry->fresh()->lesson_id);
    }
}
