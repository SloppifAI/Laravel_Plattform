<?php

namespace Tests\Feature\Review;

use App\Models\Lesson;
use App\Models\Question;
use App\Models\ReviewItem;
use App\Models\Submission;
use App\Models\User;
use App\QuestionType;

/**
 * Small builders for "Heute wiederholen": a student and the questions they have to revise.
 */
trait CreatesReviewFixtures
{
    /**
     * A student in a class of a school of their own.
     */
    private function newStudent(): User
    {
        return User::factory()->student()->create();
    }

    /**
     * A lesson released to the student, in their class and school, as in the app. Its deadline is long over, so the
     * solutions of its questions may be shown (ReviewItem::scopeAvailableTo()); pass a deadline to change that.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function releasedLesson(User $student, array $attributes = []): Lesson
    {
        return Lesson::factory()->for($student->schoolClass)->publishedFor([$student])->create(['teacher_id' => $student->schoolClass->teacher_id, 'due_on' => '2020-01-01', ...$attributes]);
    }

    /**
     * The student's evaluated hand-in for the lesson (the one they have already, if any): without it nothing of the
     * lesson is offered for revising.
     */
    private function handedIn(User $student, Lesson $lesson): Submission
    {
        return Submission::query()->whereBelongsTo($lesson)->whereBelongsTo($student, 'student')->first()
            ?? Submission::factory()->for($lesson)->for($student, 'student')->evaluated()->create();
    }

    /**
     * A question to revise, due today unless the item says otherwise. Without a lesson it is one of a new lesson that is
     * released to the student, handed in and past its deadline, as after an evaluation in the app; pass a lesson to put
     * several questions into one (and hand it in yourself).
     *
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $item
     */
    private function itemFor(User $student, array $question = [], array $item = [], ?Lesson $lesson = null): ReviewItem
    {
        if ($lesson === null) {
            $lesson = $this->releasedLesson($student);
            $this->handedIn($student, $lesson);
        }

        $question = Question::factory()->for($lesson)->create(['type' => QuestionType::Comprehension, ...$question]);

        return ReviewItem::factory()->for($student, 'student')->for($question)->create($item);
    }
}
