<?php

namespace App\Policies;

use App\Models\RecordingConsent;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\RecordingConsentGate;

/**
 * Consents are handled per class: the school administration looks after every class of its school, a teacher only after
 * the classes they lead or teach. Students never see other people's consents, only confirm their own agreement.
 * The platform operator sees no consents at all.
 */
class RecordingConsentPolicy
{
    public function viewAny(User $user): bool
    {
        return ($user->isAdmin() || $user->isTeacher()) && $user->school_id !== null;
    }

    /**
     * Letters, paper consents and revocations for the students of a class.
     */
    public function manageClass(User $user, SchoolClass $schoolClass): bool
    {
        if (! $user->isMemberOf($schoolClass->school)) {
            return false;
        }

        return $user->isAdmin() || RecordingConsentGate::teachesClass($user, $schoolClass);
    }

    /**
     * Confirming or withdrawing the student's own agreement, once the parents have consented.
     */
    public function confirmOwn(User $user, RecordingConsent $consent): bool
    {
        return $user->isStudent() && $consent->student()->is($user);
    }
}
