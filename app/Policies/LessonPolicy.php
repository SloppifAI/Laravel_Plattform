<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\StudentQuestion;
use App\Models\User;

/**
 * Teachers manage their own lessons, school administrations all lessons of their school.
 * Students only see published homework delegated to them. The platform operator sees no lessons.
 *
 * Which class a lesson may be held in is decided by createFor(): teachers only in classes they teach (lead or are
 * assigned to). That limit applies when a lesson is created or moved to another class, and when it is published (it
 * reaches the class's current students then), but not to editing lessons that already exist: a teacher who no longer
 * teaches a class keeps editing the drafts they held there, they just cannot hand them out.
 */
class LessonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    public function view(User $user, Lesson $lesson): bool
    {
        if ($user->isStudent()) {
            return $lesson->isPublished() && $lesson->students()->whereKey($user->getKey())->exists();
        }

        return $this->manages($user, $lesson);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    /**
     * Whether a lesson may be placed in the class, on creation and when an existing lesson is moved to another class.
     * Call it with `$user->can('createFor', [Lesson::class, $schoolClass])`; create() only says whether the user may
     * start a lesson at all.
     */
    public function createFor(User $user, SchoolClass $schoolClass): bool
    {
        return match (true) {
            $user->isAdmin() => $user->isMemberOf($schoolClass->school),
            $user->isTeacher() => $schoolClass->isTaughtBy($user),
            default => false,
        };
    }

    public function update(User $user, Lesson $lesson): bool
    {
        return $this->manages($user, $lesson);
    }

    /**
     * Handing the homework out to the class's current students counts as placing the lesson in the class, so a draft
     * held in a class the teacher no longer teaches cannot be published.
     */
    public function publish(User $user, Lesson $lesson): bool
    {
        return $this->update($user, $lesson) && $this->createFor($user, $lesson->schoolClass);
    }

    /**
     * Whether the user may read the students' individual results of the lesson (hand-ins, answers, "Erklär's mir"
     * sessions, questions to the teacher): the administration of the school and the lesson's teacher, except a teacher
     * who is in the class only as a substitute whose substitution is over. That one keeps their drafts (update()) but
     * loses the students' data with the end of the period, like everything else the substitution granted. Query
     * counterpart: StudentQuestion::scopeManagedBy().
     *
     * Not decided here: a teacher who was assigned to the class and is removed still reads the results of the lessons
     * they held (their ownership of the lesson counts), unlike the learning data of LearningInsights.
     */
    public function viewResults(User $user, Lesson $lesson): bool
    {
        if (! $this->manages($user, $lesson)) {
            return false;
        }

        return $user->isAdmin()
            || ! $lesson->schoolClass->substitutions()->where('substitute_id', $user->getKey())->exists()
            || $lesson->schoolClass->isTaughtBy($user);
    }

    /**
     * Deleting a lesson takes the students' hand-ins and questions with it, so whoever may no longer read them (a
     * substitute whose substitution is over, see viewResults()) can delete only a lesson that holds none, like
     * SubmissionPolicy::delete().
     */
    public function delete(User $user, Lesson $lesson): bool
    {
        return $this->manages($user, $lesson)
            && ($this->viewResults($user, $lesson) || ! $this->holdsStudentData($lesson));
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    private function holdsStudentData(Lesson $lesson): bool
    {
        return $lesson->submissions()->exists() || StudentQuestion::query()->whereBelongsTo($lesson)->exists();
    }

    private function manages(User $user, Lesson $lesson): bool
    {
        return ($user->isAdmin() && $user->isMemberOf($lesson->school))
            || ($user->isTeacher() && $lesson->teacher()->is($user));
    }
}
