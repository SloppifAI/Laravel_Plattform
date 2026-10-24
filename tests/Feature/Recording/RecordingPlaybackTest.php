<?php

namespace Tests\Feature\Recording;

use App\Filament\Pages\RecordLesson;
use App\Models\LessonRecording;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class RecordingPlaybackTest extends TestCase
{
    use RecordingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLesson();
    }

    public function test_the_stream_sends_the_file_with_its_content_type_and_no_cache_headers(): void
    {
        $recording = $this->recordingOf($this->lesson);

        $response = $this->actingAs($this->teacher)->get(route('recordings.stream', $recording))->assertOk();

        $this->assertSame('audio/webm', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('webm-bytes', file_get_contents($response->getFile()->getPathname()));
    }

    public function test_the_stream_answers_range_requests_so_the_player_can_seek(): void
    {
        $recording = $this->recordingOf($this->lesson);

        $response = $this->actingAs($this->teacher)->get(route('recordings.stream', $recording), ['Range' => 'bytes=0-3'])->assertStatus(206);

        $this->assertSame('bytes 0-3/10', $response->headers->get('Content-Range'));
    }

    public function test_a_file_in_another_format_keeps_its_own_content_type(): void
    {
        $recording = LessonRecording::factory()->for($this->lesson)->withAudio('ogg-bytes')->create(['mime_type' => 'audio/ogg', 'path' => 'recordings/1/own.ogg']);

        $this->actingAs($this->teacher)->get(route('recordings.stream', $recording))->assertOk()->assertHeader('Content-Type', 'audio/ogg');
    }

    public function test_the_download_is_an_attachment_with_a_plain_file_name(): void
    {
        $recording = $this->recordingOf($this->lesson);
        $recording->update(['started_at' => '2026-10-24 10:15:00']);

        $response = $this->actingAs($this->teacher)->get(route('recordings.download', $recording))->assertOk();

        $this->assertStringStartsWith('attachment; filename=aufnahme-biologie-2026-10-24-10-15.webm', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_a_missing_file_is_not_found(): void
    {
        $recording = LessonRecording::factory()->for($this->lesson)->create();

        $this->actingAs($this->teacher)->get(route('recordings.stream', $recording))->assertNotFound();
        $this->actingAs($this->teacher)->get(route('recordings.download', $recording))->assertNotFound();
    }

    public function test_there_is_no_public_url_for_the_audio(): void
    {
        $recording = $this->recordingOf($this->lesson);

        $this->assertFalse(Storage::disk('public')->exists($recording->path));
        // The framework's own route for the local disk only answers to signed URLs.
        $this->assertContains($this->get('/storage/'.$recording->path)->status(), [403, 404]);
        $this->assertStringNotContainsString($recording->path, route('recordings.stream', $recording));
    }

    public function test_deleting_removes_the_file_and_the_row_after_a_confirmation(): void
    {
        $recording = $this->recordingOf($this->lesson);
        $other = $this->recordingOf($this->lesson);

        Livewire::actingAs($this->teacher)
            ->test(RecordLesson::class, ['lesson' => $this->lesson])
            ->mountAction(TestAction::make('deleteRecording')->arguments(['recording' => $recording->id]))
            ->assertActionMounted(TestAction::make('deleteRecording')->arguments(['recording' => $recording->id]))
            ->callMountedAction()
            ->assertHasNoErrors();

        $this->assertModelMissing($recording);
        $this->assertFalse(LessonRecording::disk()->exists($recording->path));
        $this->assertModelExists($other);
        $this->assertTrue(LessonRecording::disk()->exists($other->path));
    }

    public function test_the_row_is_not_deleted_when_the_confirmation_is_cancelled(): void
    {
        $recording = $this->recordingOf($this->lesson);

        Livewire::actingAs($this->teacher)
            ->test(RecordLesson::class, ['lesson' => $this->lesson])
            ->mountAction(TestAction::make('deleteRecording')->arguments(['recording' => $recording->id]))
            ->unmountAction();

        $this->assertModelExists($recording);
        $this->assertTrue(LessonRecording::disk()->exists($recording->path));
    }
}
