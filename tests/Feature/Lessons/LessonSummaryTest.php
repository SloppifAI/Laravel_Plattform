<?php

namespace Tests\Feature\Lessons;

use App\Ai\LessonAssistant;
use App\Filament\Resources\Lessons\Pages\CreateLesson;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Models\Competency;
use App\Models\Lesson;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * The summary on the lesson page: shown from the first save on, edited in the rich editor, written anew on request on
 * top of the one the lesson has ("Zusammenfassung neu erstellen").
 */
class LessonSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function page(Lesson $lesson): mixed
    {
        return Livewire::actingAs($lesson->teacher)->test(EditLesson::class, ['record' => $lesson->getRouteKey()]);
    }

    private function revise(): TestAction
    {
        return TestAction::make('reviseSummary')->schemaComponent('summary-section');
    }

    public function test_the_summary_section_shows_on_every_saved_lesson_even_while_it_is_empty(): void
    {
        $lesson = Lesson::factory()->create(['summary' => null]);

        $this->page($lesson)->assertSee(__('Noch leer. „Hausaufgabe mit KI erstellen“ schreibt sie aus deinen Notizen, oder du schreibst sie selbst. Die Schüler:innen lesen sie vor den Fragen.'));
        Livewire::actingAs($lesson->teacher)->test(CreateLesson::class)->assertDontSee(__('Noch leer. „Hausaufgabe mit KI erstellen“ schreibt sie aus deinen Notizen, oder du schreibst sie selbst. Die Schüler:innen lesen sie vor den Fragen.'));
    }

    public function test_a_markdown_summary_opens_in_the_editor_and_is_saved_as_html(): void
    {
        $lesson = Lesson::factory()->create(['summary' => "## Lernziele\n\n- Fotosynthese verstehen\n- Versuch deuten"]);

        $this->page($lesson)->call('save')->assertHasNoFormErrors();

        $summary = (string) $lesson->refresh()->summary;
        $this->assertStringContainsString('Lernziele</h2>', $summary);
        $this->assertStringContainsString('Versuch deuten', $summary);
        $this->assertStringNotContainsString('## ', $summary);
    }

    public function test_rewriting_the_summary_saves_the_page_first_and_stores_the_new_text(): void
    {
        $lesson = Lesson::factory()->create(['summary' => 'Alte Zusammenfassung.', 'notes' => 'Alte Notizen']);
        $lesson->forceFill(['summary_from_app' => true])->save();
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('reviseSummary')
            // Saved from the editor first, so the old summary arrives as HTML.
            ->withArgs(fn (Lesson $sent): bool => $sent->notes === 'Neue Notizen' && str_contains((string) $sent->summary, 'Alte Zusammenfassung.'))
            ->andReturn("## Neu\n\nAlte Zusammenfassung, ergänzt."));

        $this->page($lesson)
            ->fillForm(['notes' => 'Neue Notizen'])
            ->callAction($this->revise())
            ->assertNotified(__('Zusammenfassung neu erstellt'));

        $lesson->refresh();
        $this->assertSame("## Neu\n\nAlte Zusammenfassung, ergänzt.", $lesson->summary);
        $this->assertSame('Neue Notizen', $lesson->notes);
        // The teacher now checks an AI text, so it is no longer the app's.
        $this->assertFalse($lesson->summary_from_app);
    }

    public function test_a_failing_ai_keeps_the_summary(): void
    {
        $lesson = Lesson::factory()->create(['summary' => 'Bleibt.']);
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('reviseSummary')->andThrow(new RuntimeException('KI weg')));

        $this->page($lesson)->callAction($this->revise())->assertNotified(__('KI-Entwurf fehlgeschlagen'));

        $this->assertStringContainsString('Bleibt.', (string) $lesson->refresh()->summary);
    }

    public function test_rewriting_is_offered_only_for_an_unreleased_lesson_with_a_summary(): void
    {
        // A hidden action in a section header is left out of the page altogether.
        $this->page(Lesson::factory()->create(['summary' => 'Da.']))->assertActionVisible($this->revise());
        $this->page(Lesson::factory()->create(['summary' => null]))->assertDontSee(__('Zusammenfassung neu erstellen'));
        $this->page(Lesson::factory()->create(['summary' => 'Da.', 'published_at' => now(), 'due_on' => today()->addWeek()]))->assertDontSee(__('Zusammenfassung neu erstellen'));
        $this->page(Lesson::factory()->create(['summary' => 'Da.', 'practice_competency_id' => Competency::factory()->create()->id]))->assertDontSee(__('Zusammenfassung neu erstellen'));
    }

    public function test_an_emptied_editor_leaves_no_summary(): void
    {
        $lesson = Lesson::factory()->create(['summary' => 'Weg damit.']);

        $lesson->update(['summary' => '<p></p>']);

        $this->assertNull($lesson->refresh()->summary);
    }
}
