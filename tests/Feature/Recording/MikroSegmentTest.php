<?php

namespace Tests\Feature\Recording;

use App\Ai\DemoTranscriber;
use App\Ai\SchoolAwareTranscriber;
use App\Ai\Transcriber;
use App\Models\RecordingConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Transcription;
use RuntimeException;
use Tests\TestCase;

/**
 * Sound segments: recognised through the SDK (faked here, never a real provider) from memory, in the same request.
 * No sound is ever written to a disk and no text stays on the server.
 */
class MikroSegmentTest extends TestCase
{
    use RecordingFixtures;
    use RefreshDatabase;

    private const string TEXT = 'Heute sprechen wir über die Fotosynthese. Anna Müller fragt: Warum brauchen Pflanzen Licht?';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLesson();
        $this->actingAs($this->teacher);
    }

    public function test_a_segment_is_recognised_through_the_sdk_without_touching_a_disk(): void
    {
        $this->enableAi();
        $recording = $this->startedSession();
        $seen = [];

        Transcription::fake(function (TranscriptionPrompt $prompt) use (&$seen): string {
            $seen = [
                'content' => $prompt->audio->content(),
                'mime' => $prompt->audio->mimeType(),
                'language' => $prompt->language,
                'files' => $this->filesOnDisk(),
            ];

            return self::TEXT;
        });

        $this->postSegment($recording, 'segment-bytes', 'audio/webm;codecs=opus')->assertOk()->assertExactJson(['text' => self::TEXT]);

        // The provider got the bytes from memory; there was no file while it worked (a request that dies leaves nothing), and there is none afterwards.
        $this->assertSame('segment-bytes', $seen['content']);
        $this->assertSame('audio/webm', $seen['mime']);
        $this->assertSame('de', $seen['language']);
        $this->assertSame([], $seen['files']);
        $this->assertSame([], $this->filesOnDisk());

        Transcription::assertGenerated(fn (TranscriptionPrompt $prompt): bool => $prompt->language === 'de' && ! $prompt->isDiarized());
        $this->assertTrue($recording->fresh()->audio_sent_to_ai);
    }

    public function test_every_segment_returns_its_own_text_in_order(): void
    {
        $this->enableAi();
        $recording = $this->startedSession();
        Transcription::fake(['Erster Teil.', 'Zweiter Teil.']);

        $this->postSegment($recording)->assertOk()->assertJson(['text' => 'Erster Teil.']);
        $this->postSegment($recording)->assertOk()->assertJson(['text' => 'Zweiter Teil.']);

        $this->assertSame([], $this->filesOnDisk());
    }

    public function test_nothing_is_left_on_a_disk_when_the_recognition_fails(): void
    {
        $this->enableAi();
        $recording = $this->startedSession();
        $files = null;

        Transcription::fake(function () use (&$files): never {
            $files = $this->filesOnDisk();

            throw new RuntimeException('Provider down');
        });

        $this->postSegment($recording)->assertStatus(502)->assertJson(['message' => 'Ein Abschnitt konnte nicht mitgeschrieben werden.']);

        $this->assertSame([], $files);
        $this->assertSame([], $this->filesOnDisk());
        $this->assertNull(optional($recording->fresh())->finished_at);
    }

    public function test_the_demo_transcriber_looks_at_nothing(): void
    {
        $this->assertInstanceOf(SchoolAwareTranscriber::class, app(Transcriber::class));
        $this->assertNull((new DemoTranscriber)->transcribe($this->lesson, 'bytes', 'audio/webm'));
        $this->assertNull(app(Transcriber::class)->transcribe($this->lesson, 'bytes', 'audio/webm'));
    }

    public function test_a_school_without_ai_takes_no_sound_at_all(): void
    {
        $recording = $this->startedSession();
        Transcription::fake()->preventStrayTranscriptions();

        $this->postSegment($recording)->assertStatus(409)->assertJsonPath('message', 'Die KI ist für deine Schule ausgeschaltet, deshalb wird nichts mehr mitgeschrieben.');

        Transcription::assertNothingGenerated();
        $this->assertSame([], $this->filesOnDisk());
        $this->assertFalse($recording->fresh()->audio_sent_to_ai);
    }

    public function test_without_the_platform_switch_nothing_goes_to_the_ai_even_for_an_opted_in_school(): void
    {
        $this->enableAi();
        $recording = $this->startedSession();
        config(['lernplattform.ai.enabled' => false]);
        Transcription::fake()->preventStrayTranscriptions();

        $this->postSegment($recording)->assertStatus(409);

        Transcription::assertNothingGenerated();
        $this->assertFalse($recording->fresh()->audio_sent_to_ai);
    }

    public function test_the_ai_switched_off_during_the_lesson_stops_the_uploads_instead_of_taking_sound_for_nothing(): void
    {
        $this->enableAi();
        $recording = $this->startedSession();
        $calls = 0;
        Transcription::fake(function () use (&$calls): string {
            $calls++;

            return 'Erster Teil.';
        });

        $this->postSegment($recording)->assertOk()->assertJson(['text' => 'Erster Teil.']);

        $this->schoolClass->school->forceFill(['ai_enabled_at' => null])->save();

        // The browser turns the microphone off on this answer; nothing was read, kept or sent.
        $this->postSegment($recording)->assertStatus(409);
        $this->postSegment($recording)->assertStatus(409);

        $this->assertSame(1, $calls);
        $this->assertSame([], $this->filesOnDisk());
    }

    public function test_a_consent_revoked_during_the_lesson_stops_the_next_segment_before_anything_leaves(): void
    {
        $this->enableAi();
        $recording = $this->startedSession();
        Transcription::fake()->preventStrayTranscriptions();

        RecordingConsent::query()->where('student_id', $this->students[1]->id)->update(['revoked_at' => now()]);

        $response = $this->postSegment($recording)->assertStatus(409);

        $this->assertStringContainsString('1 von 3', $response->json('message'));
        Transcription::assertNothingGenerated();
        $this->assertSame([], $this->filesOnDisk());
        $this->assertFalse($recording->fresh()->audio_sent_to_ai);
    }

    public function test_the_school_switching_recordings_off_stops_the_session(): void
    {
        $this->enableAi();
        $recording = $this->startedSession();
        Transcription::fake()->preventStrayTranscriptions();
        $this->switchRecordings($this->schoolClass->school, false);

        $this->postSegment($recording)->assertStatus(409);

        Transcription::assertNothingGenerated();
    }

    public function test_a_finished_session_takes_no_more_segments(): void
    {
        $this->enableAi();
        $recording = $this->startedSession();
        $this->postJson(route('recordings.finish', $recording), ['duration_seconds' => 30])->assertOk();
        Transcription::fake()->preventStrayTranscriptions();

        $this->postSegment($recording)->assertStatus(409);

        Transcription::assertNothingGenerated();
        $this->assertSame([], $this->filesOnDisk());
    }

    public function test_segments_of_the_wrong_type_size_or_content_are_refused(): void
    {
        $this->enableAi();
        $recording = $this->startedSession();
        Transcription::fake()->preventStrayTranscriptions();
        config(['lernplattform.recordings.max_segment_kb' => 1]);

        $this->postSegment($recording, 'abc', 'video/webm')->assertStatus(415);
        $this->postSegment($recording, 'abc', 'application/octet-stream')->assertStatus(415);
        $this->postSegment($recording, '')->assertUnprocessable();
        $this->postSegment($recording, str_repeat('a', 1025))->assertStatus(413);

        Transcription::assertNothingGenerated();
        $this->assertSame([], $this->filesOnDisk());
    }

    public function test_nobody_else_can_send_segments(): void
    {
        $this->enableAi();
        $recording = $this->startedSession();
        Transcription::fake()->preventStrayTranscriptions();

        foreach ($this->outsiders() as $who => $user) {
            $this->actingAs($user);
            $this->postSegment($recording)->assertForbidden("{$who} must not send segments");
        }

        Transcription::assertNothingGenerated();
        $this->assertSame([], $this->filesOnDisk());
    }

    public function test_no_text_and_no_sound_is_stored_on_the_server(): void
    {
        $this->enableAi();
        $recording = $this->startedSession();
        Transcription::fake([self::TEXT]);

        $this->postSegment($recording, 'unique-audio-marker')->assertOk();
        $this->postJson(route('recordings.finish', $recording), ['duration_seconds' => 30])->assertOk();

        $stored = json_encode(DB::table('lesson_recordings')->get());

        $this->assertStringNotContainsString('Fotosynthese', $stored);
        $this->assertStringNotContainsString('unique-audio-marker', $stored);
        $this->assertSame([], $this->filesOnDisk());
        $this->assertSame('Tafelbild: Licht, Wasser, CO2', $this->lesson->fresh()->notes);
        $this->assertEqualsCanonicalizing(
            ['id', 'lesson_id', 'teacher_id', 'started_at', 'finished_at', 'duration_seconds', 'present_student_ids', 'attendance_confirmed_at', 'consent_form_version', 'purposes', 'audio_sent_to_ai', 'created_at', 'updated_at'],
            array_keys($recording->fresh()->getAttributes()),
        );
    }
}
