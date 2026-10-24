<?php

namespace Tests\Feature\MikroBoard;

use App\Filament\Pages\MikroBoard;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\LessonRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Recording\RecordingFixtures;
use Tests\TestCase;

/**
 * The Beamer display of Tuto-Mikro: for the lesson's teacher only, the topic and the state and nothing about the class,
 * and the small status endpoint it asks when it cannot hear the Tuto-Mikro tab.
 */
class MikroBoardTest extends TestCase
{
    use RecordingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLesson();
    }

    private function boardUrl(?Lesson $lesson = null): string
    {
        return MikroBoard::getUrl(['lesson' => $lesson ?? $this->lesson]);
    }

    private function statusUrl(?Lesson $lesson = null): string
    {
        return route('recordings.status', $lesson ?? $this->lesson);
    }

    public function test_the_lessons_teacher_opens_the_board_with_the_topic_and_the_channel(): void
    {
        $response = $this->actingAs($this->teacher)->get($this->boardUrl())
            ->assertOk()
            ->assertSee('Fotosynthese')
            ->assertSee('Mikro an')
            ->assertSee('Mikro aus')
            ->assertSee('tuto-mikro-'.$this->lesson->getKey(), false);

        // The configuration is JSON in an attribute, so the slashes of the URL are escaped there.
        $this->assertStringContainsString($this->statusUrl(), str_replace('\\', '', $response->getContent()));
    }

    public function test_the_board_shows_no_names_and_no_text_of_the_lesson(): void
    {
        $html = $this->actingAs($this->teacher)->get($this->boardUrl())->assertOk()->getContent();

        foreach ([...$this->students->pluck('name'), $this->teacher->name, 'Tafelbild: Licht, Wasser, CO2'] as $private) {
            $this->assertStringNotContainsString($private, $html);
        }
    }

    public function test_the_recording_page_offers_the_board_and_publishes_on_its_channel(): void
    {
        $this->actingAs($this->teacher)->get($this->pageUrl())
            ->assertOk()
            ->assertSee('Beamer-Anzeige öffnen')
            ->assertSee($this->boardUrl(), false)
            ->assertSee('tuto-mikro-'.$this->lesson->getKey(), false);
    }

    public function test_nobody_else_opens_the_board(): void
    {
        $this->get($this->boardUrl())->assertRedirect();

        foreach ($this->outsiders() as $who => $user) {
            $this->actingAs($user)->get($this->boardUrl())->assertForbidden("{$who} must not open the board");
        }

        $this->actingAs($this->teacher)->get($this->boardUrl(Lesson::factory()->create()))->assertForbidden();
    }

    public function test_a_foerderaufgabe_has_no_board(): void
    {
        $task = Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')->create(['practice_competency_id' => Competency::factory()]);

        $this->actingAs($this->teacher)->get($this->boardUrl($task))->assertNotFound();
        $this->actingAs($this->teacher)->getJson($this->statusUrl($task))->assertOk()->assertExactJson(['on' => false]);
    }

    public function test_the_status_follows_the_session_of_the_lesson(): void
    {
        $this->actingAs($this->teacher)->getJson($this->statusUrl())->assertOk()->assertExactJson(['on' => false]);

        $recording = $this->startedSession();

        $this->getJson($this->statusUrl())->assertExactJson(['on' => true]);

        $this->postJson(route('recordings.finish', $recording), ['duration_seconds' => 60])->assertOk();

        $this->getJson($this->statusUrl())->assertExactJson(['on' => false]);
    }

    public function test_a_session_that_was_never_ended_does_not_stay_on_forever(): void
    {
        $this->startedSession();

        $this->travel((int) config('lernplattform.recordings.max_session_hours') + 1)->hours();

        $this->actingAs($this->teacher)->getJson($this->statusUrl())->assertExactJson(['on' => false]);
    }

    public function test_the_status_of_another_lesson_is_not_affected(): void
    {
        $this->startedSession();
        $other = Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')->create(['subject' => 'Biologie']);

        $this->getJson($this->statusUrl($other))->assertExactJson(['on' => false]);
    }

    public function test_the_status_is_for_the_lessons_teacher_only(): void
    {
        $this->startedSession();

        auth()->logout();
        $this->getJson($this->statusUrl())->assertUnauthorized();

        foreach ($this->outsiders() as $who => $user) {
            $this->actingAs($user)->getJson($this->statusUrl())->assertForbidden("{$who} must not ask the status");
        }

        $this->assertSame(1, LessonRecording::query()->count());
    }
}
