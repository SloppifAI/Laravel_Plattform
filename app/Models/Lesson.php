<?php

namespace App\Models;

use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * A held lesson. Its notes and worksheets are the source for the AI summary and homework questions;
 * once published, the homework is delegated to the students in `students`.
 */
#[Fillable(['school_class_id', 'teacher_id', 'subject', 'topic', 'held_on', 'notes', 'attachments', 'summary', 'published_at', 'due_on', 'practice_competency_id'])]
class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory;

    /**
     * Photo formats of worksheets the upload takes and the AI receives (Gemini reads these); PDFs come on top.
     */
    public const array WORKSHEET_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    /**
     * The "Einfache Sprache" copy (`summary_simple`) belongs to the summary it was made from: when the summary changes, the copy goes.
     */
    protected static function booted(): void
    {
        static::saving(function (Lesson $lesson): void {
            if ($lesson->isDirty('summary') && ! $lesson->isDirty('summary_simple')) {
                $lesson->setAttribute('summary_simple', null);
            }
        });
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * The school of the lesson's class.
     *
     * @return HasOneThrough<School, SchoolClass, $this>
     */
    public function school(): HasOneThrough
    {
        return $this->hasOneThrough(School::class, SchoolClass::class, 'id', 'id', 'school_class_id', 'school_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /**
     * Students the homework is delegated to.
     *
     * @return BelongsToMany<User, $this>
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * The competency a Förderaufgabe practises; null for an ordinary homework.
     *
     * @return BelongsTo<Competency, $this>
     */
    public function practiceCompetency(): BelongsTo
    {
        return $this->belongsTo(Competency::class, 'practice_competency_id');
    }

    /**
     * @return HasMany<Question, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position');
    }

    /**
     * The Klassenbuch entry made from this lesson, if any.
     *
     * @return HasOne<ClassBookEntry, $this>
     */
    public function classBookEntry(): HasOne
    {
        return $this->hasOne(ClassBookEntry::class);
    }

    /**
     * @return HasMany<Submission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function isPracticeTask(): bool
    {
        return $this->practice_competency_id !== null;
    }

    /**
     * @param  Builder<Lesson>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at');
    }

    /**
     * Published lessons whose homework was delegated to the given student.
     *
     * @param  Builder<Lesson>  $query
     */
    public function scopeAssignedTo(Builder $query, User $student): void
    {
        $query->published()->whereHas('students', fn (Builder $query) => $query->whereKey($student->getKey()));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'held_on' => 'date',
            'due_on' => 'date',
            'published_at' => 'datetime',
            'attachments' => 'array',
        ];
    }
}
