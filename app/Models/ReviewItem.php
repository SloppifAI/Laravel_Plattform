<?php

namespace App\Models;

use App\QuestionType;
use App\Support\ReviewSchedule;
use Database\Factories\ReviewItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question a student revises in "Heute wiederholen" (Leitner box 1 to 5, see ReviewSchedule). Private to the student:
 * nothing of it reaches the score, the learning progress, the parents' report or a teacher. Only counters are kept.
 */
#[Fillable(['student_id', 'question_id', 'box', 'due_on', 'last_reviewed_at', 'knew_count', 'hard_count', 'forgot_count'])]
class ReviewItem extends Model
{
    /** @use HasFactory<ReviewItemFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * The student's own items that they may revise now: the question has a solution to revise, its lesson is published,
     * was delegated to this student and belongs to the student's school, and the student has an evaluated hand-in for it
     * while its deadline is over. The solution (Erwartungshorizont) comes with the question, so it is never on offer while
     * the homework is open again for the student (a returned hand-in) or still open for their classmates (an early one).
     * A taken-back homework or a lesson of another school (the student's school changed) is never offered either,
     * whatever rows exist.
     *
     * @param  Builder<ReviewItem>  $query
     */
    public function scopeAvailableTo(Builder $query, User $student): void
    {
        $query->whereBelongsTo($student, 'student')
            ->whereHas('question', fn (Builder $question) => $question
                ->whereIn('type', array_column(QuestionType::reviewable(), 'value'))
                ->whereHas('lesson', fn (Builder $lesson) => $lesson
                    ->assignedTo($student)
                    ->whereHas('schoolClass', fn (Builder $schoolClass) => $schoolClass->where('school_id', $student->school_id))
                    ->whereHas('submissions', fn (Builder $submission) => $submission->whereBelongsTo($student, 'student')->whereNotNull('evaluated_at'))
                    ->where(fn (Builder $deadline) => $deadline->whereNull('due_on')->orWhereDate('due_on', '<', ReviewSchedule::schoolToday()->toDateString()))));
    }

    /**
     * The text of the question in the level the student answered it in (Standard when there is no answer to tell).
     */
    public function askedPrompt(): string
    {
        $answer = Answer::query()
            ->whereBelongsTo($this->question)
            ->whereHas('submission', fn (Builder $submission) => $submission->where('student_id', $this->student_id))
            ->first();

        return $answer?->setRelation('question', $this->question)->askedPrompt() ?? $this->question->prompt;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'box' => 'integer',
            'due_on' => 'date',
            'last_reviewed_at' => 'datetime',
        ];
    }
}
