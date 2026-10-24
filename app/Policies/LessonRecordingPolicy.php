<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Models\User;

/**
 * Tuto-Mikro belongs to the teacher who leads the lesson. School administrations, other teachers, students and the
 * platform operator get neither the page nor the endpoints: the lesson policy lets admins manage the lesson, so the
 * role is checked explicitly on top of it.
 *
 * Starting a session additionally needs the school's switch ("Tuto-Mikro & Einwilligungen"); the consent of the
 * present students is checked by RecordingConsentGate on top of that.
 */
class LessonRecordingPolicy
{
    /**
     * Whether the user is the lesson's teacher and still teaches its class: opens the page. The page lists the class's
     * students with their consent status, so a teacher who only held a lesson there (a substitution that is over, an
     * assignment that was removed) gets neither it nor the endpoints.
     */
    public function create(User $user, Lesson $lesson): bool
    {
        return $user->isTeacher() && $user->can('update', $lesson) && $lesson->schoolClass->isTaughtBy($user);
    }

    /**
     * Whether a new session may start: the lesson's teacher, in a school whose administration allows recordings.
     */
    public function record(User $user, Lesson $lesson): bool
    {
        return $this->create($user, $lesson) && ($lesson->school?->recordingsEnabled() ?? false);
    }

    /**
     * Sending segments, ending the session, asking for the summary.
     */
    public function update(User $user, LessonRecording $recording): bool
    {
        return $recording->teacher()->is($user) && $this->create($user, $recording->lesson);
    }
}
