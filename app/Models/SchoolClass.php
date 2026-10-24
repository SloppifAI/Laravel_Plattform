<?php

namespace App\Models;

use Database\Factories\SchoolClassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
