<?php

namespace App\Policies;

use App\Models\ClassBookEntry;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;

/**
 * The Klassenbuch of a class belongs to the teachers of that class (Klassenleitung, assigned teachers and, as long as
 * the substitution lasts, the substitute: the same rule as for the learning data, SchoolClass::scopeTaughtBy) and to the
 * administration of its school. A substitute sees the whole book of the class, the absences of earlier days and the CSV
 * export of any range included, because the school made them a teacher of the class for that time; the access ends
 * with the substitution. Students, the platform operator and everyone from another school get nothing: the entries
 * name absent students.
 *
 * A teacher completes the entries of their own lessons; a confirmed entry is locked, and only the school
 * administration may reopen it.
 */
class ClassBookEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return ($user->isAdmin() || $user->isTeacher()) && $user->school_id !== null;
    }

    public function view(User $user, ClassBookEntry $entry): bool
    {
        return $this->accessClass($user, $entry->schoolClass);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Whether the user works with the Klassenbuch of the class: sees its entries and exports them. Call it with
     * `$user->can('accessClass', [ClassBookEntry::class, $schoolClass])`.
     */
    public function accessClass(User $user, SchoolClass $schoolClass): bool
    {
        return match (true) {
            $user->isAdmin() => $user->isMemberOf($schoolClass->school),
            $user->isTeacher() => $schoolClass->isTaughtBy($user),
            default => false,
        };
    }

    /**
     * Whether a draft may be made from the lesson: by the lesson's teacher, or by the administration of the school. Never
     * from a practice task, which is not held in class.
     */
    public function createFromLesson(User $user, Lesson $lesson): bool
    {
        return ! $lesson->isPracticeTask()
            && $this->accessClass($user, $lesson->schoolClass)
            && ($user->isAdmin() || $lesson->teacher()->is($user));
    }

    /**
     * Editing and confirming: the entry's teacher (while they still teach the class) or the administration, as long as
     * the entry is not confirmed.
     */
    public function update(User $user, ClassBookEntry $entry): bool
    {
        return ! $entry->isConfirmed()
            && $this->accessClass($user, $entry->schoolClass)
            && ($user->isAdmin() || $entry->teacher()->is($user));
    }

    public function confirm(User $user, ClassBookEntry $entry): bool
    {
        return $this->update($user, $entry);
    }

    public function reopen(User $user, ClassBookEntry $entry): bool
    {
        return $entry->isConfirmed() && $user->isAdmin() && $user->isMemberOf($entry->schoolClass->school);
    }
}
