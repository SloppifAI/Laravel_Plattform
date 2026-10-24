<?php

namespace Tests\Feature\Differentiation;

use App\Ai\LessonAssistant;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\RelationManagers\QuestionsRelationManager;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\User;
use App\QuestionLevel;
use App\QuestionType;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Feature\Ai\EnablesSchoolAi;
use Tests\TestCase;

/**
 * Teachers write the Einstieg and Profi level of a question themselves or let the AI draft them ("Stufen mit KI erstellen").
 */
class QuestionLevelsRelationManagerTest extends TestCase
{
    use EnablesSchoolAi;
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

    public function test_teachers_write_the_levels_of_a_question_in_the_edit_form(): void
    {
        $question = Question::factory()->for($this->lesson)->create(['prompt' => 'Was ist Chlorophyll?']);

        $this->manager()
            ->callAction(TestAction::make(EditAction::class)->table($question), data: [
                'prompt_basis' => 'Chlorophyll ist grün. Wofür braucht die Pflanze es?',
                'prompt_plus' => 'Was ist Chlorophyll und warum sehen Blätter im Herbst nicht mehr grün aus?',
            ])
            ->assertHasNoFormErrors();

        $question->refresh();
        $this->assertSame('Chlorophyll ist grün. Wofür braucht die Pflanze es?', $question->prompt_basis);
        $this->assertSame('Was ist Chlorophyll und warum sehen Blätter im Herbst nicht mehr grün aus?', $question->prompt_plus);
        $this->assertSame([QuestionLevel::Basis, QuestionLevel::Standard, QuestionLevel::Plus], $question->availableLevels());
    }

    public function test_the_table_names_the_levels_a_question_has(): void
    {
        $withLevels = Question::factory()->for($this->lesson)->create(['prompt_basis' => 'Leichter?', 'prompt_plus' => null]);
        $without = Question::factory()->for($this->lesson)->create();

        $this->manager()
            ->assertTableColumnStateSet('levels', QuestionLevel::Basis->getLabel().' · '.QuestionLevel::Standard->getLabel(), $withLevels)
            ->assertTableColumnStateSet('levels', null, $without);
    }

    public function test_the_lesson_action_fills_the_missing_levels_and_keeps_what_the_teacher_wrote(): void
    {
        $bare = Question::factory()->for($this->lesson)->create(['prompt' => 'Frage ohne Stufen?', 'position' => 1]);
        $partly = Question::factory()->for($this->lesson)->create(['prompt' => 'Frage mit Einstieg?', 'prompt_basis' => 'Mein eigener Einstieg.', 'position' => 2]);
        $complete = Question::factory()->for($this->lesson)->create(['prompt' => 'Fertige Frage?', 'prompt_basis' => 'Eigener Einstieg.', 'prompt_plus' => 'Eigenes Profi.', 'position' => 3]);
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('differentiateQuestion')
            ->twice()
            ->andReturnUsing(fn (Question $question): array => ['basis' => "KI-Einstieg zu {$question->prompt}", 'plus' => "KI-Profi zu {$question->prompt}"]));

        $this->manager()
            ->callAction(TestAction::make('differentiateAll')->table())
            ->assertNotified('Stufen für 2 Fragen erstellt');

