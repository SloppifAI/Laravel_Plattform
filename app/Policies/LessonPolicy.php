<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\User;

/**
 * Teachers manage their own lessons, school administrations all lessons of their school.
 * Students only see published homework delegated to them. The platform operator sees no lessons.
 */
class LessonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    public function view(User $user, Lesson $lesson): bool
    {
        if ($user->isStudent()) {
            return $lesson->isPublished() && $lesson->students()->whereKey($user->getKey())->exists();
        }

        return $this->manages($user, $lesson);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    public function update(User $user, Lesson $lesson): bool
    {
        return $this->manages($user, $lesson);
    }

    public function delete(User $user, Lesson $lesson): bool
    {
        return $this->manages($user, $lesson);
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    private function manages(User $user, Lesson $lesson): bool
    {
        return ($user->isAdmin() && $user->isMemberOf($lesson->school))
            || ($user->isTeacher() && $lesson->teacher()->is($user));
    }
}
