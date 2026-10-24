<?php

namespace App\Policies;

use App\Models\ClassSubstitution;
use App\Models\SchoolClass;
use App\Models\User;

/**
 * Substitutions are set up and ended by the Klassenleitung of the class or the administration of its school. The
 * substitute may see their own. Which class a substitution may be set up for is decided by createFor(); create() only
 * says whether the user may set one up at all (the school and the class are checked there).
 *
 * The same people (plus the substitute while the substitution lasts) may open the Übergabe-Mappe of the class,
 * see viewHandoverSheet().
 */
class ClassSubstitutionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    public function view(User $user, ClassSubstitution $substitution): bool
    {
        return $this->manages($user, $substitution) || ($user->isTeacher() && $substitution->substitute()->is($user));
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    /**
     * Whether a substitution may be set up for the class. Call it with
     * `$user->can('createFor', [ClassSubstitution::class, $schoolClass])`.
     */
    public function createFor(User $user, SchoolClass $schoolClass): bool
    {
        return $this->administers($user, $schoolClass) || $this->leads($user, $schoolClass);
    }

    public function update(User $user, ClassSubstitution $substitution): bool
    {
        return $this->manages($user, $substitution);
    }

    public function delete(User $user, ClassSubstitution $substitution): bool
    {
        return $this->manages($user, $substitution);
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    /**
     * The Übergabe-Mappe of a class: for its Klassenleitung, the administration of its school and the teacher who
     * substitutes in it today. Teachers who are merely assigned to the class or who held a lesson there do not get it.
     * Call it with `$user->can('viewHandoverSheet', [ClassSubstitution::class, $schoolClass])`.
     */
    public function viewHandoverSheet(User $user, SchoolClass $schoolClass): bool
    {
        return $this->createFor($user, $schoolClass)
            || ($user->isTeacher()
                && $user->isMemberOf($schoolClass->school)
                && $schoolClass->substitutions()->active()->where('substitute_id', $user->getKey())->exists());
    }

    private function manages(User $user, ClassSubstitution $substitution): bool
    {
        return $this->createFor($user, $substitution->schoolClass);
    }

    private function administers(User $user, SchoolClass $schoolClass): bool
    {
        return $user->isAdmin() && $user->isMemberOf($schoolClass->school);
    }

    private function leads(User $user, SchoolClass $schoolClass): bool
    {
        return $user->isTeacher() && $schoolClass->teacher_id === $user->getKey() && $user->isMemberOf($schoolClass->school);
    }
}
