<?php

namespace Tests\Feature\ExplainIt;

use App\Ai\LessonAssistant;
use App\Filament\Pages\ExplainIt;
use App\MessageAuthor;
use App\Models\ExplanationMessage;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\User;
use App\UnderstandingLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ExplainItTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_students_can_open_the_page(): void
    {
        $this->actingAs(User::factory()->student()->create())
            ->get('/erklaer-es-mir')
            ->assertOk()
            ->assertSee('Explain it to me');

        $this->actingAs(User::factory()->teacher()->create())->get('/erklaer-es-mir')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/erklaer-es-mir')->assertForbidden();
    }

    public function test_the_lesson_query_parameter_preselects_only_a_delegated_lesson(): void
    {
        $student = User::factory()->student()->create();
        $delegated = Lesson::factory()->publishedFor([$student])->create();
        $foreign = Lesson::factory()->publishedFor([User::factory()->student()->create()])->create();
        $this->actingAs($student);

        Livewire::withQueryParams(['lesson' => $delegated->id])
            ->test(ExplainIt::class)
            ->assertFormSet(['lesson_id' => $delegated->id]);

        Livewire::withQueryParams(['lesson' => $foreign->id])
            ->test(ExplainIt::class)
            ->assertFormSet(['lesson_id' => null]);
    }

    public function test_starting_with_a_lesson_not_delegated_to_the_student_is_rejected(): void
    {
        $student = User::factory()->student()->create();
        Lesson::factory()->publishedFor([$student])->create();
        $foreign = Lesson::factory()->publishedFor([User::factory()->student()->create()])->create();

        Livewire::actingAs($student)
            ->test(ExplainIt::class)
            ->fillForm(['lesson_id' => $foreign->id])
            ->call('start')
            ->assertHasFormErrors(['lesson_id']);

        $this->assertDatabaseCount(ExplanationSession::class, 0);
    }

    public function test_starting_from_a_lesson_creates_a_session_with_an_opening_question(): void
    {
        $student = User::factory()->student()->create();
        $lesson = Lesson::factory()->publishedFor([$student])->create(['topic' => 'Fotosynthese']);

        $page = Livewire::actingAs($student)
            ->test(ExplainIt::class)
            ->fillForm(['lesson_id' => $lesson->id])
            ->call('start')
            ->assertHasNoFormErrors();

        $session = $student->explanationSessions()->sole();
        $this->assertTrue($session->lesson->is($lesson));
        $this->assertSame('Fotosynthese', $session->topic);
        $this->assertSame([MessageAuthor::Tutor], $session->messages->pluck('author')->all());
        $this->assertStringContainsString('Tuto', $session->messages->first()->content);
        $page->assertSet('sessionId', $session->id)->assertSee('Fotosynthese');
    }

    public function test_starting_with_a_free_topic_creates_a_session_without_a_lesson(): void
    {
        $student = User::factory()->student()->create();

        Livewire::actingAs($student)
            ->test(ExplainIt::class)
            ->fillForm(['topic' => 'Der Satz des Pythagoras'])
            ->call('start')
            ->assertHasNoFormErrors();

        $session = $student->explanationSessions()->sole();
        $this->assertNull($session->lesson_id);
        $this->assertSame('Der Satz des Pythagoras', $session->topic);
    }

    public function test_sending_stores_the_explanation_and_the_tutors_follow_up(): void
    {
        $session = $this->openSessionWithOpeningQuestion();
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('askFollowUp')->once()->andReturn('Und woher bekommt die Pflanze das Licht?'));

        Livewire::actingAs($session->student)
            ->test(ExplainIt::class)
            ->call('openSession', $session->id)
            ->set('newMessage', 'Pflanzen machen aus Licht, Wasser und CO2 Zucker.')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('newMessage', '')
            ->assertSee('Und woher bekommt die Pflanze das Licht?');

        $this->assertSame(
            [[MessageAuthor::Tutor, 'Hallo?'], [MessageAuthor::Student, 'Pflanzen machen aus Licht, Wasser und CO2 Zucker.'], [MessageAuthor::Tutor, 'Und woher bekommt die Pflanze das Licht?']],
            $session->messages()->get()->map(fn (ExplanationMessage $message): array => [$message->author, $message->content])->all(),
        );
    }

    public function test_another_students_session_can_neither_be_opened_nor_written_to(): void
    {
        $foreignSession = $this->openSessionWithOpeningQuestion();
        $student = User::factory()->student()->create();
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock
            ->shouldNotReceive('askFollowUp', 'assessExplanation'));

        Livewire::actingAs($student)
            ->test(ExplainIt::class)
            ->call('openSession', $foreignSession->id)
            ->assertNotFound();

        Livewire::actingAs($student)
            ->test(ExplainIt::class)
            ->set('sessionId', $foreignSession->id)
            ->assertDontSee($foreignSession->topic)
            ->set('newMessage', 'Reingeschmuggelt')
            ->call('send')
            ->assertNotFound();

        $this->assertSame(1, $foreignSession->messages()->count());
        $this->assertFalse($foreignSession->refresh()->isFinished());
    }

    public function test_finishing_stores_the_assessment_and_blocks_further_messages(): void
    {
        $session = $this->openSessionWithOpeningQuestion();
        $this->mock(LessonAssistant::class, function (MockInterface $mock): void {
            $mock->shouldReceive('assessExplanation')->once()->andReturn(['understanding_score' => 82, 'feedback' => 'Sehr anschaulich erklärt.']);
            $mock->shouldNotReceive('askFollowUp');
        });

        $page = Livewire::actingAs($session->student)
            ->test(ExplainIt::class)
            ->call('openSession', $session->id)
            ->callAction('finish')
            ->assertSee('Sehr anschaulich erklärt.')
            ->assertSee(UnderstandingLevel::Understood->getLabel());

        $session->refresh();
        $this->assertSame(82, $session->understanding_score);
        $this->assertSame('Sehr anschaulich erklärt.', $session->feedback);
        $this->assertTrue($session->isFinished());

        $page->set('newMessage', 'Noch eine Sache …')->call('send')->assertForbidden();
        $this->assertSame(1, $session->messages()->count());
    }

    public function test_a_failing_assistant_keeps_the_students_message(): void
    {
        $session = $this->openSessionWithOpeningQuestion();
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('askFollowUp')->once()->andThrow(new RuntimeException('KI nicht erreichbar')));

        Livewire::actingAs($session->student)
            ->test(ExplainIt::class)
            ->call('openSession', $session->id)
            ->set('newMessage', 'Chlorophyll ist der grüne Farbstoff.')
            ->call('send')
            ->assertOk()
            ->assertNotified()
            ->assertSee('Chlorophyll ist der grüne Farbstoff.');

        $this->assertSame(
            [MessageAuthor::Tutor, MessageAuthor::Student],
            $session->messages()->pluck('author')->all(),
        );
    }

    private function openSessionWithOpeningQuestion(): ExplanationSession
    {
        $session = ExplanationSession::factory()->for(User::factory()->student(), 'student')->create();
        ExplanationMessage::factory()->for($session, 'session')->create(['author' => MessageAuthor::Tutor, 'content' => 'Hallo?']);

        return $session;
    }
}
