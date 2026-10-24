<?php

namespace App\Support;

use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use App\UnderstandingLevel;
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
}
