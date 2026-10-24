<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\ExplanationAssessor;
use App\Ai\Agents\FollowUpAsker;
use App\Ai\Agents\HomeworkDrafter;
use App\Ai\Agents\PracticeTaskDrafter;
use App\Ai\Agents\SubmissionEvaluator;
use App\Ai\DemoLessonAssistant;
use App\Ai\LessonAssistant;
use App\Ai\SchoolAwareLessonAssistant;
use App\MessageAuthor;
use App\Models\Competency;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Exceptions\AiException;
use RuntimeException;
use Tests\TestCase;

/**
 * Which assistant answers: the SDK only for a school that opted in while the platform switch is on, otherwise the demo logic.
 */
class SchoolAwareLessonAssistantTest extends TestCase
{
    use EnablesSchoolAi;
    use RefreshDatabase;

    private School $aiSchool;

    private School $demoSchool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preventRealAiCalls();

        $this->aiSchool = $this->enableAiFor(School::factory()->create());
        $this->demoSchool = School::factory()->create();

        // Whatever the agents would be asked in a school without opt-in fails the test.
        foreach ([HomeworkDrafter::class, PracticeTaskDrafter::class, SubmissionEvaluator::class, FollowUpAsker::class, ExplanationAssessor::class] as $agent) {
            $agent::fake()->preventStrayPrompts();
        }
    }

    private function assistant(): LessonAssistant
    {
        return app(LessonAssistant::class);
    }

    private function lessonIn(School $school): Lesson
    {
        $teacher = User::factory()->teacher()->for($school)->create();

        return Lesson::factory()
            ->for(SchoolClass::factory()->for($school)->for($teacher, 'teacher')->create())
            ->for($teacher, 'teacher')
            ->create(['subject' => 'Biologie', 'topic' => 'Fotosynthese', 'notes' => 'Pflanzen bauen mit Lichtenergie Glucose auf.']);
    }

    private function sessionIn(School $school): ExplanationSession
    {
        $session = ExplanationSession::factory()->for(User::factory()->student(SchoolClass::factory()->for($school)->create())->create(), 'student')->create(['topic' => 'Fotosynthese']);
        $session->messages()->create(['author' => MessageAuthor::Student, 'content' => 'Pflanzen machen aus Licht Zucker.']);

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function homeworkAnswer(): array
    {
        $question = fn (string $type): array => ['type' => $type, 'prompt' => 'Frage vom SDK?', 'expected_answer' => 'Antwort.', 'competency_id' => null];

        return ['summary' => 'Zusammenfassung vom SDK.', 'questions' => [$question('comprehension'), $question('comprehension'), $question('application'), $question('application'), $question('reflection')]];
    }

    public function test_the_application_binds_the_school_aware_assistant(): void
    {
        $this->assertInstanceOf(SchoolAwareLessonAssistant::class, $this->assistant());
    }

    public function test_homework_drafts_follow_the_school_of_the_lesson(): void
    {
        $demoLesson = $this->lessonIn($this->demoSchool);

        $this->assertEquals(app(DemoLessonAssistant::class)->draftHomework($demoLesson), $this->assistant()->draftHomework($demoLesson));
        HomeworkDrafter::assertNeverPrompted();

        HomeworkDrafter::fake([$this->homeworkAnswer()]);

        $draft = $this->assistant()->draftHomework($this->lessonIn($this->aiSchool));

        $this->assertSame('Zusammenfassung vom SDK.', $draft['summary']);
        HomeworkDrafter::assertPromptedTimes(1);
    }

    public function test_practice_tasks_follow_the_school_of_the_source_lessons_or_else_of_the_signed_in_user(): void
    {
        $competency = Competency::factory()->create(['subject' => 'Biologie']);
        $demoLesson = $this->lessonIn($this->demoSchool);

        $this->assertEquals(
            app(DemoLessonAssistant::class)->draftPracticeTask($competency, 'Biologie', collect([$demoLesson]), []),
            $this->assistant()->draftPracticeTask($competency, 'Biologie', collect([$demoLesson]), []),
        );
        PracticeTaskDrafter::assertNeverPrompted();

        PracticeTaskDrafter::fake(fn () => ['summary' => 'Übung vom SDK.', 'questions' => [['type' => 'comprehension', 'prompt' => 'Frage?', 'expected_answer' => 'Antwort.']]]);

        $this->assertSame('Übung vom SDK.', $this->assistant()->draftPracticeTask($competency, 'Biologie', collect([$this->lessonIn($this->aiSchool)]), [])['summary']);
        PracticeTaskDrafter::assertPromptedTimes(1);

        // Without a source lesson the signed-in user's school decides; without anybody the demo logic answers.
        $this->assistant()->draftPracticeTask($competency, 'Fachübergreifend', collect(), []);
        PracticeTaskDrafter::assertPromptedTimes(1);

        $this->actingAs(User::factory()->teacher()->for($this->aiSchool)->create());
        $this->assistant()->draftPracticeTask($competency, 'Fachübergreifend', collect(), []);
        PracticeTaskDrafter::assertPromptedTimes(2);
    }

    public function test_evaluations_follow_the_school_of_the_lessons(): void
    {
        $demoLesson = $this->lessonIn($this->demoSchool);
        $demoSubmission = Submission::factory()->for($demoLesson)->for(User::factory()->student($demoLesson->schoolClass)->create(), 'student')->create(['summary' => 'Pflanzen bauen mit Licht Glucose auf.']);

        $this->assertEquals(app(DemoLessonAssistant::class)->evaluateSubmission($demoSubmission), $this->assistant()->evaluateSubmission($demoSubmission));
        SubmissionEvaluator::assertNeverPrompted();

        $aiLesson = $this->lessonIn($this->aiSchool);
        $aiSubmission = Submission::factory()->for($aiLesson)->for(User::factory()->student($aiLesson->schoolClass)->create(), 'student')->create();
        SubmissionEvaluator::fake([['understanding_score' => 77, 'feedback' => 'Vom SDK.', 'gaps' => [], 'answers' => []]]);

        $this->assertSame(77, $this->assistant()->evaluateSubmission($aiSubmission)['understanding_score']);
        SubmissionEvaluator::assertPromptedTimes(1);
    }

    public function test_the_explanation_conversation_follows_the_school_of_the_student(): void
    {
        $demoSession = $this->sessionIn($this->demoSchool);

        $this->assertEquals(app(DemoLessonAssistant::class)->askFollowUp($demoSession), $this->assistant()->askFollowUp($demoSession));
        $this->assertEquals(app(DemoLessonAssistant::class)->assessExplanation($demoSession), $this->assistant()->assessExplanation($demoSession));
        FollowUpAsker::assertNeverPrompted();
        ExplanationAssessor::assertNeverPrompted();

        FollowUpAsker::fake(['Woher kommt das Licht?']);
        ExplanationAssessor::fake([['understanding_score' => 64, 'feedback' => 'Vom SDK.']]);
        $aiSession = $this->sessionIn($this->aiSchool);

        $this->assertSame('Woher kommt das Licht?', $this->assistant()->askFollowUp($aiSession));
        $this->assertSame(['understanding_score' => 64, 'feedback' => 'Vom SDK.'], $this->assistant()->assessExplanation($aiSession));
    }

    public function test_the_platform_switch_turns_the_opted_in_school_back_to_the_demo_logic(): void
    {
        config(['lernplattform.ai.enabled' => false]);
        $lesson = $this->lessonIn($this->aiSchool);

        $this->assertTrue($this->aiSchool->ai_enabled_at !== null);
        $this->assertFalse($this->aiSchool->aiEnabled());
        $this->assertEquals(app(DemoLessonAssistant::class)->draftHomework($lesson), $this->assistant()->draftHomework($lesson));
        HomeworkDrafter::assertNeverPrompted();
    }

    public function test_a_school_that_switched_the_ai_off_or_lost_a_confirmation_gets_the_demo_logic(): void
    {
        $lesson = $this->lessonIn($this->aiSchool);

        $this->aiSchool->disableAi();
        $this->assertEquals(app(DemoLessonAssistant::class)->draftHomework($lesson), $this->assistant()->draftHomework($lesson));

        $this->aiSchool->forceFill(['ai_enabled_at' => now(), 'ai_checklist' => [...$this->aiSchool->ai_checklist, 'paid_tier' => false]])->save();
        $this->assertEquals(app(DemoLessonAssistant::class)->draftHomework($lesson), $this->assistant()->draftHomework($lesson));

        HomeworkDrafter::assertNeverPrompted();
    }

    public function test_a_failure_of_the_ai_is_not_hidden_behind_the_demo_logic(): void
    {
        HomeworkDrafter::fake(fn () => throw new AiException('Gemini Error: [500] kaputt'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nicht erreichbar');

        $this->assistant()->draftHomework($this->lessonIn($this->aiSchool));
    }
}
