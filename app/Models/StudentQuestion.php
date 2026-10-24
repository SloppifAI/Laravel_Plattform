<?php

namespace App\Models;

use Database\Factories\StudentQuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question a student sends to the teacher of a lesson, from "Erklär’s mir" (then it belongs to that session) or from
 * the result page of the lesson's homework. It goes away with its lesson, like the lesson's other learning data. It is open until a teacher answers it or marks it as done; the student then reads the
 * answer ("read_at").
 */
#[Fillable(['student_id', 'lesson_id', 'explanation_session_id', 'question', 'answer', 'answered_by', 'answered_at', 'read_at'])]
class StudentQuestion extends Model
{
    /**
     * How many unanswered questions a student may have about one lesson, so a teacher's inbox cannot be flooded.
     */
    public const int MAX_OPEN_PER_LESSON = 5;

    /** @use HasFactory<StudentQuestionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return BelongsTo<ExplanationSession, $this>
     */
    public function explanationSession(): BelongsTo
    {
        return $this->belongsTo(ExplanationSession::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function answeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    public function isOpen(): bool
    {
        return $this->answered_at === null;
    }

    /**
     * A question closed without a written answer, e.g. because the teacher talked it through in class.
     */
    public function wasMarkedDone(): bool
    {
        return $this->answered_at !== null && blank($this->answer);
    }

    public function hasUnreadAnswer(): bool
    {
        return $this->answered_at !== null && $this->read_at === null;
    }

    /**
     * Questions for lessons whose results the user may read (the lesson's teacher unless they are in the class only as a
     * substitute whose substitution is over, the administration of its school), the same rule as
     * LessonPolicy::viewResults. Everyone else, operators included, gets none.
     *
     * @param  Builder<StudentQuestion>  $query
     */
    public function scopeManagedBy(Builder $query, User $user): void
    {
        match (true) {
            $user->isAdmin() && $user->school_id !== null => $query->whereRelation('lesson.schoolClass', 'school_id', $user->school_id),
            $user->isTeacher() => $query
                ->whereRelation('lesson', 'teacher_id', $user->getKey())
                ->whereHas('lesson.schoolClass', fn (Builder $classes) => $classes->where(fn (Builder $classes) => $classes
                    ->whereDoesntHave('substitutions', fn (Builder $substitutions) => $substitutions->where('substitute_id', $user->getKey()))
                    ->orWhere(fn (Builder $taught) => $taught->taughtBy($user)))),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * @param  Builder<StudentQuestion>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('answered_at');
    }

    /**
     * @param  Builder<StudentQuestion>  $query
     */
    public function scopeUnreadAnswer(Builder $query): void
    {
        $query->whereNotNull('answered_at')->whereNull('read_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }
}
