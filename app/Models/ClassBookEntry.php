<?php

namespace App\Models;

use App\ClassBookStatus;
use Database\Factories\ClassBookEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a class's Klassenbuch: what was taught in a lesson, the homework and who was absent. It starts as a draft
 * made from a lesson (App\Support\ClassBookDraft); the teacher completes it and confirms it, which locks it.
 *
 * The absences are personal data: only the teachers of the class and the school administration may see them
 * (ClassBookEntryPolicy), never the students.
 */
#[Fillable(['school_class_id', 'lesson_id', 'teacher_id', 'held_on', 'period', 'subject', 'topic', 'content', 'homework', 'absent_student_ids', 'remarks', 'confirmed_at'])]
class ClassBookEntry extends Model
{
    /** @use HasFactory<ClassBookEntryFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * Null once the lesson was deleted: the entry stays.
     *
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Null once the teacher's account was deleted: the entry stays.
     *
     * @return BelongsTo<User, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * Whether subject, topic and content are filled in: a draft made from a lesson without notes has no content yet,
     * and an entry like that must not be locked.
     */
    public function isComplete(): bool
    {
        return trim((string) $this->subject) !== '' && trim((string) $this->topic) !== '' && trim((string) $this->content) !== '';
    }

    /**
     * Takes a deleted student out of the absence lists of all entries (erasure: the id must not stay behind, locked
     * entries included).
     */
    public static function forgetAbsentStudent(User $student): void
    {
        static::query()
            ->whereJsonContains('absent_student_ids', $student->getKey())
            ->each(fn (ClassBookEntry $entry) => $entry->forceFill([
                'absent_student_ids' => array_values(array_diff($entry->absent_student_ids ?? [], [$student->getKey()])),
            ])->save());
    }

    public function status(): ClassBookStatus
    {
        return $this->isConfirmed() ? ClassBookStatus::Confirmed : ClassBookStatus::Draft;
    }

    /**
     * The absent students by name, also those who have left the class since (they are still of the school).
     *
     * @return Collection<int, User>
     */
    public function absentStudents(): Collection
    {
        return User::query()
            ->where('school_id', $this->schoolClass->school_id)
            ->whereKey($this->absent_student_ids ?? [])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'held_on' => 'date',
            'period' => 'integer',
            'absent_student_ids' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }
}
