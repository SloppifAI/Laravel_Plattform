<?php

namespace Tests\Feature\Notifications;

use App\Ai\LessonAssistant;
use App\Filament\Pages\ExplainIt;
use App\Filament\Pages\QuestionInbox;
use App\Filament\Pages\WorkOnHomework;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\StudentQuestion;
use App\Models\Submission;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * The student and question pages call Notify at the right moments: once per real event, to the people concerned only.
 */
class StudentFlowNotificationWiringTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private User $student;

    private User $classmate;

    private Lesson $lesson;

    private User $teacher;

    /**
     * Teacher and student of another school, who must never hear of anything here.
     *
     * @var list<User>
     */
    private array $bystanders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolClass = SchoolClass::factory()->create();
        [$this->student, $this->classmate] = User::factory()->student($this->schoolClass)->count(2)->create();
        $this->lesson = Lesson::factory()->for($this->schoolClass)->publishedFor([$this->student, $this->classmate])->create(['topic' => 'Fotosynthese']);
        $this->teacher = $this->lesson->teacher;
        $this->bystanders = [User::factory()->teacher()->create(), User::factory()->student()->create(), User::factory()->admin()->create()];
    }

    public function test_a_hand_in_notifies_the_lessons_teacher_once(): void
    {
        $question = Question::factory()->for($this->lesson)->create(['position' => 1]);
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('evaluateSubmission')->andReturn([
            'understanding_score' => 80,
            'feedback' => 'Gut.',
            'gaps' => [],
            'answers' => [],
        ]));

        Livewire::actingAs($this->student)
            ->test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->fillForm($this->handInData($question))
            ->call('submit')
            ->assertHasNoFormErrors();

        $notification = $this->teacher->unreadNotifications->sole();
        $this->assertSame(trans_choice('Neue Abgabe zu „:topic“|:count neue Abgaben zu „:topic“', 1, ['topic' => 'Fotosynthese']), $notification->data['title']);
        $this->assertStringNotContainsString($this->student->name, json_encode($notification->data));
        $this->assertNobodyElseWasNotified();
    }

    public function test_a_hand_in_whose_evaluation_fails_still_notifies_the_teacher(): void
    {
        $question = Question::factory()->for($this->lesson)->create(['position' => 1]);
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('evaluateSubmission')->andThrow(new RuntimeException('KI nicht erreichbar')));

        Livewire::actingAs($this->student)
            ->test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->fillForm($this->handInData($question))
            ->call('submit');

        $this->assertCount(1, $this->teacher->unreadNotifications);
    }

    public function test_an_invalid_hand_in_a_duplicate_and_a_later_evaluation_do_not_notify(): void
    {
        $question = Question::factory()->for($this->lesson)->create(['position' => 1]);
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('evaluateSubmission')->once()->andReturn([
            'understanding_score' => 40,
            'feedback' => 'Schau noch einmal hin.',
            'gaps' => [],
            'answers' => [],
        ]));

        // Too short a summary: nothing is handed in.
        Livewire::actingAs($this->student)
            ->test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->fillForm(['summary' => 'Zu kurz.'])
            ->call('submit')
            ->assertHasFormErrors();

        // Another tab handed in first: the page only shows that hand-in.
        $page = Livewire::actingAs($this->student)
            ->test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->fillForm($this->handInData($question));
        $submission = Submission::factory()->for($this->lesson)->for($this->student, 'student')->create();
        $page->call('submit');

        // The evaluation of an existing hand-in is not a new hand-in.
        Livewire::actingAs($this->student)
            ->test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->callAction('evaluate');

        $this->assertTrue($submission->refresh()->isEvaluated());
        $this->assertCount(0, $this->teacher->notifications);
    }

    public function test_a_failing_bell_does_not_break_the_hand_in(): void
    {
        $question = Question::factory()->for($this->lesson)->create(['position' => 1]);
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('evaluateSubmission')->andReturn([
            'understanding_score' => 80,
            'feedback' => 'Gut.',
            'gaps' => [],
            'answers' => [],
        ]));
        Schema::drop('notifications');

        Livewire::actingAs($this->student)
            ->test(WorkOnHomework::class, ['lesson' => $this->lesson])
            ->fillForm($this->handInData($question))
            ->call('submit')
            ->assertNotified('Done!');

        $this->assertTrue(Submission::query()->sole()->isEvaluated());
    }

    public function test_a_question_from_erklaer_es_mir_notifies_the_lessons_teacher_once(): void
    {
        $session = ExplanationSession::factory()->for($this->student, 'student')->for($this->lesson)->create();

        Livewire::actingAs($this->student)
            ->test(ExplainIt::class)
            ->call('openSession', $session->id)
            ->callAction('askTeacher', ['question' => 'Warum ist Chlorophyll grün?'])
            ->assertNotified('Your question has reached your teacher');

        $notification = $this->teacher->unreadNotifications->sole();
        $this->assertSame(trans_choice('Neue Frage zu „:topic“|:count neue Fragen zu „:topic“', 1, ['topic' => 'Fotosynthese']), $notification->data['title']);
        $this->assertSame(QuestionInbox::getUrl(isAbsolute: false), $notification->data['actions'][0]['url']);
        $this->assertNobodyElseWasNotified();
    }

    public function test_a_refused_question_notifies_nobody(): void
    {
        $session = ExplanationSession::factory()->for($this->student, 'student')->for($this->lesson)->create();
        StudentQuestion::factory()->count(StudentQuestion::MAX_OPEN_PER_LESSON)->for($this->student, 'student')->for($this->lesson)->create();

        Livewire::actingAs($this->student)
            ->test(ExplainIt::class)
            ->call('openSession', $session->id)
            ->callAction('askTeacher', ['question' => 'Noch eine Frage.']);

        $freeSession = ExplanationSession::factory()->for($this->student, 'student')->create(['lesson_id' => null]);

        Livewire::actingAs($this->student)
            ->test(ExplainIt::class)
            ->call('openSession', $freeSession->id)
            ->callAction('askTeacher', ['question' => 'Reingeschmuggelt'])
            ->assertForbidden();

        $this->assertCount(0, $this->teacher->notifications);
    }

    public function test_answering_notifies_only_the_student_who_asked(): void
    {
        $question = $this->question($this->student);

        Livewire::actingAs($this->teacher)
            ->test(QuestionInbox::class)
            ->callAction(TestAction::make('answer')->table($question), ['answer' => 'Chlorophyll absorbiert rotes und blaues Licht.']);

        $notification = $this->student->notifications->sole();
        $this->assertSame(__('Deine Frage zu „:topic“ wurde beantwortet', ['topic' => 'Fotosynthese']), $notification->data['title']);
        $this->assertCount(0, $this->classmate->notifications);
        $this->assertCount(0, $this->teacher->notifications);
        $this->assertNobodyElseWasNotified();
    }

    public function test_marking_a_question_done_notifies_the_student_once(): void
    {
        $question = $this->question($this->student);

        Livewire::actingAs($this->teacher)
            ->test(QuestionInbox::class)
            ->callAction(TestAction::make('markDone')->table($question));

        $this->assertCount(1, $this->student->notifications);
        $this->assertCount(0, $this->classmate->notifications);
        $this->assertNobodyElseWasNotified();
    }

    public function test_marking_several_questions_done_notifies_only_the_students_of_rows_that_changed(): void
    {
        $first = $this->question($this->student);
        $second = $this->question($this->classmate);
        $alreadyAnswered = $this->question($this->student, ['answer' => 'Schon beantwortet.', 'answered_by' => $this->teacher->id, 'answered_at' => now()->subDay()]);
        $colleaguesStudent = User::factory()->student($this->schoolClass)->create();
        $colleaguesQuestion = StudentQuestion::factory()
            ->for(Lesson::factory()->for($this->schoolClass)->for(User::factory()->teacher()->for($this->schoolClass->school), 'teacher'))
            ->for($colleaguesStudent, 'student')
            ->create();

        Livewire::actingAs($this->teacher)
            ->test(QuestionInbox::class)
            ->selectTableRecords([$first->getKey(), $second->getKey(), $alreadyAnswered->getKey(), $colleaguesQuestion->getKey()])
            ->callAction(TestAction::make('markAllDone')->table()->bulk());

        $this->assertTrue($first->refresh()->wasMarkedDone());
        $this->assertTrue($second->refresh()->wasMarkedDone());
        // The student of the already answered question only hears of the question that changed.
        $this->assertCount(1, $this->student->notifications);
        $this->assertCount(1, $this->classmate->notifications);
        $this->assertCount(0, $colleaguesStudent->notifications);
        $this->assertCount(0, $this->teacher->notifications);
        $this->assertNobodyElseWasNotified();
    }

    public function test_a_failing_bell_does_not_break_answering(): void
    {
        $question = $this->question($this->student);
        Schema::drop('notifications');

        Livewire::actingAs($this->teacher)
            ->test(QuestionInbox::class)
            ->callAction(TestAction::make('answer')->table($question), ['answer' => 'Antwort.'])
            ->assertNotified('Answer sent');

        $this->assertFalse($question->refresh()->isOpen());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function question(User $student, array $attributes = []): StudentQuestion
    {
        return StudentQuestion::factory()->for($student, 'student')->for($this->lesson)->create($attributes);
    }

    /**
     * @return array{summary: string, answers: array<int, string>}
     */
    private function handInData(Question $question): array
    {
        return [
            'summary' => 'Pflanzen stellen mit Licht, Wasser und Kohlendioxid Zucker her. Dabei entsteht Sauerstoff als Abfallprodukt.',
            'answers' => [$question->id => 'Licht, Wasser und Sonne'],
        ];
    }

    private function assertNobodyElseWasNotified(): void
    {
        foreach ($this->bystanders as $bystander) {
            $this->assertCount(0, $bystander->notifications);
        }
    }
}
