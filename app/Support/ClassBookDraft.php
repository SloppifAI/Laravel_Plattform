<?php

namespace App\Support;

use App\Models\ClassBookEntry;
use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Models\User;
use App\Role;
use Illuminate\Support\Str;

/**
 * Makes the draft of a Klassenbuch entry from a held lesson. Nothing is invented: the content comes from the lesson's
 * summary (or the first sentences of its notes), the homework from the released homework, and the absences from the
 * attendance the teacher confirmed in the latest Tuto-Mikro session of the lesson, if there was one (never for students
 * without a consent: they cannot be on that list).
 */
class ClassBookDraft
{
    /**
     * How much of the notes becomes the content when the lesson has no summary yet.
     */
    private const int NOTES_SENTENCES = 3;

    private const int NOTES_MAX_CHARS = 400;

    /**
     * The entry of the lesson: the draft made now, or the entry that already exists (a lesson has at most one).
     * Check `wasRecentlyCreated` to tell the two apart. Authorisation is the caller's job.
     */
    public static function createFor(Lesson $lesson): ClassBookEntry
    {
        return ClassBookEntry::query()->createOrFirst(['lesson_id' => $lesson->getKey()], self::attributesFor($lesson));
    }

    /**
     * @return array{school_class_id: int, lesson_id: int, teacher_id: int, held_on: mixed, subject: string, topic: string, content: string, homework: string|null, absent_student_ids: list<int>}
     */
    public static function attributesFor(Lesson $lesson): array
    {
        return [
            'school_class_id' => $lesson->school_class_id,
            'lesson_id' => $lesson->getKey(),
            'teacher_id' => $lesson->teacher_id,
            'held_on' => $lesson->held_on,
            'subject' => $lesson->subject,
            'topic' => $lesson->topic,
            'content' => self::contentFor($lesson),
            'homework' => self::homeworkFor($lesson),
            'absent_student_ids' => self::absentStudentIdsFor($lesson),
        ];
    }

    private static function contentFor(Lesson $lesson): string
    {
        $summary = trim((string) $lesson->summary);

        if ($summary !== '') {
            return $summary;
        }

        $notes = Str::of((string) $lesson->notes)->squish()->toString();
        $sentences = preg_split('/(?<=[.!?])\s+/u', $notes, self::NOTES_SENTENCES + 1, PREG_SPLIT_NO_EMPTY) ?: [];

        return Str::limit(implode(' ', array_slice($sentences, 0, self::NOTES_SENTENCES)), self::NOTES_MAX_CHARS);
    }

    /**
     * Only the released homework of the whole class counts; a Förderaufgabe goes to a few students and is no
     * homework of the class.
     */
    private static function homeworkFor(Lesson $lesson): ?string
    {
        if (! $lesson->isPublished() || $lesson->isPracticeTask()) {
            return null;
        }

        $questions = $lesson->questions()->count();
        $details = array_filter([
            $questions > 0 ? ($questions === 1 ? '1 Frage' : "{$questions} Fragen") : null,
            $lesson->due_on !== null ? 'fällig am '.$lesson->due_on->format('d.m.Y') : null,
        ]);

        return 'Hausaufgabe in Tuto'.($details !== [] ? ': '.implode(', ', $details) : '').'.';
    }

    /**
     * The students of the class who were not marked as present and who could have been: Tuto-Mikro refuses a student
     * without a valid consent, so such a student is never on the list, whether in the room or not. Not being on the
     * list says nothing about them and they are left out (the teacher adds them by hand). Empty without a session
     * whose attendance was confirmed (the teacher's list is the only source) and when it lists nobody: a whole class
     * "absent" would be a wrong default, not a fact.
     *
     * shortcut: the consent is checked as of today, not as of the session, so a consent given since then lets a student
     * who could not be ticked look absent; store the consent state with the session if that matters.
     *
     * @return list<int>
     */
    private static function absentStudentIdsFor(Lesson $lesson): array
    {
        $present = LessonRecording::query()
            ->where('lesson_id', $lesson->getKey())
            ->whereNotNull('attendance_confirmed_at')
            ->latest('started_at')
            ->latest('id')
            ->first()?->present_student_ids ?? [];

        if ($present === []) {
            return [];
        }

        $notPresent = $lesson->schoolClass->students()->withRole(Role::Student)->whereKeyNot($present)->get();

        if ($notPresent->isEmpty()) {
            return [];
        }

        $lacking = LessonRecording::consentCheck($lesson, $lesson->teacher, $notPresent)->studentsLackingConsent;

        return $notPresent->reject(fn (User $student): bool => $lacking->has($student->getKey()))->sortBy('name')->values()->modelKeys();
    }
}
