<?php

namespace App\Policies;

use App\Models\SchoolClass;
use App\Models\User;

/**
 * Classes are managed by the administration of their school.
 */
class SchoolClassPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, SchoolClass $schoolClass): bool
    {
        return $this->manages($user, $schoolClass);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, SchoolClass $schoolClass): bool
    {
        return $this->manages($user, $schoolClass);
    }

    public function delete(User $user, SchoolClass $schoolClass): bool
    {
        return $this->manages($user, $schoolClass);
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }

    private function manages(User $user, SchoolClass $schoolClass): bool
    {
        return $user->isAdmin() && $user->isMemberOf($schoolClass->school);
    }
}
