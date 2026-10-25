<?php

namespace App\Models;

use Database\Factories\SchoolClassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A class. Students join it by registering with its `join_code`, which teachers hand out.
 */
#[Fillable(['school_id', 'name', 'teacher_id'])]
class SchoolClass extends Model
{
    /** @use HasFactory<SchoolClassFactory> */
    use HasFactory;

    /**
     * Without 0/O and 1/I/L so children can type the code from a board without mix-ups.
     */
    private const string JOIN_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected static function booted(): void
    {
        static::creating(function (SchoolClass $schoolClass): void {
            $schoolClass->join_code ??= static::generateJoinCode();
        });
    }

    public static function generateJoinCode(): string
    {
        do {
            $code = collect(range(1, 6))
                ->map(fn (): string => self::JOIN_CODE_ALPHABET[random_int(0, strlen(self::JOIN_CODE_ALPHABET) - 1)])
                ->implode('');
        } while (static::query()->where('join_code', $code)->exists());

        return $code;
    }

    /**
     * Invalidates the old code, e.g. when it was shared beyond the class.
     */
    public function regenerateJoinCode(): void
    {
        $this->forceFill(['join_code' => static::generateJoinCode()])->save();
    }

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * The Klassenleitung.
     *
     * @return BelongsTo<User, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /**
     * The teachers the administration assigned to the class besides the Klassenleitung, who teaches it anyway
     * (see isTaughtBy()).
     *
     * @return BelongsToMany<User, $this>
     */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'school_class_teacher')->withTimestamps();
    }

    /**
     * Substitutions of the class, past, current and upcoming (see ClassSubstitution).
     *
     * @return HasMany<ClassSubstitution, $this>
     */
    public function substitutions(): HasMany
    {
        return $this->hasMany(ClassSubstitution::class);
    }

    /**
     * The weekly timetable of the class (see TimetableEntry).
     *
     * @return HasMany<TimetableEntry, $this>
     */
    public function timetableEntries(): HasMany
    {
        return $this->hasMany(TimetableEntry::class);
    }

    /**
     * The classes a teacher teaches: the ones they lead, the ones they are assigned to and the ones they substitute in
     * today, always within their school. This decides which classes a teacher may create lessons in and whose learning
     * data they see; an expired or not yet started substitution grants nothing.
     *
     * @param  Builder<SchoolClass>  $query
     */
    public function scopeTaughtBy(Builder $query, User $teacher): void
    {
        // Both conditions together match nothing for a user without a school, instead of "school_id IS NULL".
        $query->whereNotNull('school_classes.school_id')
            ->where('school_classes.school_id', $teacher->school_id)
            ->where(fn (Builder $query) => $query
                ->where('school_classes.teacher_id', $teacher->getKey())
                ->orWhereHas('teachers', fn (Builder $teachers) => $teachers->whereKey($teacher->getKey()))
                ->orWhereHas('substitutions', fn (Builder $substitutions) => $substitutions->active()->where('class_substitutions.substitute_id', $teacher->getKey())));
    }

    public function isTaughtBy(User $teacher): bool
    {
        return static::query()->taughtBy($teacher)->whereKey($this->getKey())->exists();
    }

    /**
     * @return HasMany<User, $this>
     */
    public function students(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }
}
