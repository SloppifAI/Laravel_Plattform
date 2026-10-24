<?php

namespace App\Ai;

use App\Models\Lesson;

/**
 * Speech recognition for one short segment of a Tuto-Mikro session. This is the only place in the platform where
 * sound could leave the server. The application binds SchoolAwareTranscriber: the SdkTranscriber (Laravel AI SDK,
 * Gemini by default) only for a school whose AI is on (School::aiEnabled()), otherwise the DemoTranscriber, which
 * cannot process audio and returns null.
 *
 * The caller has authorised the session and checked the consent of the present students. The sound is handed over as
 * bytes in memory and never touches a disk of the platform, so a crashed request leaves nothing behind. The returned
 * text is not stored anywhere by the platform.
 */
interface Transcriber
{
    /**
     * Recognise the German speech of one segment.
     *
     * @param  string  $audio  the bytes of the segment, a complete file
     * @param  string  $mimeType  the container type of the file (a key of LessonRecording::MIME_TYPES)
     * @return string|null the recognised text, or null when this implementation does not recognise speech (the demo)
     *
     * @throws TranscriberException with a German message the teacher can read when the recognition fails
     */
    public function transcribe(Lesson $lesson, string $audio, string $mimeType): ?string;
}
