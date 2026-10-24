<?php

namespace App\Models;

use App\QuestionLevel;
use App\QuestionType;
use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A homework question with its Erwartungshorizont (`expected_answer`), which students never see. `prompt` is the
 * Standard level; `prompt_basis` (Einstieg) and `prompt_plus` (Profi) are optional variants that share the Erwartungshorizont.
 * `ai_drafted` marks a question of the AI draft the teacher has not edited; a new draft replaces only those (HomeworkDraft).
 */
#[Fillable(['lesson_id', 'competency_id', 'type', 'prompt', 'prompt_basis', 'prompt_plus', 'expected_answer', 'position', 'ai_drafted'])]
class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    /**
     * The "Einfache Sprache" copy of a text belongs to the text it was made from: when the text changes, the copy goes.
     */
    protected static function booted(): void
    {
        static::saving(function (Question $question): void {
            foreach (QuestionLevel::cases() as $level) {
                if ($question->isDirty($level->promptColumn()) && ! $question->isDirty($level->simpleColumn())) {
                    $question->setAttribute($level->simpleColumn(), null);
                }
            }
        });
    }

    public function hasLevels(): bool
    {
        return filled($this->prompt_basis) || filled($this->prompt_plus);
    }

    /**
     * The level a student gets for the requested one: that level when the question has it, otherwise Standard.
     */
    public function levelFor(mixed $requested): QuestionLevel
    {
        $level = $requested instanceof QuestionLevel ? $requested : QuestionLevel::tryFrom((string) $requested);

        return $level !== null && filled($this->getAttribute($level->promptColumn())) ? $level : QuestionLevel::Standard;
    }

    /**
     * @return list<QuestionLevel> Standard plus every variant the question has
     */
    public function availableLevels(): array
    {
        return array_values(array_filter(QuestionLevel::cases(), fn (QuestionLevel $level): bool => $this->levelFor($level) === $level));
    }

    public function promptFor(QuestionLevel $level): string
    {
        return (string) $this->getAttribute($level->promptColumn());
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return BelongsTo<Competency, $this>
     */
    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }

    /**
     * @return HasMany<Answer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'ai_drafted' => 'boolean',
        ];
    }
}
