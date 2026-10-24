<?php

namespace App\Ai;

use App\Models\Lesson;

/**
 * Interprets the notes of a Tuto-Mikro session for the teacher. Works on text only, never on audio.
 *
 * The application binds SchoolAwareRecordingAnalyst: the SdkRecordingAnalyst only for a school whose AI is on
 * (School::aiEnabled()), otherwise the offline DemoRecordingAnalyst. The caller passes a text whose names are already
 * replaced (Pseudonymizer::forLesson()); the SDK implementation additionally replaces the names in the lesson's
 * subject and topic. Nothing of the result is stored by the platform.
 */
interface RecordingAnalyst
{
    /**
     * Summary, key terms, a board sketch and the questions asked in the lesson, plus notes that can be copied into
     * the lesson's notes as they are. All texts are German.
     *
     * @return array{summary: string, key_terms: list<string>, board_outline: list<string>, class_questions: list<string>, suggested_notes: string}
     *
     * @throws \RuntimeException with a German message the teacher can read when the analysis fails
     */
    public function analyse(Lesson $lesson, string $text): array;
}
