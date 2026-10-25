<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLessonRequest;
use App\Http\Requests\Api\V1\UpdateLessonSummaryRequest;
use App\Http\Resources\Api\V1\LessonResource;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * What a connected app sees and changes: the lessons the user may edit (LessonPolicy::update), new lessons in the
 * classes the user may place lessons in (LessonPolicy::createFor) and the summaries. Förderaufgaben are left out; they
 * have no lesson an app could summarise. Nothing about students is part of an answer (LessonResource).
 */
class LessonController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'held_on' => ['nullable', 'date_format:Y-m-d'],
            'class_id' => ['nullable', 'integer'],
        ]);
        /** @var User $user */
        $user = $request->user();

        // Same scope as the lesson list in the panel (LessonResource::getEloquentQuery), which matches LessonPolicy::update.
        $lessons = Lesson::query()
            ->whereNull('practice_competency_id')
            ->when(
                $user->isAdmin(),
                fn (Builder $query) => $query->whereRelation('schoolClass', 'school_id', $user->school_id),
                fn (Builder $query) => $query->whereBelongsTo($user, 'teacher'),
            )
            ->when($validated['held_on'] ?? null, fn (Builder $query, string $heldOn) => $query->whereDate('held_on', $heldOn))
            ->when($validated['class_id'] ?? null, fn (Builder $query, int|string $classId) => $query->where('school_class_id', $classId))
            ->with('schoolClass')
            ->orderByDesc('held_on')
            ->orderByDesc('id')
            ->paginate();

        return LessonResource::collection($lessons);
    }

    /**
     * Like CreateLesson: only in a class the user may place lessons in, and the lesson belongs to whoever enters it,
     * administrations included. The notes are optional here; the teacher adds them on the lesson page before the AI
     * draft. Answers 201 (a resource of a model that was just created).
     */
    public function store(StoreLessonRequest $request): LessonResource
    {
        Gate::authorize('createFor', [Lesson::class, SchoolClass::findOrFail($request->validated('school_class_id'))]);

        $lesson = Lesson::create([
            ...$request->safe()->only(['school_class_id', 'subject', 'topic']),
            'held_on' => $request->validated('held_on') ?? today(),
            'notes' => $request->validated('notes') ?? '',
            'teacher_id' => $request->user()->getKey(),
        ]);

        return new LessonResource($lesson);
    }

    public function show(Lesson $lesson): LessonResource
    {
        Gate::authorize('update', $lesson);

        return new LessonResource($lesson);
    }

    /**
     * Stores the app's summary and marks it, so a new AI draft keeps it (HomeworkDraft). The plain-language copy of the
     * old summary goes with the change (Lesson's saving hook).
     */
    public function updateSummary(UpdateLessonSummaryRequest $request, Lesson $lesson): LessonResource
    {
        $lesson->forceFill(['summary' => $request->validated('summary'), 'summary_from_app' => true])->save();

        return new LessonResource($lesson);
    }
}
