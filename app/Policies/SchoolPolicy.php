<?php

namespace App\Policies;

use App\Models\School;
use App\Models\User;

/**
 * Schools are overseen by the platform operator. A school administration may view and edit its own school's master data.
 */
class SchoolPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOperator();
    }

    public function view(User $user, School $school): bool
    {
        return $user->isOperator() || ($user->isAdmin() && $user->school()->is($school));
    }

    public function create(User $user): bool
    {
        return $user->isOperator();
    }

    public function update(User $user, School $school): bool
    {
        return $this->view($user, $school);
    }

    /**
     * Blocking locks out every user of the school; only the operator decides that.
     */
    public function block(User $user, School $school): bool
    {
        return $user->isOperator();
    }

    public function delete(User $user, School $school): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
