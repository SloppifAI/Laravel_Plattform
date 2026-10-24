<?php

namespace App\Policies;

use App\Models\ConsentForm;
use App\Models\School;
use App\Models\User;

/**
 * The consent form and the recording switch belong to the school administration of the school itself. A published
 * version is never changed or deleted: the school publishes a new version instead, so every consent stays traceable.
 */
class ConsentFormPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() && $user->school_id !== null;
    }

    public function view(User $user, ConsentForm $consentForm): bool
    {
        return $user->isAdmin() && $user->isMemberOf($consentForm->school);
    }

    public function create(User $user, School $school): bool
    {
        return $user->isAdmin() && $user->isMemberOf($school);
    }

    public function update(User $user, ConsentForm $consentForm): bool
    {
        return false;
    }

    public function delete(User $user, ConsentForm $consentForm): bool
    {
        return false;
    }
}
