<?php

namespace Tests\Feature\Substitutions;

use App\Models\ClassSubstitution;
use App\Models\Lesson;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubstitutionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private SchoolClass $schoolClass;

    private ClassSubstitution $substitution;

    private User $leader;

    private User $substitute;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->leader = User::factory()->teacher()->for($this->school)->create();
        $this->substitute = User::factory()->teacher()->for($this->school)->create();
        $this->schoolClass = SchoolClass::factory()->for($this->school)->create(['teacher_id' => $this->leader->id]);
        $this->substitution = ClassSubstitution::factory()->for($this->schoolClass)->create(['substitute_id' => $this->substitute->id]);
    }

    public function test_only_the_klassenleitung_and_the_school_administration_set_up_and_end_substitutions(): void
    {
        $admin = User::factory()->admin()->for($this->school)->create();
        $assigned = $this->teacher();
        $this->schoolClass->teachers()->attach($assigned);

        foreach ([$this->leader, $admin] as $allowed) {
            $this->assertTrue($allowed->can('createFor', [ClassSubstitution::class, $this->schoolClass]));
            $this->assertTrue($allowed->can('update', $this->substitution));
            $this->assertTrue($allowed->can('delete', $this->substitution));
            $this->assertTrue($allowed->can('view', $this->substitution));
        }

        foreach ($this->outsiders($assigned) as $name => $outsider) {
            $this->assertFalse($outsider->can('createFor', [ClassSubstitution::class, $this->schoolClass]), $name);
            $this->assertFalse($outsider->can('update', $this->substitution), $name);
            $this->assertFalse($outsider->can('delete', $this->substitution), $name);
        }
    }

    public function test_the_substitute_sees_their_own_substitution_but_cannot_change_it(): void
    {
        $this->assertTrue($this->substitute->can('view', $this->substitution));
        $this->assertFalse($this->substitute->can('update', $this->substitution));
        $this->assertFalse($this->substitute->can('delete', $this->substitution));
        $this->assertFalse($this->teacher()->can('view', $this->substitution));
    }

    public function test_a_leader_cannot_set_up_a_substitution_for_a_class_of_a_colleague(): void
    {
        $colleaguesClass = SchoolClass::factory()->for($this->school)->create(['teacher_id' => $this->teacher()->id]);

        $this->assertFalse($this->leader->can('createFor', [ClassSubstitution::class, $colleaguesClass]));
    }

    public function test_the_handover_sheet_is_for_the_klassenleitung_the_administration_and_the_active_substitute(): void
    {
        $admin = User::factory()->admin()->for($this->school)->create();
        $assigned = $this->teacher();
        $this->schoolClass->teachers()->attach($assigned);
        $held = $this->teacher();
        Lesson::factory()->for($this->schoolClass)->for($held, 'teacher')->create();

        foreach ([$this->leader, $admin, $this->substitute] as $allowed) {
            $this->assertTrue($allowed->can('viewHandoverSheet', [ClassSubstitution::class, $this->schoolClass]));
        }

        foreach ([...$this->outsiders($assigned), 'held a lesson' => $held] as $name => $outsider) {
            $this->assertFalse($outsider->can('viewHandoverSheet', [ClassSubstitution::class, $this->schoolClass]), $name);
        }
    }

    public function test_an_ended_or_upcoming_substitute_gets_no_handover_sheet(): void
    {
        $ended = $this->teacher();
        $upcoming = $this->teacher();
        ClassSubstitution::factory()->for($this->schoolClass)->ended()->create(['substitute_id' => $ended->id]);
        ClassSubstitution::factory()->for($this->schoolClass)->upcoming()->create(['substitute_id' => $upcoming->id]);

        $this->assertFalse($ended->can('viewHandoverSheet', [ClassSubstitution::class, $this->schoolClass]));
        $this->assertFalse($upcoming->can('viewHandoverSheet', [ClassSubstitution::class, $this->schoolClass]));

        $this->travel(3)->days();

        $this->assertTrue($upcoming->can('viewHandoverSheet', [ClassSubstitution::class, $this->schoolClass]));
        $this->assertFalse($ended->can('viewHandoverSheet', [ClassSubstitution::class, $this->schoolClass]));
    }

    public function test_a_substitute_of_another_school_never_gets_the_sheet(): void
    {
        $this->substitute->update(['school_id' => School::factory()->create()->id]);

        $this->assertFalse($this->substitute->fresh()->can('viewHandoverSheet', [ClassSubstitution::class, $this->schoolClass]));
    }

    /**
     * Everybody who is neither the Klassenleitung, the school's administration nor the substitute.
     *
     * @return array<string, User>
     */
    private function outsiders(User $assigned): array
    {
        return [
            'assigned teacher' => $assigned,
            'colleague' => $this->teacher(),
            'teacher of another school' => User::factory()->teacher()->create(),
            'admin of another school' => User::factory()->admin()->create(),
            'student' => User::factory()->student($this->schoolClass)->create(),
            'operator' => User::factory()->operator()->create(),
        ];
    }

    private function teacher(): User
    {
        return User::factory()->teacher()->for($this->school)->create();
    }
}
