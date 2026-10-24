<?php

namespace Tests\Feature\Recording;

use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Models\User;
use App\TranscriptStatus;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Tests\TestCase;

class RecordingUploadTest extends TestCase
{
    use RecordingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLesson();
        $this->actingAs($this->teacher);
    }

    public function test_a_recording_is_created_with_the_consent_confirmation(): void
    {
        $this->travelTo(now()->setTime(10, 15));

        $response = $this->postJson(route('recordings.store', $this->lesson), ['consent' => true, 'mime_type' => 'audio/webm'])->assertCreated();

        $recording = LessonRecording::sole();
        $this->assertSame($this->teacher->id, $recording->teacher_id);
        $this->assertSame($this->lesson->id, $recording->lesson_id);
        $this->assertSame('audio/webm', $recording->mime_type);
        $this->assertEquals(now(), $recording->consent_confirmed_at);
        $this->assertEquals(now(), $recording->started_at);
        $this->assertNull($recording->finished_at);
        $this->assertSame(TranscriptStatus::None, $recording->transcript_status);
        $this->assertMatchesRegularExpression('#^recordings/'.$this->lesson->id.'/[0-9a-z]{26}\.webm$#', $recording->path);
        $response->assertJson(['id' => $recording->id, 'append_url' => route('recordings.append', $recording), 'finish_url' => route('recordings.finish', $recording)]);
    }

    public function test_a_recording_needs_the_consent_and_a_known_audio_type(): void
    {
        $this->postJson(route('recordings.store', $this->lesson), ['mime_type' => 'audio/webm'])->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->postJson(route('recordings.store', $this->lesson), ['consent' => false, 'mime_type' => 'audio/webm'])->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->postJson(route('recordings.store', $this->lesson), ['consent' => true, 'mime_type' => 'video/webm'])->assertUnprocessable()->assertJsonValidationErrors('mime_type');
        $this->postJson(route('recordings.store', $this->lesson), ['consent' => true])->assertUnprocessable()->assertJsonValidationErrors('mime_type');

        $this->assertSame(0, LessonRecording::count());
    }

    public function test_chunks_are_appended_in_order_to_one_file(): void
    {
        $recording = LessonRecording::factory()->for($this->lesson)->create();

        $this->appendChunk($recording, 0, 'AAA')->assertOk()->assertJson(['received' => 1]);
        $this->appendChunk($recording, 1, 'BBB')->assertOk()->assertJson(['received' => 2]);
        $this->appendChunk($recording, 2, "C\r\nC")->assertOk()->assertJson(['received' => 3]);

        $this->assertSame("AAABBBC\r\nC", LessonRecording::disk()->get($recording->path));
        $this->assertSame(3, $recording->fresh()->chunks_received);
    }

    public function test_a_chunk_that_skips_ahead_is_refused_and_a_repeated_one_is_not_appended_twice(): void
    {
        $recording = LessonRecording::factory()->for($this->lesson)->create();

        $this->appendChunk($recording, 1, 'BBB')->assertConflict();
        $this->assertFalse($recording->fresh()->hasAudio());

        $this->appendChunk($recording, 0, 'AAA')->assertOk();
        $this->appendChunk($recording, 0, 'AAA')->assertOk()->assertJson(['received' => 1, 'duplicate' => true]);
        $this->appendChunk($recording, 1, 'BBB')->assertOk();

        $this->assertSame('AAABBB', LessonRecording::disk()->get($recording->path));
    }

    public function test_finishing_stores_the_duration_and_closes_the_recording_for_more_chunks(): void
    {
        $recording = LessonRecording::factory()->for($this->lesson)->create();
        $this->appendChunk($recording, 0, 'AAA')->assertOk();

        $this->postJson(route('recordings.finish', $recording), ['duration_seconds' => 754])->assertOk()->assertJson(['finished' => true, 'duration_seconds' => 754]);

        $recording->refresh();
        $this->assertTrue($recording->isFinished());
        $this->assertSame(754, $recording->duration_seconds);
        $this->assertSame('12:34', $recording->durationLabel());

        $this->appendChunk($recording, 1, 'BBB')->assertConflict();
        $this->putJson(route('recordings.transcript', $recording), ['transcript' => 'zu spät'])->assertConflict();
        $this->assertSame('AAA', LessonRecording::disk()->get($recording->path));
        $this->assertNull($recording->fresh()->transcript);

        // Repeating the call after a lost response changes nothing.
        $this->postJson(route('recordings.finish', $recording), ['duration_seconds' => 999])->assertOk()->assertJson(['duration_seconds' => 754]);
    }

    public function test_a_recording_without_any_sound_is_dropped_when_it_is_finished(): void
    {
        $recording = LessonRecording::factory()->for($this->lesson)->create();

        $this->postJson(route('recordings.finish', $recording), ['duration_seconds' => 2])->assertOk()->assertJson(['discarded' => true]);

        $this->assertModelMissing($recording);
    }

    public function test_the_duration_must_be_a_sensible_number(): void
    {
        $recording = LessonRecording::factory()->for($this->lesson)->create();
        $this->appendChunk($recording, 0, 'AAA')->assertOk();

        $this->postJson(route('recordings.finish', $recording), ['duration_seconds' => -1])->assertUnprocessable();
        $this->postJson(route('recordings.finish', $recording), ['duration_seconds' => 999999])->assertUnprocessable();
        $this->postJson(route('recordings.finish', $recording))->assertUnprocessable();
        $this->assertFalse($recording->fresh()->isFinished());
    }

    public function test_a_chunk_of_the_wrong_type_is_refused(): void
    {
        $recording = LessonRecording::factory()->for($this->lesson)->create();

        $this->appendChunk($recording, 0, 'AAA', 'video/webm')->assertStatus(415);
        $this->appendChunk($recording, 0, 'AAA', 'audio/ogg')->assertStatus(415);
        $this->appendChunk($recording, 0, 'AAA', 'application/octet-stream')->assertStatus(415);
        $this->assertFalse($recording->fresh()->hasAudio());

        // The codec parameter the browser adds is fine.
        $this->appendChunk($recording, 0, 'AAA', 'audio/webm;codecs=opus')->assertOk();
    }

    public function test_an_empty_or_oversized_chunk_is_refused(): void
    {
        config(['lernplattform.recordings.max_chunk_kb' => 1]);
        $recording = LessonRecording::factory()->for($this->lesson)->create();

        $this->appendChunk($recording, 0, '')->assertUnprocessable();
        $this->appendChunk($recording, 0, str_repeat('x', 1025))->assertStatus(413);
        $this->assertFalse($recording->fresh()->hasAudio());

        $this->appendChunk($recording, 0, str_repeat('x', 1024))->assertOk();
    }

    public function test_the_whole_file_is_capped(): void
    {
        config(['lernplattform.recordings.max_total_mb' => 1, 'lernplattform.recordings.max_chunk_kb' => 700]);
        $recording = LessonRecording::factory()->for($this->lesson)->create();

        $this->appendChunk($recording, 0, str_repeat('x', 700 * 1024))->assertOk();
        $this->appendChunk($recording, 1, str_repeat('x', 700 * 1024))->assertStatus(413);

        $this->assertSame(700 * 1024, strlen(LessonRecording::disk()->get($recording->path)));
        $this->assertSame(1, $recording->fresh()->chunks_received);
    }

    public function test_the_chunk_number_is_required(): void
    {
        $recording = LessonRecording::factory()->for($this->lesson)->create();
        $server = ['CONTENT_TYPE' => 'audio/webm', 'HTTP_ACCEPT' => 'application/json'];

        $this->call('POST', route('recordings.append', $recording), server: $server, content: 'AAA')->assertUnprocessable();
        $this->call('POST', route('recordings.append', ['recording' => $recording, 'index' => 'abc']), server: $server, content: 'AAA')->assertUnprocessable();
    }

    public function test_the_live_transcript_is_stored_while_recording(): void
    {
        $recording = LessonRecording::factory()->for($this->lesson)->create();

        $this->putJson(route('recordings.transcript', $recording), ['transcript' => '  Heute sprechen wir über Pflanzen.  '])->assertOk()->assertJson(['saved' => true]);

        $recording->refresh();
        $this->assertSame('Heute sprechen wir über Pflanzen.', $recording->transcript);
        $this->assertSame(TranscriptStatus::Live, $recording->transcript_status);
        $this->assertSame(LessonRecording::SOURCE_ON_DEVICE, $recording->transcript_source);

        $this->putJson(route('recordings.transcript', $recording), ['transcript' => ''])->assertOk()->assertJson(['saved' => false]);
        $this->assertSame('Heute sprechen wir über Pflanzen.', $recording->fresh()->transcript);
    }

    public function test_every_recording_route_is_csrf_protected_and_behind_the_panel_login(): void
    {
        $router = app('router');

        foreach (['store', 'append', 'finish', 'transcript', 'stream', 'download'] as $name) {
            $route = $router->getRoutes()->getByName("recordings.{$name}");
            $middleware = $router->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());

            $this->assertContains(PreventRequestForgery::class, $middleware, "{$name} needs CSRF protection");
            $this->assertContains(StartSession::class, $middleware, "{$name} needs the session");
            $this->assertContains(Authenticate::class, $middleware, "{$name} needs the panel login");
        }
    }

    public function test_every_call_of_the_recorder_counts_as_a_sign_of_life_also_without_text(): void
    {
        $recording = LessonRecording::factory()->for($this->lesson)->interrupted()->create(['transcript' => 'Text', 'transcript_status' => TranscriptStatus::Live]);
        $this->assertFalse($recording->isLive());

        $this->putJson(route('recordings.transcript', $recording), ['transcript' => ''])->assertOk()->assertJson(['saved' => false]);

        $recording->refresh();
        $this->assertTrue($recording->isLive());
        $this->assertSame('Text', $recording->transcript);

        // 90 seconds without any call and it looks interrupted again.
        $this->travel(LessonRecording::LIVE_SECONDS + 1)->seconds();
        $this->assertFalse($recording->isLive());
    }

    public function test_a_finished_recording_is_never_live(): void
    {
        $recording = LessonRecording::factory()->for($this->lesson)->finished()->create();

        $this->assertFalse($recording->isLive());
    }

    public function test_a_teacher_cannot_pile_up_unfinished_recordings(): void
    {
        config(['lernplattform.recordings.max_unfinished' => 3]);
        LessonRecording::factory()->count(3)->for($this->lesson)->create();

        $this->postJson(route('recordings.store', $this->lesson), ['consent' => true, 'mime_type' => 'audio/webm'])->assertStatus(429);
        $this->assertSame(3, LessonRecording::count());

        // Finished recordings and ones from before the last 12 hours do not count.
        LessonRecording::query()->limit(1)->update(['finished_at' => now()]);
        $this->postJson(route('recordings.store', $this->lesson), ['consent' => true, 'mime_type' => 'audio/webm'])->assertCreated();

        LessonRecording::query()->whereNull('finished_at')->update(['started_at' => now()->subHours(13)]);
        $this->postJson(route('recordings.store', $this->lesson), ['consent' => true, 'mime_type' => 'audio/webm'])->assertCreated();
    }

    public function test_the_unfinished_recordings_of_a_colleague_do_not_count(): void
    {
        config(['lernplattform.recordings.max_unfinished' => 1]);
        LessonRecording::factory()->for(Lesson::factory()->create())->create();

        $this->postJson(route('recordings.store', $this->lesson), ['consent' => true, 'mime_type' => 'audio/webm'])->assertCreated();
    }

    public function test_starting_recordings_is_throttled_per_user(): void
    {
        config(['lernplattform.recordings.max_unfinished' => 1000]);

        foreach (range(1, 10) as $attempt) {
            $this->postJson(route('recordings.store', $this->lesson), ['consent' => true, 'mime_type' => 'audio/webm'])->assertCreated();
        }

        $this->postJson(route('recordings.store', $this->lesson), ['consent' => true, 'mime_type' => 'audio/webm'])->assertStatus(429)->assertHeader('Retry-After');

        // Another teacher has a counter of their own.
        $this->actingAs(User::factory()->teacher()->create());
        $this->postJson(route('recordings.store', $this->lesson), ['consent' => true, 'mime_type' => 'audio/webm'])->assertForbidden();
    }

    public function test_uploading_chunks_is_throttled_but_leaves_room_for_a_backlog_after_an_outage(): void
    {
        $recording = LessonRecording::factory()->for($this->lesson)->create();

        // A backlog of 40 minutes (120 chunks) after a lost connection still drains in one go.
        $this->appendChunk($recording, 0, 'AAA')->assertOk();

        foreach (range(2, 120) as $attempt) {
            $this->appendChunk($recording, 0, 'AAA')->assertOk();
        }

        $this->appendChunk($recording, 0, 'AAA')->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame('AAA', LessonRecording::disk()->get($recording->path));
    }
}
