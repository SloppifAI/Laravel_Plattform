<?php

namespace App\Models;

use App\MistakeSource;
use App\MistakeType;
use Database\Factories\AnswerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's answer to one question with the AI's score and feedback. `mistake_type` is only a hint on what kind of
 * mistake an answer below the threshold shows, with `mistake_confidence` (0–100, AI only) and `mistake_source`
 * (see App\Support\MistakeDiagnosis).
 */
#[Fillable(['submission_id', 'question_id', 'answer', 'level', 'score', 'feedback', 'mistake_type', 'mistake_confidence', 'mistake_source'])]
class Answer extends Model
{
    /** @use HasFactory<AnswerFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Submission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /**
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * The text of the question in the level the student answered it in (Standard when no level was recorded).
     */
    public function askedPrompt(): string
    {
        return $this->question->promptFor($this->question->levelFor($this->level));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'mistake_type' => MistakeType::class,
            'mistake_confidence' => 'integer',
            'mistake_source' => MistakeSource::class,
        ];
    }
}
