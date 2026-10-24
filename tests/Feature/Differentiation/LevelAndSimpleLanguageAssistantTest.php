<?php

namespace Tests\Feature\Differentiation;

use App\Ai\Agents\QuestionDifferentiator;
use App\Ai\Agents\SubmissionEvaluator;
use App\Ai\Agents\TextSimplifier;
use App\Ai\DemoLessonAssistant;
use App\Ai\LessonAssistant;
use App\Ai\SdkLessonAssistant;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\QuestionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;
use Tests\Feature\Ai\EnablesSchoolAi;
use Tests\TestCase;

/**
 * differentiateQuestion() and simplify(): the offline demo logic, the real AI behind SDK fakes, and which of them answers.
 */
class LevelAndSimpleLanguageAssistantTest extends TestCase
{
    use EnablesSchoolAi;
    use RefreshDatabase;

    private const string PROMPT = 'Erkläre mit eigenen Worten, was man unter „Chlorophyll“ versteht und welche Bedeutung der Begriff für das Thema „Fotosynthese“ hat.';

    private const string SECRET = 'GEHEIMER ERWARTUNGSHORIZONT Glucose und Sauerstoff';

    private function demoQuestion(string $prompt = self::PROMPT, QuestionType $type = QuestionType::Comprehension): Question
    {
        return Question::factory()->create(['type' => $type, 'prompt' => $prompt, 'expected_answer' => self::SECRET]);
    }

    public function test_the_demo_cuts_a_long_question_into_a_short_one_with_a_hint_and_adds_a_reason_for_profi(): void
    {
        $levels = app(DemoLessonAssistant::class)->differentiateQuestion($this->demoQuestion());

        $this->assertSame('Erkläre mit eigenen Worten, was man unter „Chlorophyll“ versteht. Tipp: Schreib zwei bis drei kurze Sätze. Denk dabei an „Chlorophyll“.', $levels['basis']);
        $this->assertSame(self::PROMPT.' Erkläre zusätzlich, warum das so ist, und nenne ein Beispiel aus dem Alltag.', $levels['plus']);
    }

    public function test_the_demo_keeps_a_short_question_and_asks_for_a_transfer_depending_on_the_type(): void
    {
        $assistant = app(DemoLessonAssistant::class);

        $reflection = $assistant->differentiateQuestion($this->demoQuestion('Was hat dich im Versuch überrascht?', QuestionType::Reflection));
        $application = $assistant->differentiateQuestion($this->demoQuestion('Warum stellt man Pflanzen ans Fenster?', QuestionType::Application));

        $this->assertSame('Was hat dich im Versuch überrascht? Tipp: Schreib zwei bis drei kurze Sätze und schau in die Zusammenfassung deiner Lehrkraft.', $reflection['basis']);
        $this->assertStringEndsWith('Begründe außerdem, was du daraus für dich mitnimmst und was du nächstes Mal anders machen würdest.', $reflection['plus']);
        $this->assertSame('Warum stellt man Pflanzen ans Fenster? Tipp: Schreib zwei bis drei kurze Sätze und schau in die Zusammenfassung deiner Lehrkraft.', $application['basis']);
        $this->assertStringEndsWith('Begründe zusätzlich deine Lösung und überlege, in welcher anderen Situation dasselbe gilt.', $application['plus']);
    }

    public function test_the_demo_levels_are_deterministic_and_never_contain_the_expected_answer(): void
    {
        $question = $this->demoQuestion();
        $assistant = app(DemoLessonAssistant::class);

        $this->assertSame($assistant->differentiateQuestion($question), $assistant->differentiateQuestion($question));

        foreach ($assistant->differentiateQuestion($question) as $text) {
            $this->assertStringNotContainsString('GEHEIMER', $text);
            $this->assertStringNotContainsString('Glucose', $text);
        }
    }

    public function test_the_demo_writes_simple_language_with_short_sentences_everyday_words_and_the_same_lines(): void
    {
        $text = "Pflanzen nutzen Licht, um Zucker herzustellen; außerdem geben sie Sauerstoff ab, aber nur bei Licht und Wasser im Blatt. Das ist z. B. bei der Wasserpest gut zu sehen.\n\n- Eigentlich wichtig: Chlorophyll bzw. Blattgrün.\n- ca. 20 Blätter, die sozusagen zählen";

        $simple = app(DemoLessonAssistant::class)->simplify($text, Lesson::factory()->create());

        $this->assertSame(
            "Pflanzen nutzen Licht, um Zucker herzustellen. Auch geben sie Sauerstoff ab. Aber nur bei Licht und Wasser im Blatt. Das ist zum Beispiel bei der Wasserpest gut zu sehen.\n\n- Eigentlich wichtig: Chlorophyll oder Blattgrün.\n- etwa 20 Blätter, die zählen",
            $simple,
        );
        $this->assertSame($simple, app(DemoLessonAssistant::class)->simplify($text, Lesson::factory()->create()));
    }

