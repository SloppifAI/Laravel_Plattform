<?php

namespace Tests\Feature\Differentiation;

use App\Ai\Agents\SubmissionEvaluator;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\RelationManagers\SubmissionsRelationManager;
use App\Models\Answer;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\QuestionLevel;
use App\Support\PersonalDataExport;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\ViewAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The level a student answered in is not hidden: the teacher reads the answer next to the question the student saw,
 * and the student's data export carries it.
 */
class AnsweredLevelVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Lesson $lesson;

    private Submission $submission;

    protected function setUp(): void
    {
        parent::setUp();

        $schoolClass = SchoolClass::factory()->create();
        $this->student = User::factory()->student($schoolClass)->create();
        $this->lesson = Lesson::factory()->for($schoolClass)->for($schoolClass->teacher, 'teacher')->publishedFor()->create();
        $leveled = Question::factory()->for($this->lesson)->create([
            'prompt' => 'Standardfrage: Was braucht eine Pflanze?',
            'prompt_basis' => 'Einstiegsfrage: Was braucht eine Pflanze? Tipp: Denk an Licht.',
            'position' => 1,
        ]);
        $standard = Question::factory()->for($this->lesson)->create(['prompt' => 'Zweite Frage ohne Stufen?', 'position' => 2]);

        $this->submission = Submission::factory()->for($this->lesson)->for($this->student, 'student')->evaluated(80)->create();
        Answer::factory()->for($this->submission)->for($leveled)->create(['answer' => 'Licht', 'level' => 'basis']);
        Answer::factory()->for($this->submission)->for($standard)->create(['answer' => 'Nichts']);
    }

    public function test_the_teacher_sees_the_text_and_the_level_the_student_answered(): void
    {
        $this->actingAs($this->lesson->teacher);

        Livewire::test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->mountAction(TestAction::make(ViewAction::class)->table($this->submission))
            ->assertMountedActionModalSee(['Einstiegsfrage: Was braucht eine Pflanze? Tipp: Denk an Licht.', 'Starter', 'Zweite Frage ohne Stufen?'])
            ->assertMountedActionModalDontSee(['Standardfrage: Was braucht eine Pflanze?', 'Standard']);
    }

    public function test_the_export_carries_the_level_and_the_question_text_of_that_level(): void
    {
        $answers = PersonalDataExport::for($this->student)['abgaben'][0]['antworten'];

        $this->assertSame('Einstiegsfrage: Was braucht eine Pflanze? Tipp: Denk an Licht.', $answers[0]['frage']);
        $this->assertSame(QuestionLevel::Basis->getLabel(), $answers[0]['stufe']);
        $this->assertSame('Zweite Frage ohne Stufen?', $answers[1]['frage']);
        $this->assertNull($answers[1]['stufe']);
    }

    public function test_every_level_is_scored_against_the_same_erwartungshorizont(): void
    {
        $instructions = (new SubmissionEvaluator)->instructions();

        $this->assertStringContainsString('für alle Stufen derselbe', $instructions);
        $this->assertStringNotContainsString('reichen die Kernpunkte', $instructions);
        $this->assertStringNotContainsString('erwartest du zusätzlich', $instructions);
    }
}
