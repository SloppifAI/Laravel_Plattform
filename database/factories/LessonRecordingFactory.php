<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\LessonRecording;
use App\TranscriptStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An unfinished recording, as it exists right after the teacher confirmed the consent. The file is not written;
 * tests that need audio use withAudio().
 *
 * @extends Factory<LessonRecording>
 */
class LessonRecordingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lesson_id' => Lesson::factory(),
            'teacher_id' => fn (array $attributes) => Lesson::find($attributes['lesson_id'])->teacher_id,
            'path' => fn (array $attributes) => LessonRecording::newPath(Lesson::find($attributes['lesson_id']), 'audio/webm'),
            'mime_type' => 'audio/webm',
            'consent_confirmed_at' => now()->subMinutes(1),
            'started_at' => now()->subMinutes(1),
            'transcript_status' => TranscriptStatus::None,
        ];
    }

    public function finished(int $durationSeconds = 754): static
    {
        return $this->state(fn (array $attributes) => [
            'duration_seconds' => $durationSeconds,
            'finished_at' => now(),
        ]);
    }

    /**
     * Writes a small placeholder file to the 'local' disk (fake it in the test) after the row is created.
     */
    public function withAudio(string $contents = 'webm-bytes'): static
    {
        return $this->afterCreating(function (LessonRecording $recording) use ($contents): void {
            LessonRecording::disk()->put($recording->path, $contents);
        });
    }

    /**
     * Never finished and silent for a while (browser closed, connection lost): the page lists it as an ordinary
     * recording. Without this state a new recording counts as still running (LessonRecording::isLive()).
     */
    public function interrupted(): static
    {
        return $this->state(fn (array $attributes) => ['updated_at' => now()->subMinutes(10)]);
    }

    public function startedDaysAgo(int $days): static
    {
        return $this->state(fn (array $attributes) => [
            'started_at' => now()->subDays($days),
            'consent_confirmed_at' => now()->subDays($days),
        ]);
    }

    public function withTranscript(string $transcript = 'Heute sprechen wir über die Fotosynthese. Was braucht eine Pflanze zum Wachsen?'): static
    {
        return $this->state(fn (array $attributes) => [
            'transcript' => $transcript,
            'transcript_status' => TranscriptStatus::Done,
            'transcript_source' => LessonRecording::SOURCE_MANUAL,
        ]);
    }
}
