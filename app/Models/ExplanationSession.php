<?php

namespace App\Models;

use Database\Factories\ExplanationSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "Erklär's mir" (reverse learning): the student explains a topic and the tutor asks follow-up questions
 * until the student finishes and gets an assessment of how well they understood it.
 */
#[Fillable(['student_id', 'lesson_id', 'topic', 'understanding_score', 'feedback', 'finished_at'])]
class ExplanationSession extends Model
{
    /** @use HasFactory<ExplanationSessionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * The lesson the topic comes from, if the student picked one of their homework lessons.
     *
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return HasMany<ExplanationMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ExplanationMessage::class)->orderBy('id');
    }

    /**
     * Questions the student sent to their teacher from this session.
     *
     * @return HasMany<StudentQuestion, $this>
     */
    public function studentQuestions(): HasMany
    {
        return $this->hasMany(StudentQuestion::class);
    }

    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'understanding_score' => 'integer',
            'finished_at' => 'datetime',
        ];
    }
}
