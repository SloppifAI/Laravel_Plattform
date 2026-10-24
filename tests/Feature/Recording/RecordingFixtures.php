<?php

namespace Tests\Feature\Recording;

use App\Filament\Pages\RecordLesson;
use App\Models\ConsentForm;
use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Models\RecordingConsent;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * A lesson with its teacher, a class whose parents all consented to everything, and everyone who must not get at Tuto-Mikro.
 * The AI stays off until a test calls enableAi(); the SDK fakes are the test's job.
 */
trait RecordingFixtures
{
    protected SchoolClass $schoolClass;

    protected User $teacher;

    protected Lesson $lesson;

    protected ConsentForm $form;

    /** @var Collection<int, User> */
    protected Collection $students;

    protected function setUpLesson(): void
    {
        Storage::fake('local');

        $this->schoolClass = SchoolClass::factory()->create(['name' => '7b']);
        $school = $this->schoolClass->school;
        $this->switchRecordings($school, true);

        $this->form = ConsentForm::factory()->create(['school_id' => $school->getKey()]);
        $this->teacher = $this->schoolClass->teacher;
        $this->students = new Collection([
            User::factory()->student($this->schoolClass)->create(['name' => 'Anna Müller']),
            User::factory()->student($this->schoolClass)->create(['name' => 'Ben Schneider']),
            User::factory()->student($this->schoolClass)->create(['name' => 'Cem Yilmaz']),
        ]);

        foreach ($this->students as $student) {
            RecordingConsent::factory()->forForm($this->form)->create(['student_id' => $student->getKey()]);
        }

        $this->lesson = Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')
            ->create(['subject' => 'Biologie', 'topic' => 'Fotosynthese', 'held_on' => '2026-10-01', 'notes' => 'Tafelbild: Licht, Wasser, CO2']);
    }

    /**
     * The school administration's switch ("Tuto-Mikro & Einwilligungen"): on means the whole checklist is confirmed.
     */
    protected function switchRecordings(School $school, bool $on): void
    {
        $school->forceFill([
            'recording_checklist' => array_fill_keys(School::RECORDING_CHECKLIST, true),
            'recordings_enabled_at' => $on ? now() : null,
        ])->save();
    }

    /**
     * The AI of the platform and the school's opt-in with the complete checklist.
     */
    protected function enableAi(): void
    {
        config(['lernplattform.ai.enabled' => true]);

        $this->schoolClass->school->forceFill([
            'ai_checklist' => array_fill_keys(School::AI_CHECKLIST, true),
            'ai_enabled_at' => now(),
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

    /**
     * The sentences the script of the Tuto-Mikro page shows, as the page hands them over in the component config.
     *
     * @return array<string, string>
     */
    protected function micTexts(string $html): array
    {
        $this->assertSame(1, preg_match("/tutoMikro\\(JSON\\.parse\\('(.*?)'\\)\\)\"/s", $html, $match), 'The page hands its config over as JSON');

        // The config sits in an HTML attribute as a JS string literal holding JSON: undo both layers.
        return json_decode(json_decode('"'.html_entity_decode($match[1]).'"', true, 512, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)['texts'];
    }

    /**
     * What a reader sees of a page: no scripts or styles, no tags.
     */
    protected function visibleText(string $html): string
    {
        return html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', '', $html)));
    }

    protected function pageUrl(?Lesson $lesson = null): string
    {
        return RecordLesson::getUrl(['lesson' => $lesson ?? $this->lesson]);
    }

    /**
     * @param  iterable<User>|null  $present  everybody of the class when null
     */
    protected function startSession(?iterable $present = null, ?User $as = null, bool $attendanceConfirmed = true): TestResponse
    {
        $ids = collect($present ?? $this->students)->map->getKey()->values()->all();

        return $this->actingAs($as ?? $this->teacher)->postJson(route('recordings.store', $this->lesson), ['present' => $ids, 'attendance_confirmed' => $attendanceConfirmed]);
    }

    protected function startedSession(): LessonRecording
    {
        $this->startSession()->assertCreated();

        return LessonRecording::query()->latest('id')->firstOrFail();
    }

    protected function postSegment(LessonRecording $recording, string $bytes = 'webm-bytes', string $contentType = 'audio/webm'): TestResponse
    {
        return $this->call(
            'POST',
            route('recordings.segment', $recording),
            server: ['CONTENT_TYPE' => $contentType, 'HTTP_ACCEPT' => 'application/json'],
            content: $bytes,
        );
    }

    /**
     * Every file Tuto-Mikro left on the private disk: always none.
     *
     * @return list<string>
     */
    protected function filesOnDisk(): array
    {
        return LessonRecording::disk()->allFiles();
    }
}
