<?php

namespace Tests\Feature\Mistakes;

use App\Ai\Agents\SubmissionEvaluator;
use App\Ai\DemoLessonAssistant;
use App\Ai\DemoMistakeClassifier;
use App\Ai\LessonAssistant;
use App\Ai\MistakeClassification;
use App\Ai\MistakeClassifier;
use App\Filament\Pages\LearningProgress;
use App\Filament\Pages\ParentReport;
use App\Filament\Pages\PrivacyDocuments;
use App\Filament\Pages\WorkOnHomework;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\RelationManagers\SubmissionsRelationManager;
use App\MistakeSource;
use App\MistakeType;
use App\Models\Answer;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\Support\LearningInsights;
use App\Support\MistakeDiagnosis;
use App\Support\PersonalDataExport;
use App\Support\PracticeTasks;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\ViewAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Feature\Ai\EnablesSchoolAi;
use Tests\TestCase;

/**
 * Fehlerdiagnose (issue #3): the kind of mistake of an answer below the threshold, only ever a hint. One test per
 * acceptance criterion AC-1 to AC-9; AC-10 lives in PrivacyAndAiTest and LegalPagesTest.
 */
class MistakeDiagnosisTest extends TestCase
{
    use EnablesSchoolAi;
    use RefreshDatabase;

    private const string EXPECTED = 'Bei der Fotosynthese entsteht im Licht Sauerstoff, der in kleinen Bläschen aus den Blättern der Wasserpest aufsteigt.';

    private SchoolClass $schoolClass;

    private User $teacher;

    private User $student;

    private Competency $competency;

    private Lesson $lesson;

