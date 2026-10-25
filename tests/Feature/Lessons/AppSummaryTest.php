<?php

namespace Tests\Feature\Lessons;

use App\Ai\LessonAssistant;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Models\Lesson;
use App\Models\Question;
use App\QuestionType;
use App\Support\HomeworkDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A summary a connected app set stays when the teacher lets the AI draft the homework: only the questions are drafted.
 */
class AppSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lesson = Lesson::factory()->create(['summary' => 'Aus der App.']);
        $this->lesson->forceFill(['summary_from_app' => true])->save();
        $this->actingAs($this->lesson->teacher);
    }

    private function mockDraft(): void
    {
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->shouldReceive('draftHomework')->once()->andReturn([
            'summary' => 'Von der KI.',
            'questions' => [['type' => QuestionType::Comprehension, 'prompt' => 'KI-Frage', 'expected_answer' => 'A', 'competency_id' => null]],
        ]));
    }

    public function test_an_ai_draft_keeps_the_apps_summary_and_drafts_the_questions(): void
    {
        $this->mockDraft();

        HomeworkDraft::create($this->lesson);

        $this->lesson->refresh();
        $this->assertSame('Aus der App.', $this->lesson->summary);
        $this->assertTrue($this->lesson->summary_from_app);
        $this->assertSame(['KI-Frage'], $this->lesson->questions()->pluck('prompt')->all());
    }

    public function test_the_demo_draft_keeps_the_apps_summary_too(): void
    {
        HomeworkDraft::create($this->lesson);

        $this->assertSame('Aus der App.', $this->lesson->refresh()->summary);
        $this->assertTrue($this->lesson->questions()->exists());
    }

    public function test_an_ai_draft_replaces_a_summary_that_did_not_come_from_an_app(): void
    {
        $this->lesson->update(['summary' => 'Von der Lehrkraft.']);
        $this->lesson->forceFill(['summary_from_app' => false])->save();
        $this->mockDraft();

        HomeworkDraft::create($this->lesson);

        $this->assertSame('Von der KI.', $this->lesson->refresh()->summary);
    }

    public function test_emptying_the_summary_ends_the_apps_claim(): void
    {
        Livewire::test(EditLesson::class, ['record' => $this->lesson->getRouteKey()])
            ->fillForm(['summary' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->lesson->refresh();
        $this->assertNull($this->lesson->summary);
        $this->assertFalse($this->lesson->summary_from_app);

        $this->mockDraft();
        HomeworkDraft::create($this->lesson);
        $this->assertSame('Von der KI.', $this->lesson->refresh()->summary);
    }

    public function test_the_lesson_page_says_that_the_apps_summary_stays(): void
    {
        Question::factory()->for($this->lesson)->aiDrafted()->create();

        Livewire::test(EditLesson::class, ['record' => $this->lesson->getRouteKey()])
            ->assertSee(__('Von einer App gesetzt. Ein neuer KI-Entwurf behält sie und erstellt nur die Fragen. Die Schüler:innen lesen sie vor den Fragen, du kannst sie vor der Freigabe anpassen.'))
            ->mountAction('draftHomework')
            ->assertMountedActionModalSee(__('Die KI-Fragen werden durch einen neuen KI-Entwurf ersetzt. Die Zusammenfassung aus der App bleibt erhalten.'));
    }
}
