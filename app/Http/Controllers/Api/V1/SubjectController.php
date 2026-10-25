<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The subjects a connected app suggests for a new lesson: those used in lessons of the user's school, alphabetically.
 * Only a suggestion, the subject of a new lesson stays free text (StoreLessonRequest). Förderaufgaben are left out.
 * Without a school there is nothing to suggest: the classes of other school-less accounts are none of the user's business.
 */
class SubjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->school_id === null) {
            return response()->json(['data' => []]);
        }

        $subjects = Lesson::query()
            ->whereNull('practice_competency_id')
            ->whereRelation('schoolClass', 'school_id', $user->school_id)
            ->distinct()
            ->orderBy('subject')
            ->pluck('subject');

        return response()->json(['data' => $subjects]);
    }
}
