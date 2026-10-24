<?php

namespace Tests\Feature\Substitutions;

use App\Models\ClassSubstitution;
use App\Models\Lesson;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\LearningInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubstitutionTeachingTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $leader;

    private User $substitute;

    private SchoolClass $schoolClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->leader = User::factory()->teacher()->for($this->school)->create();
        $this->substitute = User::factory()->teacher()->for($this->school)->create();
        $this->schoolClass = SchoolClass::factory()->for($this->school)->create(['teacher_id' => $this->leader->id]);
    }

    public function test_an_active_substitution_makes_the_substitute_teach_the_class(): void
    {
        $this->substitution(['starts_on' => today()->subDays(2), 'ends_on' => today()->addDays(2)]);

        $this->assertTrue($this->schoolClass->isTaughtBy($this->substitute));
        $this->assertSame([$this->schoolClass->id], SchoolClass::query()->taughtBy($this->substitute)->pluck('id')->all());
    }

    public function test_the_first_and_the_last_day_still_count(): void
    {
        $this->substitution(['starts_on' => today(), 'ends_on' => today()]);

        $this->assertTrue($this->schoolClass->isTaughtBy($this->substitute));

        $this->travel(1)->days();

        $this->assertFalse($this->schoolClass->isTaughtBy($this->substitute));
    }

    public function test_an_expired_substitution_grants_nothing(): void
    {
        $this->substitution(['starts_on' => today()->subDays(10), 'ends_on' => today()->subDay()]);

        $this->assertFalse($this->schoolClass->isTaughtBy($this->substitute));
        $this->assertSame([], SchoolClass::query()->taughtBy($this->substitute)->pluck('id')->all());
    }

    public function test_a_substitution_that_has_not_started_grants_nothing_until_it_does(): void
    {
        $this->substitution(['starts_on' => today()->addDays(2), 'ends_on' => today()->addDays(5)]);

        $this->assertFalse($this->schoolClass->isTaughtBy($this->substitute));

        $this->travel(2)->days();

        $this->assertTrue($this->schoolClass->isTaughtBy($this->substitute));
    }

    public function test_a_substitution_only_covers_its_own_class_and_its_own_substitute(): void
    {
        $otherClass = SchoolClass::factory()->for($this->school)->create(['teacher_id' => $this->leader->id]);
        $colleague = User::factory()->teacher()->for($this->school)->create();
        $this->substitution();

        $this->assertFalse($otherClass->isTaughtBy($this->substitute));
        $this->assertFalse($this->schoolClass->isTaughtBy($colleague));
    }

    public function test_a_substitution_never_reaches_across_schools(): void
    {
        $this->substitution();
        $this->substitute->update(['school_id' => School::factory()->create()->id]);

        $this->assertFalse($this->schoolClass->fresh()->isTaughtBy($this->substitute->fresh()));
    }

    public function test_leaders_and_assigned_teachers_keep_teaching_without_a_substitution(): void
    {
        $assigned = User::factory()->teacher()->for($this->school)->create();
        $this->schoolClass->teachers()->attach($assigned);

        $this->assertTrue($this->schoolClass->isTaughtBy($this->leader));
        $this->assertTrue($this->schoolClass->isTaughtBy($assigned));
        $this->assertFalse($this->schoolClass->isTaughtBy($this->substitute));
    }

    public function test_an_active_substitute_may_place_lessons_in_the_class_until_it_ends(): void
    {
        $this->assertFalse($this->substitute->can('createFor', [Lesson::class, $this->schoolClass]));

        $substitution = $this->substitution();

        $this->assertTrue($this->substitute->can('createFor', [Lesson::class, $this->schoolClass]));

        $substitution->end();

        $this->assertFalse($this->substitute->fresh()->can('createFor', [Lesson::class, $this->schoolClass]));
    }

    public function test_the_substitute_sees_the_learning_data_of_the_class_only_during_the_period(): void
    {
        $student = User::factory()->student($this->schoolClass)->create();

        $this->assertFalse(LearningInsights::canView($this->substitute, $student));

        $this->substitution(['starts_on' => today(), 'ends_on' => today()->addDay()]);

        $this->assertTrue(LearningInsights::canView($this->substitute, $student));
        $this->assertSame([$this->schoolClass->id], LearningInsights::visibleClasses($this->substitute)->pluck('id')->all());

        $this->travel(2)->days();

        $this->assertFalse(LearningInsights::canView($this->substitute, $student));
        $this->assertSame([], LearningInsights::visibleClasses($this->substitute)->pluck('id')->all());
    }

    public function test_ending_a_running_substitution_keeps_it_as_a_record_that_ended_yesterday(): void
    {
        $substitution = $this->substitution(['starts_on' => today()->subDays(3), 'ends_on' => today()->addDays(3)]);

        $substitution->end();

        $this->assertSame(today()->subDay()->toDateString(), $substitution->fresh()->ends_on->toDateString());
        $this->assertSame('ended', $substitution->fresh()->status());
    }

    public function test_ending_one_that_has_not_started_withdraws_it(): void
    {
        $upcoming = $this->substitution(['starts_on' => today()->addDays(3), 'ends_on' => today()->addDays(6)]);

        $upcoming->end();

        $this->assertModelMissing($upcoming);
        $this->assertFalse($this->schoolClass->isTaughtBy($this->substitute));
    }

    public function test_ending_one_that_starts_today_keeps_it_as_a_record_that_grants_nothing(): void
    {
        $startingToday = $this->substitution(['starts_on' => today(), 'ends_on' => today()->addDays(6)]);

        $startingToday->end();

        $this->assertModelExists($startingToday);
        $this->assertSame('ended', $startingToday->fresh()->status());
        $this->assertFalse($this->schoolClass->isTaughtBy($this->substitute));
        $this->assertSame(0, ClassSubstitution::daysPlanned($this->schoolClass, $this->substitute->id));
    }

    public function test_the_status_tells_active_upcoming_and_ended_apart(): void
    {
        $this->assertSame('active', ClassSubstitution::factory()->for($this->schoolClass)->create()->status());
        $this->assertSame('upcoming', ClassSubstitution::factory()->for($this->schoolClass)->upcoming()->create()->status());
        $this->assertSame('ended', ClassSubstitution::factory()->for($this->schoolClass)->ended()->create()->status());
    }

    public function test_deleting_the_class_or_the_substitute_removes_the_substitution(): void
    {
        $forClass = $this->substitution();
        $otherClass = SchoolClass::factory()->for($this->school)->create(['teacher_id' => $this->leader->id]);
        $forSubstitute = ClassSubstitution::factory()->for($otherClass)->create(['substitute_id' => $this->substitute->id]);

        $this->schoolClass->delete();
        $this->substitute->delete();

        $this->assertModelMissing($forClass);
        $this->assertModelMissing($forSubstitute);
    }

    public function test_the_substitution_survives_the_account_of_who_set_it_up(): void
    {
        $admin = User::factory()->admin()->for($this->school)->create();
        $substitution = $this->substitution(['granted_by' => $admin->id]);

        $admin->delete();

        $this->assertModelExists($substitution);
        $this->assertNull($substitution->fresh()->granted_by);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function substitution(array $attributes = []): ClassSubstitution
    {
        return ClassSubstitution::factory()->for($this->schoolClass)->create([
            'substitute_id' => $this->substitute->id,
            'granted_by' => $this->leader->id,
            ...$attributes,
        ]);
    }
}
