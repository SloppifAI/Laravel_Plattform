<?php

namespace App\Models;

use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A student's homework: their own summary of the lesson plus answers, and the AI's check of both.
 */
#[Fillable(['lesson_id', 'student_id', 'summary', 'understanding_score', 'feedback', 'gaps', 'evaluated_at'])]
class Submission extends Model
{
    /** @use HasFactory<SubmissionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * @return HasMany<Answer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    public function isEvaluated(): bool
    {
        return $this->evaluated_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'understanding_score' => 'integer',
            'gaps' => 'array',
            'evaluated_at' => 'datetime',
        ];
    }
}
