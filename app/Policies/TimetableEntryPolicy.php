<?php

namespace App\Policies;

use App\Models\SchoolClass;
use App\Models\TimetableEntry;
use App\Models\User;

/**
 * The timetable is maintained by the administration of the class's school. Students and teachers only read their own
 * week on "Stundenplan" (viewOwn()): a student the one of their class, a teacher the slots they are entered for; a
 * Klassenleitung also the whole timetable of the classes they lead (viewClass()). Nobody reads another school's
 * timetable, and the platform operator, who sees no lesson data, none at all.
 */
class TimetableEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() && $user->school_id !== null;
    }

    public function view(User $user, TimetableEntry $entry): bool
    {
        return $this->manages($user, $entry);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, TimetableEntry $entry): bool
    {
        return $this->manages($user, $entry);
    }

    public function delete(User $user, TimetableEntry $entry): bool
    {
        return $this->manages($user, $entry);
    }

    public function deleteAny(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Whether the user has a week of their own to look at. Call it with `$user->can('viewOwn', TimetableEntry::class)`.
     */
    public function viewOwn(User $user): bool
    {
        return ($user->isStudent() || $user->isTeacher()) && $user->school_id !== null;
    }

    /**
     * Whether the user may see the whole timetable of the class, with every teacher: its Klassenleitung. Call it with
     * `$user->can('viewClass', [TimetableEntry::class, $schoolClass])`.
     */
    public function viewClass(User $user, SchoolClass $schoolClass): bool
    {
        return $user->isTeacher() && $schoolClass->teacher_id === $user->getKey() && $user->isMemberOf($schoolClass->school);
    }

    private function manages(User $user, TimetableEntry $entry): bool
    {
        return $user->isAdmin() && $user->isMemberOf($entry->schoolClass->school);
    }
}
