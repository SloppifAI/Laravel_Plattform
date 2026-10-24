<?php

namespace Tests\Feature\StudentProgress;

use App\Models\Answer;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Submission;
use App\Models\User;

/**
 * Small builders for evaluated homework and scored answers of a student in a class.
 */
trait CreatesLearningResults
{
    /**
     * An evaluated submission for a new lesson that is delegated to the student.
     *
     * @param  list<string>  $gaps
     */
    private function evaluatedHomework(User $student, string $topic, string $heldOn = '2026-10-01', array $gaps = [], int $understandingScore = 60): Submission
    {
        $lesson = Lesson::factory()
            ->for($student->schoolClass)
            ->publishedFor([$student])
            ->create(['subject' => 'Biologie', 'topic' => $topic, 'held_on' => $heldOn]);

        return Submission::factory()
            ->for($lesson)
            ->for($student, 'student')
            ->evaluated($understandingScore)
            ->create(['gaps' => $gaps]);
    }

    private function answer(Submission $submission, Competency $competency, int $score): void
    {
        $question = Question::factory()->for($submission->lesson)->create(['competency_id' => $competency->id]);

        Answer::factory()->for($submission)->for($question)->create(['score' => $score]);
    }
}
