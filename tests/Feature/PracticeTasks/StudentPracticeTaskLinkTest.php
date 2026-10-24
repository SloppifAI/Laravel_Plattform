<?php

namespace Tests\Feature\PracticeTasks;

use App\Filament\Pages\MyLearningProgress;
use App\Filament\Pages\WorkOnHomework;
use App\Filament\Widgets\StudentLearningWidget;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\StudentProgress\CreatesLearningResults;
use Tests\TestCase;

/**
 * Students find an open Förderaufgabe right where "Mein Lernstand" and the start page show the matching competency.
 */
class StudentPracticeTaskLinkTest extends TestCase
{
    use CreatesLearningResults;
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private User $student;

    private Competency $competency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolClass = SchoolClass::factory()->create();
        $this->student = User::factory()->student($this->schoolClass)->create();
        $this->competency = Competency::factory()->create(['subject' => 'Biologie', 'name' => 'Versuche auswerten']);

        $this->answer($this->evaluatedHomework($this->student, 'Fotosynthese'), $this->competency, 30);
    }

    public function test_an_open_foerderaufgabe_is_linked_on_the_progress_page_and_the_dashboard_card(): void
    {
        $task = $this->practiceTask(['topic' => 'Förderaufgabe: Versuche auswerten']);
        $url = WorkOnHomework::getUrl(['lesson' => $task]);

        $this->actingAs($this->student)
            ->get(MyLearningProgress::getUrl())
            ->assertOk()
            ->assertSee('Deine Lehrkraft hat dir eine Förderaufgabe gegeben.')
            ->assertSee('Förderaufgabe bearbeiten')
            ->assertSee($url, false);

        Livewire::test(StudentLearningWidget::class)
            ->assertSee('Förderaufgabe bearbeiten')
            ->assertSee($url, false)
            ->assertSee('Thema Tuto erklären');

        $this->get($url)->assertOk();
    }

    public function test_without_an_open_foerderaufgabe_there_is_no_link(): void
    {
        $this->actingAs($this->student);

        Livewire::test(StudentLearningWidget::class)->assertDontSee('Förderaufgabe bearbeiten');
        $this->get(MyLearningProgress::getUrl())->assertOk()->assertDontSee('Förderaufgabe bearbeiten');
    }

    public function test_drafts_foreign_tasks_other_competencies_and_finished_tasks_are_not_linked(): void
    {
        $this->practiceTask(published: false);
        $this->practiceTask(delegatedTo: [User::factory()->student($this->schoolClass)->create()]);
        $this->practiceTask(competency: Competency::factory()->create(['subject' => 'Biologie']));
        $finished = $this->practiceTask();
        Submission::factory()->for($finished)->for($this->student, 'student')->create();
        // An ordinary homework is no Förderaufgabe, even when it is open.
        Lesson::factory()->for($this->schoolClass)->publishedFor([$this->student])->create();

        $this->actingAs($this->student);

        Livewire::test(StudentLearningWidget::class)->assertDontSee('Förderaufgabe bearbeiten');
        $this->get(MyLearningProgress::getUrl())->assertOk()->assertDontSee('Förderaufgabe bearbeiten');
    }

    public function test_the_newest_open_foerderaufgabe_of_the_competency_is_linked(): void
    {
        $older = $this->practiceTask(['published_at' => now()->subDays(3)]);
        $newer = $this->practiceTask(['published_at' => now()->subDay()]);

        $this->actingAs($this->student)
            ->get(MyLearningProgress::getUrl())
            ->assertSee(WorkOnHomework::getUrl(['lesson' => $newer]), false)
            ->assertDontSee(WorkOnHomework::getUrl(['lesson' => $older]), false);
    }

    /**
     * A Förderaufgabe for the test competency, by default published and delegated to the student.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<User>|null  $delegatedTo
     */
    private function practiceTask(array $attributes = [], bool $published = true, ?array $delegatedTo = null, ?Competency $competency = null): Lesson
    {
        $factory = Lesson::factory()->for($this->schoolClass);

        return ($published ? $factory->publishedFor($delegatedTo ?? [$this->student]) : $factory)
            ->create([...$attributes, 'practice_competency_id' => ($competency ?? $this->competency)->id]);
    }
}
