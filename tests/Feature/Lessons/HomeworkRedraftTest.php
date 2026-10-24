<?php

namespace Tests\Feature\Lessons;

use App\Ai\LessonAssistant;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\RelationManagers\QuestionsRelationManager;
use App\Models\Answer;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Submission;
use App\QuestionType;
use App\Support\HomeworkDraft;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A new AI draft of the homework replaces only the AI questions the teacher left as they were: own and edited questions stay.
 */
class HomeworkRedraftTest extends TestCase
{
    use RefreshDatabase;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lesson = Lesson::factory()->create();
        $this->actingAs($this->lesson->teacher);
    }

    private function manager(): Testable
    {
        return Livewire::test(QuestionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class]);
    }

    public function test_own_and_edited_questions_stay_in_front_and_unchanged_ai_questions_are_replaced(): void
    {
        Question::factory()->for($this->lesson)->aiDrafted()->create(['prompt' => 'KI-Frage, unverändert', 'position' => 1]);
        $edited = Question::factory()->for($this->lesson)->aiDrafted()->create(['prompt' => 'KI-Frage vor dem Bearbeiten', 'position' => 2]);
        Question::factory()->for($this->lesson)->create(['prompt' => 'Eigene Frage', 'position' => 3]);

        $this->manager()
            ->callAction(TestAction::make(EditAction::class)->table($edited), data: ['prompt' => 'Von mir bearbeitete Frage'])
            ->assertHasNoFormErrors();
        $this->assertFalse($edited->refresh()->ai_drafted);

        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->shouldReceive('draftHomework')->once()->andReturn([
            'summary' => 'Neue Zusammenfassung.',
            'questions' => [
                ['type' => QuestionType::Comprehension, 'prompt' => 'Neue KI-Frage 1', 'expected_answer' => 'A', 'competency_id' => null],
                ['type' => QuestionType::Reflection, 'prompt' => 'Neue KI-Frage 2', 'expected_answer' => 'B', 'competency_id' => null],
            ],
        ]));

        Livewire::test(EditLesson::class, ['record' => $this->lesson->getRouteKey()])->callAction('draftHomework');

        $this->assertSame('Neue Zusammenfassung.', $this->lesson->refresh()->summary);
        $this->assertSame([
            ['prompt' => 'Von mir bearbeitete Frage', 'position' => 1, 'ai_drafted' => false],
            ['prompt' => 'Eigene Frage', 'position' => 2, 'ai_drafted' => false],
            ['prompt' => 'Neue KI-Frage 1', 'position' => 3, 'ai_drafted' => true],
            ['prompt' => 'Neue KI-Frage 2', 'position' => 4, 'ai_drafted' => true],
        ], $this->lesson->questions()->get(['prompt', 'position', 'ai_drafted'])->toArray());
    }

    public function test_an_ai_question_a_student_already_answered_is_never_deleted(): void
    {
        $answered = Question::factory()->for($this->lesson)->aiDrafted()->create(['prompt' => 'Beantwortete KI-Frage', 'position' => 1]);
        Question::factory()->for($this->lesson)->aiDrafted()->create(['prompt' => 'Unbeantwortete KI-Frage', 'position' => 2]);
        $answer = Answer::factory()->for($answered)->for(Submission::factory()->for($this->lesson))->create();
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->shouldReceive('draftHomework')->once()->andReturn([
            'summary' => 'Neue Zusammenfassung.',
            'questions' => [['type' => QuestionType::Comprehension, 'prompt' => 'Neue KI-Frage', 'expected_answer' => 'A', 'competency_id' => null]],
        ]));

        HomeworkDraft::create($this->lesson);

        $this->assertSame(['Beantwortete KI-Frage', 'Neue KI-Frage'], $this->lesson->questions()->pluck('prompt')->all());
        $this->assertModelExists($answer);
    }

    public function test_saving_an_ai_question_unchanged_keeps_it_an_ai_question(): void
    {
        $question = Question::factory()->for($this->lesson)->aiDrafted()->create();

        $this->manager()
            ->callAction(TestAction::make(EditAction::class)->table($question))
            ->assertHasNoFormErrors();

        $this->assertTrue($question->refresh()->ai_drafted);
    }

    public function test_reordering_and_ai_levels_leave_an_ai_question_an_ai_question(): void
    {
        $first = Question::factory()->for($this->lesson)->aiDrafted()->create(['position' => 1]);
        $second = Question::factory()->for($this->lesson)->aiDrafted()->create(['position' => 2]);

        $this->manager()
            ->call('reorderTable', [$second->getKey(), $first->getKey()])
            ->callAction(TestAction::make('differentiate')->table($first));

        $first->refresh();
        $this->assertSame(2, $first->position);
        $this->assertTrue($first->hasLevels());
        $this->assertTrue($first->ai_drafted);
        $this->assertTrue($second->refresh()->ai_drafted);
    }

    public function test_the_table_shows_where_a_question_comes_from(): void
    {
        $ai = Question::factory()->for($this->lesson)->aiDrafted()->create();
        $own = Question::factory()->for($this->lesson)->create();

        $this->manager()
            ->assertTableColumnStateSet('origin', 'KI-Entwurf', $ai)
            ->assertTableColumnStateSet('origin', 'Eigene Frage', $own);
    }

    public function test_the_confirmation_names_the_own_questions_that_stay(): void
    {
        Question::factory()->for($this->lesson)->aiDrafted()->create();

        Livewire::test(EditLesson::class, ['record' => $this->lesson->getRouteKey()])
            ->mountAction('draftHomework')
            ->assertMountedActionModalSee('Die Zusammenfassung und die KI-Fragen werden durch einen neuen KI-Entwurf ersetzt.')
            ->assertMountedActionModalDontSee('bleibt erhalten');

        Question::factory()->for($this->lesson)->create();

        Livewire::test(EditLesson::class, ['record' => $this->lesson->getRouteKey()])
            ->mountAction('draftHomework')
            ->assertMountedActionModalSee('1 eigene oder bearbeitete Frage bleibt erhalten.');
    }

    public function test_the_demo_keeps_an_edited_question_and_does_not_repeat_it(): void
    {
        $this->lesson->update(['topic' => 'Fotosynthese', 'notes' => 'Pflanzen nutzen Licht, Wasser und Kohlendioxid. Chlorophyll fängt das Licht ein. Im Versuch bildete die Wasserpest Bläschen aus Sauerstoff.']);
        HomeworkDraft::create($this->lesson);
        $kept = $this->lesson->questions()->first();
        // Only the Erwartungshorizont changed, so the demo would draft the same prompt again.
        $kept->update(['expected_answer' => 'Mein eigener Erwartungshorizont', 'ai_drafted' => false]);

        HomeworkDraft::create($this->lesson);

        $questions = $this->lesson->questions()->get();
        $this->assertSame($kept->getKey(), $questions->first()->getKey());
        $this->assertFalse($questions->first()->ai_drafted);
        $this->assertSame(4, $questions->where('ai_drafted', true)->count());
        $this->assertSame($questions->count(), $questions->pluck('prompt')->unique()->count());
    }
}
