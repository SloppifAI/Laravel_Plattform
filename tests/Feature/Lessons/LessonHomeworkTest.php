<?php

namespace Tests\Feature\Lessons;

use App\Ai\LessonAssistant;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\RelationManagers\QuestionsRelationManager;
use App\Filament\Resources\Lessons\RelationManagers\SubmissionsRelationManager;
use App\Models\Answer;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\QuestionType;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\ViewAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class LessonHomeworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_drafting_homework_stores_the_summary_and_replaces_the_questions(): void
    {
        $lesson = Lesson::factory()->create(['subject' => 'Biologie']);
        Question::factory()->for($lesson)->create(['prompt' => 'Alte Frage']);
        $competency = Competency::factory()->create(['subject' => 'Biologie']);
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->shouldReceive('draftHomework')->once()->andReturn([
            'summary' => 'Pflanzen bauen mit Licht aus CO2 und Wasser Glucose auf.',
            'questions' => [
                ['type' => QuestionType::Comprehension, 'prompt' => 'Was braucht die Fotosynthese?', 'expected_answer' => 'Licht, CO2, Wasser', 'competency_id' => $competency->id],
                ['type' => QuestionType::Reflection, 'prompt' => 'Was zeigten die Bläschen an der Wasserpest?', 'expected_answer' => 'Sauerstoff entsteht', 'competency_id' => 9999],
            ],
        ]));
        $this->actingAs($lesson->teacher);

        Livewire::test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->callAction('draftHomework')
            ->assertNotified('Hausaufgabe erstellt');

        $this->assertSame('Pflanzen bauen mit Licht aus CO2 und Wasser Glucose auf.', $lesson->refresh()->summary);
        $this->assertSame([
            ['prompt' => 'Was braucht die Fotosynthese?', 'position' => 1, 'competency_id' => $competency->id],
            // An unknown competency from the AI is dropped instead of breaking the foreign key.
            ['prompt' => 'Was zeigten die Bläschen an der Wasserpest?', 'position' => 2, 'competency_id' => null],
        ], $lesson->questions()->get(['prompt', 'position', 'competency_id'])->toArray());
    }

    public function test_a_failing_assistant_shows_an_error_and_keeps_the_questions(): void
    {
        $lesson = Lesson::factory()->has(Question::factory())->create();
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->shouldReceive('draftHomework')->andThrow(new RuntimeException('API down')));
        $this->actingAs($lesson->teacher);

        Livewire::test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->callAction('draftHomework')
            ->assertNotified('KI-Entwurf fehlgeschlagen');

        $this->assertSame(1, $lesson->questions()->count());
        $this->assertNull($lesson->refresh()->summary);
    }

    public function test_delegating_needs_questions(): void
    {
        $lesson = Lesson::factory()->create();
        $this->actingAs($lesson->teacher);

        Livewire::test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->assertActionDisabled('delegate');
    }

    public function test_delegating_to_selected_students_syncs_exactly_those(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        [$first, , $third] = User::factory()->student($schoolClass)->count(3)->create();
        $lesson = Lesson::factory()->for($schoolClass)->has(Question::factory())->create();
        $this->actingAs($lesson->teacher);

        Livewire::test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->callAction('delegate', data: [
                'due_on' => today()->addDays(10)->toDateString(),
                'audience' => 'selected',
                'students' => [$first->id, $third->id],
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Hausaufgabe verteilt');

        $lesson->refresh();
        $this->assertTrue($lesson->isPublished());
        $this->assertTrue($lesson->due_on->isSameDay(today()->addDays(10)));
        $this->assertEqualsCanonicalizing([$first->id, $third->id], $lesson->students()->pluck('users.id')->all());
    }

    public function test_students_of_another_school_cannot_be_delegated_to(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $classmate = User::factory()->student($schoolClass)->create();
        $foreignStudent = User::factory()->student()->create();
        $lesson = Lesson::factory()->for($schoolClass)->has(Question::factory())->create();
        $this->actingAs($lesson->teacher);

        Livewire::test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->callAction('delegate', data: [
                'due_on' => today()->addDays(3)->toDateString(),
                'audience' => 'selected',
                'students' => [$classmate->id, $foreignStudent->id],
            ]);

        $this->assertNotContains($foreignStudent->id, $lesson->students()->pluck('users.id')->all());
    }

    public function test_delegating_to_the_whole_class_syncs_all_its_students(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $classStudents = User::factory()->student($schoolClass)->count(3)->create();
        User::factory()->student()->create();
        $lesson = Lesson::factory()->for($schoolClass)->has(Question::factory())->create();
        $this->actingAs($lesson->teacher);

        Livewire::test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->mountAction('delegate')
            ->assertSchemaStateSet(['due_on' => today()->addDays(7)->toDateString(), 'audience' => 'class'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertTrue($lesson->refresh()->isPublished());
        $this->assertEqualsCanonicalizing($classStudents->modelKeys(), $lesson->students()->pluck('users.id')->all());
    }

    public function test_withdrawing_unpublishes_while_nobody_has_submitted(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        User::factory()->student($schoolClass)->count(2)->create();
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor()->create();
        $this->actingAs($lesson->teacher);

        Livewire::test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->assertActionHidden('draftHomework')
            ->callAction('withdraw');

        $this->assertFalse($lesson->refresh()->isPublished());
        $this->assertSame(0, $lesson->students()->count());
    }

    public function test_withdrawing_is_not_possible_once_someone_has_submitted(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $student = User::factory()->student($schoolClass)->create();
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor()->create();
        Submission::factory()->for($lesson)->for($student, 'student')->create();
        $this->actingAs($lesson->teacher);

        Livewire::test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->assertActionHidden('withdraw');
    }

    public function test_teachers_add_questions_at_the_end_until_the_homework_is_delegated(): void
    {
        $lesson = Lesson::factory()->create();
        Question::factory()->for($lesson)->create(['position' => 1]);
        $this->actingAs($lesson->teacher);

        Livewire::test(QuestionsRelationManager::class, ['ownerRecord' => $lesson, 'pageClass' => EditLesson::class])
            ->callAction(TestAction::make(CreateAction::class)->table(), data: [
                'type' => QuestionType::Application->value,
                'prompt' => 'Wie würdest du den Versuch im Dunkeln wiederholen?',
                'expected_answer' => 'Ohne Licht keine Bläschen.',
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Question::class, ['lesson_id' => $lesson->id, 'type' => QuestionType::Application, 'position' => 2]);

        $lesson->update(['published_at' => now()]);

        Livewire::test(QuestionsRelationManager::class, ['ownerRecord' => $lesson, 'pageClass' => EditLesson::class])
            ->assertActionHidden(TestAction::make(CreateAction::class)->table());
    }

    public function test_teachers_see_who_has_not_submitted_and_can_return_a_submission(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $submitted = User::factory()->student($schoolClass)->create();
        User::factory()->student($schoolClass)->create(['name' => 'Mia Muster']);
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor()->create();
        $submission = Submission::factory()->for($lesson)->for($submitted, 'student')->evaluated(80)->create();
        Answer::factory()->for($submission)->create(['answer' => 'Licht und Wasser', 'score' => 40, 'feedback' => 'CO2 fehlt.']);
        $this->actingAs($lesson->teacher);

        Livewire::test(SubmissionsRelationManager::class, ['ownerRecord' => $lesson, 'pageClass' => EditLesson::class])
            ->assertCanSeeTableRecords([$submission])
            ->assertSee('Noch nicht abgegeben (1): Mia Muster')
            ->assertSee('80 % · Gut verstanden')
            ->mountAction(TestAction::make(ViewAction::class)->table($submission))
            ->assertMountedActionModalSee(['Licht und Wasser', '40 % · Förderbedarf', 'CO2 fehlt.', $submission->gaps[0]]);

        Livewire::test(SubmissionsRelationManager::class, ['ownerRecord' => $lesson, 'pageClass' => EditLesson::class])
            ->callAction(TestAction::make(DeleteAction::class)->table($submission));

        $this->assertModelMissing($submission);
    }
}
