<?php

namespace Tests\Feature\ClassTeachers;

use App\Models\Lesson;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\LearningInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassTeachingTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $leader;

    private User $assigned;

    private User $colleague;

    private SchoolClass $schoolClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->leader = User::factory()->teacher()->for($this->school)->create();
        $this->assigned = User::factory()->teacher()->for($this->school)->create();
        $this->colleague = User::factory()->teacher()->for($this->school)->create();
        $this->schoolClass = SchoolClass::factory()->for($this->school)->create(['teacher_id' => $this->leader->id]);
        $this->schoolClass->teachers()->attach($this->assigned);
    }

    public function test_a_teacher_teaches_the_classes_they_lead_or_are_assigned_to(): void
    {
        $this->assertTrue($this->schoolClass->isTaughtBy($this->leader));
        $this->assertTrue($this->schoolClass->isTaughtBy($this->assigned));
        $this->assertFalse($this->schoolClass->isTaughtBy($this->colleague));

        $this->assertSame([$this->schoolClass->id], SchoolClass::query()->taughtBy($this->leader)->pluck('id')->all());
        $this->assertSame([$this->schoolClass->id], SchoolClass::query()->taughtBy($this->assigned)->pluck('id')->all());
        $this->assertSame([], SchoolClass::query()->taughtBy($this->colleague)->pluck('id')->all());
        $this->assertSame([$this->schoolClass->id], $this->assigned->taughtClasses()->pluck('school_classes.id')->all());
    }

    public function test_an_assignment_never_reaches_across_schools(): void
    {
        $foreignClass = SchoolClass::factory()->create();
        $foreignClass->teachers()->attach($this->colleague);
        $leaderWithoutSchool = User::factory()->teacher()->create(['school_id' => null]);
        $classWithoutSchool = SchoolClass::factory()->create(['teacher_id' => $leaderWithoutSchool->id, 'school_id' => null]);

        $this->assertFalse($foreignClass->isTaughtBy($this->colleague));
        $this->assertSame([], SchoolClass::query()->taughtBy($this->colleague)->pluck('id')->all());
        $this->assertFalse($classWithoutSchool->isTaughtBy($leaderWithoutSchool));
        $this->assertSame([], SchoolClass::query()->taughtBy($leaderWithoutSchool)->pluck('id')->all());
    }

    public function test_teachers_may_place_lessons_only_in_classes_they_teach(): void
    {
        $this->assertTrue($this->leader->can('createFor', [Lesson::class, $this->schoolClass]));
        $this->assertTrue($this->assigned->can('createFor', [Lesson::class, $this->schoolClass]));
        $this->assertFalse($this->colleague->can('createFor', [Lesson::class, $this->schoolClass]));
        $this->assertFalse(User::factory()->teacher()->create()->can('createFor', [Lesson::class, $this->schoolClass]));
    }

    public function test_assigning_and_removing_a_teacher_changes_what_they_may_create(): void
    {
        $this->assertFalse($this->colleague->can('createFor', [Lesson::class, $this->schoolClass]));

        $this->schoolClass->teachers()->attach($this->colleague);
        $this->assertTrue($this->colleague->can('createFor', [Lesson::class, $this->schoolClass]));

        $this->schoolClass->teachers()->detach($this->colleague);
        $this->assertFalse($this->colleague->can('createFor', [Lesson::class, $this->schoolClass]));
    }

    public function test_admins_may_place_lessons_in_every_class_of_their_school_only(): void
    {
        $admin = User::factory()->admin()->for($this->school)->create();

        $this->assertTrue($admin->can('createFor', [Lesson::class, $this->schoolClass]));
        $this->assertFalse($admin->can('createFor', [Lesson::class, SchoolClass::factory()->create()]));
    }

    public function test_students_and_operators_may_not_place_lessons_anywhere(): void
    {
        $this->assertFalse(User::factory()->student($this->schoolClass)->create()->can('createFor', [Lesson::class, $this->schoolClass]));
        $this->assertFalse(User::factory()->operator()->create()->can('createFor', [Lesson::class, $this->schoolClass]));
    }

    public function test_a_teacher_without_classes_may_still_open_the_lesson_form_but_has_no_class_to_pick(): void
    {
        $this->assertTrue($this->colleague->can('create', Lesson::class));
        $this->assertSame(0, SchoolClass::query()->taughtBy($this->colleague)->count());
    }

    public function test_existing_lessons_stay_editable_after_the_teacher_stopped_teaching_the_class(): void
    {
        $lesson = Lesson::factory()->for($this->schoolClass)->for($this->assigned, 'teacher')->create();
        $this->schoolClass->teachers()->detach($this->assigned);

        $this->assertFalse($this->schoolClass->isTaughtBy($this->assigned));
        $this->assertTrue($this->assigned->can('update', $lesson));
        $this->assertTrue($this->assigned->can('delete', $lesson));
        $this->assertFalse($this->colleague->can('update', $lesson));
        $this->assertTrue($this->leader->can('createFor', [Lesson::class, $this->schoolClass]));
        $this->assertFalse($this->leader->can('update', $lesson));
    }

    public function test_a_teacher_publishes_only_into_classes_they_teach(): void
    {
        $draft = Lesson::factory()->for($this->schoolClass)->for($this->assigned, 'teacher')->create();

        $this->assertTrue($this->assigned->can('publish', $draft));
        $this->assertFalse($this->colleague->can('publish', $draft));
        $this->assertFalse($this->leader->can('publish', $draft));

        $this->schoolClass->teachers()->detach($this->assigned);

        $this->assertTrue($this->assigned->can('update', $draft));
        $this->assertFalse($this->assigned->can('publish', $draft));
        $this->assertTrue(User::factory()->admin()->for($this->school)->create()->can('publish', $draft));
    }

    public function test_insights_follow_the_assignments(): void
    {
        $assignedStudent = User::factory()->student($this->schoolClass)->create();
        $otherClass = SchoolClass::factory()->for($this->school)->create(['teacher_id' => $this->colleague->id]);
        $otherStudent = User::factory()->student($otherClass)->create();
        // A published lesson in a class does not make the class visible any more.
        Lesson::factory()->for($otherClass)->for($this->assigned, 'teacher')->publishedFor()->create();

        $this->assertEqualsCanonicalizing([$this->schoolClass->id], LearningInsights::visibleClasses($this->assigned)->pluck('id')->all());
        $this->assertTrue(LearningInsights::canView($this->assigned, $assignedStudent));
        $this->assertFalse(LearningInsights::canView($this->assigned, $otherStudent));

        $otherClass->teachers()->attach($this->assigned);

        $this->assertEqualsCanonicalizing([$this->schoolClass->id, $otherClass->id], LearningInsights::visibleClasses($this->assigned)->pluck('id')->all());
        $this->assertTrue(LearningInsights::canView($this->assigned, $otherStudent));

        $this->schoolClass->teachers()->detach($this->assigned);
        $otherClass->teachers()->detach($this->assigned);

        $this->assertSame(0, LearningInsights::visibleClasses($this->assigned)->count());
        $this->assertFalse(LearningInsights::canView($this->assigned, $assignedStudent));
        $this->assertSame(0, LearningInsights::visibleStudents($this->assigned)->count());
    }

    public function test_the_klassenleitung_always_sees_the_class_and_admins_see_the_whole_school(): void
    {
        $student = User::factory()->student($this->schoolClass)->create();
        $admin = User::factory()->admin()->for($this->school)->create();

        $this->assertTrue(LearningInsights::canView($this->leader, $student));
        $this->assertTrue(LearningInsights::canView($admin, $student));
        $this->assertFalse(LearningInsights::canView($this->colleague, $student));
        $this->assertFalse(LearningInsights::canView(User::factory()->admin()->create(), $student));
    }

    public function test_an_assignment_of_a_teacher_who_left_the_school_shows_nothing(): void
    {
        $student = User::factory()->student($this->schoolClass)->create();
        $this->assigned->forceFill(['school_id' => School::factory()->create()->id])->save();

        $this->assertFalse(LearningInsights::canView($this->assigned, $student));
        $this->assertSame(0, LearningInsights::visibleClasses($this->assigned)->count());
    }

    public function test_deleting_a_class_or_a_teacher_removes_their_assignments(): void
    {
        $this->assertDatabaseCount('school_class_teacher', 1);

        $this->assigned->delete();

        $this->assertDatabaseCount('school_class_teacher', 0);

        $this->schoolClass->teachers()->attach($this->colleague);
        $this->schoolClass->delete();

        $this->assertDatabaseCount('school_class_teacher', 0);
    }
}
