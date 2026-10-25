<?php

namespace Tests\Feature\Review;

use App\Models\Lesson;
use App\Models\Question;
use App\Models\ReviewItem;
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
     * A question to revise, due today unless the item says otherwise. Its lesson is released to the student, in their
     * class and school, as in the app; pass a lesson to put several questions into one.
     *
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $item
     */
    private function itemFor(User $student, array $question = [], array $item = [], ?Lesson $lesson = null): ReviewItem
    {
        $lesson ??= Lesson::factory()->for($student->schoolClass)->publishedFor([$student])->create();
        $question = Question::factory()->for($lesson)->create(['type' => QuestionType::Comprehension, ...$question]);

        return ReviewItem::factory()->for($student, 'student')->for($question)->create($item);
    }
}