    public function test_the_demo_leaves_a_text_that_is_simple_already_alone(): void
    {
        $text = 'Pflanzen brauchen Licht. Dann machen sie Zucker.';

        $this->assertSame($text, app(DemoLessonAssistant::class)->simplify($text, Lesson::factory()->create()));
    }

    public function test_the_sdk_asks_for_the_levels_without_names_the_expected_answer_or_the_notes(): void
    {
        $this->preventRealAiCalls();
        $teacher = User::factory()->teacher()->create(['name' => 'Berta Lehnhardt']);
        $schoolClass = SchoolClass::factory()->for($teacher, 'teacher')->create();
        $zacharias = User::factory()->student($schoolClass)->create(['name' => 'Zacharias Quellenberg', 'username' => 'zquellenberg', 'email' => 'zacharias.quellenberg@example.org']);
        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->create([
            'subject' => 'Biologie',
            'topic' => 'Fotosynthese',
            'summary' => 'Frau Lehnhardt fasst zusammen: Pflanzen bauen Zucker auf.',
            'notes' => 'GEHEIME NOTIZEN der Stunde mit Quellenberg',
        ]);
        $question = Question::factory()->for($lesson)->create(['type' => QuestionType::Application, 'prompt' => 'Was würde Zacharias im Dunkeln beobachten?', 'expected_answer' => self::SECRET]);
        QuestionDifferentiator::fake([['basis' => '  Leichter: Was passiert im Dunkeln?  ', 'plus' => 'Schwerer: Begründe, was im Dunkeln passiert.']]);

        $levels = app(SdkLessonAssistant::class)->differentiateQuestion($question);

        $this->assertSame(['basis' => 'Leichter: Was passiert im Dunkeln?', 'plus' => 'Schwerer: Begründe, was im Dunkeln passiert.'], $levels);
        QuestionDifferentiator::assertPrompted(function (AgentPrompt $prompt) use ($zacharias): bool {
            $text = $prompt->prompt;

            $this->assertSame('gemini', $prompt->provider->name());
            $this->assertStringContainsString('Fach: Biologie', $text);
            $this->assertStringContainsString('Thema: Fotosynthese', $text);
            $this->assertStringContainsString('Art der Frage: Anwendung', $text);
            $this->assertStringContainsString('Was würde Schüler:in', $text);
            $this->assertStringContainsString('Pflanzen bauen Zucker auf.', $text);
            $this->assertStringNotContainsString('Erwartungshorizont', $text);
            $this->assertStringNotContainsString('GEHEIM', $text);
            $this->assertStringNotContainsString('Glucose', $text);
            $this->assertStringContainsString('Erwartungshorizont', $prompt->agent->instructions());
            foreach (['Zacharias', 'Quellenberg', 'Lehnhardt', 'Berta', 'zquellenberg', $zacharias->email, (string) $zacharias->id.'"'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $text);
            }

            return true;
        });
    }

