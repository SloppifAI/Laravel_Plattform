<?php

namespace Tests\Feature\Ai;

use App\Ai\ClaudeLessonAssistant;
use App\Ai\LessonAssistant;
use App\MessageAuthor;
use App\Models\Competency;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Submission;
use App\Models\User;
use App\QuestionType;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ClaudeLessonAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'services.anthropic.enabled' => true,
            'services.anthropic.key' => 'test-key',
            'services.anthropic.model' => 'claude-opus-5-5',
        ]);
    }

    public function test_it_is_bound_when_the_connection_is_enabled(): void
    {
        $this->assertInstanceOf(ClaudeLessonAssistant::class, app(LessonAssistant::class));
    }

    public function test_drafting_sends_a_structured_output_request_with_worksheets_and_parses_the_questions(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('worksheets/arbeitsblatt.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
        $competency = Competency::factory()->create(['subject' => 'Biologie']);
        $lesson = Lesson::factory()->create([
            'subject' => 'Biologie',
            'topic' => 'Fotosynthese',
            'attachments' => ['worksheets/arbeitsblatt.pdf', 'worksheets/geloescht.png'],
        ]);

        Http::fake(['api.anthropic.com/*' => $this->messageResponse([
            'summary' => 'Pflanzen bauen mit Licht Glucose auf.',
            'questions' => [
                ['type' => 'comprehension', 'prompt' => 'Was ist Chlorophyll?', 'expected_answer' => 'Der grüne Farbstoff.', 'competency_id' => $competency->id],
                ['type' => 'reflection', 'prompt' => 'Was hat dich am Versuch überrascht?', 'expected_answer' => 'Individuell.', 'competency_id' => 999],
            ],
        ])]);

        $draft = app(LessonAssistant::class)->draftHomework($lesson);

        $this->assertSame('Pflanzen bauen mit Licht Glucose auf.', $draft['summary']);
        $this->assertSame(QuestionType::Comprehension, $draft['questions'][0]['type']);
        $this->assertSame($competency->id, $draft['questions'][0]['competency_id']);
        $this->assertSame(QuestionType::Reflection, $draft['questions'][1]['type']);
        $this->assertNull($draft['questions'][1]['competency_id']);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $body = $request->data();
            $content = $body['messages'][0]['content'];

            $this->assertSame('https://api.anthropic.com/v1/messages', $request->url());
            $this->assertTrue($request->hasHeader('x-api-key', 'test-key'));
            $this->assertTrue($request->hasHeader('anthropic-version', '2026-10-21'));
            $this->assertTrue($request->hasHeader('anthropic-beta', 'server-side-fallback-2026-07-01'));
            $this->assertSame('claude-opus-5-5', $body['model']);
            $this->assertSame(16000, $body['max_tokens']);
            $this->assertSame('default', $body['fallbacks']);
            $this->assertSame('medium', $body['output_config']['effort']);
            $this->assertSame('json_schema', $body['output_config']['format']['type']);
            $this->assertFalse($body['output_config']['format']['schema']['additionalProperties']);
            $this->assertSame(['summary', 'questions'], $body['output_config']['format']['schema']['required']);
            $this->assertSame([], array_intersect(['temperature', 'top_p', 'top_k', 'thinking'], array_keys($body)));
            $this->assertCount(2, $content);
            $this->assertSame('document', $content[0]['type']);
            $this->assertSame('application/pdf', $content[0]['source']['media_type']);
            $this->assertSame('text', $content[1]['type']);
            $this->assertStringContainsString('Fotosynthese', $content[1]['text']);
            $this->assertStringContainsString('- ID', $content[1]['text']);

            return true;
        });
    }

    public function test_evaluation_sends_the_texts_but_never_the_students_identity(): void
    {
        $student = User::factory()->student()->create([
            'name' => 'Zacharias Quellenberg',
            'username' => 'zquellenberg',
            'email' => 'zacharias.quellenberg@example.org',
        ]);
        $lesson = Lesson::factory()->create();
        $question = Question::factory()->for($lesson)->create();
        $submission = Submission::factory()->for($lesson)->for($student, 'student')->create(['summary' => 'Meine eigene Zusammenfassung.']);
        $submission->answers()->create(['question_id' => $question->id, 'answer' => 'Meine Antwort auf die Frage.']);

        Http::fake(['api.anthropic.com/*' => $this->messageResponse([
            'understanding_score' => 140,
            'feedback' => 'Gut gemacht!',
            'gaps' => ['Eins', 'Zwei', 'Drei', 'Vier'],
            'answers' => [
                ['question_id' => $question->id, 'score' => 80, 'feedback' => 'Richtig.'],
                ['question_id' => 999999, 'score' => 50, 'feedback' => 'Gibt es nicht.'],
            ],
        ])]);

        $evaluation = app(LessonAssistant::class)->evaluateSubmission($submission);

        $this->assertSame(100, $evaluation['understanding_score']);
        $this->assertSame('Gut gemacht!', $evaluation['feedback']);
        $this->assertSame(['Eins', 'Zwei', 'Drei'], $evaluation['gaps']);
        $this->assertSame([$question->id => ['score' => 80, 'feedback' => 'Richtig.']], $evaluation['answers']);

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();

            $this->assertStringContainsString('Meine eigene Zusammenfassung.', $body);
            $this->assertStringContainsString('Meine Antwort auf die Frage.', $body);
            $this->assertStringNotContainsString('Zacharias', $body);
            $this->assertStringNotContainsString('Quellenberg', $body);
            $this->assertStringNotContainsStringIgnoringCase('zquellenberg', $body);

            return true;
        });
    }

    public function test_follow_up_and_assessment_play_frieda_without_the_students_identity(): void
    {
        $student = User::factory()->student()->create(['name' => 'Zacharias Quellenberg', 'username' => 'zquellenberg']);
        $session = ExplanationSession::factory()->for($student, 'student')->create(['topic' => 'Fotosynthese']);
        $session->messages()->create(['author' => MessageAuthor::Student, 'content' => 'Pflanzen machen aus Licht Zucker.']);

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->messageBody(['question' => '  Woher kommt das Licht?  ']))
            ->push($this->messageBody(['understanding_score' => -5, 'feedback' => 'Danke, das war verständlich!']))]);

        $assistant = app(LessonAssistant::class);

        $this->assertSame('Woher kommt das Licht?', $assistant->askFollowUp($session));
        $this->assertSame(['understanding_score' => 0, 'feedback' => 'Danke, das war verständlich!'], $assistant->assessExplanation($session));

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            $this->assertStringContainsString('Tuto', $request['system']);
            $this->assertStringContainsString('Pflanzen machen aus Licht Zucker.', $request->body());
            $this->assertStringNotContainsString('Quellenberg', $request->body());

            return true;
        });
    }

    public function test_a_refusal_raises_an_error_instead_of_returning_content(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'type' => 'message',
            'role' => 'assistant',
            'content' => [],
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => null, 'explanation' => null],
        ])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('abgelehnt');

        app(LessonAssistant::class)->askFollowUp(ExplanationSession::factory()->create());
    }

    public function test_a_missing_api_key_fails_before_any_request(): void
    {
        config(['services.anthropic.key' => null]);
        Http::fake();

        try {
            app(LessonAssistant::class)->assessExplanation(ExplanationSession::factory()->create());
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('API-Schlüssel', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * A faked Messages API response whose text block holds the given JSON, after an (empty) thinking block.
     *
     * @param  array<string, mixed>  $result
     */
    private function messageResponse(array $result): PromiseInterface
    {
        return Http::response($this->messageBody($result));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function messageBody(array $result): array
    {
        return [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'test'],
                ['type' => 'text', 'text' => json_encode($result)],
            ],
            'stop_reason' => 'end_turn',
            'stop_details' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ];
    }
}
