<?php

namespace App\Policies;

use App\Models\Competency;
use App\Models\User;

/**
 * The competency framework is shared by all schools, so only the platform operator maintains it.
 * School administrations and teachers may look it up.
 */
class CompetencyPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isStudent();
    }

    public function view(User $user, Competency $competency): bool
    {
        return ! $user->isStudent();
    }

    public function create(User $user): bool
    {
        return $user->isOperator();
    }

    public function update(User $user, Competency $competency): bool
    {
        return $user->isOperator();
    }

    public function delete(User $user, Competency $competency): bool
    {
        return $user->isOperator();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isOperator();
    }
}
