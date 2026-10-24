<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\ExplanationAssessor;
use App\Ai\Agents\FollowUpAsker;
use App\Ai\Agents\HomeworkDrafter;
use App\Ai\Agents\PracticeTaskDrafter;
use App\Ai\Agents\SubmissionEvaluator;
use App\Ai\LessonAssistant;
use App\Ai\SdkLessonAssistant;
use App\MessageAuthor;
use App\Models\Competency;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\QuestionType;
use App\Support\PracticeTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Prompts\AgentPrompt;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * The real AI behind the LessonAssistant, with the SDK's agent fakes: no request ever leaves the test.
 */
class SdkLessonAssistantTest extends TestCase
{
    use EnablesSchoolAi;
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private User $teacher;

    private User $zacharias;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preventRealAiCalls();

        $this->teacher = User::factory()->teacher()->create(['name' => 'Berta Lehnhardt']);
        $this->schoolClass = SchoolClass::factory()->for($this->teacher, 'teacher')->create();
        $this->zacharias = User::factory()->student($this->schoolClass)->create(['name' => 'Zacharias Quellenberg', 'username' => 'zquellenberg', 'email' => 'zacharias.quellenberg@example.org']);
        User::factory()->student($this->schoolClass)->create(['name' => 'Amelie Rosenthal']);
    }

    private function assistant(): SdkLessonAssistant
    {
        return app(SdkLessonAssistant::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lesson(array $attributes = []): Lesson
    {
        return Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')->create($attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function homeworkAnswer(?int $competencyId = null): array
    {
        return [
            'summary' => 'Pflanzen bauen mit Licht Glucose auf.',
            'questions' => [
                ['type' => 'comprehension', 'prompt' => 'Was ist Chlorophyll?', 'expected_answer' => 'Der grüne Farbstoff.', 'competency_id' => $competencyId],
                ['type' => 'comprehension', 'prompt' => 'Wie hängen Licht und Glucose zusammen?', 'expected_answer' => 'Licht liefert die Energie.', 'competency_id' => 999999],
                ['type' => 'application', 'prompt' => 'Warum stellt man Pflanzen ans Fenster?', 'expected_answer' => 'Sie brauchen Licht.', 'competency_id' => null],
                ['type' => 'application', 'prompt' => 'Was passiert im Dunkeln?', 'expected_answer' => 'Keine Fotosynthese.', 'competency_id' => $competencyId],
                ['type' => 'reflection', 'prompt' => 'Was hat dich am Versuch vom 21.10.2026 überrascht?', 'expected_answer' => 'Individuell.', 'competency_id' => $competencyId],
            ],
        ];
    }

    public function test_the_homework_draft_has_five_questions_and_maps_them_to_known_competencies(): void
    {
        $competency = Competency::factory()->create(['subject' => 'Biologie']);
        HomeworkDrafter::fake([$this->homeworkAnswer($competency->id)]);

        $draft = $this->assistant()->draftHomework($this->lesson(['subject' => 'Biologie']));

        $this->assertSame('Pflanzen bauen mit Licht Glucose auf.', $draft['summary']);
        $this->assertCount(5, $draft['questions']);
        $this->assertSame(
            [QuestionType::Comprehension, QuestionType::Comprehension, QuestionType::Application, QuestionType::Application, QuestionType::Reflection],
            array_column($draft['questions'], 'type'),
        );
        $this->assertSame('Was ist Chlorophyll?', $draft['questions'][0]['prompt']);
        $this->assertSame('Der grüne Farbstoff.', $draft['questions'][0]['expected_answer']);
        // An unknown competency (999999) and a missing one become null.
        $this->assertSame([$competency->id, null, null, $competency->id, $competency->id], array_column($draft['questions'], 'competency_id'));
    }

    public function test_the_homework_request_is_pseudonymised_carries_the_worksheets_and_uses_the_configured_provider(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('worksheets/blatt.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
        Storage::disk('local')->put('worksheets/foto.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        Storage::disk('local')->put('worksheets/tabelle.csv', "a;b\n1;2\n");
        $competency = Competency::factory()->create(['subject' => 'Biologie', 'area' => 'Erkenntnisgewinnung', 'name' => 'Versuche deuten']);
        $lesson = $this->lesson([
            'subject' => 'Biologie',
            'topic' => 'Fotosynthese',
            'held_on' => '2026-10-21',
            'summary' => 'Frau Lehnhardt fasst zusammen.',
            'notes' => 'Zacharias Quellenberg hat die Wasserpest an der Tafel gezeigt. Amelie hat die Bläschen gezählt. Frau Lehnhardt lobt Quellenberg.',
            'attachments' => ['worksheets/blatt.pdf', 'worksheets/foto.png', 'worksheets/geloescht.png', 'worksheets/tabelle.csv'],
        ]);
        HomeworkDrafter::fake([$this->homeworkAnswer($competency->id)]);

        $this->assistant()->draftHomework($lesson);

        HomeworkDrafter::assertPrompted(function (AgentPrompt $prompt) use ($competency): bool {
            $text = $prompt->prompt;

            $this->assertSame('gemini', $prompt->provider->name());
            $this->assertSame('gemini-test-model', $prompt->model);
            $this->assertSame(120, $prompt->timeout);
            $this->assertStringContainsString('Thema: Fotosynthese', $text);
            $this->assertStringContainsString('Datum der Stunde: 21.10.2026', $text);
            $this->assertStringContainsString('Wasserpest an der Tafel', $text);
            $this->assertStringContainsString("- ID {$competency->id}: Erkenntnisgewinnung – Versuche deuten", $text);
            $this->assertStringContainsString('Lehrkraft', $text);
            $this->assertStringContainsString('Schüler:in', $text);
            foreach (['Zacharias', 'Quellenberg', 'Amelie', 'Rosenthal', 'Lehnhardt', 'Berta', 'zquellenberg', $this->zacharias->email, (string) $this->zacharias->id.'"'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $text);
            }
            // Photos and PDFs only: a missing file and a spreadsheet are skipped.
            $this->assertSame([StoredDocument::class, StoredImage::class], $prompt->attachments->map(fn (object $file): string => $file::class)->all());

            return true;
        });
    }

    public function test_the_agents_instructions_describe_the_five_questions_and_the_tutor(): void
    {
        $homework = (new HomeworkDrafter)->instructions();

        $this->assertStringContainsString('genau fünf Hausaufgabenfragen', $homework);
        $this->assertStringContainsString('zwei Verständnisfragen (comprehension), zwei Anwendungsfragen (application)', $homework);
        $this->assertStringContainsString('eine Reflexionsfrage (reflection)', $homework);
        $this->assertStringContainsString('„du“', $homework);
        $this->assertStringContainsString('Tuto', (new FollowUpAsker)->instructions());
        $this->assertStringContainsString('Tuto', (new ExplanationAssessor)->instructions());
    }

    public function test_the_homework_schema_demands_exactly_five_questions(): void
    {
        $schema = (new ObjectSchema((new HomeworkDrafter)->schema(new JsonSchemaTypeFactory)))->toSchema();

        $this->assertSame(5, $schema['properties']['questions']['minItems']);
        $this->assertSame(5, $schema['properties']['questions']['maxItems']);
        $this->assertEqualsCanonicalizing(['summary', 'questions'], $schema['required']);
    }

    public function test_unusable_questions_are_dropped_and_surplus_ones_cut(): void
    {
        $answer = $this->homeworkAnswer();
        array_splice($answer['questions'], 1, 0, [['type' => 'quiz', 'prompt' => 'Unbekannter Typ', 'expected_answer' => '', 'competency_id' => null]]);
        $answer['questions'][] = ['type' => 'reflection', 'prompt' => 'Sechste Frage, zu viel', 'expected_answer' => '', 'competency_id' => null];
        $answer['questions'][] = ['type' => 'reflection', 'prompt' => '   ', 'expected_answer' => '', 'competency_id' => null];
        HomeworkDrafter::fake([$answer]);

        $draft = $this->assistant()->draftHomework($this->lesson());

        $this->assertCount(5, $draft['questions']);
        $this->assertNotContains('Unbekannter Typ', array_column($draft['questions'], 'prompt'));
        $this->assertNotContains('Sechste Frage, zu viel', array_column($draft['questions'], 'prompt'));
    }

    public function test_a_draft_without_usable_questions_or_summary_is_an_error_instead_of_an_empty_homework(): void
    {
        HomeworkDrafter::fake([['summary' => 'Nichts.', 'questions' => []]]);

        try {
            $this->assistant()->draftHomework($this->lesson());
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('keine Fragen', $exception->getMessage());
        }

        HomeworkDrafter::fake([[...$this->homeworkAnswer(), 'summary' => '  ']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('keine Zusammenfassung');

        $this->assistant()->draftHomework($this->lesson());
    }

    public function test_an_unreadable_answer_is_an_error(): void
    {
        HomeworkDrafter::fake([[]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('konnte nicht gelesen werden');

        $this->assistant()->draftHomework($this->lesson());
    }

    public function test_a_practice_task_sends_competency_lessons_and_gaps_without_names_and_maps_every_question_to_the_competency(): void
    {
        $competency = Competency::factory()->create(['subject' => 'Biologie', 'area' => 'Erkenntnisgewinnung', 'name' => 'Versuche deuten', 'description' => 'Beobachtungen beschreiben und deuten']);
        $lesson = $this->lesson(['subject' => 'Biologie', 'topic' => 'Fotosynthese', 'held_on' => '2026-10-21', 'notes' => 'Wasserpest unter der Lampe: Zacharias hat Sauerstoffbläschen gezählt.']);
        PracticeTaskDrafter::fake([[
            'summary' => 'Hier übst du, Versuche zu deuten.',
            'questions' => [
                ['type' => 'comprehension', 'prompt' => 'Was zeigt der Versuch?', 'expected_answer' => 'Sauerstoff entsteht bei Licht.'],
                ['type' => 'comprehension', 'prompt' => 'Warum steigen Bläschen auf?', 'expected_answer' => 'Sauerstoff ist ein Gas.'],
                ['type' => 'application', 'prompt' => 'Plane einen eigenen Versuch.', 'expected_answer' => 'Eine Variable wird verändert.'],
                ['type' => 'comprehension', 'prompt' => 'Knobelaufgabe', 'expected_answer' => 'Individuell.'],
                ['type' => 'comprehension', 'prompt' => 'Fünfte Frage, zu viel', 'expected_answer' => 'Wird abgeschnitten.'],
            ],
        ]]);

        $draft = $this->assistant()->draftPracticeTask($competency, 'Biologie', collect([$lesson]), ['Beschreibe genauer, was Zacharias beobachtet.']);

        $this->assertSame('Hier übst du, Versuche zu deuten.', $draft['summary']);
        $this->assertCount(4, $draft['questions']);
        $this->assertSame(QuestionType::Application, $draft['questions'][2]['type']);
        $this->assertSame([$competency->id], array_values(array_unique(array_column($draft['questions'], 'competency_id'))));

        PracticeTaskDrafter::assertPrompted(function (AgentPrompt $prompt): bool {
            $text = $prompt->prompt;

            $this->assertStringContainsString('Fach: Biologie', $text);
            $this->assertStringContainsString('Erkenntnisgewinnung – Versuche deuten', $text);
            $this->assertStringContainsString('Beobachtungen beschreiben und deuten', $text);
            $this->assertStringContainsString('Datum der Stunde: 21.10.2026', $text);
            $this->assertStringContainsString('Wasserpest unter der Lampe', $text);
            $this->assertStringContainsString('- Beschreibe genauer, was Schüler:in', $text);
            $this->assertCount(0, $prompt->attachments);
            foreach (['Zacharias', 'Quellenberg', 'zquellenberg', 'zacharias.quellenberg@example.org'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $text);
            }

            return true;
        });
    }

    public function test_a_practice_task_sends_only_topic_and_date_of_a_lesson_whose_notes_were_withheld(): void
    {
        $competency = Competency::factory()->create(['subject' => 'Biologie']);
        $own = $this->lesson(['topic' => 'Fotosynthese', 'held_on' => '2026-10-21', 'notes' => 'Eigene Notizen zur Wasserpest.']);
        $colleagues = $this->lesson(['topic' => 'Zellaufbau', 'held_on' => '2026-10-22', 'notes' => 'GEHEIME NOTIZ von Kollegin']);
        $colleagues->notes = null;
        PracticeTaskDrafter::fake([['summary' => 'Hier übst du.', 'questions' => [['type' => 'comprehension', 'prompt' => 'Frage?', 'expected_answer' => 'Antwort.']]]]);

        $this->assistant()->draftPracticeTask($competency, 'Biologie', collect([$own, $colleagues]), []);

        PracticeTaskDrafter::assertPrompted(function (AgentPrompt $prompt): bool {
            $this->assertStringContainsString("Thema: Fotosynthese\nDatum der Stunde: 21.10.2026\nNotizen der Lehrkraft:\nEigene Notizen zur Wasserpest.", $prompt->prompt);
            $this->assertStringContainsString("Thema: Zellaufbau\nDatum der Stunde: 22.10.2026", $prompt->prompt);
            $this->assertSame(1, substr_count($prompt->prompt, 'Notizen der Lehrkraft'));
            $this->assertStringNotContainsString('GEHEIME NOTIZ', $prompt->prompt);

            return true;
        });
    }

    public function test_a_practice_task_without_questions_is_an_error_instead_of_an_empty_task(): void
    {
        PracticeTaskDrafter::fake([['summary' => 'Nichts.', 'questions' => []]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('keine Übungsfragen');

        $this->assistant()->draftPracticeTask(Competency::factory()->create(), 'Biologie', collect(), []);
    }

    public function test_the_evaluation_is_clamped_and_never_names_the_student(): void
    {
        $lesson = $this->lesson(['notes' => 'Notizen der Stunde.']);
        $question = Question::factory()->for($lesson)->create(['prompt' => 'Erkläre, was Zacharias gezeigt hat.', 'expected_answer' => 'Die Wasserpest gibt Sauerstoff ab.']);
        $unanswered = Question::factory()->for($lesson)->create();
        $submission = Submission::factory()->for($lesson)->for($this->zacharias, 'student')->create(['summary' => 'Ich, Zacharias Quellenberg, fasse zusammen.']);
        $submission->answers()->create(['question_id' => $question->id, 'answer' => 'Amelie hat es gezeigt, ich habe zugesehen.']);
        SubmissionEvaluator::fake([[
            'understanding_score' => 140,
            'feedback' => '  Gut gemacht!  ',
            'gaps' => ['Eins', 'Zwei', 'Drei', 'Vier'],
            'answers' => [
                ['question_id' => $question->id, 'score' => 180, 'feedback' => 'Richtig.'],
                ['question_id' => $unanswered->id, 'score' => 50, 'feedback' => 'Es gibt keine Antwort dazu.'],
                ['question_id' => 999999, 'score' => 50, 'feedback' => 'Gibt es nicht.'],
            ],
        ]]);

        $evaluation = $this->assistant()->evaluateSubmission($submission);

        $this->assertSame(100, $evaluation['understanding_score']);
        $this->assertSame('Gut gemacht!', $evaluation['feedback']);
        $this->assertSame(['Eins', 'Zwei', 'Drei'], $evaluation['gaps']);
        // Only questions the student answered, with the score clamped to 100.
        $this->assertSame([$question->id => ['score' => 100, 'feedback' => 'Richtig.']], $evaluation['answers']);

        SubmissionEvaluator::assertPrompted(function (AgentPrompt $prompt) use ($question): bool {
            $text = $prompt->prompt;

            $this->assertStringContainsString("Frage-ID {$question->id} (", $text);
            $this->assertStringContainsString('Erwartungshorizont: Die Wasserpest gibt Sauerstoff ab.', $text);
            $this->assertStringContainsString('zusammen.', $text);
            $this->assertStringContainsString('zugesehen', $text);
            foreach (['Zacharias', 'Quellenberg', 'Amelie', 'zquellenberg', 'zacharias.quellenberg@example.org'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $text);
            }

            return true;
        });
    }

    public function test_a_student_who_left_the_class_is_pseudonymised_as_well(): void
    {
        $lesson = $this->lesson();
        $moved = User::factory()->student()->create(['name' => 'Wilhelmina Abendroth']);
        $submission = Submission::factory()->for($lesson)->for($moved, 'student')->create(['summary' => 'Wilhelmina Abendroth schreibt: Es ging um Licht.']);
        SubmissionEvaluator::fake([['understanding_score' => 70, 'feedback' => 'Gut.', 'gaps' => [], 'answers' => []]]);

        $this->assistant()->evaluateSubmission($submission);

        SubmissionEvaluator::assertPrompted(function (AgentPrompt $prompt): bool {
            $this->assertStringContainsString('Es ging um Licht.', $prompt->prompt);
            $this->assertStringNotContainsString('Wilhelmina', $prompt->prompt);
            $this->assertStringNotContainsString('Abendroth', $prompt->prompt);

            return true;
        });
    }

    public function test_no_teacher_of_the_class_is_named_when_an_administration_practice_task_is_drafted_evaluated_and_discussed(): void
    {
        $school = $this->enableAiFor($this->schoolClass->school);
        $admin = User::factory()->for($school)->admin()->create(['name' => 'Anton Verwaltung']);
        $held = User::factory()->teacher()->create(['name' => 'Karla Kowalski']);
        $assigned = User::factory()->teacher()->create(['name' => 'Dora Dachsbau']);
        $this->schoolClass->teachers()->attach($assigned);
        $competency = Competency::factory()->create(['subject' => 'Biologie', 'name' => 'Versuche deuten']);
        $first = Lesson::factory()->for($this->schoolClass)->for($held, 'teacher')->create(['notes' => 'Frau Kowalski zeigt den Versuch mit der Wasserpest.']);
        $second = Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')->create(['notes' => 'Frau Dachsbau und Frau Lehnhardt helfen beim Auswerten.']);
        PracticeTaskDrafter::fake([['summary' => 'Hier übst du.', 'questions' => [['type' => 'comprehension', 'prompt' => 'Was zeigt der Versuch?', 'expected_answer' => 'Sauerstoff.']]]]);
        SubmissionEvaluator::fake([['understanding_score' => 70, 'feedback' => 'Gut.', 'gaps' => [], 'answers' => []]]);
        FollowUpAsker::fake(['Und dann?']);
        $forbidden = ['Kowalski', 'Karla', 'Dachsbau', 'Dora', 'Lehnhardt', 'Berta'];

        $practice = PracticeTasks::create($admin, $this->schoolClass, $competency, ['student_ids' => [], 'lesson_ids' => [$first->id, $second->id]], [$this->zacharias->id]);

        // What is stored for the administration's task still quotes the colleagues; the AI must not see it.
        $this->assertTrue($practice->teacher->is($admin));
        $this->assertStringContainsString('Frau Kowalski zeigt den Versuch', $practice->notes);

        $submission = Submission::factory()->for($practice)->for($this->zacharias, 'student')->create(['summary' => 'Es ging um Licht.']);
        app(LessonAssistant::class)->evaluateSubmission($submission);
        $session = ExplanationSession::factory()->for($this->zacharias, 'student')->for($practice)->create();
        app(LessonAssistant::class)->askFollowUp($session);

        foreach ([PracticeTaskDrafter::class, SubmissionEvaluator::class, FollowUpAsker::class] as $agent) {
            $agent::assertPrompted(function (AgentPrompt $prompt) use ($forbidden): bool {
                $this->assertStringContainsString('Versuch', $prompt->prompt);
                foreach ($forbidden as $name) {
                    $this->assertStringNotContainsStringIgnoringCase($name, $prompt->prompt);
                }

                return true;
            });
        }
        SubmissionEvaluator::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Frau Lehrkraft zeigt den Versuch mit der Wasserpest.'));
    }

    public function test_no_provider_stores_requests_on_its_side_unless_the_operator_asks_for_it(): void
    {
        $this->assertFalse(config('ai.providers.openai.store'));
        $this->assertFalse(config('ai.providers.azure.store'));
    }

    public function test_the_follow_up_is_asked_as_tuto_and_the_assessment_is_clamped(): void
    {
        $lesson = $this->lesson(['topic' => 'Fotosynthese', 'notes' => 'Chlorophyll fängt Licht ein.']);
        $session = ExplanationSession::factory()->for($this->zacharias, 'student')->for($lesson)->create(['topic' => 'Fotosynthese']);
        $session->messages()->create(['author' => MessageAuthor::Tutor, 'content' => 'Hi, ich bin Tuto.']);
        $session->messages()->create(['author' => MessageAuthor::Student, 'content' => 'Zacharias erklärt: Pflanzen machen aus Licht Zucker, sagt Amelie.']);
        FollowUpAsker::fake(['  Woher kommt das Licht?  ']);
        ExplanationAssessor::fake([['understanding_score' => -5, 'feedback' => 'Danke, das war verständlich!'], ['understanding_score' => 250, 'feedback' => 'Super.']]);

        $this->assertSame('Woher kommt das Licht?', $this->assistant()->askFollowUp($session));
        $this->assertSame(['understanding_score' => 0, 'feedback' => 'Danke, das war verständlich!'], $this->assistant()->assessExplanation($session));
        $this->assertSame(100, $this->assistant()->assessExplanation($session)['understanding_score']);

        FollowUpAsker::assertPrompted(function (AgentPrompt $prompt): bool {
            $this->assertStringContainsString('Tuto', $prompt->agent->instructions());
            $this->assertStringContainsString('Thema der Erklärung: Fotosynthese', $prompt->prompt);
            $this->assertStringContainsString('Pflanzen machen aus Licht Zucker', $prompt->prompt);
            $this->assertStringContainsString('Chlorophyll fängt Licht ein.', $prompt->prompt);
            $this->assertStringContainsString('Tuto (du): Hi, ich bin Tuto.', $prompt->prompt);
            foreach (['Zacharias', 'Quellenberg', 'Amelie', 'zquellenberg'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $prompt->prompt);
            }

            return true;
        });
        ExplanationAssessor::assertPromptedTimes(2);
    }

    public function test_an_empty_follow_up_is_an_error(): void
    {
        FollowUpAsker::fake(['   ']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('keine Rückfrage');

        $this->assistant()->askFollowUp(ExplanationSession::factory()->for($this->zacharias, 'student')->create());
    }

    public function test_the_provider_and_model_come_from_the_configuration(): void
    {
        config(['lernplattform.ai.provider' => 'anthropic', 'lernplattform.ai.model' => null, 'lernplattform.ai.timeout' => 45]);
        FollowUpAsker::fake(['Und dann?']);

        $this->assistant()->askFollowUp(ExplanationSession::factory()->for($this->zacharias, 'student')->create());

        FollowUpAsker::assertPrompted(function (AgentPrompt $prompt): bool {
            $this->assertSame('anthropic', $prompt->provider->name());
            $this->assertSame(45, $prompt->timeout);
            // No model configured: the provider's own default.
            $this->assertSame($prompt->provider->defaultTextModel(), $prompt->model);

            return true;
        });
    }

    /**
     * @return array<string, array{AiException, string}>
     */
    public static function providerFailures(): array
    {
        return [
            'rate limit' => [RateLimitedException::forProvider('gemini'), 'überlastet'],
            'overloaded' => [ProviderOverloadedException::forProvider('gemini'), 'überlastet'],
            'no credit' => [InsufficientCreditsException::forProvider('gemini'), 'Guthaben'],
            'no connection' => [ProviderConnectionException::forProvider('gemini'), 'nicht erreichbar'],
            'other provider error' => [new AiException('Gemini Error: [400] kaputt'), 'nicht erreichbar'],
        ];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failures_become_a_runtime_exception_with_a_german_message(AiException $failure, string $expected): void
    {
        FollowUpAsker::fake(fn () => throw $failure);

        try {
            $this->assistant()->askFollowUp(ExplanationSession::factory()->for($this->zacharias, 'student')->create());
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($expected, $exception->getMessage());
            $this->assertSame($failure, $exception->getPrevious());
        }
    }
}
