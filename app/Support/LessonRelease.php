<?php

namespace App\Support;

use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Hands a lesson's homework out to its students ("freigeben") and takes it back. Shared by the lesson page and
 * "Heute", so both do exactly the same: published_at, due date and the students who get it are set in one transaction,
 * and the students reached get one bell notification (Notify::homeworkReleased). Withdrawing and a release that
 * reaches nobody notify no one.
 */
final class LessonRelease
{
    public const int DEFAULT_DUE_DAYS = 7;

    /**
     * Releases the homework to the whole class or, with `$studentIds`, to the chosen students of the class (anyone
     * else in the list is ignored).
     *
     * @param  list<int|string>|null  $studentIds  null for the whole class
     * @return int the number of students it reached; 0 when nothing was released (nobody in the class, or the lesson is released already)
     *
     * @throws AuthorizationException when `$by` may not publish the lesson (LessonPolicy::publish)
     */
    public static function release(Lesson $lesson, User $by, ?CarbonInterface $dueOn = null, ?array $studentIds = null): int
    {
        Gate::forUser($by)->authorize('publish', $lesson);

        if ($lesson->isPublished()) {
            return 0;
        }

        $classStudentIds = $lesson->schoolClass->students()->pluck('id');
        $recipientIds = $studentIds === null ? $classStudentIds : $classStudentIds->intersect($studentIds);

        if ($recipientIds->isEmpty()) {
            return 0;
        }

        $released = DB::transaction(function () use ($lesson, $dueOn, $recipientIds): bool {
            // Read fresh under a row lock: of two releases running at once (two tabs, a double click) the second sees the first one's published_at and does nothing.
            if (Lesson::query()->lockForUpdate()->whereKey($lesson->getKey())->value('published_at') !== null) {
                return false;
            }

            $lesson->update(['published_at' => now(), 'due_on' => $dueOn ?? today()->addDays(self::DEFAULT_DUE_DAYS)]);
            $lesson->students()->sync($recipientIds->all());

            return true;
        });

        if (! $released) {
            return 0;
        }

        // After the commit, so nobody is told about a release that rolled back; the synced students are exactly those reached.
        // The release stands: a failing bell is reported, it must not turn the release into an error (and a retry into a second one).
        rescue(fn () => Notify::homeworkReleased($lesson, $lesson->students()->get()));

        return $recipientIds->count();
    }

    /**
     * Takes the release back. The caller authorises (`update`) and offers this only while nobody has handed in.
     */
    public static function withdraw(Lesson $lesson): void
    {
        DB::transaction(function () use ($lesson): void {
            $lesson->update(['published_at' => null, 'due_on' => null]);

            // A Förderaufgabe keeps its students: they were chosen when it was made, not when it was released.
            if (! $lesson->isPracticeTask()) {
                $lesson->students()->detach();
            }
        });
    }
}
