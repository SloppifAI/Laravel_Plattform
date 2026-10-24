<?php

namespace App\Support;

use App\Models\Competency;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\Role;
use App\UnderstandingLevel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may see whose learning results, and the score aggregation behind "Lernstand & Förderbedarf" and "Elterngespräch".
 * A competency score is the average of all AI-evaluated answers to questions mapped to that competency.
 */
class LearningInsights
{
    /**
     * Admins see every class, teachers the classes they lead or hold lessons in, everyone else none.
     *
     * @return Builder<SchoolClass>
     */
    public static function visibleClasses(User $user): Builder
    {
        $query = SchoolClass::query();

        if ($user->isAdmin()) {
            return $query;
        }

        if (! $user->isTeacher()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $query) => $query
            ->where('teacher_id', $user->getKey())
            ->orWhereHas('lessons', fn (Builder $query) => $query->where('teacher_id', $user->getKey())));
    }

    /**
     * @return Builder<User>
     */
    public static function visibleStudents(User $user): Builder
    {
        return User::query()
            ->withRole(Role::Student)
            ->when(! $user->isAdmin(), fn (Builder $query) => $query->whereIn('school_class_id', static::visibleClasses($user)->select('id')));
    }

    public static function canView(User $user, User $student): bool
    {
        return static::visibleStudents($user)->whereKey($student->getKey())->exists();
    }

    /**
     * Every evaluated answer of the given students whose question is mapped to a competency.
     *
     * @param  array<int>  $studentIds
     * @return Collection<int, object{student_id: int, competency_id: int, lesson_id: int, score: int}>
     */
    public static function scoredAnswers(array $studentIds): Collection
    {
        return DB::table('answers')
            ->join('submissions', 'submissions.id', '=', 'answers.submission_id')
            ->join('questions', 'questions.id', '=', 'answers.question_id')
            ->whereIn('submissions.student_id', $studentIds)
            ->whereNotNull('answers.score')
            ->whereNotNull('questions.competency_id')
            ->get(['submissions.student_id', 'questions.competency_id', 'submissions.lesson_id', 'answers.score']);
    }

    /**
     * @param  Collection<int, object{student_id: int, competency_id: int, lesson_id: int, score: int}>  $answers
     * @return array<int, array<int, int>> student id => competency id => average score, rounded to whole points
     */
    public static function competencyAverages(Collection $answers): array
    {
        return $answers->groupBy('student_id')
            ->map(fn (Collection $answersOfStudent): array => $answersOfStudent->groupBy('competency_id')
                ->map(fn (Collection $answersOfCompetency): int => (int) round($answersOfCompetency->avg('score')))
                ->all())
            ->all();
    }

    /**
     * Competencies whose average is below the support threshold, with the lessons in which the weak answers were given.
     *
     * @param  Collection<int, object{student_id: int, competency_id: int, lesson_id: int, score: int}>  $answers
     * @return array<int, list<array{competency_id: int, average: int, lesson_ids: list<int>}>> keyed by student id
     */
    public static function supportNeeds(Collection $answers): array
    {
        $needs = [];

        foreach (static::competencyAverages($answers) as $studentId => $averages) {
            foreach ($averages as $competencyId => $average) {
                if ($average >= UnderstandingLevel::SUPPORT_THRESHOLD) {
                    continue;
                }

                $needs[$studentId][] = [
                    'competency_id' => $competencyId,
                    'average' => $average,
                    'lesson_ids' => $answers
                        ->where('student_id', $studentId)
                        ->where('competency_id', $competencyId)
                        ->where('score', '<', UnderstandingLevel::SUPPORT_THRESHOLD)
                        ->map(fn (object $answer): int => (int) $answer->lesson_id)
                        ->unique()
                        ->values()
                        ->all(),
                ];
            }
        }

        return $needs;
    }

    /**
     * A student's own competencies for "Mein Lernstand": strengths, nearly there and those worth practising (below the
     * support threshold), the latter with the lessons where they showed and the gaps the AI named in those submissions.
     * Reads only the given student's answers; strongest/weakest first.
     *
     * @return array{
     *     strengths: Collection<int, array{competency: Competency, average: int}>,
     *     almost: Collection<int, array{competency: Competency, average: int}>,
     *     practice: Collection<int, array{competency: Competency, average: int, lessons: Collection<int, Lesson>, gaps: list<string>}>
     * }
     */
    public static function studentCompetencies(User $student): array
    {
        $answers = static::scoredAnswers([$student->getKey()]);
        $averages = static::competencyAverages($answers)[$student->getKey()] ?? [];
        $needs = collect(static::supportNeeds($answers)[$student->getKey()] ?? [])->keyBy('competency_id');

        $competencies = Competency::query()->whereKey(array_keys($averages))->get()->keyBy('id');
        // Only lessons still delegated to the student, so every link built from them can be opened.
        $lessons = Lesson::assignedTo($student)
            ->whereKey($needs->pluck('lesson_ids')->flatten()->unique()->all())
            ->orderByDesc('held_on')
            ->get()
            ->keyBy('id');
        $gapsByLesson = $student->submissions()
            ->whereIn('lesson_id', $lessons->keys())
            ->whereNotNull('evaluated_at')
            ->get()
            ->pluck('gaps', 'lesson_id');

        $rows = collect($averages)->map(fn (int $average, int $competencyId): array => [
            'competency' => $competencies[$competencyId],
            'average' => $average,
        ]);
        $withLevel = fn (UnderstandingLevel $level): Collection => $rows
            ->filter(fn (array $row): bool => UnderstandingLevel::fromScore($row['average']) === $level);

        return [
            'strengths' => $withLevel(UnderstandingLevel::Understood)->sortByDesc('average')->values(),
            'almost' => $withLevel(UnderstandingLevel::Partly)->sortByDesc('average')->values(),
            'practice' => $withLevel(UnderstandingLevel::NeedsSupport)->sortBy('average')->values()
                ->map(function (array $row) use ($needs, $lessons, $gapsByLesson): array {
                    $ownLessons = $lessons->only($needs[$row['competency']->getKey()]['lesson_ids'])->values();

                    return [
                        ...$row,
                        'lessons' => $ownLessons,
                        'gaps' => $ownLessons
                            ->flatMap(fn (Lesson $lesson): array => $gapsByLesson->get($lesson->getKey()) ?? [])
                            ->filter()
                            ->unique()
                            ->values()
                            ->all(),
                    ];
                }),
        ];
    }

    /**
     * A student's evaluated homework in lesson order (oldest first), limited to lessons still delegated to them and
     * published: the same population the parent report counts, so both show the same average and trend.
     *
     * @return Collection<int, Submission>
     */
    public static function evaluatedSubmissions(User $student): Collection
    {
        return $student->submissions()
            ->whereNotNull('submissions.evaluated_at')
            ->whereIn('submissions.lesson_id', Lesson::query()->assignedTo($student)->select('lessons.id'))
            ->join('lessons', 'lessons.id', '=', 'submissions.lesson_id')
            ->orderBy('lessons.held_on')
            ->orderBy('submissions.id')
            ->select('submissions.*')
            ->with('lesson')
            ->get();
    }

    /**
     * Label, colour and icon of a score for students. "Förderbedarf" is teacher language and red reads as failing to a
     * child, so the lowest level becomes "Noch üben" in the calm warning colour; icon and label keep it readable without colour.
     *
     * @return array{label: string, color: string, icon: Heroicon}
     */
    public static function studentLevel(int $score): array
    {
        $level = UnderstandingLevel::fromScore($score);

        return match ($level) {
            UnderstandingLevel::NeedsSupport => ['label' => 'Noch üben', 'color' => 'warning', 'icon' => Heroicon::OutlinedLightBulb],
            default => ['label' => $level->getLabel(), 'color' => $level->getColor(), 'icon' => $level->getIcon()],
        };
    }

    /**
     * Newer half of the scores against the older half, split and rounded like the parent report (an odd middle score
     * counts as newer): 'up' or 'down' from a difference of 5 points, otherwise 'steady'. Null below two scores.
     *
     * @param  Collection<int, int>  $scoresOldestFirst
     * @return 'up'|'down'|'steady'|null
     */
    public static function understandingTrend(Collection $scoresOldestFirst): ?string
    {
        if ($scoresOldestFirst->count() < 2) {
            return null;
        }

        $half = intdiv($scoresOldestFirst->count(), 2);
        $difference = (int) round($scoresOldestFirst->slice($half)->avg() - $scoresOldestFirst->take($half)->avg());

        return match (true) {
            $difference >= 5 => 'up',
            $difference <= -5 => 'down',
            default => 'steady',
        };
    }
}
