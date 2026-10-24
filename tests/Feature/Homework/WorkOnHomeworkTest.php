<?php

namespace Tests\Feature\Homework;

use App\Ai\LessonAssistant;
use App\Filament\Pages\ExplainIt;
use App\Filament\Pages\WorkOnHomework;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\QuestionType;
use App\UnderstandingLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class WorkOnHomeworkTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Lesson $lesson;

    private Question $comprehension;

    private Question $reflection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->student()->create();
        $this->lesson = Lesson::factory()->publishedFor([$this->student])->create([
            'summary' => 'Offizielle Zusammenfassung der Lehrkraft über die Fotosynthese.',
        ]);
        $this->comprehension = Question::factory()->for($this->lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => 'Was braucht eine Pflanze für die Fotosynthese?',
            'expected_answer' => 'GEHEIMER ERWARTUNGSHORIZONT EINS',
            'position' => 1,
        ]);
        $this->reflection = Question::factory()->for($this->lesson)->create([
            'type' => QuestionType::Reflection,
            'prompt' => 'Welches Experiment aus der Stunde hat dich überrascht?',
            'expected_answer' => 'GEHEIMER ERWARTUNGSHORIZONT ZWEI',
            'position' => 2,
        ]);

        $this->actingAs($this->student);
    }

    public function test_lessons_not_delegated_to_the_student_cannot_be_opened(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $forSomeoneElse = Lesson::factory()->for($schoolClass)->publishedFor([User::factory()->student($schoolClass)->create()])->create();
        $draft = Lesson::factory()->create();
        $draft->students()->attach($this->student);

        $this->get(WorkOnHomework::getUrl(['lesson' => $forSomeoneElse]))->assertForbidden();
        $this->get(WorkOnHomework::getUrl(['lesson' => $draft]))->assertForbidden();
        $this->get(WorkOnHomework::getUrl(['lesson' => 999999]))->assertNotFound();
    }

    public function test_homework_of_another_school_cannot_be_opened_or_listed(): void
    {
        $otherSchoolLesson = Lesson::factory()->publishedFor([User::factory()->student()->create()])->create();

        $this->get(WorkOnHomework::getUrl(['lesson' => $otherSchoolLesson]))->assertForbidden();
        $this->assertFalse($this->student->isMemberOf($otherSchoolLesson->school));
        $this->assertNotContains($otherSchoolLesson->id, Lesson::assignedTo($this->student)->pluck('id')->all());
    }

    public function test_open_homework_shows_the_teacher_summary_then_the_questions_then_the_own_summary(): void
    {
        $this->get(WorkOnHomework::getUrl(['lesson' => $this->lesson]))
            ->assertOk()
            ->assertSee('Lies zuerst die Zusammenfassung deiner Lehrkraft')
            ->assertSeeInOrder([
                'Zusammenfassung der Stunde',
                $this->lesson->summary,
                $this->comprehension->prompt,
                $this->reflection->prompt,
                'In deinen Worten',
                'Zusammenfassung in deinen Worten',
            ])
            ->assertSee([QuestionType::Comprehension->getLabel(), QuestionType::Reflection->getLabel()])
            ->assertSee(QuestionType::Reflection->getDescription())
            ->assertDontSee('GEHEIMER ERWARTUNGSHORIZONT');
    }

    public function test_the_own_summary_keeps_its_minimum_length(): void
    {
        $this->assertSame(80, WorkOnHomework::MINIMUM_SUMMARY_LENGTH);
        $this->assertSame(40, WorkOnHomework::MINIMUM_PRACTICE_SUMMARY_LENGTH);

        $this->get(WorkOnHomework::getUrl(['lesson' => $this->lesson]))
            ->assertSee('Mindestens 80 Zeichen');
    }

    public function test_a_practice_task_names_its_competency_above_the_questions(): void
    {
        $competency = Competency::factory()->create(['name' => 'Texte zusammenfassen']);
        $practice = Lesson::factory()->publishedFor([$this->student])->create([
            'practice_competency_id' => $competency->id,
            'summary' => 'Eine Zusammenfassung ist kurz und nennt nur das Wichtigste.',
        ]);
        Question::factory()->for($practice)->create(['prompt' => 'Was gehört in eine Zusammenfassung?', 'position' => 1]);

        $this->get(WorkOnHomework::getUrl(['lesson' => $practice]))
            ->assertOk()
            ->assertSeeInOrder(['Zusammenfassung zu „Texte zusammenfassen“', $practice->summary, 'Was gehört in eine Zusammenfassung?', 'In deinen Worten'])
            ->assertSee('Mindestens 40 Zeichen');
    }

    public function test_the_lesson_can_be_practised_with_erklaer_es_mir(): void
    {
        $this->get(WorkOnHomework::getUrl(['lesson' => $this->lesson]))
            ->assertSee(ExplainIt::getUrl(['lesson' => $this->lesson->getKey()]), false);
    }

    public function test_submitting_stores_submission_answers_and_evaluation(): void
    {
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('evaluateSubmission')->andReturn([
            'understanding_score' => 82,
            'feedback' => 'Du hast die Fotosynthese gut erklärt.',
            'gaps' => ['Die Rolle von Chlorophyll'],
            'answers' => [
                $this->comprehension->id => ['score' => 90, 'feedback' => 'Richtig: Licht, Wasser und CO2.'],
                $this->reflection->id => ['score' => 70, 'feedback' => 'Schöne Beobachtung.'],
            ],
        ]));

        Livewire::test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->fillForm($this->validFormData())
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertNotified('Geschafft!')
            ->assertRedirect(WorkOnHomework::getUrl(['lesson' => $this->lesson]));

        $submission = Submission::query()->sole();
        $this->assertTrue($submission->student->is($this->student));
        $this->assertSame(82, $submission->understanding_score);
        $this->assertSame(['Die Rolle von Chlorophyll'], $submission->gaps);
        $this->assertTrue($submission->isEvaluated());
        $this->assertSame(90, $submission->answers()->whereBelongsTo($this->comprehension)->sole()->score);
        $this->assertSame('Schöne Beobachtung.', $submission->answers()->whereBelongsTo($this->reflection)->sole()->feedback);

        $this->get(WorkOnHomework::getUrl(['lesson' => $this->lesson]))
            ->assertOk()
            ->assertSee([UnderstandingLevel::Understood->getLabel().' · 82 von 100', 'Darauf solltest du noch einmal schauen', 'Die Rolle von Chlorophyll'])
            ->assertSee(['Licht, Wasser und Sonne', 'Richtig: Licht, Wasser und CO2.'])
            ->assertSee(['So fasst deine Lehrkraft die Stunde zusammen', $this->lesson->summary])
            ->assertDontSee('GEHEIMER ERWARTUNGSHORIZONT');
    }

    public function test_summary_and_answers_are_required(): void
    {
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->shouldNotReceive('evaluateSubmission'));

        Livewire::test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->fillForm(['summary' => 'Zu kurz.'])
            ->call('submit')
            ->assertHasFormErrors(['summary' => 'min', "answers.{$this->comprehension->id}" => 'required']);

        $this->assertDatabaseEmpty('submissions');
    }

    public function test_text_copied_from_the_visible_teacher_summary_is_refused_before_the_ai_runs(): void
    {
        $summary = 'Pflanzen stellen mit Licht, Wasser und Kohlendioxid in den Blättern Zucker her. Dabei entsteht Sauerstoff, der an die Luft abgegeben wird. Den grünen Farbstoff dafür nennt man Chlorophyll.';
        $this->lesson->update(['summary' => $summary]);
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->shouldNotReceive('evaluateSubmission'));

        Livewire::test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->fillForm(['summary' => $summary, 'answers' => [
                $this->comprehension->id => mb_strtoupper($summary),
                $this->reflection->id => 'Das Experiment mit der Wasserpflanze, die Blasen gemacht hat.',
            ]])
            ->call('submit')
            ->assertHasFormErrors(['summary', "answers.{$this->comprehension->id}"])
            ->assertHasNoFormErrors(["answers.{$this->reflection->id}"]);

        $this->assertDatabaseEmpty('submissions');
    }

    public function test_an_own_text_that_only_shares_a_phrase_with_the_teacher_summary_is_accepted(): void
    {
        $this->lesson->update(['summary' => 'Pflanzen stellen mit Licht, Wasser und Kohlendioxid in den Blättern Zucker her. Dabei entsteht Sauerstoff, der an die Luft abgegeben wird.']);
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('evaluateSubmission')->andReturn([
            'understanding_score' => 80,
            'feedback' => 'Gut.',
            'gaps' => [],
            'answers' => [],
        ]));

        Livewire::test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->fillForm($this->validFormData())
            ->call('submit')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('submissions', 1);
    }

    public function test_a_second_submit_does_not_create_a_duplicate(): void
    {
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->shouldNotReceive('evaluateSubmission'));

        $page = Livewire::test(WorkOnHomework::class, ['lesson' => $this->lesson])->fillForm($this->validFormData());

        // Meanwhile the same student hands in from another tab.
        Submission::factory()->for($this->lesson)->for($this->student, 'student')->create();

        $page->call('submit')
            ->assertRedirect(WorkOnHomework::getUrl(['lesson' => $this->lesson]));

        $this->assertSame(1, Submission::query()->count());
    }

    public function test_failing_assistant_keeps_the_submission_unevaluated_and_shows_a_waiting_state(): void
    {
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('evaluateSubmission')
            ->andThrow(new RuntimeException('KI nicht erreichbar')));

        Livewire::test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->fillForm($this->validFormData())
            ->call('submit')
            ->assertNotified('Abgegeben – die Auswertung kommt später');

        $submission = Submission::query()->sole();
        $this->assertFalse($submission->isEvaluated());
        $this->assertSame(2, $submission->answers()->count());

        $this->get(WorkOnHomework::getUrl(['lesson' => $this->lesson]))
            ->assertOk()
            ->assertSee(['Deine Abgabe wird noch ausgewertet', 'Jetzt auswerten lassen', $this->lesson->summary]);
    }

    public function test_an_unevaluated_submission_can_be_evaluated_later(): void
    {
        $submission = Submission::factory()->for($this->lesson)->for($this->student, 'student')->create();
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('evaluateSubmission')->andReturn([
            'understanding_score' => 40,
            'feedback' => 'Schau dir die Grundlagen noch einmal an.',
            'gaps' => [],
            'answers' => [],
        ]));

        Livewire::test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->callAction('evaluate')
            ->assertNotified('Geschafft!');

        $this->assertSame(40, $submission->refresh()->understanding_score);
        $this->assertTrue($submission->isEvaluated());
    }

    /**
     * @return array{summary: string, answers: array<int, string>}
     */
    private function validFormData(): array
    {
        return [
            'summary' => 'Pflanzen stellen mit Licht, Wasser und Kohlendioxid Zucker her. Dabei entsteht Sauerstoff als Abfallprodukt.',
            'answers' => [
                $this->comprehension->id => 'Licht, Wasser und Sonne',
                $this->reflection->id => 'Das Experiment mit der Wasserpflanze, die Blasen gemacht hat.',
            ],
        ];
    }
}
