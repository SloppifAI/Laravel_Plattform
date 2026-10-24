<?php

namespace App\Policies;

use App\Models\ExplanationSession;
use App\Models\User;

/**
 * Students run their own "Erklär's mir" sessions. Teachers see sessions about their lessons, admins all.
 */
class ExplanationSessionPolicy
{
    /**
     * The session list is for teachers and admins; students reach their own sessions through the ExplainIt page.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    public function view(User $user, ExplanationSession $explanationSession): bool
    {
        if ($user->isStudent()) {
            return $explanationSession->student()->is($user);
        }

        return $user->isAdmin()
            || ($explanationSession->lesson !== null && $user->can('update', $explanationSession->lesson));
    }

    public function create(User $user): bool
    {
        return $user->isStudent();
    }

    /**
     * Writing into a session, which is only possible until the student finishes it.
     */
    public function update(User $user, ExplanationSession $explanationSession): bool
    {
        return $explanationSession->student()->is($user) && ! $explanationSession->isFinished();
    }

    public function delete(User $user, ExplanationSession $explanationSession): bool
    {
        return $explanationSession->student()->is($user) || $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
