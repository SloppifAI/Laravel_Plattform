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
 *  - the teacher leads the class or is assigned to it (SchoolClass::isTaughtBy, also as today's substitute),
 *  - the lesson is no Förderaufgabe and its subject is not one of the school's sensitive subjects (lessonRefusal),
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
     * Whether the teacher teaches the class: leads it, is assigned to it or substitutes in it today. Having held a
     * lesson there does not count.
     */
    public static function teachesClass(User $teacher, SchoolClass $schoolClass): bool
    {
        return $teacher->isTeacher() && $schoolClass->isTaughtBy($teacher);
    }

    /**
     * Why Tuto-Mikro is never available in this lesson, in the teacher's language; null when nothing speaks against it.
     * The "Tuto-Mikro" action of EditLesson asks this too, so it can stay hidden where the page would refuse.
     */
    public static function lessonRefusal(Lesson $lesson): ?string
    {
        if ($lesson->isPracticeTask()) {
            return __('Für Förderaufgaben gibt es kein Tuto-Mikro.');
        }

        $subject = trim((string) $lesson->subject);

        return $lesson->schoolClass->school?->blocksMikroForSubject($subject)
            ? __('Im Fach :subject ist Tuto-Mikro an deiner Schule nicht möglich.', ['subject' => $subject])
            : null;
    }

    /**
     * @param  iterable<User>  $presentStudents  everybody whose voice may be on the recording
     * @param  list<string>|null  $purposes  keys of ConsentForm::PURPOSES that are about to be processed; the tone recording is always included. Null asks for every purpose of the current form.
     * @param  Lesson|null  $lesson  the lesson the microphone is for: its Förderaufgabe state and its subject are checked as well
     *
     * @throws InvalidArgumentException for an unknown purpose
     */
    public static function check(SchoolClass $schoolClass, iterable $presentStudents, User $teacher, ?array $purposes = null, ?Lesson $lesson = null): RecordingConsentResult
    {
        $unknown = array_diff($purposes ?? [], array_keys(ConsentForm::PURPOSES));

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown consent purpose: '.implode(', ', $unknown));
        }

        $school = $schoolClass->school;
        $reasons = [];

        if ($lesson !== null && ($refusal = self::lessonRefusal($lesson)) !== null) {
            $reasons[] = $refusal;
        }

        if ($school === null || ! $school->recordingsEnabled()) {
            $reasons[] = $school?->isBlocked() ? __('Die Schule ist gesperrt.') : __('Tuto-Mikro ist für deine Schule nicht freigeschaltet.');
        }

        if (! self::teachesClass($teacher, $schoolClass)) {
            $reasons[] = __('Du leitest diese Klasse nicht und unterrichtest sie nicht.');
        }

        /** @var Collection<int, User> $students */
        $students = collect($presentStudents)->keyBy(fn (User $student): int => $student->getKey());

        if ($students->isEmpty()) {
            $reasons[] = __('Es sind keine anwesenden Schüler:innen angegeben.');
        }

        $form = $school?->currentConsentForm();
        $purposes = array_values(array_unique(['audio', ...($purposes ?? $form?->purposes ?? [])]));

        if ($school?->recordingsEnabled() && $form === null) {
            $reasons[] = __('Die Schule hat noch keine Einwilligungserklärung veröffentlicht.');
        }

        $consents = RecordingConsent::query()->whereIn('student_id', $students->keys())->get()->keyBy('student_id');
        $studentReasons = [];

        foreach ($students as $id => $student) {
            if (! $student->isStudent() || (int) $student->school_class_id !== (int) $schoolClass->getKey()) {
                $studentReasons[$id] = __('Gehört nicht zu dieser Klasse');

                continue;
            }

            $status = ConsentStatus::for($consents->get($id), $form, $school?->require_student_consent ?? false, $purposes);

            if ($status !== ConsentStatus::Granted) {
                $studentReasons[$id] = $status->getReason();
            }
        }

        $lacking = $students->only(array_keys($studentReasons));

        if ($lacking->isNotEmpty()) {
            $reasons[] = __('Für :lacking von :total anwesenden Schüler:innen liegt keine gültige Einwilligung vor.', ['lacking' => $lacking->count(), 'total' => $students->count()]);
        }

        return new RecordingConsentResult($reasons === [], $reasons, $lacking, $studentReasons);
    }
}
