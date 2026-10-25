<?php

namespace Tests\Feature\Review;

use App\Ai\LessonAssistant;
use App\Filament\Pages\ParentReport;
use App\Filament\Pages\WorkOnHomework;
use App\Models\Answer;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\ReviewItem;
use App\Models\Submission;
use App\Models\User;
use App\QuestionType;
use App\ReviewRating;
use App\Support\LearningInsights;
use App\Support\ReviewSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "Heute wiederholen", the planning: which questions enter the boxes when a hand-in is evaluated (AC-1), how a rating moves
 * them (AC-2) and that the ratings stay without any effect on scores and insights (AC-8).
 */
class ReviewScheduleTest extends TestCase
{
    use CreatesReviewFixtures;
    use RefreshDatabase;

    /**
     * An evaluated hand-in of the student with one answer per given question.
     *
     * @param  list<Question>  $questions
     */
    private function evaluatedHandIn(User $student, Lesson $lesson, array $questions, string $answer = 'Meine Antwort'): Submission
    {
        $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated(70)->create();

        foreach ($questions as $question) {
            Answer::factory()->for($submission)->for($question)->create(['answer' => $answer, 'score' => 60]);
        }

        return $submission;
    }

    /**
     * A lesson of the student's class with a comprehension, an application and a reflection question.
     *
     * @return array{Lesson, Question, Question, Question}
     */
    private function lessonWithThreeTypes(User $student, ?int $practiceCompetency = null): array
    {
        $lesson = $this->releasedLesson($student, ['practice_competency_id' => $practiceCompetency]);

        return [
            $lesson,
            Question::factory()->for($lesson)->create(['type' => QuestionType::Comprehension, 'position' => 1]),
            Question::factory()->for($lesson)->create(['type' => QuestionType::Application, 'position' => 2]),
            Question::factory()->for($lesson)->create(['type' => QuestionType::Reflection, 'position' => 3]),
        ];
    }

    // ---- AC-1: entering the boxes ------------------------------------------------------------------------------------

    public function test_an_evaluated_hand_in_puts_each_answered_comprehension_and_application_question_into_box_1_due_tomorrow(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $student = $this->newStudent();
        [$lesson, $comprehension, $application, $reflection] = $this->lessonWithThreeTypes($student);
        $unanswered = Question::factory()->for($lesson)->create(['type' => QuestionType::Comprehension, 'position' => 4]);
        $submission = $this->evaluatedHandIn($student, $lesson, [$comprehension, $application, $reflection]);

        ReviewSchedule::enqueueFor($submission);

        $items = ReviewItem::query()->orderBy('question_id')->get();
        $this->assertSame([$comprehension->id, $application->id], $items->pluck('question_id')->all());
        $this->assertSame([1, 1], $items->pluck('box')->all());
        $this->assertSame(['2026-10-21', '2026-10-21'], $items->map(fn (ReviewItem $item): string => $item->due_on->toDateString())->all());
        $this->assertSame([$student->id], $items->pluck('student_id')->unique()->values()->all());
        $this->assertSame(0, ReviewItem::query()->whereBelongsTo($unanswered)->count());
        $this->assertSame(0, ReviewItem::query()->whereBelongsTo($reflection)->count());
        $this->assertNull($items->first()->last_reviewed_at);
        $this->assertSame([0, 0, 0], [$items->first()->knew_count, $items->first()->hard_count, $items->first()->forgot_count]);
    }

    public function test_evaluating_again_creates_no_duplicates_and_resets_nothing(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $student = $this->newStudent();
        [$lesson, $comprehension, $application] = $this->lessonWithThreeTypes($student);
        $submission = $this->evaluatedHandIn($student, $lesson, [$comprehension, $application]);

        ReviewSchedule::enqueueFor($submission);
        ReviewSchedule::enqueueFor($submission);

        $this->assertSame(2, ReviewItem::query()->count());

        // The student has revised one of them in the meantime: a later evaluation of the same hand-in leaves it where it is.
        ReviewItem::query()->whereBelongsTo($comprehension)->update(['box' => 3, 'due_on' => '2026-10-27', 'knew_count' => 2]);
        $this->travelTo('2026-10-22 10:00:00');

        ReviewSchedule::enqueueFor($submission);

        $this->assertSame(2, ReviewItem::query()->count());
        $item = ReviewItem::query()->whereBelongsTo($comprehension)->sole();
        $this->assertSame([3, '2026-10-27', 2], [$item->box, $item->due_on->toDateString(), $item->knew_count]);
    }

