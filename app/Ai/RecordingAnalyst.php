<?php

namespace App\Ai;

use App\Models\LessonRecording;

/**
 * Interprets the transcript of a lesson recording for the teacher. Works on text only, never on audio.
 *
 * Bound in AppServiceProvider: ClaudeRecordingAnalyst when `services.anthropic.enabled` is true, otherwise the offline
 * DemoRecordingAnalyst. The real implementation pseudonymises names before anything leaves the platform.
 */
interface RecordingAnalyst
{
    /**
     * Summary, key terms, a board sketch and the questions asked in the lesson, plus notes that can be copied into
     * the lesson's notes as they are. All texts are German.
     *
     * @return array{summary: string, key_terms: list<string>, board_outline: list<string>, class_questions: list<string>, suggested_notes: string}
     */
    public function analyse(LessonRecording $recording, string $transcript): array;
}
