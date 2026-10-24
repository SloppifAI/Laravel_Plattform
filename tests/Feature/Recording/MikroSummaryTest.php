<?php

namespace Tests\Feature\Recording;

use App\Ai\Agents\LessonSummarizer;
use App\Ai\DemoRecordingAnalyst;
use App\Ai\RecordingAnalyst;
use App\Ai\SchoolAwareRecordingAnalyst;
use App\Ai\SdkRecordingAnalyst;
use App\Models\LessonRecording;
use App\Models\RecordingConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;
use Tests\TestCase;

/**
 * "Zusammenfassung erstellen": the reviewed text is pseudonymised, analysed and handed back; nothing is stored.
 */
class MikroSummaryTest extends TestCase
{
    use RecordingFixtures;
    use RefreshDatabase;

    private const string TEXT = 'Heute sprechen wir über die Fotosynthese. Die Fotosynthese findet in den Chloroplasten statt. Chloroplasten enthalten Chlorophyll, und das Chlorophyll fängt das Licht ein. Anna Müller fragt: Warum brauchen Pflanzen eigentlich Licht? Ben Schneider erklärt, dass Sauerstoff als Abfallprodukt entsteht. Was passiert nachts? Dann läuft keine Fotosynthese.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLesson();
        $this->actingAs($this->teacher);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotOfTheDatabase(): array
    {
        return [
            'sessions' => DB::table('lesson_recordings')->get()->all(),
            'notes' => $this->lesson->fresh()->notes,
            'lessons' => DB::table('lessons')->get()->all(),
            'files' => $this->filesOnDisk(),
        ];
    }

    public function test_without_the_school_ai_the_demo_summarises_and_the_names_are_replaced(): void
    {
        $recording = $this->startedSession();
        $before = $this->snapshotOfTheDatabase();

        $response = $this->postJson(route('recordings.analyse', $recording), ['text' => self::TEXT])->assertOk();

        $this->assertInstanceOf(SchoolAwareRecordingAnalyst::class, app(RecordingAnalyst::class));
        $this->assertSame(['summary', 'key_terms', 'board_outline', 'class_questions', 'suggested_notes'], array_keys($response->json('analysis')));
        $this->assertSame('Fotosynthese', $response->json('analysis.key_terms.0'));
        $this->assertContains('Was passiert nachts?', $response->json('analysis.class_questions'));

        $content = json_encode($response->json(), JSON_UNESCAPED_UNICODE);

        foreach (['Anna', 'Müller', 'Ben', 'Schneider'] as $name) {
            $this->assertStringNotContainsString($name, $content, "{$name} must not be in the result");
        }

        $this->assertStringContainsString('Schüler:in A', $content);
        $this->assertEquals($before, $this->snapshotOfTheDatabase());
    }

    public function test_with_the_school_ai_the_sdk_gets_text_only_and_no_names(): void
    {
        $this->enableAi();
        $this->lesson->update(['topic' => 'Fotosynthese bei Ben']);
        $recording = $this->startedSession();
        $before = $this->snapshotOfTheDatabase();

        LessonSummarizer::fake([[
            'summary' => 'Es ging um die Fotosynthese.',
            'key_terms' => ['Fotosynthese', 'Chlorophyll', ' '],
            'board_outline' => ['Licht', 'Wasser'],
            'class_questions' => ['Was passiert nachts?'],
            'suggested_notes' => 'Zusammenfassung: Fotosynthese.',
        ]])->preventStrayPrompts();

        $response = $this->postJson(route('recordings.analyse', $recording), ['text' => self::TEXT])->assertOk();

        $response->assertExactJson(['analysis' => [
            'summary' => 'Es ging um die Fotosynthese.',
            'key_terms' => ['Fotosynthese', 'Chlorophyll'],
            'board_outline' => ['Licht', 'Wasser'],
            'class_questions' => ['Was passiert nachts?'],
            'suggested_notes' => 'Zusammenfassung: Fotosynthese.',
        ]]);

        LessonSummarizer::assertPromptedTimes(1);
        LessonSummarizer::assertPrompted(function (AgentPrompt $prompt): bool {
            foreach (['Anna', 'Müller', 'Ben', 'Schneider', 'Cem', 'Yilmaz', $this->teacher->name] as $name) {
                $this->assertStringNotContainsString($name, $prompt->prompt, "{$name} must not reach the AI");
            }

            $this->assertStringContainsString('Schüler:in A fragt', $prompt->prompt);
            $this->assertStringContainsString('Thema: Fotosynthese bei Schüler:in B', $prompt->prompt);
            $this->assertEmpty($prompt->attachments);

            return true;
        });

        $this->assertEquals($before, $this->snapshotOfTheDatabase());
    }

    public function test_with_the_school_ai_every_present_student_needs_the_ai_analysis_consent(): void
    {
        $this->enableAi();
        $recording = $this->startedSession();
        LessonSummarizer::fake()->preventStrayPrompts();

        RecordingConsent::query()->where('student_id', $this->students[2]->id)->update(['purposes' => ['audio', 'transcript']]);

        $this->postJson(route('recordings.analyse', $recording), ['text' => self::TEXT])->assertStatus(409);

        LessonSummarizer::assertNeverPrompted();
    }

