<?php

namespace Tests\Feature\Recording;

use App\Filament\Pages\RecordLesson;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Models\LessonRecording;
use App\Models\School;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The school administration decides whether lessons of its school may be recorded ("Aufnahmen & Einwilligungen").
 * Without that switch the teacher can start nothing; what exists stays hearable and deletable.
 */
class RecordingSchoolSwitchTest extends TestCase
{
    use RecordingFixtures;
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLesson();
        $this->school = $this->schoolClass->school;
    }

    private function start(): TestResponse
    {
        return $this->actingAs($this->teacher)->postJson(route('recordings.store', $this->lesson), ['consent' => true, 'mime_type' => 'audio/webm']);
    }

    public function test_a_recording_cannot_be_started_while_the_school_has_recordings_off(): void
    {
        $this->school->forceFill(['recordings_enabled_at' => null, 'recording_checklist' => null])->save();

        $this->start()->assertForbidden();

        $this->assertSame(0, LessonRecording::count());
    }

    public function test_a_recording_cannot_be_started_when_the_checklist_is_no_longer_complete_or_the_school_is_blocked(): void
    {
        $this->school->forceFill(['recording_checklist' => array_merge(array_fill_keys(School::RECORDING_CHECKLIST, true), ['dsfa_done' => false])])->save();
        $this->start()->assertForbidden();

        $this->switchRecordings($this->school->refresh(), true);
        $this->start()->assertCreated();

        $this->school->forceFill(['blocked_at' => now()])->save();
        $this->start()->assertForbidden();

        $this->assertSame(1, LessonRecording::count());
    }

    public function test_switching_recordings_on_allows_starting_one(): void
    {
        $this->school->forceFill(['recordings_enabled_at' => null])->save();
        $this->start()->assertForbidden();

        $this->switchRecordings($this->school->refresh(), true);

        $this->start()->assertCreated();
    }

    public function test_the_switch_of_another_school_does_not_count(): void
    {
        $this->school->forceFill(['recordings_enabled_at' => null])->save();
        $this->switchRecordings(School::factory()->create(), true);

        $this->start()->assertForbidden();
    }

    public function test_the_page_explains_it_and_disables_the_start_button_while_recordings_are_off(): void
    {
        $this->switchRecordings($this->school, false);

        $html = $this->actingAs($this->teacher)->get($this->pageUrl())->assertOk()->getContent();

        $this->assertStringContainsString('id="lp-rec-off"', $html);
        $this->assertStringContainsString('Aufnahmen sind für deine Schule nicht freigeschaltet.', $html);
        $this->assertStringContainsString('Aufnahmen &amp; Einwilligungen', $html);
        $this->assertSame(1, preg_match('/<button\b[^>]*x-on:click="openConsent\(\)"[^>]*>/', $html, $button));
        $this->assertMatchesRegularExpression('/\sdisabled(?:=|\s|>)/', $button[0]);
        $this->assertSame(1, preg_match('/canRecord.{1,12}?(true|false)/', $html, $config));
        $this->assertSame('false', $config[1]);
    }

    public function test_the_page_has_no_notice_and_a_working_start_button_while_recordings_are_on(): void
    {
        $html = $this->actingAs($this->teacher)->get($this->pageUrl())->assertOk()->getContent();

        $this->assertStringNotContainsString('id="lp-rec-off"', $html);
        $this->assertSame(1, preg_match('/<button\b[^>]*x-on:click="openConsent\(\)"[^>]*>/', $html, $button));
        $this->assertDoesNotMatchRegularExpression('/\sdisabled(?:=|\s|>)/', $button[0]);
        $this->assertSame(1, preg_match('/canRecord.{1,12}?(true|false)/', $html, $config));
        $this->assertSame('true', $config[1]);
    }

    public function test_existing_recordings_stay_hearable_and_deletable_while_recordings_are_off(): void
    {
        $recording = LessonRecording::factory()->for($this->lesson)->withAudio()->finished()->create();
        $this->switchRecordings($this->school, false);

        $this->actingAs($this->teacher);
        $this->get(route('recordings.stream', $recording))->assertOk();
        $this->get(route('recordings.download', $recording))->assertOk();

        Livewire::test(RecordLesson::class, ['lesson' => $this->lesson])
            ->callAction(TestAction::make('deleteRecording')->arguments(['recording' => $recording->id]))
            ->assertHasNoErrors();

        $this->assertModelMissing($recording);
    }

    public function test_a_recording_that_is_running_when_the_school_switches_off_can_be_finished(): void
    {
        $recording = LessonRecording::factory()->for($this->lesson)->create();
        $this->switchRecordings($this->school, false);

        $this->actingAs($this->teacher);
        $this->appendChunk($recording, 0, 'AAA')->assertOk();
        $this->postJson(route('recordings.finish', $recording), ['duration_seconds' => 12])->assertOk();

        $this->assertTrue($recording->fresh()->isFinished());
    }

    public function test_the_header_action_stays_for_the_teacher_but_says_so_when_recordings_are_off(): void
    {
        $this->switchRecordings($this->school, false);

        Livewire::actingAs($this->teacher)
            ->test(EditLesson::class, ['record' => $this->lesson->getRouteKey()])
            ->assertActionVisible('record');

        // Others still see nothing, switch or not.
        $this->switchRecordings($this->school, true);
        Livewire::actingAs($this->outsiders()['admin of the same school'])
            ->test(EditLesson::class, ['record' => $this->lesson->getRouteKey()])
            ->assertActionHidden('record');
    }
}