    public function test_a_hand_in_that_is_not_evaluated_and_an_empty_answer_create_nothing(): void
    {
        $student = $this->newStudent();
        [$lesson, $comprehension, $application] = $this->lessonWithThreeTypes($student);
        $open = Submission::factory()->for($lesson)->for($student, 'student')->create();
        Answer::factory()->for($open)->for($comprehension)->create(['answer' => 'Antwort']);

        ReviewSchedule::enqueueFor($open);

        $this->assertDatabaseEmpty('review_items');

        $open->forceFill(['evaluated_at' => now()])->save();
        Answer::factory()->for($open)->for($application)->create(['answer' => '']);

        ReviewSchedule::enqueueFor($open);

        $this->assertSame([$comprehension->id], ReviewItem::query()->pluck('question_id')->all());
    }

    public function test_the_evaluation_in_the_app_enters_the_questions_of_homework_and_of_practice_tasks(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $student = $this->newStudent();
        $competency = Competency::factory()->create();
        [$lesson, $comprehension, $application, $reflection] = $this->lessonWithThreeTypes($student);
        [$practice, $practiceComprehension, $practiceApplication, $practiceReflection] = $this->lessonWithThreeTypes($student, $competency->id);

        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('evaluateSubmission')->twice()->andReturn([
            'understanding_score' => 80,
            'feedback' => 'Gut.',
            'gaps' => [],
            'answers' => [],
        ]));

        $this->actingAs($student);

        Livewire::test(WorkOnHomework::class, ['lesson' => $lesson])
            ->fillForm($this->homeworkForm([$comprehension, $application, $reflection]))
            ->call('submit')
            ->assertNotified('Done!');

        $this->assertEqualsCanonicalizing([$comprehension->id, $application->id], ReviewItem::query()->pluck('question_id')->all());

        Livewire::test(WorkOnHomework::class, ['lesson' => $practice])
            ->fillForm($this->homeworkForm([$practiceComprehension, $practiceApplication, $practiceReflection], 'Eine kurze Zusammenfassung zur Förderaufgabe, die lang genug ist.'))
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertNotified('Done!');

