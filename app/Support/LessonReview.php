<?php

namespace App\Support;

use App\Models\Answer;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\User;
use App\UnderstandingLevel;
use Carbon\CarbonInterface;

/**
 * What a class understood least in one lesson, to start the next lesson with: derived only from the stored scores and
 * gaps of the lesson's evaluated submissions, no AI call. The result is aggregated over the whole class and carries no
 * student ids or names. To keep it from showing one student's result as the class's, nothing is reported below
 * MIN_GROUP evaluated submissions, for questions with fewer than MIN_GROUP scored answers, or for gaps named in fewer
 * than MIN_GROUP submissions; a Förderaufgabe (delegated to a few students) is never reviewed.
 */
class LessonReview
{
    /**
     * How many points a review names.
     */
    public const int POINT_COUNT = 3;

    /**
     * The smallest group a number or gap may stand for.
     */
    public const int MIN_GROUP = 3;

    /**
     * The weakest questions (not yet understood by the class: lowest average answer score first, then most answers
     * below the support threshold, then lesson order) and the most frequent gaps (most submissions first, then
     * alphabetical), at most three of each. `points` are the three points to work on: weak questions first, filled up
     * with frequent gaps. Everything but `evaluatedCount` is empty while the group is too small.
     *
     * @return array{
     *     evaluatedCount: int,
     *     questions: list<array{question_id: int, prompt: string, expected_answer: string|null, average: int, answered: int, below: int}>,
     *     gaps: list<array{gap: string, count: int}>,
     *     points: list<array{kind: 'question'|'gap', title: string, expected_answer: string|null, average: int|null, answered: int|null, below: int|null, count: int|null}>
     * }
     */
    public static function forLesson(Lesson $lesson): array
    {
        $submissions = $lesson->submissions()->whereNotNull('evaluated_at')->orderBy('id')->get(['id', 'gaps']);

        if ($lesson->isPracticeTask() || $submissions->count() < self::MIN_GROUP) {
            return ['evaluatedCount' => $submissions->count(), 'questions' => [], 'gaps' => [], 'points' => []];
        }

        $scoresByQuestion = Answer::query()
            ->whereIn('submission_id', $submissions->modelKeys())
            ->whereNotNull('score')
            ->get(['question_id', 'score'])
            ->groupBy('question_id');

        $questions = $lesson->questions()->get()
            ->filter(fn (Question $question): bool => ($scoresByQuestion[$question->getKey()] ?? collect())->count() >= self::MIN_GROUP)
            ->map(function (Question $question) use ($scoresByQuestion): array {
                $scores = $scoresByQuestion[$question->getKey()]->pluck('score');

                return [
                    'question_id' => $question->getKey(),
                    'prompt' => $question->prompt,
                    'expected_answer' => $question->expected_answer,
                    'average' => (int) round($scores->avg()),
                    'answered' => $scores->count(),
                    'below' => $scores->filter(fn (int $score): bool => $score < UnderstandingLevel::SUPPORT_THRESHOLD)->count(),
                    'position' => $question->position,
                ];
            })
            ->reject(fn (array $row): bool => UnderstandingLevel::fromScore($row['average']) === UnderstandingLevel::Understood)
            ->sort(fn (array $a, array $b): int => [$a['average'], -$a['below'], $a['position'], $a['question_id']] <=> [$b['average'], -$b['below'], $b['position'], $b['question_id']])
            ->take(self::POINT_COUNT)
            ->map(fn (array $row): array => collect($row)->except('position')->all())
            ->values()
            ->all();

        $gaps = static::frequentGaps($submissions->pluck('gaps'));

        return [
            'evaluatedCount' => $submissions->count(),
            'questions' => $questions,
            'gaps' => $gaps,
            'points' => collect([
                ...array_map(fn (array $question): array => [
                    'kind' => 'question',
                    'title' => $question['prompt'],
                    'expected_answer' => $question['expected_answer'],
                    'average' => $question['average'],
                    'answered' => $question['answered'],
                    'below' => $question['below'],
                    'count' => null,
                ], $questions),
                ...array_map(fn (array $gap): array => [
                    'kind' => 'gap',
                    'title' => $gap['gap'],
                    'expected_answer' => null,
                    'average' => null,
                    'answered' => null,
                    'below' => null,
                    'count' => $gap['count'],
                ], $gaps),
            ])->take(self::POINT_COUNT)->values()->all(),
        ];
    }

    /**
     * The latest lesson of the class in this subject that is held before the given day (without a day: any day) and
     * has evaluated submissions. Förderaufgaben are skipped, they are delegated to single students. With the acting
     * user, only lessons that user may review: a teacher's own lessons, all lessons of the class for the administration
     * of its school, none for anyone else.
     */
    public static function previousLessonFor(SchoolClass $class, string $subject, ?CarbonInterface $before = null, ?User $for = null): ?Lesson
    {
        if ($for !== null && ! ($for->isTeacher() || ($for->isAdmin() && $for->isMemberOf($class->school)))) {
            return null;
        }

        return Lesson::query()
            ->where('school_class_id', $class->getKey())
            ->where('subject', $subject)
            ->whereNull('practice_competency_id')
            ->when($for?->isTeacher(), fn ($query) => $query->where('teacher_id', $for->getKey()))
            ->when($before, fn ($query) => $query->whereDate('held_on', '<', $before->toDateString()))
            ->whereHas('submissions', fn ($query) => $query->whereNotNull('evaluated_at'))
            ->orderByDesc('held_on')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Gaps named in at least MIN_GROUP submissions, counted once per submission. Spelling that differs only in case,
     * spacing, quotation marks or surrounding punctuation is the same gap; it is shown the way the first submission
     * wrote it. The hint to write a longer summary is skipped: it is addressed to one student, not about the topic.
     *
     * @param  iterable<array<mixed>|null>  $gapsPerSubmission
     * @return list<array{gap: string, count: int}>
     */
    private static function frequentGaps(iterable $gapsPerSubmission): array
    {
        $counted = [];

        foreach ($gapsPerSubmission as $gaps) {
            $seen = [];

            foreach ($gaps ?? [] as $gap) {
                if (! is_string($gap)) {
                    continue;
                }

                $label = trim((string) preg_replace('/\s+/u', ' ', $gap));
                $key = mb_strtolower(trim((string) preg_replace(['/[„“”‚‘’"\']/u', '/^[\p{P}\p{S}\s]+|[\p{P}\p{S}\s]+$/u'], '', $label)));

                if ($key === '' || isset($seen[$key]) || str_starts_with($key, 'schreib ')) {
                    continue;
                }

                $seen[$key] = true;
                $counted[$key] ??= ['key' => $key, 'gap' => $label, 'count' => 0];
                $counted[$key]['count']++;
            }
        }

        $frequent = array_filter($counted, fn (array $row): bool => $row['count'] >= self::MIN_GROUP);

        usort($frequent, fn (array $a, array $b): int => [-$a['count'], $a['key']] <=> [-$b['count'], $b['key']]);

        return array_map(
            fn (array $row): array => ['gap' => $row['gap'], 'count' => $row['count']],
            array_slice($frequent, 0, self::POINT_COUNT),
        );
    }
}
