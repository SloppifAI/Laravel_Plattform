<?php

namespace Tests\Feature\Lessons;

use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\LessonRelease;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The shared "freigeben" step: the lesson page and "Heute" must end up with exactly the same data.
 */
class LessonReleaseTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-21 10:00:00');
        $this->teacher = User::factory()->teacher()->create();
        $this->schoolClass = SchoolClass::factory()->for($this->teacher, 'teacher')->create();
    }

    public function test_it_releases_to_the_whole_class_with_the_default_due_date(): void
    {
        $students = User::factory()->student($this->schoolClass)->count(3)->create();
        User::factory()->student()->create();
        $lesson = $this->draft();

        $reached = LessonRelease::release($lesson, $this->teacher);

        $this->assertSame(3, $reached);
        $lesson->refresh();
        $this->assertTrue($lesson->isPublished());
        $this->assertTrue($lesson->due_on->isSameDay(today()->addDays(7)));
        $this->assertEqualsCanonicalizing($students->modelKeys(), $lesson->students()->pluck('users.id')->all());
    }

    public function test_it_releases_to_the_chosen_students_of_the_class_only(): void
    {
        [$first, , $third] = User::factory()->student($this->schoolClass)->count(3)->create();
        $foreign = User::factory()->student()->create();
        $lesson = $this->draft();

        $reached = LessonRelease::release($lesson, $this->teacher, today()->addDays(10), [$first->id, $third->id, $foreign->id]);

        $this->assertSame(2, $reached);
        $lesson->refresh();
        $this->assertTrue($lesson->due_on->isSameDay(today()->addDays(10)));
        $this->assertEqualsCanonicalizing([$first->id, $third->id], $lesson->students()->pluck('users.id')->all());
    }

    public function test_it_releases_nothing_without_recipients_or_when_already_released(): void
    {
        $lesson = $this->draft();

        $this->assertSame(0, LessonRelease::release($lesson, $this->teacher));
        $this->assertFalse($lesson->refresh()->isPublished());

        $student = User::factory()->student($this->schoolClass)->create();
        $this->assertSame(0, LessonRelease::release($lesson, $this->teacher, null, []));
        $this->assertFalse($lesson->refresh()->isPublished());

        $this->assertSame(1, LessonRelease::release($lesson, $this->teacher, today()->addDays(2)));
        User::factory()->student($this->schoolClass)->create();

        $this->assertSame(0, LessonRelease::release($lesson->refresh(), $this->teacher, today()->addDays(9)));
        $this->assertTrue($lesson->refresh()->due_on->isSameDay(today()->addDays(2)));
        $this->assertSame([$student->id], $lesson->students()->pluck('users.id')->all());
    }

    public function test_the_administration_of_the_school_may_release_but_nobody_else(): void
    {
        $student = User::factory()->student($this->schoolClass)->create();
        $lesson = $this->draft();

        $outsiders = [
            User::factory()->teacher()->for($this->teacher->school)->create(),
            User::factory()->teacher()->create(),
            User::factory()->admin()->create(),
            $student,
        ];

        foreach ($outsiders as $outsider) {
            try {
                LessonRelease::release($lesson, $outsider);
                $this->fail('Released by '.$outsider->role->value);
            } catch (AuthorizationException) {
                $this->assertFalse($lesson->refresh()->isPublished());
            }
        }

        $this->assertSame(1, LessonRelease::release($lesson, User::factory()->admin()->for($this->teacher->school)->create()));
    }

    public function test_a_teacher_who_no_longer_teaches_the_class_cannot_release(): void
    {
        User::factory()->student($this->schoolClass)->create();
        $lesson = $this->draft();
        $this->schoolClass->update(['teacher_id' => User::factory()->teacher()->for($this->teacher->school)->create()->id]);

        $this->expectException(AuthorizationException::class);

        LessonRelease::release($lesson, $this->teacher);
    }

    public function test_withdrawing_clears_the_release_and_the_students(): void
    {
        User::factory()->student($this->schoolClass)->create();
        $lesson = $this->draft();
        LessonRelease::release($lesson, $this->teacher);

        LessonRelease::withdraw($lesson->refresh());

        $lesson->refresh();
        $this->assertFalse($lesson->isPublished());
        $this->assertNull($lesson->due_on);
        $this->assertSame(0, $lesson->students()->count());
    }

    public function test_withdrawing_a_practice_task_keeps_its_students(): void
    {
        $student = User::factory()->student($this->schoolClass)->create();
        $task = $this->draft(['practice_competency_id' => Competency::factory()->create()->id]);
        $task->students()->attach($student);
        LessonRelease::release($task, $this->teacher, null, [$student->id]);

        LessonRelease::withdraw($task->refresh());

        $this->assertFalse($task->refresh()->isPublished());
        $this->assertSame([$student->id], $task->students()->pluck('users.id')->all());
    }

    public function test_students_who_join_the_class_after_the_release_can_be_added_later(): void
    {
        $early = User::factory()->student($this->schoolClass)->count(2)->create();
        $lesson = $this->draft();
        LessonRelease::release($lesson, $this->teacher, today()->addDays(3));
        $late = User::factory()->student($this->schoolClass)->create();
        $elsewhere = User::factory()->student()->create();

        $this->assertSame([$late->id], LessonRelease::studentsWithoutHomework($lesson->refresh())->pluck('id')->all());
        $this->assertSame(0, Lesson::assignedTo($late)->count());

        $reached = LessonRelease::addStudents($lesson, $this->teacher, [$late->id, $early[0]->id, $elsewhere->id]);

        $this->assertSame(1, $reached);
        $this->assertEqualsCanonicalizing([...$early->modelKeys(), $late->id], $lesson->students()->pluck('users.id')->all());
        $this->assertTrue($lesson->refresh()->due_on->isSameDay(today()->addDays(3)));
        $this->assertSame(1, Lesson::assignedTo($late)->count());
        $this->assertSame([], LessonRelease::studentsWithoutHomework($lesson)->pluck('id')->all());
        $this->assertSame(1, $late->notifications()->count());
        $this->assertSame(0, $elsewhere->notifications()->count());
        foreach ($early as $student) {
            $this->assertSame(1, $student->notifications()->count(), 'told by the release, not again');
        }

        $this->assertSame(0, LessonRelease::addStudents($lesson, $this->teacher, [$late->id]));
        $this->assertSame(1, $late->notifications()->count());
    }

    public function test_nobody_is_added_to_a_lesson_that_is_not_released(): void
    {
        $student = User::factory()->student($this->schoolClass)->create();
        $lesson = $this->draft();

        $this->assertSame(0, LessonRelease::addStudents($lesson, $this->teacher, [$student->id]));

        LessonRelease::release($lesson, $this->teacher);
        LessonRelease::withdraw($lesson->refresh());
        $late = User::factory()->student($this->schoolClass)->create();

        $this->assertSame(0, LessonRelease::addStudents($lesson->refresh(), $this->teacher, [$late->id]));
        $this->assertSame(0, $lesson->students()->count());
        $this->assertSame(0, $late->notifications()->count());
    }

    public function test_only_those_who_may_release_the_lesson_can_add_students(): void
    {
        User::factory()->student($this->schoolClass)->create();
        $lesson = $this->draft();
        LessonRelease::release($lesson, $this->teacher);
        $late = User::factory()->student($this->schoolClass)->create();

        $outsiders = [
            User::factory()->teacher()->for($this->teacher->school)->create(),
            User::factory()->teacher()->create(),
            User::factory()->admin()->create(),
            $late,
        ];

        foreach ($outsiders as $outsider) {
            try {
                LessonRelease::addStudents($lesson->refresh(), $outsider, [$late->id]);
                $this->fail('Students added by '.$outsider->role->value);
            } catch (AuthorizationException) {
                $this->assertSame(1, $lesson->students()->count());
            }
        }

        $this->assertSame(1, LessonRelease::addStudents($lesson->refresh(), User::factory()->admin()->for($this->teacher->school)->create(), [$late->id]));
    }

    public function test_the_lesson_page_offers_the_missing_students_and_hands_the_homework_on(): void
    {
        User::factory()->student($this->schoolClass)->create();
        $lesson = $this->draft();
        $elsewhere = User::factory()->student()->create();
        $this->actingAs($this->teacher);

        Livewire::test(EditLesson::class, ['record' => $lesson->getRouteKey()])->assertActionHidden('addStudents');

        LessonRelease::release($lesson, $this->teacher, today()->addDays(4));
        Livewire::test(EditLesson::class, ['record' => $lesson->getRouteKey()])->assertActionHidden('addStudents');

        $late = User::factory()->student($this->schoolClass)->create();
        Livewire::test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->assertActionVisible('addStudents')
            ->callAction('addStudents', data: ['students' => [$elsewhere->id]])
            ->assertHasActionErrors(['students.0']);
        $this->assertSame(0, Lesson::assignedTo($late)->count());

        Livewire::test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->callAction('addStudents', data: ['students' => [$late->id]])
            ->assertNotified('Students added');

        $this->assertSame(1, Lesson::assignedTo($late)->count());
        $this->assertSame(0, Lesson::assignedTo($elsewhere)->count());
        Livewire::test(EditLesson::class, ['record' => $lesson->getRouteKey()])->assertActionHidden('addStudents');
    }

    public function test_the_lesson_page_does_not_offer_to_add_students_to_a_practice_task_or_after_the_teacher_lost_the_class(): void
    {
        $student = User::factory()->student($this->schoolClass)->create();
        $task = $this->draft(['practice_competency_id' => Competency::factory()->create()->id]);
        LessonRelease::release($task, $this->teacher, null, [$student->id]);
        $lesson = $this->draft();
        LessonRelease::release($lesson, $this->teacher, null, [$student->id]);
        User::factory()->student($this->schoolClass)->create();

        Livewire::actingAs($this->teacher)->test(EditLesson::class, ['record' => $task->getRouteKey()])->assertActionHidden('addStudents');
        Livewire::actingAs($this->teacher)->test(EditLesson::class, ['record' => $lesson->getRouteKey()])->assertActionVisible('addStudents');

        $this->schoolClass->update(['teacher_id' => User::factory()->teacher()->for($this->teacher->school)->create()->id]);

        Livewire::actingAs($this->teacher)->test(EditLesson::class, ['record' => $lesson->getRouteKey()])->assertActionHidden('addStudents');
    }

    public function test_the_lesson_page_ends_up_with_the_same_data_as_the_shared_step(): void
    {
        $students = User::factory()->student($this->schoolClass)->count(3)->create();
        $viaPage = $this->draft();
        $viaSupport = $this->draft();
        $dueOn = today()->addDays(5);
        $this->actingAs($this->teacher);

        Livewire::test(EditLesson::class, ['record' => $viaPage->getRouteKey()])
            ->callAction('delegate', data: ['due_on' => $dueOn->toDateString(), 'audience' => 'selected', 'students' => [$students[0]->id, $students[2]->id]])
            ->assertNotified('Homework released');
        LessonRelease::release($viaSupport, $this->teacher, $dueOn, [$students[0]->id, $students[2]->id]);

        foreach ([$viaPage, $viaSupport] as $lesson) {
            $lesson->refresh();
            $this->assertTrue($lesson->isPublished());
            $this->assertSame($dueOn->toDateString(), $lesson->due_on->toDateString());
            $this->assertEqualsCanonicalizing([$students[0]->id, $students[2]->id], $lesson->students()->pluck('users.id')->all());
        }

        Livewire::test(EditLesson::class, ['record' => $viaPage->getRouteKey()])->callAction('withdraw');
        LessonRelease::withdraw($viaSupport);

        foreach ([$viaPage, $viaSupport] as $lesson) {
            $lesson->refresh();
            $this->assertFalse($lesson->isPublished());
            $this->assertNull($lesson->due_on);
            $this->assertSame(0, $lesson->students()->count());
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function draft(array $attributes = []): Lesson
    {
        return Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')->has(Question::factory())->create($attributes);
    }
}
