<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\TimetableEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One slot of a class's weekly timetable: the subject the class has in a period of a weekday (1 = Monday … 5 = Friday),
 * with the teacher and the room. The same entries make the students' timetable (their class) and the teachers' (their
 * entries), so both always see the same week. The administration of the school maintains them
 * (TimetableEntryPolicy); naming a teacher grants them nothing in the class, that is what assigning them does.
 */
#[Fillable(['school_class_id', 'teacher_id', 'weekday', 'period', 'subject', 'room'])]
class TimetableEntry extends Model
{
    /** @use HasFactory<TimetableEntryFactory> */
    use HasFactory;

    /**
     * The school days of the timetable, in the language of the user. The data export passes 'de': it stays German.
     *
     * @return array<int, string> names by ISO weekday number
     */
    public static function weekdays(?string $locale = null): array
    {
        return [
            1 => __('Montag', [], $locale),
            2 => __('Dienstag', [], $locale),
            3 => __('Mittwoch', [], $locale),
            4 => __('Donnerstag', [], $locale),
            5 => __('Freitag', [], $locale),
        ];
    }

    /**
     * The periods a slot can have, with their bell times (config lernplattform.timetable.periods).
     *
     * @return array<int, array{0: string, 1: string}> start and end by period number
     */
    public static function periods(): array
    {
        return config('lernplattform.timetable.periods');
    }

    /**
     * "3. Stunde · 09:50–10:35", as the period select and the timetable show it.
     */
    public static function periodLabel(int $period): string
    {
        $times = static::periods()[$period] ?? null;

        return collect([__(':period. Stunde', ['period' => $period]), $times !== null ? "{$times[0]}–{$times[1]}" : null])
            ->filter()
            ->implode(' · ');
    }

    /**
     * The period running at the given moment, or the last one of the day that has already begun (a lesson is usually
     * entered after it ended); null before the first period.
     */
    public static function periodAt(CarbonInterface $moment): ?int
    {
        $time = $moment->format('H:i');

        return collect(static::periods())
            ->filter(fn (array $times): bool => $times[0] <= $time)
            ->keys()
            ->max();
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * Null while the school has not decided who teaches the slot, and once that account is gone.
     *
     * @return BelongsTo<User, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /**
     * The entries of all classes of the school.
     *
     * @param  Builder<TimetableEntry>  $query
     */
    public function scopeOfSchool(Builder $query, ?int $schoolId): void
    {
        // whereHas with a null id would match nothing anyway; this keeps a user without a school from matching classes without one.
        $query->whereHas('schoolClass', fn (Builder $classes) => $classes->whereNotNull('school_id')->where('school_id', $schoolId));
    }

    /**
     * The teacher's slots on the weekday of the given day, within their school.
     *
     * @param  Builder<TimetableEntry>  $query
     */
    public function scopeTaughtOn(Builder $query, User $teacher, CarbonInterface $day): void
    {
        $query->ofSchool($teacher->school_id)
            ->where('teacher_id', $teacher->getKey())
            ->where('weekday', $day->dayOfWeekIso);
    }

    /**
     * Ordered like the week reads: by weekday, then period.
     *
     * @param  Builder<TimetableEntry>  $query
     */
    public function scopeInWeekOrder(Builder $query): void
    {
        $query->orderBy('weekday')->orderBy('period');
    }

    public function weekdayName(?string $locale = null): string
    {
        return static::weekdays($locale)[$this->weekday] ?? (string) $this->weekday;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'period' => 'integer',
        ];
    }
}