    private Question $question;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolClass = SchoolClass::factory()->create(['name' => 'Klasse 7b']);
        $this->teacher = $this->schoolClass->teacher;
        $this->student = User::factory()->student($this->schoolClass)->create(['name' => 'Zacharias Quellenberg']);
        $this->competency = Competency::factory()->create(['subject' => 'Biologie', 'name' => 'Fotosynthese erklären']);
        $this->lesson = Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')->publishedFor([$this->student])
            ->create(['topic' => 'Fotosynthese', 'summary' => null]);
        $this->question = Question::factory()->for($this->lesson)->for($this->competency)->create([
            'prompt' => 'Warum steigen an der Wasserpest im Licht Bläschen auf?',
            'expected_answer' => self::EXPECTED,
            'position' => 1,
        ]);
    }

    private function submission(string $answer, ?int $score = null, ?User $student = null, ?Lesson $lesson = null): Submission
    {
        $lesson ??= $this->lesson;
        $submission = Submission::factory()->for($lesson)->for($student ?? $this->student, 'student')->evaluated(40)->create();
        Answer::factory()->for($submission)->for($lesson->questions->first() ?? $this->question)->create(['answer' => $answer, 'score' => $score]);

        return $submission;
    }

    /**
     * Binds a classifier that always says the same, or throws.
     */
    private function classifierSaying(MistakeClassification|RuntimeException|null $result): void
    {
        $this->app->instance(MistakeClassifier::class, new class($result) implements MistakeClassifier
        {
            public function __construct(private readonly MistakeClassification|RuntimeException|null $result) {}

            public function classify(Question $question, Answer $answer, array $evaluation): ?MistakeClassification
            {
                if ($this->result instanceof RuntimeException) {
                    throw $this->result;
                }

                return $this->result;
            }
        });
    }

    private function answerOf(Submission $submission): Answer
    {
        return $submission->answers()->sole();
    }

    /**
     * @return array<int, array{score: int, feedback: string}>
     */
    private function evaluation(int $score): array
    {
        return [$this->question->id => ['score' => $score, 'feedback' => 'Rückmeldung.']];
    }

    private function classify(string $answer, ?string $expected = self::EXPECTED): ?MistakeType
    {
        $this->question->expected_answer = $expected;

        return app(DemoMistakeClassifier::class)->classify($this->question, new Answer(['answer' => $answer]), ['score' => 10, 'feedback' => ''])?->type;
    }

    public function test_ac1_only_answers_below_the_threshold_get_a_mistake_type(): void
    {
        $this->assertSame(80, config('lernplattform.mistakes.threshold'));

        $atThreshold = $this->submission('Sauerstoff.', 80);
        MistakeDiagnosis::record($atThreshold, $this->evaluation(80));
        $this->assertNull($this->answerOf($atThreshold)->mistake_type);

        $below = $this->submission('Sauerstoff.', 79, User::factory()->student($this->schoolClass)->create());
        MistakeDiagnosis::record($below, $this->evaluation(79));
        $this->assertSame(MistakeType::Incomplete, $this->answerOf($below)->mistake_type);
        $this->assertSame(MistakeSource::Demo, $this->answerOf($below)->mistake_source);
        // The demo rules say nothing about how sure they are; only an AI does.
        $this->assertNull($this->answerOf($below)->mistake_confidence);
    }

    public function test_ac2_the_demo_classifier_follows_its_rules(): void
    {
        $this->assertSame(MistakeType::Empty, $this->classify(''));
        $this->assertSame(MistakeType::Empty, $this->classify("   \n  "));
        $this->assertSame(MistakeType::Incomplete, $this->classify('Sauerstoff.'));
        $this->assertSame(MistakeType::OffTopic, $this->classify('Napoleon verlor die Schlacht bei Waterloo im Jahr achtzehnhundertfünfzehn gegen Wellington und Blücher.'));
        $this->assertNull($this->classify('Im Licht macht die Pflanze Fotosynthese, dabei entsteht Sauerstoff, der als Bläschen nach oben steigt.'));
        // Without an Erwartungshorizont there is nothing to compare: only "no attempt" is still certain.
        $this->assertNull($this->classify('Sauerstoff.', ''));
    }

    public function test_ac3_a_reading_below_the_minimum_confidence_is_not_stored(): void
    {
        $this->assertSame(0.6, config('lernplattform.mistakes.min_confidence'));

        $this->classifierSaying(new MistakeClassification(MistakeType::Concept, 0.59, MistakeSource::Ai));
        $unsure = $this->submission('Weil die Pflanze atmet.', 30);
        MistakeDiagnosis::record($unsure, $this->evaluation(30));
        $this->assertNull($this->answerOf($unsure)->mistake_type);
        $this->assertNull($this->answerOf($unsure)->mistake_source);

        $this->classifierSaying(new MistakeClassification(MistakeType::Concept, 0.61, MistakeSource::Ai));
        $sure = $this->submission('Weil die Pflanze atmet.', 30, User::factory()->student($this->schoolClass)->create());
        MistakeDiagnosis::record($sure, $this->evaluation(30));
        $this->assertSame(MistakeType::Concept, $this->answerOf($sure)->mistake_type);
        $this->assertSame(MistakeSource::Ai, $this->answerOf($sure)->mistake_source);
        $this->assertSame(61, $this->answerOf($sure)->mistake_confidence);
    }

    public function test_ac4_a_failing_classifier_never_stops_the_evaluation(): void
    {
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('evaluateSubmission')->andReturn([
            'understanding_score' => 35,
            'feedback' => 'Da geht noch mehr.',
            'gaps' => [],
            'answers' => [$this->question->id => ['score' => 20, 'feedback' => 'Schau noch einmal.']],
        ]));
        $this->classifierSaying(new RuntimeException('Zeitüberschreitung beim Klassifizieren'));

        Livewire::actingAs($this->student)->test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->fillForm([
                'summary' => 'In der Stunde ging es darum, wie Pflanzen im Licht Zucker herstellen und dabei etwas an das Wasser abgeben.',
                'answers' => [$this->question->id => 'Weil die Pflanze atmet.'],
            ])
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertNotified('Done!');

        $submission = Submission::query()->sole();
        $this->assertTrue($submission->isEvaluated());
        $this->assertSame(35, $submission->understanding_score);
        $this->assertSame(20, $this->answerOf($submission)->score);
        $this->assertNull($this->answerOf($submission)->mistake_type);
    }

    public function test_ac5_the_ai_names_the_mistake_in_the_same_pseudonymised_request(): void
    {
        $this->preventRealAiCalls();
        $this->enableAiFor($this->schoolClass->school);
        SubmissionEvaluator::fake([[
            'understanding_score' => 45,
            'feedback' => 'Ein Anfang.',
            'gaps' => [],
            'answers' => [['question_id' => $this->question->id, 'score' => 30, 'feedback' => 'Da fehlt noch etwas.', 'mistake_type' => 'incomplete', 'mistake_confidence' => 85]],
        ]]);

        Livewire::actingAs($this->student)->test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->fillForm([
                'summary' => 'Ich, Zacharias Quellenberg, habe gelernt, dass Pflanzen im Licht Zucker herstellen und Gas abgeben.',
                'answers' => [$this->question->id => 'Zacharias Quellenberg meint: wegen Sauerstoff.'],
            ])
            ->call('submit')
            ->assertHasNoFormErrors();

        SubmissionEvaluator::assertPrompted(function (AgentPrompt $prompt): bool {
            $this->assertStringContainsString('wegen Sauerstoff', $prompt->prompt);
            $this->assertStringContainsString('Erwartungshorizont: '.self::EXPECTED, $prompt->prompt);
            $this->assertStringNotContainsString('Zacharias', $prompt->prompt);
            $this->assertStringNotContainsString('Quellenberg', $prompt->prompt);

            return true;
        });

        $answer = $this->answerOf(Submission::query()->sole());
        // The score is the AI's, untouched by the mistake type.
        $this->assertSame(30, $answer->score);
        $this->assertSame(MistakeType::Incomplete, $answer->mistake_type);
        $this->assertSame(MistakeSource::Ai, $answer->mistake_source);
        $this->assertSame(85, $answer->mistake_confidence);
        $this->assertStringContainsString('mistake_type', (new SubmissionEvaluator)->instructions());
        $this->assertStringContainsString('nie die Person', (new SubmissionEvaluator)->instructions());
    }

    public function test_ac6_the_teachers_correction_wins_and_survives_a_new_evaluation(): void
    {
        $submission = $this->submission('Weil die Pflanze atmet.', 30);
        $answer = $this->answerOf($submission);
        $answer->update(['mistake_type' => MistakeType::Concept, 'mistake_confidence' => 70, 'mistake_source' => MistakeSource::Ai]);

        $this->actingAs($this->teacher);
        Livewire::test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->mountAction(TestAction::make(ViewAction::class)->table($submission))
            ->assertMountedActionModalSee(['Core idea not clear yet', 'AI hint, may be wrong']);

        Livewire::test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->callAction(TestAction::make('correctMistakes')->table($submission), ['mistakes' => [$answer->id => MistakeType::Procedure->value]])
            ->assertHasNoFormErrors();

        $answer->refresh();
        $this->assertSame(MistakeType::Procedure, $answer->mistake_type);
        $this->assertSame(MistakeSource::Teacher, $answer->mistake_source);
        $this->assertNull($answer->mistake_confidence);
        $this->assertSame(30, $answer->score);

        $this->classifierSaying(new MistakeClassification(MistakeType::OffTopic, 0.99, MistakeSource::Ai));
        MistakeDiagnosis::record($submission->fresh(), $this->evaluation(30));

        $this->assertSame(MistakeType::Procedure, $answer->refresh()->mistake_type);
        $this->assertSame(MistakeSource::Teacher, $answer->mistake_source);

        Livewire::test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->mountAction(TestAction::make(ViewAction::class)->table($submission))
            ->assertMountedActionModalSee(['Wrong step', 'Classified by a teacher']);
    }

    public function test_ac7_the_mistake_type_changes_no_score_and_stays_out_of_the_parent_report(): void
    {
        $submission = $this->submission('Weil die Pflanze atmet.', 30);
        $before = [
            LearningInsights::competencyAverages(LearningInsights::scoredAnswers([$this->student->id])),
            LearningInsights::supportNeeds(LearningInsights::scoredAnswers([$this->student->id])),
        ];

        $this->classifierSaying(new MistakeClassification(MistakeType::Concept, 0.9, MistakeSource::Ai));
        MistakeDiagnosis::record($submission, $this->evaluation(30));

        $this->assertSame(MistakeType::Concept, $this->answerOf($submission)->mistake_type);
        $this->assertSame(30, $this->answerOf($submission)->score);
        $this->assertSame(40, $submission->fresh()->understanding_score);
        $this->assertSame($before, [
            LearningInsights::competencyAverages(LearningInsights::scoredAnswers([$this->student->id])),
            LearningInsights::supportNeeds(LearningInsights::scoredAnswers([$this->student->id])),
        ]);

        $this->actingAs($this->teacher)->get(ParentReport::getUrl(['student' => $this->student]))
            ->assertOk()
            ->assertSee('Fotosynthese erklären')
            ->assertDontSee(['Core idea not clear yet', 'Type of mistake', 'AI hint, may be wrong', MistakeType::Concept->studentHint()]);
    }

    public function test_ac7_the_student_reads_an_encouraging_tip_and_never_the_label(): void
    {
        $submission = $this->submission('Sauerstoff.', 30);
        $this->answerOf($submission)->update(['mistake_type' => MistakeType::Incomplete, 'mistake_source' => MistakeSource::Demo]);

        $this->actingAs($this->student)->get(WorkOnHomework::getUrl(['lesson' => $this->lesson]))
            ->assertOk()
            ->assertSee(['A tip for you', 'Good start! Part of the answer is still missing.'])
            ->assertDontSee(['Type of mistake', 'AI hint, may be wrong', 'Wrong!']);
    }

    public function test_ac8_teachers_see_mistake_types_only_for_students_they_may_see(): void
    {
        $this->answerOf($this->submission('Sauerstoff.', 30))->update(['mistake_type' => MistakeType::Procedure, 'mistake_source' => MistakeSource::Ai]);

        // A class of a colleague in the same school, and a class of another school.
        $otherClass = SchoolClass::factory()->create(['school_id' => $this->schoolClass->school_id, 'name' => 'Klasse 8a']);
        $otherLesson = Lesson::factory()->for($otherClass)->for($this->teacher, 'teacher')->create();
        Question::factory()->for($otherLesson)->for($this->competency)->create();
        $otherStudent = User::factory()->student($otherClass)->create();
        $this->answerOf($this->submission('Etwas anderes.', 20, $otherStudent, $otherLesson))->update(['mistake_type' => MistakeType::Concept, 'mistake_source' => MistakeSource::Ai]);
        $foreignTeacher = SchoolClass::factory()->create()->teacher;

        Livewire::actingAs($this->teacher)->test(LearningProgress::class)
            ->assertSet('schoolClassId', $this->schoolClass->id)
            ->assertSee(['Types of mistakes per competency', 'Fotosynthese erklären', '1× Wrong step'])
            ->assertDontSee('Core idea not clear yet');

        $this->assertSame([$this->competency->id => ['procedure' => 1]], MistakeDiagnosis::counts(LearningInsights::visibleStudents($this->teacher)->pluck('id')->all()));
        $this->assertSame([], MistakeDiagnosis::counts(LearningInsights::visibleStudents($foreignTeacher)->pluck('id')->all()));

        // The teacher held the lesson in 8a without teaching the class: the hand-ins stay readable, the mistake types do not.
        $this->assertFalse(LearningInsights::canView($this->teacher, $otherStudent));
        $otherSubmission = $otherStudent->submissions()->sole();
        $this->actingAs($this->teacher);
        Livewire::test(SubmissionsRelationManager::class, ['ownerRecord' => $otherLesson, 'pageClass' => EditLesson::class])
            ->assertActionHidden(TestAction::make('correctMistakes')->table($otherSubmission))
            ->mountAction(TestAction::make(ViewAction::class)->table($otherSubmission))
            ->assertMountedActionModalDontSee(['Core idea not clear yet', 'AI hint, may be wrong']);

        Livewire::actingAs($foreignTeacher)->test(LearningProgress::class)
            ->set('schoolClassId', $this->schoolClass->id)
            ->assertForbidden();
    }

    public function test_ac9_the_practice_task_gets_the_most_frequent_mistake_type_of_the_group(): void
    {
        $classmate = User::factory()->student($this->schoolClass)->create();
        $third = User::factory()->student($this->schoolClass)->create();
        foreach ([[$this->student, MistakeType::Procedure], [$classmate, MistakeType::Procedure], [$third, MistakeType::Concept]] as [$student, $type]) {
            $this->answerOf($this->submission('Etwas.', 20, $student))->update(['mistake_type' => $type, 'mistake_source' => MistakeSource::Ai]);
        }
        $support = LearningInsights::competencySupport(LearningInsights::supportNeeds(LearningInsights::scoredAnswers([$this->student->id, $classmate->id, $third->id])), $this->competency->id);

        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('draftPracticeTask')
            ->withArgs(fn (Competency $competency, string $subject, Collection $lessons, array $gaps, ?MistakeType $mistakeType): bool => $mistakeType === MistakeType::Procedure)
            ->andReturn(['summary' => 'Hier übst du.', 'questions' => []]));

        PracticeTasks::create($this->teacher, $this->schoolClass, $this->competency, $support, $support['student_ids']);
    }

    public function test_ac9_the_demo_assistant_builds_a_different_task_for_concept_and_procedure(): void
    {
        $this->lesson->update(['notes' => 'Pflanzen nehmen Licht auf. Im Chlorophyll entsteht Zucker. Dabei wird Sauerstoff frei. Die Wasserpest zeigt das mit Bläschen.']);
        $draft = fn (?MistakeType $type): array => app(DemoLessonAssistant::class)->draftPracticeTask($this->competency, 'Biologie', collect([$this->lesson->fresh()]), ['Gehe noch einmal auf „Chlorophyll“ ein.'], $type);

        $concept = $draft(MistakeType::Concept);
        $procedure = $draft(MistakeType::Procedure);
        $none = $draft(null);

        $this->assertStringStartsWith('Zuerst die Grundidee:', $concept['questions'][0]['prompt']);
        $this->assertStringStartsWith('Ein Beispiel in Teilschritten: 1. ', $procedure['questions'][0]['prompt']);
        $this->assertStringContainsString('Wir fangen bei der Grundidee an.', $concept['summary']);
        $this->assertStringContainsString('Ein Beispiel in Teilschritten zeigt dir den Weg.', $procedure['summary']);
        $this->assertNotSame($concept['questions'][0]['prompt'], $none['questions'][0]['prompt']);
        // Only the warm-up changes: the rest of the task is the same practice.
        $this->assertSame(array_slice($none['questions'], 1), array_slice($procedure['questions'], 1));
    }

    public function test_the_record_of_processing_names_the_mistake_type(): void
    {
        $this->actingAs(User::factory()->for($this->schoolClass->school)->admin()->create())
            ->get(PrivacyDocuments::getUrl(['document' => 'verzeichnis']))
            ->assertOk()
            ->assertSee('Einschätzung von Abgaben mit der Fehlerart einer Antwort als Hinweis')
            ->assertSee('Einschätzungen samt Fehlerart einer Antwort mit ihrer Quelle')
            ->assertSee('Die Fehlerart ordnet nur die Antwort ein, nie die Person');
    }

    public function test_the_student_export_carries_the_mistake_type_of_each_answer(): void
    {
        $this->answerOf($this->submission('Sauerstoff.', 30))->update(['mistake_type' => MistakeType::Incomplete, 'mistake_confidence' => 85, 'mistake_source' => MistakeSource::Ai]);

        $answer = PersonalDataExport::for($this->student)['abgaben'][0]['antworten'][0];

        // A German document, although this suite runs in English.
        $this->assertSame('Unvollständig', $answer['fehlerart']);
        $this->assertSame(85, $answer['fehlerart_sicherheit']);
        $this->assertSame('KI', $answer['fehlerart_quelle']);
    }
}
