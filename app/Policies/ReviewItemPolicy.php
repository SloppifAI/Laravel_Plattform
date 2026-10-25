<?php

namespace App\Policies;

use App\Models\ReviewItem;
use App\Models\User;

/**
 * A review item ("Heute wiederholen") is the student's own and nobody else's: no teacher, no administration, no
 * classmate and not the platform operator sees or rates it. It is created by the platform when a hand-in is evaluated
 * (ReviewSchedule), so nobody creates, edits or deletes one by hand.
 */
class ReviewItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStudent();
    }

    public function view(User $user, ReviewItem $reviewItem): bool
    {
        return $user->isStudent() && $reviewItem->student()->is($user);
    }

    public function rate(User $user, ReviewItem $reviewItem): bool
    {
        return $this->view($user, $reviewItem);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ReviewItem $reviewItem): bool
    {
        return false;
    }

    public function delete(User $user, ReviewItem $reviewItem): bool
    {
        return false;
    }
}
