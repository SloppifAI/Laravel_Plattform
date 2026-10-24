<?php

namespace Tests\Feature\LessonStarter;

use App\Models\Answer;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\Support\LessonReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LessonReviewTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolClass = SchoolClass::factory()->create();
        $this->lesson = $this->lessonHeldOn('2026-10-01');
    }

    public function test_the_weakest_questions_come_first_and_ties_go_to_the_question_with_more_weak_answers(): void
    {
        $first = $this->question($this->lesson, 'Was ist Mitose?', 1);
        $second = $this->question($this->lesson, 'Wozu dient der Chloroplast?', 2);
        $third = $this->question($this->lesson, 'Was macht die Zellwand?', 3);
        $understood = $this->question($this->lesson, 'Was ist ein Zellkern?', 4);
        $fewAnswers = $this->question($this->lesson, 'Nur zwei Antworten', 5);
        $this->question($this->lesson, 'Nie beantwortet', 6);

        $this->evaluatedSubmission($this->lesson, [$first->id => 20, $second->id => 40, $third->id => 50, $understood->id => 90, $fewAnswers->id => 0]);
        $this->evaluatedSubmission($this->lesson, [$first->id => 30, $second->id => 60, $third->id => 50, $understood->id => 90, $fewAnswers->id => 0]);
        $this->evaluatedSubmission($this->lesson, [$first->id => 25, $second->id => 50, $third->id => 50, $understood->id => 90]);

        $review = LessonReview::forLesson($this->lesson);

        $this->assertSame(3, $review['evaluatedCount']);
        $this->assertSame([
            ['question_id' => $first->id, 'prompt' => 'Was ist Mitose?', 'expected_answer' => $first->expected_answer, 'average' => 25, 'answered' => 3, 'below' => 3],
            ['question_id' => $second->id, 'prompt' => 'Wozu dient der Chloroplast?', 'expected_answer' => $second->expected_answer, 'average' => 50, 'answered' => 3, 'below' => 1],
            ['question_id' => $third->id, 'prompt' => 'Was macht die Zellwand?', 'expected_answer' => $third->expected_answer, 'average' => 50, 'answered' => 3, 'below' => 0],
        ], $review['questions']);
        $this->assertSame(['question', 'question', 'question'], array_column($review['points'], 'kind'));
        $this->assertSame(['Was ist Mitose?', 'Wozu dient der Chloroplast?', 'Was macht die Zellwand?'], array_column($review['points'], 'title'));
    }

    public function test_equal_questions_keep_the_order_of_the_lesson(): void
    {
        $later = $this->question($this->lesson, 'Später', 2);
        $earlier = $this->question($this->lesson, 'Früher', 1);

        $this->evaluatedSubmissions($this->lesson, [$later->id => 40, $earlier->id => 40]);

        $this->assertSame(['Früher', 'Später'], array_column(LessonReview::forLesson($this->lesson)['questions'], 'prompt'));
    }

    public function test_questions_the_class_has_understood_are_no_points(): void
    {
        $understood = $this->question($this->lesson, 'Was ist Mitose?', 1);
        $almost = $this->question($this->lesson, 'Was ist Meiose?', 2);
        $this->evaluatedSubmissions($this->lesson, [$understood->id => 75, $almost->id => 74]);

        $review = LessonReview::forLesson($this->lesson);

        $this->assertSame(['Was ist Meiose?'], array_column($review['questions'], 'prompt'));
        $this->assertSame(['Was ist Meiose?'], array_column($review['points'], 'title'));
    }

    public function test_only_scored_answers_of_evaluated_submissions_of_this_lesson_count(): void
    {
        $question = $this->question($this->lesson, 'Was ist Mitose?', 1);
        $otherLesson = $this->lessonHeldOn('2026-10-02');
        $otherQuestion = $this->question($otherLesson, 'Andere Stunde', 1);

        $this->evaluatedSubmissions($this->lesson, [$question->id => 60]);
        // Not evaluated yet: their (preliminary) scores and gaps are ignored.
        foreach (range(1, 3) as $ignored) {
            $pending = Submission::factory()->for($this->lesson)->for($this->student(), 'student')->create(['gaps' => ['Unbewertet']]);
            Answer::factory()->for($pending)->for($question)->create(['score' => 0]);
        }
        // Evaluated, but this answer has no score.
        $unscored = Submission::factory()->for($this->lesson)->for($this->student(), 'student')->evaluated(50)->create(['gaps' => []]);
        Answer::factory()->for($unscored)->for($question)->create(['score' => null]);
        $this->evaluatedSubmissions($otherLesson, [$otherQuestion->id => 0], ['Andere Lücke']);

        $review = LessonReview::forLesson($this->lesson);

        $this->assertSame(4, $review['evaluatedCount']);
        $this->assertSame([['question_id' => $question->id, 'prompt' => 'Was ist Mitose?', 'expected_answer' => $question->expected_answer, 'average' => 60, 'answered' => 3, 'below' => 0]], $review['questions']);
        $this->assertSame([], $review['gaps']);
    }

    public function test_gaps_are_normalised_deduplicated_per_submission_and_ordered_by_frequency(): void
    {
        $this->evaluatedSubmission($this->lesson, [], ['Mitose und Meiose verwechselt', 'Chloroplast']);
        $this->evaluatedSubmission($this->lesson, [], ['mitose  und meiose verwechselt.', 'Zellwand', 'zellwand', ' Zellwand! ']);
        $this->evaluatedSubmission($this->lesson, [], ['Chloroplast', 'Zellmembran', '', '   ', 'Mitose und Meiose verwechselt']);
        $this->evaluatedSubmission($this->lesson, [], ['Chloroplast', 'Zellwand']);
        Submission::factory()->for($this->lesson)->for($this->student(), 'student')->create(['gaps' => ['Zellwand', 'Zellwand']]);

        $review = LessonReview::forLesson($this->lesson);

        // "Zellwand" (2 evaluated submissions) and "Zellmembran" (1) stay below the group size.
        $this->assertSame([
            ['gap' => 'Chloroplast', 'count' => 3],
            ['gap' => 'Mitose und Meiose verwechselt', 'count' => 3],
        ], $review['gaps']);
    }

    public function test_quotation_marks_and_multibyte_punctuation_do_not_split_or_corrupt_gaps(): void
    {
        foreach (['„Zellwand“ vergessen', 'Zellwand“ vergessen', 'Zellwand vergessen!', 'zellwand vergessen…'] as $spelling) {
            $this->evaluatedSubmission($this->lesson, [], [$spelling, '„Zellkern“ verwechselt', 'Gerät À']);
        }

        $review = LessonReview::forLesson($this->lesson);

        $this->assertSame([
            ['gap' => 'Gerät À', 'count' => 4],
            ['gap' => '„Zellkern“ verwechselt', 'count' => 4],
            ['gap' => '„Zellwand“ vergessen', 'count' => 4],
        ], $review['gaps']);
        $this->assertTrue(mb_check_encoding(json_encode($review['gaps'], JSON_THROW_ON_ERROR), 'UTF-8'));
    }

    public function test_the_hint_to_write_a_longer_summary_is_no_gap_of_the_class(): void
    {
        $this->evaluatedSubmissions($this->lesson, [], ['Schreib deine Zusammenfassung ausführlicher: Was war der Ablauf der Stunde und was war das Wichtigste?']);

        $this->assertSame([], LessonReview::forLesson($this->lesson)['gaps']);
    }

    public function test_the_points_are_filled_up_with_gaps_to_three(): void
    {
        $question = $this->question($this->lesson, 'Was ist Mitose?', 1);

        $this->evaluatedSubmissions($this->lesson, [$question->id => 30], ['Chloroplast', 'Zellwand', 'Zellkern']);

        $points = LessonReview::forLesson($this->lesson)['points'];

        $this->assertSame(['question', 'gap', 'gap'], array_column($points, 'kind'));
        $this->assertSame(['Was ist Mitose?', 'Chloroplast', 'Zellkern'], array_column($points, 'title'));
        $this->assertSame([null, 3, 3], array_column($points, 'count'));
    }

    public function test_fewer_than_three_evaluated_submissions_give_no_numbers_and_no_gaps(): void
    {
        $question = $this->question($this->lesson, 'Was ist Mitose?', 1);

        $this->evaluatedSubmission($this->lesson, [$question->id => 20], ['Chloroplast', 'Zellwand']);
        $this->evaluatedSubmission($this->lesson, [$question->id => 30], ['Chloroplast', 'Zellwand']);

        $this->assertSame(['evaluatedCount' => 2, 'questions' => [], 'gaps' => [], 'points' => []], LessonReview::forLesson($this->lesson));
    }

    public function test_a_foerderaufgabe_is_never_reviewed(): void
    {
        $practice = $this->lessonHeldOn('2026-10-05', ['practice_competency_id' => Competency::factory()->create()->id]);
        $question = $this->question($practice, 'Was ist Mitose?', 1);

        $this->evaluatedSubmissions($practice, [$question->id => 20], ['Chloroplast']);

        $this->assertSame(['evaluatedCount' => 3, 'questions' => [], 'gaps' => [], 'points' => []], LessonReview::forLesson($practice));
    }

    public function test_a_lesson_without_evaluated_submissions_has_no_points(): void
    {
        $this->question($this->lesson, 'Was ist Mitose?', 1);
        Submission::factory()->for($this->lesson)->for($this->student(), 'student')->create();

        $this->assertSame(['evaluatedCount' => 0, 'questions' => [], 'gaps' => [], 'points' => []], LessonReview::forLesson($this->lesson));
    }

    public function test_the_previous_lesson_is_the_latest_earlier_evaluated_one_of_that_class_and_subject(): void
    {
        $first = $this->lessonHeldOn('2026-10-01');
        $second = $this->lessonHeldOn('2026-10-08');
        $notEvaluated = $this->lessonHeldOn('2026-10-15');
        Submission::factory()->for($notEvaluated)->for($this->student(), 'student')->create();
        $practice = $this->lessonHeldOn('2026-10-14', ['practice_competency_id' => Competency::factory()->create()->id]);
        $otherSubject = $this->lessonHeldOn('2026-10-20', ['subject' => 'Mathematik']);
        $otherClass = Lesson::factory()->for(SchoolClass::factory())->create(['subject' => 'Biologie', 'held_on' => '2026-10-12']);
        Submission::factory()->for($otherClass)->for(User::factory()->student($otherClass->schoolClass), 'student')->evaluated()->create();
        foreach ([$first, $second, $practice, $otherSubject] as $lesson) {
            Submission::factory()->for($lesson)->for($this->student(), 'student')->evaluated()->create();
        }

        // $this->lesson (held 2026-10-01, no submissions) is skipped as well.
        $this->assertTrue($second->is(LessonReview::previousLessonFor($this->schoolClass, 'Biologie')));
        $this->assertTrue($second->is(LessonReview::previousLessonFor($this->schoolClass, 'Biologie', Carbon::parse('2026-10-09'))));
        $this->assertTrue($first->is(LessonReview::previousLessonFor($this->schoolClass, 'Biologie', Carbon::parse('2026-10-08'))));
        $this->assertNull(LessonReview::previousLessonFor($this->schoolClass, 'Biologie', Carbon::parse('2026-10-01')));
        $this->assertNull(LessonReview::previousLessonFor($this->schoolClass, 'Deutsch'));
    }

    public function test_the_previous_lesson_is_one_the_acting_user_may_review(): void
    {
        $school = $this->schoolClass->school;
        $teacherA = User::factory()->teacher()->for($school)->create();
        $teacherB = User::factory()->teacher()->for($school)->create();
        $own = $this->lessonHeldOn('2026-10-03', ['teacher_id' => $teacherA->id]);
        $colleagues = $this->lessonHeldOn('2026-10-05', ['teacher_id' => $teacherB->id]);
        foreach ([$own, $colleagues] as $lesson) {
            Submission::factory()->for($lesson)->for($this->student(), 'student')->evaluated()->create();
        }

        $this->assertTrue($colleagues->is(LessonReview::previousLessonFor($this->schoolClass, 'Biologie')));
        $this->assertTrue($own->is(LessonReview::previousLessonFor($this->schoolClass, 'Biologie', null, $teacherA)));
        $this->assertTrue($colleagues->is(LessonReview::previousLessonFor($this->schoolClass, 'Biologie', null, $teacherB)));
        $this->assertTrue($colleagues->is(LessonReview::previousLessonFor($this->schoolClass, 'Biologie', null, User::factory()->admin()->for($school)->create())));
        $this->assertNull(LessonReview::previousLessonFor($this->schoolClass, 'Biologie', null, User::factory()->teacher()->for($school)->create()));
        $this->assertNull(LessonReview::previousLessonFor($this->schoolClass, 'Biologie', null, User::factory()->admin()->create()));
        $this->assertNull(LessonReview::previousLessonFor($this->schoolClass, 'Biologie', null, $this->student()));
        $this->assertNull(LessonReview::previousLessonFor($this->schoolClass, 'Biologie', null, User::factory()->operator()->create()));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lessonHeldOn(string $date, array $attributes = []): Lesson
    {
        return Lesson::factory()->for($this->schoolClass)->create(['subject' => 'Biologie', 'held_on' => $date, ...$attributes]);
    }

    private function question(Lesson $lesson, string $prompt, int $position): Question
    {
        return Question::factory()->for($lesson)->create(['prompt' => $prompt, 'position' => $position]);
    }

    private function student(): User
    {
        return User::factory()->student($this->schoolClass)->create();
    }

    /**
     * An evaluated submission of a new student with scored answers (question id => score) and the given gaps.
     *
     * @param  array<int, int>  $scores
     * @param  list<string>  $gaps
     */
    private function evaluatedSubmission(Lesson $lesson, array $scores, array $gaps = []): Submission
    {
        $submission = Submission::factory()->for($lesson)->for($this->student(), 'student')->evaluated(60)->create(['gaps' => $gaps]);

        foreach ($scores as $questionId => $score) {
            Answer::factory()->for($submission)->create(['question_id' => $questionId, 'score' => $score]);
        }

        return $submission;
    }

    /**
     * The smallest group of evaluated submissions, all with the same scores (question id => score) and gaps.
     *
     * @param  array<int, int>  $scores
     * @param  list<string>  $gaps
     */
    private function evaluatedSubmissions(Lesson $lesson, array $scores, array $gaps = []): void
    {
        foreach (range(1, LessonReview::MIN_GROUP) as $ignored) {
            $this->evaluatedSubmission($lesson, $scores, $gaps);
        }
    }
}
