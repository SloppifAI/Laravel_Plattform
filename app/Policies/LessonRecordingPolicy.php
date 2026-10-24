<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Models\User;

/**
 * Lesson recordings belong to the teacher who made them, and only while that teacher still leads the lesson.
 * School administrations, other teachers, students and the platform operator get neither the page nor the audio:
 * the lesson policy lets admins manage the lesson, so the role is checked explicitly on top of it.
 *
 * Starting a new recording additionally needs the school's switch ("Aufnahmen & Einwilligungen"). Existing
 * recordings stay listenable and deletable when the school switches recordings off.
 */
class LessonRecordingPolicy
{
    /**
     * Whether the user is the lesson's teacher: opens the recording page and manages its recordings.
     */
    public function create(User $user, Lesson $lesson): bool
    {
        return $user->isTeacher() && $user->can('update', $lesson);
    }

    /**
     * Whether a new recording may start: the lesson's teacher, in a school whose administration allows recordings.
     */
    public function record(User $user, Lesson $lesson): bool
    {
        return $this->create($user, $lesson) && ($lesson->school?->recordingsEnabled() ?? false);
    }

    /**
     * Listening, downloading, transcript and analysis.
     */
    public function view(User $user, LessonRecording $recording): bool
    {
        return $this->owns($user, $recording);
    }

    /**
     * Appending chunks, finishing, editing the transcript, running the analysis.
     */
    public function update(User $user, LessonRecording $recording): bool
    {
        return $this->owns($user, $recording);
    }

    public function delete(User $user, LessonRecording $recording): bool
    {
        return $this->owns($user, $recording);
    }

    private function owns(User $user, LessonRecording $recording): bool
    {
        return $recording->teacher()->is($user) && $this->create($user, $recording->lesson);
    }
}
