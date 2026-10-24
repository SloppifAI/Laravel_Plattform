<?php

namespace Tests\Feature\Homework;

use App\Filament\Pages\MyHomework;
use App\Filament\Pages\WorkOnHomework;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyHomeworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_sees_only_published_homework_delegated_to_them(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        [$student, $classmate] = User::factory()->student($schoolClass)->count(2)->create();
        $delegated = Lesson::factory()->for($schoolClass)->publishedFor([$student])->create();
        $forClassmate = Lesson::factory()->for($schoolClass)->publishedFor([$classmate])->create();
        $otherClass = Lesson::factory()->publishedFor()->create();
        $draft = Lesson::factory()->for($schoolClass)->create();
        $draft->students()->attach($student);

        $this->actingAs($student);

        Livewire::test(MyHomework::class)
            ->assertCanSeeTableRecords([$delegated])
            ->assertCanNotSeeTableRecords([$forClassmate, $otherClass, $draft])
            ->assertSee($delegated->topic);
    }

    public function test_status_understanding_level_and_open_count_are_shown(): void
    {
        $student = User::factory()->student()->create();
        Lesson::factory()->publishedFor([$student])->create();
        Lesson::factory()->publishedFor([$student])->create(['due_on' => today()->subDay()]);
        $submitted = Lesson::factory()->publishedFor([$student])->create();
        Submission::factory()->for($submitted)->for($student, 'student')->evaluated(90)->create();

        $this->actingAs($student);

        Livewire::test(MyHomework::class)
            ->assertSee(['Open', 'Overdue', 'Submitted', 'Well understood']);

        $this->assertSame('2', MyHomework::getNavigationBadge());
    }

    public function test_a_practice_task_is_marked_on_a_phone_as_well(): void
    {
        $student = User::factory()->student()->create();
        $ordinary = Lesson::factory()->publishedFor([$student])->create();
        $practice = Lesson::factory()->publishedFor([$student])->create(['practice_competency_id' => Competency::factory()->create()->id]);
        $this->actingAs($student);

        $topic = Livewire::test(MyHomework::class)->instance()->getTable()->getColumn('topic');

        // The badge sits under the topic, a column that is never hidden, so the stacked cards of a phone show it.
        $this->assertStringContainsString('Practice task', $topic->record($practice)->getDescriptionBelow()->toHtml());
        $this->assertNull($topic->record($ordinary)->getDescriptionBelow());
        $this->assertNull($topic->getHiddenFrom());
        $this->assertNull($topic->getVisibleFrom());
    }

    public function test_friendly_empty_state_without_homework(): void
    {
        $this->actingAs(User::factory()->student()->create());

        Livewire::test(MyHomework::class)
            ->assertCountTableRecords(0)
            ->assertSee('You have no homework right now');

        $this->assertNull(MyHomework::getNavigationBadge());
    }

    public function test_teachers_cannot_open_the_homework_pages(): void
    {
        $lesson = Lesson::factory()->publishedFor()->create();

        $this->actingAs($lesson->teacher)->get(MyHomework::getUrl())->assertForbidden();
        $this->actingAs($lesson->teacher)->get(WorkOnHomework::getUrl(['lesson' => $lesson]))->assertForbidden();
    }

    public function test_student_can_open_the_list_page(): void
    {
        $student = User::factory()->student()->create();
        $lesson = Lesson::factory()->publishedFor([$student])->create();

        $this->actingAs($student)
            ->get(MyHomework::getUrl())
            ->assertOk()
            ->assertSee('My homework')
            ->assertSee(WorkOnHomework::getUrl(['lesson' => $lesson]), false);
    }
}
