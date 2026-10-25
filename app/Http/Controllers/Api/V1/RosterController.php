<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The names of the students in the user's classes, which a connected app needs to replace them with placeholders on
 * the device before any text leaves it (local pseudonymisation). Teachers get the classes they lead, are assigned to
 * or substitute today (SchoolClass::scopeTaughtBy), an administration every class of its school. Only the display
 * name: no IDs, usernames, e-mail addresses or anything a student did.
 */
class RosterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $classes = SchoolClass::query()
            ->when(
                $user->isAdmin(),
                // Both conditions together match nothing for an account without a school, instead of "school_id IS NULL".
                fn (Builder $query) => $query->whereNotNull('school_classes.school_id')->where('school_classes.school_id', $user->school_id),
                fn (Builder $query) => $query->taughtBy($user),
            )
            // Only what the answer needs, and only students of the user's own school, should a class ever hold others.
            ->with(['students' => fn (HasMany $students) => $students
                ->select(['id', 'school_class_id', 'name'])
                ->withRole(Role::Student)
                ->where('users.school_id', $user->school_id)
                ->orderBy('name')])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $classes->map(fn (SchoolClass $class): array => [
            'class' => ['id' => $class->id, 'name' => $class->name],
            'students' => $class->students->pluck('name')->all(),
        ])]);
    }
}
