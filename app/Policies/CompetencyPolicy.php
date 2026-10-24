<?php

namespace App\Policies;

use App\Models\Competency;
use App\Models\User;

/**
 * The competency framework is maintained by the administration; teachers may look it up.
 */
class CompetencyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    public function view(User $user, Competency $competency): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Competency $competency): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Competency $competency): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
