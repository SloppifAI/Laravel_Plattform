<?php

namespace App\Policies;

use App\Models\Question;
use App\Models\User;

/**
 * Questions belong to their lesson: whoever may edit the lesson may edit its questions.
 */
class QuestionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    public function view(User $user, Question $question): bool
    {
        return $user->can('update', $question->lesson);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    public function update(User $user, Question $question): bool
    {
        return $user->can('update', $question->lesson);
    }

    public function delete(User $user, Question $question): bool
    {
        return $user->can('update', $question->lesson);
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    public function reorder(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }
}