    public function test_the_sdk_levels_must_both_be_there(): void
    {
        $this->preventRealAiCalls();
        QuestionDifferentiator::fake([['basis' => 'Nur eine Stufe.', 'plus' => '  ']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('keine Stufen');

        app(SdkLessonAssistant::class)->differentiateQuestion($this->demoQuestion());
    }

    public function test_the_sdk_simplifies_a_text_without_names(): void
    {
        $this->preventRealAiCalls();
        $teacher = User::factory()->teacher()->create(['name' => 'Berta Lehnhardt']);
        $schoolClass = SchoolClass::factory()->for($teacher, 'teacher')->create();
        User::factory()->student($schoolClass)->create(['name' => 'Zacharias Quellenberg', 'username' => 'zquellenberg']);
        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->create(['subject' => 'Biologie', 'topic' => 'Fotosynthese']);
        TextSimplifier::fake(['  Pflanzen machen Zucker. Sie brauchen Licht.  ']);

        $simple = app(SdkLessonAssistant::class)->simplify('Zacharias Quellenberg hat gezeigt: Pflanzen bauen mithilfe von Lichtenergie Glucose auf. Frau Lehnhardt ergänzt das.', $lesson);

        $this->assertSame('Pflanzen machen Zucker. Sie brauchen Licht.', $simple);
        TextSimplifier::assertPrompted(function (AgentPrompt $prompt): bool {
            $this->assertStringContainsString('Thema: Fotosynthese', $prompt->prompt);
            $this->assertStringContainsString('Pflanzen bauen mithilfe von Lichtenergie Glucose auf.', $prompt->prompt);
            $this->assertStringContainsString('Schüler:in', $prompt->prompt);
            $this->assertStringContainsString('Einfacher Sprache', $prompt->agent->instructions());
            foreach (['Zacharias', 'Quellenberg', 'Lehnhardt', 'zquellenberg'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $prompt->prompt);
            }

            return true;
        });
    }

    public function test_an_empty_simple_text_and_provider_failures_are_errors(): void
    {
        $this->preventRealAiCalls();
        $lesson = Lesson::factory()->create();
        TextSimplifier::fake(['   ']);

        try {
            app(SdkLessonAssistant::class)->simplify('Ein Text.', $lesson);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Einfacher Sprache', $exception->getMessage());
        }

        TextSimplifier::fake(fn () => throw RateLimitedException::forProvider('gemini'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('überlastet');

        app(SdkLessonAssistant::class)->simplify('Ein Text.', $lesson);
    }

    public function test_the_evaluation_prompt_names_the_chosen_level_and_shows_that_levels_text(): void
    {
        $this->preventRealAiCalls();
        $lesson = Lesson::factory()->create(['notes' => 'Notizen der Stunde.']);
        $basis = Question::factory()->for($lesson)->create(['prompt' => 'Standardfrage A?', 'prompt_basis' => 'Einstiegsfrage A?', 'prompt_plus' => 'Profifrage A?', 'expected_answer' => 'Der Erwartungshorizont bleibt.']);
        $plus = Question::factory()->for($lesson)->create(['prompt' => 'Standardfrage B?', 'prompt_basis' => 'Einstiegsfrage B?', 'prompt_plus' => 'Profifrage B?']);
        $plain = Question::factory()->for($lesson)->create(['prompt' => 'Frage ohne Stufen?']);
        $submission = Submission::factory()->for($lesson)->create(['summary' => 'Meine Zusammenfassung.']);
        $submission->answers()->create(['question_id' => $basis->id, 'answer' => 'Antwort A', 'level' => 'basis']);
        $submission->answers()->create(['question_id' => $plus->id, 'answer' => 'Antwort B', 'level' => 'plus']);
        $submission->answers()->create(['question_id' => $plain->id, 'answer' => 'Antwort C']);
        SubmissionEvaluator::fake([['understanding_score' => 70, 'feedback' => 'Gut.', 'gaps' => [], 'answers' => []]]);

        app(SdkLessonAssistant::class)->evaluateSubmission($submission);

        SubmissionEvaluator::assertPrompted(function (AgentPrompt $prompt) use ($basis, $plain): bool {
            $text = $prompt->prompt;

            $this->assertStringContainsString("Frage-ID {$basis->id} (", $text);
            $this->assertStringContainsString(', Stufe: Einstieg): Einstiegsfrage A?', $text);
            $this->assertStringContainsString(', Stufe: Profi): Profifrage B?', $text);
            $this->assertStringContainsString("Frage-ID {$plain->id} (", $text);
            $this->assertStringContainsString('): Frage ohne Stufen?', $text);
            $this->assertSame(2, substr_count($text, 'Stufe:'));
            $this->assertStringNotContainsString('Standardfrage', $text);
            $this->assertStringContainsString('Erwartungshorizont: Der Erwartungshorizont bleibt.', $text);
            $this->assertStringContainsString('Stufe', $prompt->agent->instructions());

            return true;
        });
    }

    public function test_the_school_decides_whether_the_demo_or_the_sdk_writes_levels_and_simple_texts(): void
    {
        $this->preventRealAiCalls();
        $aiSchool = $this->enableAiFor(School::factory()->create());
        $demoSchool = School::factory()->create();
        QuestionDifferentiator::fake([['basis' => 'Basis vom SDK.', 'plus' => 'Plus vom SDK.']]);
        TextSimplifier::fake(['Einfach vom SDK.']);

        $lessonIn = fn (School $school): Lesson => Lesson::factory()->for(SchoolClass::factory()->for($school)->create())->create();
        $demoLesson = $lessonIn($demoSchool);
        $aiLesson = $lessonIn($aiSchool);
        $demoQuestion = Question::factory()->for($demoLesson)->create(['prompt' => 'Was ist Chlorophyll?']);
        $aiQuestion = Question::factory()->for($aiLesson)->create(['prompt' => 'Was ist Chlorophyll?']);
        $assistant = app(LessonAssistant::class);

        $this->assertEquals(app(DemoLessonAssistant::class)->differentiateQuestion($demoQuestion), $assistant->differentiateQuestion($demoQuestion));
        $this->assertSame('Pflanzen brauchen Licht.', $assistant->simplify('Pflanzen brauchen Licht.', $demoLesson));
        QuestionDifferentiator::assertNeverPrompted();
        TextSimplifier::assertNeverPrompted();

        $this->assertSame(['basis' => 'Basis vom SDK.', 'plus' => 'Plus vom SDK.'], $assistant->differentiateQuestion($aiQuestion));
        $this->assertSame('Einfach vom SDK.', $assistant->simplify('Pflanzen brauchen Licht.', $aiLesson));
        QuestionDifferentiator::assertPromptedTimes(1);
        TextSimplifier::assertPromptedTimes(1);
    }
}
