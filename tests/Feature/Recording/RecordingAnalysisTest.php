<?php

namespace Tests\Feature\Recording;

use App\Ai\ClaudeRecordingAnalyst;
use App\Ai\DemoRecordingAnalyst;
use App\Ai\DemoTranscriber;
use App\Ai\RecordingAnalyst;
use App\Ai\SpeechToTextTranscriber;
use App\Ai\Transcriber;
use App\Ai\TranscriberException;
use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class RecordingAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private const string TRANSCRIPT = 'Heute sprechen wir über die Fotosynthese. Die Fotosynthese findet in den Chloroplasten statt. Chloroplasten enthalten Chlorophyll, und das Chlorophyll fängt das Licht ein. Anna Müller fragt: Warum brauchen Pflanzen eigentlich Licht? Pflanzen brauchen Licht, weil die Fotosynthese Lichtenergie in Zucker umwandelt. Katrin Meier erklärt, dass Sauerstoff als Abfallprodukt entsteht. Was passiert nachts? Dann läuft keine Fotosynthese.';

    private LessonRecording $recording;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $teacher = User::factory()->teacher()->create(['name' => 'Katrin Meier']);
        $schoolClass = SchoolClass::factory()->for($teacher, 'teacher')->create();
        User::factory()->student($schoolClass)->create(['name' => 'Anna Müller']);
        User::factory()->student($schoolClass)->create(['name' => 'Ben Schneider']);
        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->create(['subject' => 'Biologie', 'topic' => 'Fotosynthese bei Ben']);
        $this->recording = LessonRecording::factory()->for($lesson)->create();
    }

    public function test_the_demo_analyst_returns_the_documented_shape(): void
    {
        $result = (new DemoRecordingAnalyst)->analyse($this->recording, self::TRANSCRIPT);

        $this->assertSame(['summary', 'key_terms', 'board_outline', 'class_questions', 'suggested_notes'], array_keys($result));
        $this->assertIsString($result['summary']);
        $this->assertIsString($result['suggested_notes']);

        foreach (['key_terms', 'board_outline', 'class_questions'] as $list) {
            $this->assertTrue(array_is_list($result[$list]), $list.' is a list');
            $this->assertContainsOnly('string', $result[$list], true, $list.' holds strings');
        }
    }

    public function test_the_demo_analyst_derives_everything_from_the_transcript(): void
    {
        $result = (new DemoRecordingAnalyst)->analyse($this->recording, self::TRANSCRIPT);

        $this->assertSame('Fotosynthese', $result['key_terms'][0]);
        $this->assertContains('Licht', $result['key_terms']);
        $this->assertLessThanOrEqual(6, count($result['key_terms']));

        $this->assertSame(['Warum brauchen Pflanzen eigentlich Licht?', 'Was passiert nachts?'], $result['class_questions']);
        $this->assertCount(count($result['key_terms']), $result['board_outline']);
        $this->assertStringStartsWith('Fotosynthese: ', $result['board_outline'][0]);

        // The summary only quotes sentences of the transcript, in their order, and leaves the questions out.
        $this->assertNotSame('', $result['summary']);
        $positions = array_map(fn (string $sentence): int|false => strpos(self::TRANSCRIPT, $sentence), preg_split('/(?<=[.!?])\s+/u', $result['summary']));
        $this->assertNotContains(false, $positions);
        $this->assertSame($positions, collect($positions)->sort()->values()->all());
        $this->assertLessThanOrEqual(3, count($positions));
        $this->assertStringNotContainsString('?', $result['summary']);

        $this->assertStringContainsString("Zusammenfassung:\n".$result['summary'], $result['suggested_notes']);
        $this->assertStringContainsString('Kernbegriffe: Fotosynthese', $result['suggested_notes']);
        $this->assertStringContainsString("Fragen aus der Stunde:\n- Warum brauchen Pflanzen eigentlich Licht?", $result['suggested_notes']);
    }

    public function test_the_demo_analyst_is_deterministic_and_never_calls_out(): void
    {
        $first = (new DemoRecordingAnalyst)->analyse($this->recording, self::TRANSCRIPT);
        $second = (new DemoRecordingAnalyst)->analyse($this->recording, '  '.self::TRANSCRIPT."\n\n");

        $this->assertSame($first, $second);
        Http::assertNothingSent();
    }

    public function test_the_demo_analyst_copes_with_text_without_punctuation_and_with_nothing(): void
    {
        $unpunctuated = implode(' ', array_fill(0, 12, 'wir besprechen heute die Zellteilung und die Zellkerne genau'));
        $result = (new DemoRecordingAnalyst)->analyse($this->recording, $unpunctuated);

        $this->assertContains('Zellteilung', $result['key_terms']);
        $this->assertNotSame('', $result['summary']);

        $empty = (new DemoRecordingAnalyst)->analyse($this->recording, '   ');
        $this->assertSame(['summary' => '', 'key_terms' => [], 'board_outline' => [], 'class_questions' => [], 'suggested_notes' => ''], $empty);
    }

    public function test_the_analyst_follows_the_ai_switch(): void
    {
        config(['services.anthropic.enabled' => false]);
        $this->assertInstanceOf(DemoRecordingAnalyst::class, app(RecordingAnalyst::class));

        config(['services.anthropic.enabled' => true]);
        $this->assertInstanceOf(ClaudeRecordingAnalyst::class, app(RecordingAnalyst::class));
    }

    public function test_the_real_analyst_sends_text_only_and_no_names(): void
    {
        config(['services.anthropic.enabled' => true, 'services.anthropic.key' => 'test-key', 'services.anthropic.model' => 'claude-opus-5-5']);
        Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode([
            'summary' => 'Es ging um die Fotosynthese.',
            'key_terms' => ['Fotosynthese', 'Chlorophyll', ''],
            'board_outline' => ['Licht', 'Wasser'],
            'class_questions' => ['Was passiert nachts?'],
            'suggested_notes' => 'Zusammenfassung: Fotosynthese.',
        ])]]])]);

        $result = app(RecordingAnalyst::class)->analyse($this->recording, self::TRANSCRIPT);

        $this->assertSame([
            'summary' => 'Es ging um die Fotosynthese.',
            'key_terms' => ['Fotosynthese', 'Chlorophyll'],
            'board_outline' => ['Licht', 'Wasser'],
            'class_questions' => ['Was passiert nachts?'],
            'suggested_notes' => 'Zusammenfassung: Fotosynthese.',
        ], $result);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            $this->assertSame('https://api.anthropic.com/v1/messages', $request->url());
            $this->assertTrue($request->hasHeader('x-api-key', 'test-key'));

            foreach (['Anna', 'Müller', 'Ben', 'Schneider', 'Katrin', 'Meier'] as $name) {
                $this->assertStringNotContainsString($name, $body, "{$name} must not reach the AI");
            }

            $this->assertStringContainsString('Schüler:in A fragt', $body);
            $this->assertStringContainsString('Lehrkraft erklärt', $body);
            $this->assertStringContainsString('Thema: Fotosynthese bei Schüler:in B', $body);

            // Text blocks only: no audio, no file, no recording path.
            $content = $request->data()['messages'][0]['content'];
            $this->assertSame(['text'], array_unique(array_column($content, 'type')));
            $this->assertStringNotContainsString($this->recording->path, $body);
            $this->assertStringNotContainsString('base64', $body);
            $this->assertSame('json_schema', $request->data()['output_config']['format']['type']);

            return true;
        });
    }

    public function test_the_real_analyst_needs_a_key_and_reports_failures(): void
    {
        config(['services.anthropic.enabled' => true, 'services.anthropic.key' => null]);

        try {
            app(RecordingAnalyst::class)->analyse($this->recording, self::TRANSCRIPT);
            $this->fail('A missing key must be reported.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('API-Schlüssel', $exception->getMessage());
        }

        config(['services.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529)]);
        $this->expectException(RequestException::class);

        app(RecordingAnalyst::class)->analyse($this->recording, self::TRANSCRIPT);
    }

    public function test_the_real_analyst_rejects_an_unreadable_answer(): void
    {
        config(['services.anthropic.enabled' => true, 'services.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'keine JSON-Antwort']]])]);

        $this->expectException(RuntimeException::class);

        app(RecordingAnalyst::class)->analyse($this->recording, self::TRANSCRIPT);
    }

    public function test_the_transcriber_cannot_process_audio_until_it_is_enabled(): void
    {
        config(['services.transcription.enabled' => false]);
        $this->assertInstanceOf(DemoTranscriber::class, app(Transcriber::class));

        try {
            app(Transcriber::class)->transcribe($this->recording);
            $this->fail('The demo transcriber cannot transcribe.');
        } catch (TranscriberException $exception) {
            $this->assertStringContainsString('nicht verfügbar', $exception->getMessage());
        }

        config(['services.transcription.enabled' => true]);
        $this->assertInstanceOf(SpeechToTextTranscriber::class, app(Transcriber::class));
        $this->expectException(TranscriberException::class);

        app(Transcriber::class)->transcribe($this->recording);
    }

    public function test_no_transcription_ever_sends_anything(): void
    {
        foreach ([false, true] as $enabled) {
            config(['services.transcription.enabled' => $enabled]);

            try {
                app(Transcriber::class)->transcribe($this->recording);
            } catch (TranscriberException) {
                // Expected: neither implementation can transcribe yet.
            }
        }

        Http::assertNothingSent();
    }

    public function test_transcription_is_off_by_default(): void
    {
        $this->assertFalse(config('services.transcription.enabled'));
    }
}