    public function test_a_failing_ai_leaves_everything_as_it_was(): void
    {
        $this->enableAi();
        $recording = $this->startedSession();
        $before = $this->snapshotOfTheDatabase();

        LessonSummarizer::fake(fn (): never => throw new RuntimeException('Provider down'));

        $this->postJson(route('recordings.analyse', $recording), ['text' => self::TEXT])
            ->assertStatus(502)
            ->assertJson(['message' => 'Die Zusammenfassung konnte nicht erstellt werden. Deine Notizen sind noch da. Bitte versuche es gleich noch einmal.']);

        $this->assertEquals($before, $this->snapshotOfTheDatabase());
    }

    public function test_the_text_is_required_and_limited(): void
    {
        $recording = $this->startedSession();
        config(['lernplattform.recordings.max_text_chars' => 50]);

        $this->postJson(route('recordings.analyse', $recording))->assertUnprocessable()->assertJsonValidationErrors('text');
        $this->postJson(route('recordings.analyse', $recording), ['text' => '   '])->assertUnprocessable()->assertJsonValidationErrors('text');
        $this->postJson(route('recordings.analyse', $recording), ['text' => str_repeat('a', 51)])->assertUnprocessable()->assertJsonValidationErrors('text');
    }

    public function test_nobody_else_can_ask_for_a_summary(): void
    {
        $this->enableAi();
        $recording = $this->startedSession();
        LessonSummarizer::fake()->preventStrayPrompts();

        foreach ($this->outsiders() as $who => $user) {
            $this->actingAs($user)->postJson(route('recordings.analyse', $recording), ['text' => self::TEXT])->assertForbidden("{$who} must not ask for a summary");
        }

        LessonSummarizer::assertNeverPrompted();
    }

    public function test_the_demo_analyst_derives_everything_from_the_text(): void
    {
        $result = (new DemoRecordingAnalyst)->analyse($this->lesson, self::TEXT);

        $this->assertSame('Fotosynthese', $result['key_terms'][0]);
        $this->assertContains('Licht', $result['key_terms']);
        $this->assertLessThanOrEqual(6, count($result['key_terms']));
        $this->assertSame(['Warum brauchen Pflanzen eigentlich Licht?', 'Was passiert nachts?'], $result['class_questions']);
        $this->assertCount(count($result['key_terms']), $result['board_outline']);
        $this->assertStringStartsWith('Fotosynthese: ', $result['board_outline'][0]);

        // The summary only quotes sentences of the text, in their order, and leaves the questions out.
        $this->assertNotSame('', $result['summary']);
        $positions = array_map(fn (string $sentence): int|false => strpos(self::TEXT, $sentence), preg_split('/(?<=[.!?])\s+/u', $result['summary']));
        $this->assertNotContains(false, $positions);
        $this->assertSame($positions, collect($positions)->sort()->values()->all());
        $this->assertStringNotContainsString('?', $result['summary']);

        $this->assertStringContainsString("Zusammenfassung:\n".$result['summary'], $result['suggested_notes']);
        $this->assertStringContainsString('Kernbegriffe: Fotosynthese', $result['suggested_notes']);
        $this->assertStringContainsString("Fragen aus der Stunde:\n- Warum brauchen Pflanzen eigentlich Licht?", $result['suggested_notes']);

        // Deterministic.
        $this->assertSame($result, (new DemoRecordingAnalyst)->analyse($this->lesson, '  '.self::TEXT."\n\n"));
    }

    public function test_the_demo_analyst_copes_with_text_without_punctuation_and_with_nothing(): void
    {
        $unpunctuated = implode(' ', array_fill(0, 12, 'wir besprechen heute die Zellteilung und die Zellkerne genau'));
        $result = (new DemoRecordingAnalyst)->analyse($this->lesson, $unpunctuated);

        $this->assertContains('Zellteilung', $result['key_terms']);
        $this->assertNotSame('', $result['summary']);

        $this->assertSame(
            ['summary' => '', 'key_terms' => [], 'board_outline' => [], 'class_questions' => [], 'suggested_notes' => ''],
            (new DemoRecordingAnalyst)->analyse($this->lesson, '   '),
        );
    }

    public function test_the_sdk_analyst_clamps_what_the_ai_returns(): void
    {
        LessonSummarizer::fake([[
            'summary' => '  Kurz.  ',
            'key_terms' => array_map(fn (int $number): string => "Begriff {$number}", range(1, 15)),
            'board_outline' => array_map(fn (int $number): string => "Punkt {$number}", range(1, 15)),
            'class_questions' => array_map(fn (int $number): string => "Frage {$number}?", range(1, 15)),
            'suggested_notes' => 'Notizen',
        ]]);

        $result = app(SdkRecordingAnalyst::class)->analyse($this->lesson, 'Text');

        $this->assertSame('Kurz.', $result['summary']);
        $this->assertCount(LessonSummarizer::MAX_TERMS, $result['key_terms']);
        $this->assertCount(LessonSummarizer::MAX_OUTLINE, $result['board_outline']);
        $this->assertCount(LessonSummarizer::MAX_QUESTIONS, $result['class_questions']);
    }

    public function test_a_summary_never_creates_a_session(): void
    {
        $recording = $this->startedSession();

        $this->postJson(route('recordings.analyse', $recording), ['text' => self::TEXT])->assertOk();

        $this->assertSame(1, LessonRecording::count());
    }
}
