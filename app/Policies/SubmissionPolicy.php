<?php

namespace App\Policies;

use App\Models\Submission;
use App\Models\User;

/**
 * A submission is visible to its student, the lesson's teacher and admins.
 * Students hand in once; the teacher may delete a submission so the student can redo it.
 */
class SubmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    public function view(User $user, Submission $submission): bool
    {
        if ($user->isStudent()) {
            return $submission->student()->is($user);
        }

        return $user->can('update', $submission->lesson);
    }

    public function create(User $user): bool
    {
        return $user->isStudent();
    }

    public function update(User $user, Submission $submission): bool
    {
        return false;
    }

    public function delete(User $user, Submission $submission): bool
    {
        return ! $user->isStudent() && $user->can('update', $submission->lesson);
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }
}
