<?php

namespace Tests\Feature\PracticeTasks;

use App\Filament\Pages\MyHomework;
use App\Filament\Pages\WorkOnHomework;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A Förderaufgabe is no lesson to summarise: students read about the competency instead of "Stunde vom ...",
 * need a shorter summary, and the homework list marks it. Ordinary homework stays as it was.
 */
class PracticeTaskWordingTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Lesson $practiceTask;

    private Lesson $homework;

    private Question $practiceQuestion;

    private Question $homeworkQuestion;

    protected function setUp(): void
    {
        parent::setUp();

        $schoolClass = SchoolClass::factory()->create();
        $this->student = User::factory()->student($schoolClass)->create();
        $competency = Competency::factory()->create(['subject' => 'Mathematik', 'name' => 'Probleme mathematisch lösen']);

        $this->practiceTask = Lesson::factory()->for($schoolClass)->publishedFor([$this->student])->create([
            'topic' => 'Förderaufgabe: Probleme mathematisch lösen',
            'held_on' => '2026-09-15',
            'practice_competency_id' => $competency->id,
        ]);
        $this->homework = Lesson::factory()->for($schoolClass)->publishedFor([$this->student])->create([
            'topic' => 'Satz des Pythagoras',
            'held_on' => '2026-09-12',
        ]);
        $this->practiceQuestion = Question::factory()->for($this->practiceTask)->create(['competency_id' => $competency->id]);
        $this->homeworkQuestion = Question::factory()->for($this->homework)->create();

        $this->actingAs($this->student);
    }

    public function test_a_foerderaufgabe_names_the_competency_instead_of_the_lesson(): void
    {
        $this->get(WorkOnHomework::getUrl(['lesson' => $this->practiceTask]))
            ->assertOk()
            ->assertSee('Förderaufgabe zu „Probleme mathematisch lösen“')
            ->assertSee('Schreib in ein paar Sätzen auf, was du jetzt über „Probleme mathematisch lösen“ weißt')
            ->assertSee('Mindestens '.WorkOnHomework::MINIMUM_PRACTICE_SUMMARY_LENGTH.' Zeichen')
            ->assertDontSee('Stunde vom')
            ->assertDontSee('15.09.2026')
            ->assertDontSee('Fasse die Stunde')
            ->assertDontSee('Mindestens '.WorkOnHomework::MINIMUM_SUMMARY_LENGTH.' Zeichen');
    }

    public function test_the_result_of_a_foerderaufgabe_does_not_talk_about_a_lesson(): void
    {
        $this->practiceTask->update(['summary' => 'So gehst du bei Sachaufgaben vor.']);
        $this->practiceTask->submissions()->create(['student_id' => $this->student->id, 'summary' => 'Erst die Skizze, dann die Einheiten angleichen.']);

        $this->get(WorkOnHomework::getUrl(['lesson' => $this->practiceTask]))
            ->assertOk()
            ->assertSee(['So fasst deine Lehrkraft „Probleme mathematisch lösen“ zusammen', 'So gehst du bei Sachaufgaben vor.'])
            ->assertDontSee('die Stunde zusammen');
    }

    public function test_ordinary_homework_keeps_the_lesson_wording(): void
    {
        $this->get(WorkOnHomework::getUrl(['lesson' => $this->homework]))
            ->assertOk()
            ->assertSee('Stunde vom 12.09.2026')
            ->assertSee('Fasse die Stunde in deinen eigenen Worten zusammen')
            ->assertSee('Mindestens '.WorkOnHomework::MINIMUM_SUMMARY_LENGTH.' Zeichen')
            ->assertDontSee('Förderaufgabe');
    }

    public function test_a_foerderaufgabe_summary_may_be_shorter_than_a_homework_summary(): void
    {
        $this->assertLessThan(WorkOnHomework::MINIMUM_SUMMARY_LENGTH, WorkOnHomework::MINIMUM_PRACTICE_SUMMARY_LENGTH);

        $tooShort = str_repeat('a', WorkOnHomework::MINIMUM_PRACTICE_SUMMARY_LENGTH - 1);
        $longEnough = str_repeat('a', WorkOnHomework::MINIMUM_PRACTICE_SUMMARY_LENGTH);

        Livewire::test(WorkOnHomework::class, ['lesson' => $this->practiceTask])
            ->fillForm(['summary' => $tooShort])
            ->call('submit')
            ->assertHasFormErrors(['summary' => 'min']);

        // The unanswered question still stops the hand-in, but the summary itself is accepted.
        Livewire::test(WorkOnHomework::class, ['lesson' => $this->practiceTask])
            ->fillForm(['summary' => $longEnough])
            ->call('submit')
            ->assertHasFormErrors(["answers.{$this->practiceQuestion->id}" => 'required'])
            ->assertHasNoFormErrors(['summary']);
    }

    public function test_the_same_summary_is_too_short_for_ordinary_homework(): void
    {
        Livewire::test(WorkOnHomework::class, ['lesson' => $this->homework])
            ->fillForm(['summary' => str_repeat('a', WorkOnHomework::MINIMUM_PRACTICE_SUMMARY_LENGTH)])
            ->call('submit')
            ->assertHasFormErrors(['summary' => 'min', "answers.{$this->homeworkQuestion->id}" => 'required']);
    }

    public function test_the_homework_list_marks_a_foerderaufgabe_instead_of_showing_a_lesson_date(): void
    {
        Livewire::test(MyHomework::class)
            ->assertCanSeeTableRecords([$this->practiceTask, $this->homework])
            ->assertTableColumnFormattedStateSet('held_on', 'Förderaufgabe', $this->practiceTask)
            ->assertTableColumnFormattedStateSet('held_on', '12.09.2026', $this->homework)
            ->assertSee('Förderaufgabe')
            ->assertDontSee('15.09.2026');
    }

    public function test_the_homework_list_without_a_foerderaufgabe_has_no_such_badge(): void
    {
        $this->practiceTask->students()->detach($this->student);

        Livewire::test(MyHomework::class)
            ->assertCanSeeTableRecords([$this->homework])
            ->assertSee('12.09.2026')
            ->assertDontSee('Förderaufgabe');
    }
}