        $this->assertSame(['KI-Einstieg zu Frage ohne Stufen?', 'KI-Profi zu Frage ohne Stufen?'], [$bare->refresh()->prompt_basis, $bare->prompt_plus]);
        $this->assertSame(['Mein eigener Einstieg.', 'KI-Profi zu Frage mit Einstieg?'], [$partly->refresh()->prompt_basis, $partly->prompt_plus]);
        $this->assertSame(['Eigener Einstieg.', 'Eigenes Profi.'], [$complete->refresh()->prompt_basis, $complete->prompt_plus]);
    }

    public function test_the_question_action_replaces_the_levels_of_that_question_only(): void
    {
        $question = Question::factory()->for($this->lesson)->create(['prompt' => 'Erste Frage?', 'prompt_basis' => 'Alt', 'prompt_plus' => 'Alt']);
        $other = Question::factory()->for($this->lesson)->create(['prompt' => 'Zweite Frage?']);
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('differentiateQuestion')
            ->once()
            ->withArgs(fn (Question $asked): bool => $asked->is($question))
            ->andReturn(['basis' => 'Neu leichter', 'plus' => 'Neu schwerer']));

        $this->manager()->callAction(TestAction::make('differentiate')->table($question));

        $this->assertSame(['Neu leichter', 'Neu schwerer'], [$question->refresh()->prompt_basis, $question->prompt_plus]);
        $this->assertNull($other->refresh()->prompt_basis);
    }

    public function test_in_demo_mode_the_teacher_is_told_that_the_levels_are_examples(): void
    {
        Question::factory()->for($this->lesson)->create(['type' => QuestionType::Comprehension, 'prompt' => 'Warum brauchen Pflanzen Licht?']);

        $this->manager()
            ->callAction(TestAction::make('differentiateAll')->table())
            ->assertNotified(Notification::make()
                ->success()
                ->title('Stufen für 1 Frage erstellt')
                ->body('Demo-Modus – KI-Anbindung vorbereitet, aber nicht aktiv. Die Stufen sind Beispielentwürfe, bitte prüfe und passe sie an.')
                ->persistent());

        $question = $this->lesson->questions()->first();
        $this->assertStringStartsWith('Warum brauchen Pflanzen Licht? Tipp:', $question->prompt_basis);
        $this->assertStringContainsString('Erkläre zusätzlich', $question->prompt_plus);
    }

    public function test_with_the_ai_on_the_teacher_is_asked_to_review_the_levels(): void
    {
        $this->preventRealAiCalls();
        $school = $this->enableAiFor($this->lesson->schoolClass->school);
        $this->assertTrue($school->aiEnabled());
        Question::factory()->for($this->lesson)->create();
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('differentiateQuestion')->andReturn(['basis' => 'Leichter', 'plus' => 'Schwerer']));

        $this->manager()
            ->callAction(TestAction::make('differentiateAll')->table())
            ->assertNotified(Notification::make()
                ->success()
                ->title('Stufen für 1 Frage erstellt')
                ->body('Prüfe die Stufen, bevor du die Hausaufgabe freigibst.')
                ->persistent());
    }

    public function test_a_teacher_who_does_not_own_the_lesson_cannot_ask_for_levels(): void
    {
        $question = Question::factory()->for($this->lesson)->create();
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->shouldNotReceive('differentiateQuestion'));
        $this->actingAs(User::factory()->teacher()->for($this->lesson->schoolClass->school)->create());

        $this->manager()
            ->assertActionHidden(TestAction::make('differentiate')->table($question))
            ->assertActionHidden(TestAction::make('differentiateAll')->table());

        // Hidden buttons are no protection: the action itself must refuse as well.
        $this->manager()
            ->call('mountAction', 'differentiate', [], ['table' => true, 'recordKey' => $question->getKey()])
            ->call('callMountedAction');
        $this->manager()
            ->call('mountAction', 'differentiateAll', [], ['table' => true])
            ->call('callMountedAction');

        $this->assertNull($question->refresh()->prompt_basis);
    }

    public function test_released_homework_keeps_its_questions_and_levels(): void
    {
        $question = Question::factory()->for($this->lesson)->create();
        $this->lesson->update(['published_at' => now()]);
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->shouldNotReceive('differentiateQuestion'));

        $this->manager()
            ->assertActionHidden(TestAction::make('differentiate')->table($question))
            ->assertActionHidden(TestAction::make('differentiateAll')->table());
    }

    public function test_a_failing_assistant_is_reported_and_keeps_what_was_stored_so_far(): void
    {
        $first = Question::factory()->for($this->lesson)->create(['position' => 1]);
        $second = Question::factory()->for($this->lesson)->create(['position' => 2]);
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->shouldReceive('differentiateQuestion')
            ->andReturn(['basis' => 'Erste Stufe', 'plus' => 'Erste Profi-Stufe'])
            ->once()
            ->ordered()
            ->getMock()
            ->shouldReceive('differentiateQuestion')
            ->andThrow(new RuntimeException('Die KI ist gerade überlastet.'))
            ->once()
            ->ordered());

        $this->manager()
            ->callAction(TestAction::make('differentiateAll')->table())
            ->assertNotified('Stufen konnten nicht erstellt werden');

        $this->assertSame('Erste Stufe', $first->refresh()->prompt_basis);
        $this->assertNull($second->refresh()->prompt_basis);
    }

    public function test_changing_a_text_throws_away_its_simple_language_copy(): void
    {
        $this->lesson->update(['summary' => 'Alte Zusammenfassung.']);
        $this->lesson->forceFill(['summary_simple' => 'Alt, einfach.'])->saveQuietly();
        $question = Question::factory()->for($this->lesson)->create(['prompt' => 'Alte Frage?', 'prompt_basis' => 'Alt leicht?']);
        $question->forceFill(['prompt_simple' => 'Alt einfach?', 'prompt_basis_simple' => 'Alt leicht einfach?'])->saveQuietly();

        $this->lesson->update(['topic' => 'Anderes Thema']);
        $question->update(['expected_answer' => 'Neuer Erwartungshorizont']);

        $this->assertSame('Alt, einfach.', $this->lesson->refresh()->summary_simple);
        $this->assertSame(['Alt einfach?', 'Alt leicht einfach?'], [$question->refresh()->prompt_simple, $question->prompt_basis_simple]);

        $this->lesson->update(['summary' => 'Neue Zusammenfassung.']);
        $question->update(['prompt' => 'Neue Frage?']);

        $this->assertNull($this->lesson->refresh()->summary_simple);
        $this->assertSame([null, 'Alt leicht einfach?'], [$question->refresh()->prompt_simple, $question->prompt_basis_simple]);

        $question->update(['prompt_basis' => 'Neu leicht?']);

        $this->assertNull($question->refresh()->prompt_basis_simple);
    }
}
