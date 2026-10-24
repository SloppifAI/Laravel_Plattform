<?php

namespace App\Policies;

use App\Models\SchoolClass;
use App\Models\User;

/**
 * Classes are managed by the administration.
 */
class SchoolClassPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, SchoolClass $schoolClass): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, SchoolClass $schoolClass): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, SchoolClass $schoolClass): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
