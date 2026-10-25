<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SchoolClassResource;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The classes a connected app offers for a new lesson: those the user may place lessons in (LessonPolicy::createFor),
 * the same choice as the class select of the lesson form.
 */
class SchoolClassController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $classes = SchoolClass::query()
            ->when(
                $user->isAdmin(),
                fn (Builder $query) => $query->where('school_classes.school_id', $user->school_id),
                fn (Builder $query) => $query->taughtBy($user),
            )
            ->orderBy('name')
            ->get();

        return SchoolClassResource::collection($classes);
    }
}
