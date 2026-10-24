<?php

namespace Tests\Feature\Recording;

use App\Filament\Pages\RecordLesson;
use App\Models\Lesson;
use App\Models\LessonRecording;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Only the lesson's own teacher: not another teacher, not the school administration (which may manage the lesson),
 * not students, not the platform operator, not a guest.
 */
class RecordingAccessTest extends TestCase
{
    use RecordingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLesson();
    }

    public function test_the_lessons_teacher_opens_the_page(): void
    {
        $this->actingAs($this->teacher)->get($this->pageUrl())->assertOk()->assertSee('Stunde aufnehmen');
    }

    public function test_nobody_else_opens_the_page(): void
    {
        foreach ($this->outsiders() as $who => $user) {
            $this->actingAs($user)->get($this->pageUrl())->assertForbidden("{$who} must not open the page");
        }
    }

    public function test_a_teacher_cannot_open_the_page_of_a_lesson_taught_by_a_colleague(): void
    {
        $colleagueLesson = Lesson::factory()->create();

        $this->actingAs($this->teacher)->get($this->pageUrl($colleagueLesson))->assertForbidden();
    }

    public function test_guests_are_sent_to_the_login_and_get_nothing_from_the_endpoints(): void
    {
        $recording = $this->recordingOf($this->lesson);

        $this->get($this->pageUrl())->assertRedirect();
        $this->postJson(route('recordings.store', $this->lesson), ['consent' => true, 'mime_type' => 'audio/webm'])->assertUnauthorized();
        $this->appendChunk($recording, 0, 'abc')->assertUnauthorized();
        $this->postJson(route('recordings.finish', $recording), ['duration_seconds' => 5])->assertUnauthorized();
        $this->putJson(route('recordings.transcript', $recording), ['transcript' => 'x'])->assertUnauthorized();
        $this->getJson(route('recordings.stream', $recording))->assertUnauthorized();
        $this->getJson(route('recordings.download', $recording))->assertUnauthorized();
        $this->assertSame('webm-bytes', LessonRecording::disk()->get($recording->path));
    }

    public function test_nobody_else_can_create_upload_finish_listen_or_download(): void
    {
        $recording = $this->recordingOf($this->lesson);

        foreach ($this->outsiders() as $who => $user) {
            $this->actingAs($user);

            $this->postJson(route('recordings.store', $this->lesson), ['consent' => true, 'mime_type' => 'audio/webm'])->assertForbidden("{$who} must not start a recording");
            $this->appendChunk($recording, 0, 'abc')->assertForbidden("{$who} must not upload");
            $this->postJson(route('recordings.finish', $recording), ['duration_seconds' => 5])->assertForbidden("{$who} must not finish");
            $this->putJson(route('recordings.transcript', $recording), ['transcript' => 'x'])->assertForbidden("{$who} must not write the transcript");
            $this->get(route('recordings.stream', $recording))->assertForbidden("{$who} must not listen");
            $this->get(route('recordings.download', $recording))->assertForbidden("{$who} must not download");
        }

        $this->assertSame(1, LessonRecording::count());
        $this->assertSame(0, $recording->fresh()->chunks_received);
        $this->assertFalse($recording->fresh()->isFinished());
        $this->assertSame('webm-bytes', LessonRecording::disk()->get($recording->path));
    }

    public function test_a_teacher_cannot_use_the_recording_of_a_colleague(): void
    {
        $foreign = $this->recordingOf(Lesson::factory()->create());

        $this->actingAs($this->teacher);

        $this->appendChunk($foreign, 0, 'abc')->assertForbidden();
        $this->get(route('recordings.stream', $foreign))->assertForbidden();
        $this->get(route('recordings.download', $foreign))->assertForbidden();
    }

    public function test_nobody_else_can_use_the_page_actions(): void
    {
        $recording = $this->recordingOf($this->lesson);

        foreach ($this->outsiders() as $who => $user) {
            Livewire::actingAs($user)
                ->test(RecordLesson::class, ['lesson' => $this->lesson])
                ->assertForbidden();
        }

        $this->assertTrue($recording->fresh()->hasAudio());
    }

    public function test_the_teacher_cannot_touch_a_recording_of_another_lesson_through_the_page(): void
    {
        $otherLesson = Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')->create();
        $foreign = $this->recordingOf(Lesson::factory()->create());
        $ownOtherLesson = $this->recordingOf($otherLesson);

        $this->actingAs($this->teacher);

        foreach ([$foreign, $ownOtherLesson] as $recording) {
            $this->assertNotFoundOnPage(fn ($page) => $page->callAction(TestAction::make('deleteRecording')->arguments(['recording' => $recording->id])));
            $this->assertNotFoundOnPage(fn ($page) => $page->call('saveTranscript', $recording->id));
            $this->assertNotFoundOnPage(fn ($page) => $page->call('analyse', $recording->id));
        }

        $this->assertSame(2, LessonRecording::count());
    }

    public function test_a_recording_stays_closed_to_the_teacher_who_no_longer_leads_the_lesson(): void
    {
        $recording = $this->recordingOf($this->lesson);
        $this->lesson->update(['teacher_id' => $this->outsiders()['another teacher of the same school']->id]);

        $this->actingAs($this->teacher)->get(route('recordings.stream', $recording))->assertForbidden();
    }

    private function assertNotFoundOnPage(callable $act): void
    {
        $act(Livewire::test(RecordLesson::class, ['lesson' => $this->lesson]))->assertNotFound();
    }
}
