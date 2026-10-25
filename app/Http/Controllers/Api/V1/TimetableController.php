<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\TimetableEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The user's own slots of the weekly timetable on the weekday of a date, with the bell times of their periods and the
 * Tuto lesson of the same class, subject and date if there is one. Substitutions and cancellations are not part of it
 * yet. Nothing about students: a slot names only the class.
 */
class TimetableController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        /** @var User $user */
        $user = $request->user();
        $date = CarbonImmutable::createFromFormat('Y-m-d', $validated['date'])->startOfDay();

        $periods = TimetableEntry::periods();

        // A period without a bell time (the configuration was shortened) has no row in the timetable either.
        $entries = TimetableEntry::query()
            ->taughtOn($user, $date)
            ->whereIn('period', array_keys($periods))
            ->with('schoolClass')
            ->orderBy('period')
            ->orderBy('id')
            ->get();

        $lessonIds = $entries->isEmpty() ? [] : $this->lessonIdsOn($user, $date, $entries);

        return response()->json(['data' => $entries->map(fn (TimetableEntry $entry): array => [
            'starts_at' => $periods[$entry->period][0],
            'ends_at' => $periods[$entry->period][1],
            'class' => ['id' => $entry->schoolClass->id, 'name' => $entry->schoolClass->name],
            'subject' => $entry->subject,
            'room' => $entry->room,
            'lesson_id' => $lessonIds[$entry->school_class_id.'|'.$entry->subject] ?? null,
        ])]);
    }

    /**
     * The first lesson per class and subject on the date, among those the app may open (the scope of GET /lessons,
     * LessonPolicy::update), keyed "class id|subject".
     *
     * @param  Collection<int, TimetableEntry>  $entries
     * @return array<string, int>
     */
    private function lessonIdsOn(User $user, CarbonImmutable $date, Collection $entries): array
    {
        return Lesson::query()
            ->whereNull('practice_competency_id')
            ->when(
                $user->isAdmin(),
                fn (Builder $query) => $query->whereRelation('schoolClass', 'school_id', $user->school_id),
                fn (Builder $query) => $query->whereBelongsTo($user, 'teacher'),
            )
            ->whereIn('school_class_id', $entries->pluck('school_class_id'))
            ->whereDate('held_on', $date)
            ->orderBy('id')
            ->get(['id', 'school_class_id', 'subject'])
            ->reverse()
            ->mapWithKeys(fn (Lesson $lesson): array => [$lesson->school_class_id.'|'.$lesson->subject => $lesson->id])
            ->all();
    }
}
