<?php

namespace Tests\Feature\Recording;

use App\Filament\Pages\RecordLesson;
use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * A lesson with its teacher, and everyone who must not get at the teacher's recordings.
 */
trait RecordingFixtures
{
    protected SchoolClass $schoolClass;

    protected User $teacher;

    protected Lesson $lesson;

    protected function setUpLesson(): void
    {
        Storage::fake('local');

        $this->schoolClass = SchoolClass::factory()->create(['name' => '7b']);
        $this->switchRecordings($this->schoolClass->school, true);
        $this->teacher = $this->schoolClass->teacher;
        $this->lesson = Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')
            ->create(['subject' => 'Biologie', 'topic' => 'Fotosynthese', 'held_on' => '2026-10-01', 'notes' => 'Tafelbild: Licht, Wasser, CO2']);
    }

    /**
     * The school administration's switch ("Aufnahmen & Einwilligungen"): on means the whole checklist is confirmed.
     */
    protected function switchRecordings(School $school, bool $on): void
    {
        $school->forceFill([
            'recording_checklist' => array_fill_keys(School::RECORDING_CHECKLIST, true),
            'recordings_enabled_at' => $on ? now() : null,
        ])->save();
    }

    /**
     * @return array<string, User>
     */
    protected function outsiders(): array
    {
        return [
            'another teacher of the same school' => User::factory()->teacher()->create(['school_id' => $this->schoolClass->school_id]),
            'admin of the same school' => User::factory()->admin()->create(['school_id' => $this->schoolClass->school_id]),
            'student of the class' => User::factory()->student($this->schoolClass)->create(),
            'operator' => User::factory()->operator()->create(),
            'teacher of another school' => User::factory()->teacher()->create(),
        ];
    }

    protected function pageUrl(?Lesson $lesson = null): string
    {
        return RecordLesson::getUrl(['lesson' => $lesson ?? $this->lesson]);
    }

    protected function appendChunk(LessonRecording $recording, int $index, string $bytes, string $contentType = 'audio/webm'): TestResponse
    {
        return $this->call(
            'POST',
            route('recordings.append', ['recording' => $recording, 'index' => $index]),
            server: ['CONTENT_TYPE' => $contentType, 'HTTP_ACCEPT' => 'application/json'],
            content: $bytes,
        );
    }

    protected function recordingOf(Lesson $lesson, string $state = 'withAudio'): LessonRecording
    {
        return LessonRecording::factory()->for($lesson)->interrupted()->{$state}()->create();
    }
}
