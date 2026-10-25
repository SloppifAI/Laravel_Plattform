<?php

namespace App\Policies;

use App\Models\User;
use App\Role;

/**
 * School administrations manage the people of their own school. The platform operator only manages
 * administration accounts (to set up schools), never teachers' or students' records. It never sets or resets the
 * sign-in of a school's administration (see UserForm), because that would give it the school's learning data;
 * administrations get back in themselves through "Passwort vergessen?".
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
     * Nobody deletes themselves, so a school or the platform never loses its last way in by accident. A school keeps
     * its last administration too: otherwise the operator could delete it and set up one of its own choosing.
     */
    public function delete(User $user, User $model): bool
    {
        return $this->manages($user, $model) && $user->isNot($model) && ! $this->isLastAdministrationOfSchool($model);
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

    private function isLastAdministrationOfSchool(User $model): bool
    {
        return $model->isAdmin()
            && ! User::query()->where('school_id', $model->school_id)->where('role', Role::Admin)->whereKeyNot($model)->exists();
    }
}