        $this->assertTrue($practice->isPracticeTask());
        $this->assertSame(4, ReviewItem::query()->count());
        $this->assertEqualsCanonicalizing(
            [$practiceComprehension->id, $practiceApplication->id],
            ReviewItem::query()->whereBelongsTo($student, 'student')->whereIn('question_id', $practice->questions()->pluck('id'))->pluck('question_id')->all(),
        );
    }

    public function test_an_evaluation_that_was_postponed_enters_the_questions_when_it_runs_later(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $student = $this->newStudent();
        [$lesson, $comprehension] = $this->lessonWithThreeTypes($student);
        $submission = Submission::factory()->for($lesson)->for($student, 'student')->create();
        Answer::factory()->for($submission)->for($comprehension)->create(['answer' => 'Meine Antwort']);
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('evaluateSubmission')->andReturn([
            'understanding_score' => 40,
            'feedback' => 'Schau noch einmal hin.',
            'gaps' => [],
            'answers' => [],
        ]));

        $this->assertDatabaseEmpty('review_items');

        $this->travelTo('2026-10-23 08:00:00');
        $this->actingAs($student);

        Livewire::test(WorkOnHomework::class, ['lesson' => $lesson])
            ->callAction('evaluate')
            ->assertNotified('Done!');

        // Due the day after the evaluation, not after the hand-in.
        $this->assertSame('2026-10-24', ReviewItem::query()->sole()->due_on->toDateString());
    }

    public function test_a_failing_hook_never_turns_an_evaluation_into_a_failure(): void
    {
        $student = $this->newStudent();
        [$lesson, $comprehension, $application, $reflection] = $this->lessonWithThreeTypes($student);
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('evaluateSubmission')->andReturn([
            'understanding_score' => 80,
            'feedback' => 'Gut.',
            'gaps' => [],
            'answers' => [],
        ]));
        Schema::drop('review_items');

        $this->actingAs($student);

        Livewire::test(WorkOnHomework::class, ['lesson' => $lesson])
            ->fillForm($this->homeworkForm([$comprehension, $application, $reflection]))
            ->call('submit')
            ->assertNotified('Done!');

        $submission = Submission::query()->sole();
        $this->assertTrue($submission->isEvaluated());
        $this->assertSame(80, $submission->understanding_score);
    }

    /**
     * @param  list<Question>  $questions
     * @return array{summary: string, answers: array<int, string>}
     */
    private function homeworkForm(array $questions, string $summary = 'Pflanzen stellen mit Licht, Wasser und Kohlendioxid Zucker her. Dabei entsteht Sauerstoff als Abfallprodukt.'): array
    {
        return [
            'summary' => $summary,
            'answers' => collect($questions)->mapWithKeys(fn (Question $question): array => [$question->id => 'Eine Antwort aus eigenen Worten.'])->all(),
        ];
    }

    // ---- AC-2: the boxes ---------------------------------------------------------------------------------------------

    /**
     * The Leitner table: days until the next revision per box 1 to 4 are 1, 3, 7 and 21, box 5 counts as consolidated.
     *
     * @return array<string, array{int, ReviewRating, int, int|null}>
     */
    public static function transitions(): array
    {
        return [
            'box 1 known' => [1, ReviewRating::Knew, 2, 3],
            'box 1 hard' => [1, ReviewRating::Hard, 1, 1],
            'box 1 forgotten' => [1, ReviewRating::Forgot, 1, 1],
            'box 2 known' => [2, ReviewRating::Knew, 3, 7],
            'box 2 hard' => [2, ReviewRating::Hard, 2, 3],
            'box 2 forgotten' => [2, ReviewRating::Forgot, 1, 1],
            'box 3 known' => [3, ReviewRating::Knew, 4, 21],
            'box 3 hard' => [3, ReviewRating::Hard, 3, 7],
            'box 3 forgotten' => [3, ReviewRating::Forgot, 1, 1],
            'box 4 known' => [4, ReviewRating::Knew, 5, null],
            'box 4 hard' => [4, ReviewRating::Hard, 4, 21],
            'box 4 forgotten' => [4, ReviewRating::Forgot, 1, 1],
        ];
    }

    #[DataProvider('transitions')]
    public function test_a_rating_moves_the_question_between_the_boxes_as_planned(int $box, ReviewRating $rating, int $expectedBox, ?int $expectedDays): void
    {
        $this->travelTo('2026-10-20 09:30:00');
        $student = $this->newStudent();
        $item = $this->itemFor($student, item: ['box' => $box, 'due_on' => '2026-10-20']);

        $this->assertTrue(ReviewSchedule::rate($item, $rating));

        $item->refresh();
        $this->assertSame($expectedBox, $item->box);
        $this->assertSame('2026-10-20 09:30:00', $item->last_reviewed_at->toDateTimeString());
        $this->assertSame(
            [$rating === ReviewRating::Knew ? 1 : 0, $rating === ReviewRating::Hard ? 1 : 0, $rating === ReviewRating::Forgot ? 1 : 0],
            [$item->knew_count, $item->hard_count, $item->forgot_count],
        );

        if ($expectedDays === null) {
            // Box 5 is consolidated: it is not asked any more, however much time passes.
            $this->travelTo('2030-01-01');
            $this->assertCount(0, ReviewSchedule::todaysQueue($student));
            $this->assertSame(0, ReviewSchedule::todaysCount($student));

            return;
        }

        $this->assertSame(Carbon::parse('2026-10-20')->addDays($expectedDays)->toDateString(), $item->due_on->toDateString());
    }

    public function test_a_question_known_every_time_is_asked_after_1_3_7_and_21_days_and_then_never_again(): void
    {
        $this->travelTo('2026-10-20 08:00:00');
        $student = $this->newStudent();
        [$lesson, $comprehension] = $this->lessonWithThreeTypes($student);
        ReviewSchedule::enqueueFor($this->evaluatedHandIn($student, $lesson, [$comprehension]));

        // Box 1 is due the next day, not before.
        $this->travelTo('2026-10-20 21:59:00');
        $this->assertSame(0, ReviewSchedule::todaysCount($student));

        foreach ([['2026-10-21', 2], ['2026-10-24', 3], ['2026-10-31', 4], ['2026-11-21', 5]] as [$day, $boxAfter]) {
            $this->travelTo("{$day} 00:00:00");
            $this->assertSame(1, ReviewSchedule::todaysCount($student), "due on {$day}");

            $item = ReviewSchedule::todaysQueue($student)->sole();
            ReviewSchedule::rate($item, ReviewRating::Knew);

            $this->assertSame($boxAfter, $item->fresh()->box);
            $this->assertSame(0, ReviewSchedule::todaysCount($student), 'nothing more on the day it was rated');
        }

        $this->travelTo('2027-06-01');
        $this->assertSame(0, ReviewSchedule::todaysCount($student));
    }

    public function test_a_question_that_is_not_due_or_was_rated_already_is_not_rated_again(): void
    {
        $this->travelTo('2026-10-20 09:00:00');
        $student = $this->newStudent();
        $notDue = $this->itemFor($student, item: ['box' => 2, 'due_on' => '2026-10-21']);
        $due = $this->itemFor($student, item: ['box' => 1, 'due_on' => '2026-10-20']);

        $this->assertFalse(ReviewSchedule::rate($notDue, ReviewRating::Knew));
        $this->assertSame([2, 0], [$notDue->fresh()->box, $notDue->fresh()->knew_count]);

        // A double click or a second tab: the second rating of the same question finds it moved on.
        $this->assertTrue(ReviewSchedule::rate($due, ReviewRating::Knew));
        $this->assertFalse(ReviewSchedule::rate($due, ReviewRating::Knew));
        $this->assertSame([2, 1], [$due->fresh()->box, $due->fresh()->knew_count]);
    }

    public function test_the_days_and_the_daily_limit_come_from_the_configuration(): void
    {
        $this->assertSame([1, 3, 7, 21], ReviewSchedule::intervals());
        $this->assertSame(5, ReviewSchedule::dailyLimit());
        $this->assertSame(5, ReviewSchedule::consolidatedBox());

        config(['lernplattform.review.intervals' => [2, 5], 'lernplattform.review.daily_limit' => 3]);

        $this->assertSame([2, 5], ReviewSchedule::intervals());
        $this->assertSame(3, ReviewSchedule::consolidatedBox());
        $this->assertSame(3, ReviewSchedule::dailyLimit());

        $this->travelTo('2026-10-20 09:00:00');
        $student = $this->newStudent();
        [$lesson, $comprehension] = $this->lessonWithThreeTypes($student);
        ReviewSchedule::enqueueFor($this->evaluatedHandIn($student, $lesson, [$comprehension]));

        $this->assertSame('2026-10-22', ReviewItem::query()->sole()->due_on->toDateString());

        // A broken setting falls back to the defaults instead of asking a question every day or never.
        config(['lernplattform.review.intervals' => [0, -3], 'lernplattform.review.daily_limit' => -1]);

        $this->assertSame([1, 3, 7, 21], ReviewSchedule::intervals());
        $this->assertSame(0, ReviewSchedule::dailyLimit());
    }

    // ---- The school's day, not the server's -------------------------------------------------------------------------

    public function test_tomorrow_is_tomorrow_in_school_time(): void
    {
        // 00:30 on the 21st in Berlin (summer time) is still the 20th on the server's UTC clock.
        $this->travelTo('2026-10-20 22:30:00');
        $student = $this->newStudent();
        [$lesson, $comprehension] = $this->lessonWithThreeTypes($student);

        ReviewSchedule::enqueueFor($this->evaluatedHandIn($student, $lesson, [$comprehension]));

        $this->assertSame('2026-10-22', ReviewItem::query()->sole()->due_on->toDateString());

        // 02:05 on the same German day: not due, although the UTC date is the 21st already.
        $this->travelTo('2026-10-21 00:05:00');
        $this->assertSame(0, ReviewSchedule::todaysCount($student));

        // 23:59 on the 21st in Berlin still is not the day after, 00:01 on the 22nd is.
        $this->travelTo('2026-10-21 21:59:00');
        $this->assertSame(0, ReviewSchedule::todaysCount($student));

        $this->travelTo('2026-10-21 22:01:00');
        $this->assertSame(1, ReviewSchedule::todaysCount($student));
    }

    public function test_a_rating_after_german_midnight_waits_for_the_next_german_day(): void
    {
        // 00:20 on the 10th in Berlin (winter time) is 23:20 on the 9th in UTC.
        $this->travelTo('2026-11-09 23:20:00');
        $student = $this->newStudent();
        $item = $this->itemFor($student, item: ['box' => 1]);

        $this->assertTrue(ReviewSchedule::rate($item, ReviewRating::Hard));

        $this->assertSame('2026-11-11', $item->fresh()->due_on->toDateString());

        // 01:10 on the same German day: it never comes back on the day it was rated.
        $this->travelTo('2026-11-10 00:10:00');
        $this->assertSame(0, ReviewSchedule::todaysCount($student));
    }

    public function test_the_daily_limit_counts_the_german_day(): void
    {
        $this->travelTo('2026-11-09 23:20:00');
        $student = $this->newStudent();

        foreach (range(1, 6) as $number) {
            $this->itemFor($student, ['prompt' => "Frage-{$number}"]);
        }

        foreach (range(1, ReviewSchedule::dailyLimit()) as $ignored) {
            $this->assertTrue(ReviewSchedule::rate(ReviewSchedule::todaysQueue($student)->first(), ReviewRating::Knew));
        }

        $this->assertSame(0, ReviewSchedule::todaysCount($student));

        // 01:10 on the same German day: five were rated today, so the limit still holds.
        $this->travelTo('2026-11-10 00:10:00');
        $this->assertSame(5, ReviewSchedule::reviewedToday($student));
        $this->assertSame(0, ReviewSchedule::todaysCount($student));

        // The next German day starts at 23:00 UTC: the sixth question is there.
        $this->travelTo('2026-11-10 23:10:00');
        $this->assertSame(0, ReviewSchedule::reviewedToday($student));
        $this->assertSame(1, ReviewSchedule::todaysCount($student));
    }

    // ---- AC-8: practice only -----------------------------------------------------------------------------------------

    public function test_ratings_change_no_score_no_understanding_score_and_no_insight(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $student = $this->newStudent();
        $competency = Competency::factory()->create();
        $lesson = Lesson::factory()->for($student->schoolClass)->publishedFor([$student])->create();
        $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated(55)->create(['gaps' => ['Eine Lücke']]);

        foreach ([QuestionType::Comprehension, QuestionType::Application] as $position => $type) {
            $question = Question::factory()->for($lesson)->create(['type' => $type, 'competency_id' => $competency->id, 'position' => $position + 1]);
            Answer::factory()->for($submission)->for($question)->create(['answer' => 'Antwort', 'score' => 40, 'feedback' => 'Fast.']);
        }

        ReviewSchedule::enqueueFor($submission);
        $before = $this->learningState($student);
        $this->assertNotEmpty($before['competencies']);

        foreach (ReviewRating::cases() as $rating) {
            ReviewItem::query()->update(['box' => 2, 'due_on' => today()]);

            foreach (ReviewItem::query()->get() as $item) {
                $this->assertTrue(ReviewSchedule::rate($item, $rating));
            }
        }

        $this->assertSame(6, ReviewItem::query()->sum('knew_count') + ReviewItem::query()->sum('hard_count') + ReviewItem::query()->sum('forgot_count'));
        $this->assertEquals($before, $this->learningState($student));

        $this->actingAs($student->schoolClass->teacher)
            ->get(ParentReport::getUrl(['student' => $student]))
            ->assertOk()
            ->assertDontSee(['Revise today', 'Knew it straight away', 'With effort', 'Did not know it']);
    }

    /**
     * Everything a score or an insight is made of, as stored and as computed.
     *
     * @return array<string, mixed>
     */
    private function learningState(User $student): array
    {
        $answers = LearningInsights::scoredAnswers([$student->id]);

        return [
            'submissions' => Submission::query()->orderBy('id')->get()->map(fn (Submission $submission): array => $submission->getAttributes())->all(),
            'answers' => Answer::query()->orderBy('id')->get()->map(fn (Answer $answer): array => $answer->getAttributes())->all(),
            'competencies' => LearningInsights::studentCompetencies($student),
            'averages' => LearningInsights::competencyAverages($answers),
            'support' => LearningInsights::supportNeeds($answers),
            'evaluated' => LearningInsights::evaluatedSubmissions($student)->map(fn (Submission $submission): array => $submission->getAttributes())->all(),
        ];
    }
}
