<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ClassSubstitutionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A teacher of the school teaches a class for a while, e.g. while the Klassenleitung is ill: from `starts_on` to
 * `ends_on` (both days included) the substitute counts as teaching the class (SchoolClass::scopeTaughtBy()). After the
 * last day nothing is granted any more; the row stays as a record, so there is nothing to clean up.
 */
#[Fillable(['school_class_id', 'substitute_id', 'granted_by', 'starts_on', 'ends_on', 'note'])]
class ClassSubstitution extends Model
{
    /** @use HasFactory<ClassSubstitutionFactory> */
    use HasFactory;

    /**
     * The longest a single substitution may last, in days (first and last day included), and the most a teacher may be
     * set up to teach one class in all running and planned substitutions together.
     */
    public static function maxDays(): int
    {
        return (int) config('lernplattform.substitutions.max_days', 90);
    }

    /**
     * What a substitution grants, as a German clause for every text that asks somebody to set one up or end it. It is
     * everything SchoolClass::taughtBy() gives a teacher of the class: lessons, the students' results, the whole
     * Klassenbuch with the absences, the consents of the parents and Tuto-Mikro. Keep the texts in step with it.
     */
    public static function grants(): string
    {
        return 'Stunden anlegen, die Lernstände der Klasse sehen, das Klassenbuch mit allen Fehlzeiten lesen und exportieren, Einwilligungen der Eltern erfassen und Tuto-Mikro nutzen';
    }

    /**
     * The days from `$from` (but not before today) to `$to`, both included; 0 for a period that is over.
     */
    public static function countDays(CarbonInterface $from, CarbonInterface $to): int
    {
        $from = $from->copy()->startOfDay()->max(today());
        $to = $to->copy()->startOfDay();

        return $to->lt($from) ? 0 : (int) round($from->diffInDays($to)) + 1;
    }

    /**
     * How many days the teacher is already set up to teach the class: all running and planned substitutions, the past
     * days of a running one not counted. Overlapping periods count twice, which only makes the limit stricter.
     */
    public static function daysPlanned(SchoolClass $schoolClass, int $substituteId): int
    {
        return $schoolClass->substitutions()
            ->notEnded()
            ->where('substitute_id', $substituteId)
            ->get(['starts_on', 'ends_on'])
            ->sum(fn (self $substitution): int => static::countDays($substitution->starts_on, $substitution->ends_on));
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function substitute(): BelongsTo
    {
        return $this->belongsTo(User::class, 'substitute_id');
    }

    /**
     * Who set the substitution up: the Klassenleitung or an administration. Null once that account is gone.
     *
     * @return BelongsTo<User, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * Substitutions that are in force today.
     *
     * @param  Builder<ClassSubstitution>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereDate('class_substitutions.starts_on', '<=', today())
            ->whereDate('class_substitutions.ends_on', '>=', today());
    }

    /**
     * Substitutions that are in force today or start later.
     *
     * @param  Builder<ClassSubstitution>  $query
     */
    public function scopeNotEnded(Builder $query): void
    {
        $query->whereDate('class_substitutions.ends_on', '>=', today());
    }

    public function isActive(): bool
    {
        return $this->starts_on->lte(today()) && $this->ends_on->gte(today());
    }

    public function isUpcoming(): bool
    {
        return $this->starts_on->gt(today());
    }

    public function hasEnded(): bool
    {
        return $this->ends_on->lt(today());
    }

    /**
     * @return 'active'|'upcoming'|'ended'
     */
    public function status(): string
    {
        return match (true) {
            $this->isUpcoming() => 'upcoming',
            $this->hasEnded() => 'ended',
            default => 'active',
        };
    }

    /**
     * Ends the substitution today: it grants nothing from now on. One that has not started yet is withdrawn instead.
     * One that started stays as a record, even when it ends on its first day (then `ends_on` lies before `starts_on`):
     * LessonPolicy::viewResults() recognises a substitute who is no longer one by it.
     */
    public function end(): void
    {
        if ($this->isUpcoming()) {
            $this->delete();

            return;
        }

        $this->update(['ends_on' => today()->subDay()]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }
}
