<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\User;

/**
 * Teachers manage their own lessons, admins all of them. Students only see published homework delegated to them.
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
        return $user->isAdmin() || ($user->isTeacher() && $lesson->teacher()->is($user));
    }
}
