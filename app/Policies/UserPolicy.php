<?php

namespace App\Policies;

use App\Models\User;

/**
 * School administrations manage the people of their own school. The platform operator only manages
 * administration accounts (to set up schools and help them back in), never teachers' or students' records.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isOperator();
    }

    public function view(User $user, User $model): bool
    {
        return $this->manages($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isOperator();
    }

    public function update(User $user, User $model): bool
    {
        return $this->manages($user, $model);
    }

    /**
     * Nobody deletes themselves, so a school or the platform never loses its last way in by accident.
     */
    public function delete(User $user, User $model): bool
    {
        return $this->manages($user, $model) && $user->isNot($model);
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin() || $user->isOperator();
    }

    private function manages(User $user, User $model): bool
    {
        if ($user->isOperator()) {
            return $model->isAdmin() || $model->isOperator();
        }

        return $user->isAdmin() && ! $model->isOperator() && $user->isMemberOf($model->school);
    }
}
