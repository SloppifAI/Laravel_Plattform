<?php

namespace App\Support;

use App\ConsentStatus;
use App\Models\ConsentForm;
use App\Models\Lesson;
use App\Models\RecordingConsent;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The gate in front of every lesson recording. Recording the spoken word without consent of everybody recorded is a
 * criminal offence (§ 201 StGB) and needs a legal basis under the GDPR, so the recording only starts when
 *
 *  - the school has switched recordings on (checklist confirmed) and is not blocked,
 *  - the teacher leads or teaches the class,
 *  - every present student belongs to the class and has a current consent: granted, not revoked, for the latest
 *    published version of the form, covering the requested purposes, and confirmed by the student where the school asks for it.
 *
 * The check is a snapshot; the recording code has to call it again when a student joins or a consent changes.
 *
 * A consent covers the purposes the parents ticked, and each one is a separate processing with its own legal basis
 * (Art. 6 Abs. 1 lit. a DSGVO): the tone recording, the transcript (also the live one of the browser) and the AI
 * analysis. So the caller names what it is about to do, and without a list the check is the strictest one: every
 * purpose of the current form. Narrowing it to what is really started is the caller's job, and a later step (server
 * transcription, AI analysis) has to run the check again with its own purpose.
 */
final class RecordingConsentGate
{
    /**
     * Whether the teacher leads the class (Klassenleitung) or has held a lesson in it.
     */
    public static function teachesClass(User $teacher, SchoolClass $schoolClass): bool
    {
        return $teacher->isTeacher()
            && $teacher->school_id !== null
            && (int) $teacher->school_id === (int) $schoolClass->school_id
            && ((int) $schoolClass->teacher_id === (int) $teacher->getKey()
                || Lesson::query()->where('school_class_id', $schoolClass->getKey())->where('teacher_id', $teacher->getKey())->exists());
    }

    /**
     * @param  iterable<User>  $presentStudents  everybody whose voice may be on the recording
     * @param  list<string>|null  $purposes  keys of ConsentForm::PURPOSES that are about to be processed; the tone recording is always included. Null asks for every purpose of the current form.
     *
     * @throws InvalidArgumentException for an unknown purpose
     */
    public static function check(SchoolClass $schoolClass, iterable $presentStudents, User $teacher, ?array $purposes = null): RecordingConsentResult
    {
        $unknown = array_diff($purposes ?? [], array_keys(ConsentForm::PURPOSES));

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown consent purpose: '.implode(', ', $unknown));
        }

        $school = $schoolClass->school;
        $reasons = [];

        if ($school === null || ! $school->recordingsEnabled()) {
            $reasons[] = $school?->isBlocked() ? 'Die Schule ist gesperrt.' : 'Aufnahmen sind für deine Schule nicht freigeschaltet.';
        }

        if (! self::teachesClass($teacher, $schoolClass)) {
            $reasons[] = 'Du leitest diese Klasse nicht und unterrichtest sie nicht.';
        }

        /** @var Collection<int, User> $students */
        $students = collect($presentStudents)->keyBy(fn (User $student): int => $student->getKey());

        if ($students->isEmpty()) {
            $reasons[] = 'Es sind keine anwesenden Schüler:innen angegeben.';
        }

        $form = $school?->currentConsentForm();
        $purposes = array_values(array_unique(['audio', ...($purposes ?? $form?->purposes ?? [])]));

        if ($school?->recordingsEnabled() && $form === null) {
            $reasons[] = 'Die Schule hat noch keine Einwilligungserklärung veröffentlicht.';
        }

        $consents = RecordingConsent::query()->whereIn('student_id', $students->keys())->get()->keyBy('student_id');
        $studentReasons = [];

        foreach ($students as $id => $student) {
            if (! $student->isStudent() || (int) $student->school_class_id !== (int) $schoolClass->getKey()) {
                $studentReasons[$id] = 'Gehört nicht zu dieser Klasse';

                continue;
            }

            $status = ConsentStatus::for($consents->get($id), $form, $school?->require_student_consent ?? false, $purposes);

            if ($status !== ConsentStatus::Granted) {
                $studentReasons[$id] = $status->getReason();
            }
        }

        $lacking = $students->only(array_keys($studentReasons));

        if ($lacking->isNotEmpty()) {
            $reasons[] = sprintf('Für %d von %d anwesenden Schüler:innen liegt keine gültige Einwilligung vor.', $lacking->count(), $students->count());
        }

        return new RecordingConsentResult($reasons === [], $reasons, $lacking, $studentReasons);
    }
}
