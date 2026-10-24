<?php

namespace App\Policies;

use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\StudentQuestion;
use App\Models\User;

/**
 * A question is visible to its student, the teacher of its lesson (not once a substitute's substitution is
 * over) and the administration of that lesson's school, never to classmates or the platform operator. Only those who may read the
 * lesson's results answer it; only the student marks the answer as read.
 */
class StudentQuestionPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isOperator();
    }

    public function view(User $user, StudentQuestion $studentQuestion): bool
    {
        if ($user->isStudent()) {
            return $studentQuestion->student()->is($user);
        }

        return $this->manages($user, $studentQuestion);
    }

    public function create(User $user): bool
    {
        return $user->isStudent();
    }

    /**
     * Whether the student may ask their teacher about the session: their own session, about a lesson that is still
     * published for them (a free topic has no teacher to ask, and a taken-back or reassigned lesson's teacher no longer
     * teaches the student). Call it with `$user->can('createFor', [StudentQuestion::class, $session])`.
     */
    public function createFor(User $user, ExplanationSession $explanationSession): bool
    {
        return $explanationSession->student()->is($user)
            && $explanationSession->lesson_id !== null
            && $this->isAssignedTo($user, $explanationSession->lesson_id);
    }

    /**
     * Whether the student may ask their teacher about a lesson directly, e.g. from its homework result page: only while
     * the lesson is still published for them. Call it with `$user->can('createForLesson', [StudentQuestion::class, $lesson])`.
     */
    public function createForLesson(User $user, Lesson $lesson): bool
    {
        return $this->isAssignedTo($user, $lesson->getKey());
    }

    public function update(User $user, StudentQuestion $studentQuestion): bool
    {
        return false;
    }

    public function answer(User $user, StudentQuestion $studentQuestion): bool
    {
        return $studentQuestion->isOpen() && $this->manages($user, $studentQuestion);
    }

    public function markAsRead(User $user, StudentQuestion $studentQuestion): bool
    {
        return $studentQuestion->student()->is($user);
    }

    public function delete(User $user, StudentQuestion $studentQuestion): bool
    {
        return false;
    }

    private function isAssignedTo(User $user, int $lessonId): bool
    {
        return $user->isStudent() && Lesson::assignedTo($user)->whereKey($lessonId)->exists();
    }

    private function manages(User $user, StudentQuestion $studentQuestion): bool
    {
        return $studentQuestion->lesson !== null && $user->can('viewResults', $studentQuestion->lesson);
    }
}
