<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A session that has just started, without anybody marked as present; tests that need a snapshot call presentStudents().
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
            'started_at' => now()->subMinutes(1),
            'present_student_ids' => [],
            'attendance_confirmed_at' => now()->subMinutes(1),
            'consent_form_version' => 1,
            'purposes' => ['audio', 'transcript'],
            'audio_sent_to_ai' => false,
        ];
    }

    public function finished(int $durationSeconds = 754): static
    {
        return $this->state(fn (array $attributes) => [
            'duration_seconds' => $durationSeconds,
            'finished_at' => now(),
        ]);
    }

    public function startedDaysAgo(int $days): static
    {
        return $this->state(fn (array $attributes) => ['started_at' => now()->subDays($days)]);
    }

    /**
     * The snapshot of who was present.
     *
     * @param  iterable<User>  $students
     */
    public function presentStudents(iterable $students): static
    {
        return $this->state(fn (array $attributes) => ['present_student_ids' => collect($students)->map->getKey()->sort()->values()->all()]);
    }
}
