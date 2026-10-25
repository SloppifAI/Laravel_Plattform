<?php

namespace App\Support;

use App\Models\Question;
use App\Models\ReviewItem;
use App\Models\Submission;
use App\Models\TimetableEntry;
use App\Models\User;
use App\QuestionType;
use App\ReviewRating;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Heute wiederholen": revising in intervals with Leitner boxes, fully deterministic and without any AI call. When a
 * hand-in is evaluated, each answered comprehension and application question enters box 1, due the next day. A question
 * rated "Sofort gewusst" moves up one box, "Mit Mühe" stays and waits for the interval of its box again, "Nicht gewusst"
 * goes back to box 1. The days per box come from `lernplattform.review.intervals`; past the last one a question counts as
 * consolidated and is not asked any more. At most `lernplattform.review.daily_limit` questions a day, oldest due first.
 * A day is the school's day (TimetableEntry::TIMEZONE), not the server's: "tomorrow" and the limit follow the German clock.
 *
 * Practice only: ratings never touch a score, the learning progress or the parents' report. Nothing here is shown to
 * anybody but the student, and no response times or single attempts are kept, only counters.
 */
final class ReviewSchedule
{
    /**
     * Days until the next revision, one entry per box (box 1 first), each at least 1 so a question never comes back on
     * the day it was rated. A broken setting falls back to 1, 3, 7 and 21.
     *
     * @return list<int>
     */
    public static function intervals(): array
    {
        $days = array_values(array_filter(
            array_map('intval', (array) config('lernplattform.review.intervals')),
            fn (int $days): bool => $days >= 1,
        ));

        return $days === [] ? [1, 3, 7, 21] : $days;
    }

    /**
     * The box of questions that count as known and are not asked any more: one past the last interval.
     */
    public static function consolidatedBox(): int
    {
        return count(self::intervals()) + 1;
    }

    public static function dailyLimit(): int
    {
        return max(0, (int) config('lernplattform.review.daily_limit'));
    }

    /**
     * The start of today in school time. Every date and every "today" of the plan comes from here.
     */
    public static function schoolToday(): CarbonInterface
    {
        return TimetableEntry::schoolNow()->startOfDay();
    }

    /**
     * Puts every question the hand-in answered into box 1, due after the first interval. A question the student has
     * already (also from an earlier hand-in or evaluation) keeps its box and due date, so evaluating again creates no
     * duplicates and resets nothing. Questions without a fixed solution (reflection) are left out.
     */
    public static function enqueueFor(Submission $submission): void
    {
        if (! $submission->isEvaluated()) {
            return;
        }

        $now = now();
        $dueOn = $submission->evaluated_at->copy()->setTimezone(TimetableEntry::TIMEZONE)->addDays(self::intervals()[0])->toDateString();

        $rows = $submission->answers()
            ->where('answer', '!=', '')
            ->whereHas('question', fn (Builder $question) => $question->whereIn('type', array_column(QuestionType::reviewable(), 'value')))
            ->pluck('question_id')
            ->unique()
            ->map(fn (mixed $questionId): array => [
                'student_id' => $submission->student_id,
                'question_id' => (int) $questionId,
                'box' => 1,
                'due_on' => $dueOn,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        // Ignoring a clash with the unique key makes this safe when two evaluations run at once.
        if ($rows !== []) {
            ReviewItem::query()->insertOrIgnore($rows);
        }
    }

    /**
     * A returned hand-in is deleted so the student can redo it: their questions of that lesson leave the boxes with it
     * (the solution must not stay on offer while the homework is open again), and the next evaluation enters them afresh.
     */
    public static function forget(Submission $submission): void
    {
        ReviewItem::query()
            ->where('student_id', $submission->student_id)
            ->whereIn('question_id', Question::query()->where('lesson_id', $submission->lesson_id)->select('id'))
            ->delete();
    }

    /**
     * Moves the item between the boxes by the rating and sets when it is due next. Only an item that is due and not
     * consolidated moves, so a double click or a second tab rates a question once.
     *
     * @return bool whether the rating was taken
     */
    public static function rate(ReviewItem $item, ReviewRating $rating): bool
    {
        $consolidated = self::consolidatedBox();
        $box = match ($rating) {
            ReviewRating::Knew => min($item->box + 1, $consolidated),
            ReviewRating::Hard => $item->box,
            ReviewRating::Forgot => 1,
        };
        $today = self::schoolToday();
        $dueOn = $box >= $consolidated ? $today : $today->copy()->addDays(self::intervals()[$box - 1]);

        return self::dueQuery()
            ->whereKey($item->getKey())
            ->update([
                'box' => $box,
                'due_on' => $dueOn->toDateString(),
                'last_reviewed_at' => now(),
                $rating->counterColumn() => DB::raw($rating->counterColumn().' + 1'),
            ]) === 1;
    }

    /**
     * What the student revises today: the due questions of their lessons (oldest first), as many as the daily limit
     * has room for after what they revised already today.
     *
     * @return Collection<int, ReviewItem>
     */
    public static function todaysQueue(User $student): Collection
    {
        return self::dueQuery()
            ->availableTo($student)
            ->orderBy('due_on')
            ->orderBy('id')
            ->limit(self::remainingToday($student))
            ->with('question.lesson')
            ->get();
    }

    /**
     * How many questions the student gets today, as shown on their start page.
     */
    public static function todaysCount(User $student): int
    {
        return min(self::remainingToday($student), self::dueQuery()->availableTo($student)->count());
    }

    /**
     * How many questions the student has rated today (counts against the daily limit).
     */
    public static function reviewedToday(User $student): int
    {
        return ReviewItem::query()->whereBelongsTo($student, 'student')->where('last_reviewed_at', '>=', self::schoolToday()->setTimezone(config('app.timezone')))->count();
    }

    private static function remainingToday(User $student): int
    {
        return max(0, self::dailyLimit() - self::reviewedToday($student));
    }

    /**
     * @return Builder<ReviewItem>
     */
    private static function dueQuery(): Builder
    {
        return ReviewItem::query()
            ->where('box', '<', self::consolidatedBox())
            ->whereDate('due_on', '<=', self::schoolToday()->toDateString());
    }
}
