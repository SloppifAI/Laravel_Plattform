<?php

namespace Tests\Feature\ClassBook;

use App\Models\ClassBookEntry;
use App\Models\ClassSubstitution;
use App\Models\Lesson;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassBookAccessTest extends TestCase
{
    use CreatesClassBookFixtures;
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private User $leader;

    private User $assigned;

    private User $bystander;

    private User $foreignTeacher;

    private User $admin;

    private User $foreignAdmin;

    private User $student;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $school = School::factory()->create();
        [$this->schoolClass, $students, $this->leader] = $this->classWithStudents(2, $school);
        $this->assigned = User::factory()->for($school)->teacher()->create();
        $this->schoolClass->teachers()->attach($this->assigned);
        $this->bystander = User::factory()->for($school)->teacher()->create();
        $this->foreignTeacher = User::factory()->teacher()->create();
        $this->admin = User::factory()->for($school)->admin()->create();
        $this->foreignAdmin = User::factory()->admin()->create();
        $this->student = $students->first();
        $this->operator = User::factory()->operator()->create();
    }

    /**
     * @param  list<User>  $allowed
     */
    private function assertOnly(array $allowed, string $ability, mixed $arguments): void
    {
        $everybody = [
            'leader' => $this->leader, 'assigned' => $this->assigned, 'bystander' => $this->bystander,
            'foreignTeacher' => $this->foreignTeacher, 'admin' => $this->admin, 'foreignAdmin' => $this->foreignAdmin,
            'student' => $this->student, 'operator' => $this->operator,
        ];

        foreach ($everybody as $name => $user) {
            $this->assertSame(
                collect($allowed)->contains(fn (User $each): bool => $each->is($user)),
                $user->can($ability, $arguments),
                "{$ability} for {$name}",
            );
        }
    }

    public function test_teachers_and_administrators_may_open_the_klassenbuch_nobody_else(): void
    {
        $this->assertOnly(
            [$this->leader, $this->assigned, $this->bystander, $this->foreignTeacher, $this->admin, $this->foreignAdmin],
            'viewAny',
            ClassBookEntry::class,
        );
        $this->assertOnly(
            [$this->leader, $this->assigned, $this->bystander, $this->foreignTeacher, $this->admin, $this->foreignAdmin],
            'create',
            ClassBookEntry::class,
        );
    }

    public function test_only_the_teachers_of_the_class_and_the_administration_of_its_school_work_with_its_klassenbuch(): void
    {
        $entry = ClassBookEntry::factory()->create(['school_class_id' => $this->schoolClass->getKey(), 'teacher_id' => $this->leader->getKey()]);
        $allowed = [$this->leader, $this->assigned, $this->admin];

        $this->assertOnly($allowed, 'accessClass', [ClassBookEntry::class, $this->schoolClass]);
        $this->assertOnly($allowed, 'view', $entry);
    }

    public function test_a_teacher_who_no_longer_teaches_the_class_loses_access_to_its_entries(): void
    {
        $entry = ClassBookEntry::factory()->create(['school_class_id' => $this->schoolClass->getKey(), 'teacher_id' => $this->assigned->getKey()]);
        $this->assertTrue($this->assigned->can('update', $entry));

        $this->schoolClass->teachers()->detach($this->assigned);

        $this->assertFalse($this->assigned->can('view', $entry));
        $this->assertFalse($this->assigned->can('update', $entry));
    }

    public function test_a_substitute_sees_the_whole_klassenbuch_of_the_class_only_while_the_substitution_lasts(): void
    {
        $entry = ClassBookEntry::factory()->absent([$this->student])->create(['school_class_id' => $this->schoolClass->getKey(), 'teacher_id' => $this->leader->getKey(), 'held_on' => today()->subMonths(5)]);
        $substitution = ClassSubstitution::factory()->create(['school_class_id' => $this->schoolClass->getKey(), 'substitute_id' => $this->bystander->getKey()]);

        // Decided on purpose, see ClassBookEntryPolicy: the same rule as for the learning data of the class.
        $this->assertTrue($this->bystander->can('accessClass', [ClassBookEntry::class, $this->schoolClass]));
        $this->assertTrue($this->bystander->can('view', $entry));
        $this->assertFalse($this->bystander->can('update', $entry));
        $this->assertFalse($this->bystander->can('reopen', $entry));

        $substitution->update(['starts_on' => today()->addDay(), 'ends_on' => today()->addDays(7)]);
        $this->assertFalse($this->bystander->can('accessClass', [ClassBookEntry::class, $this->schoolClass]));

        $substitution->update(['starts_on' => today()->subDays(7), 'ends_on' => today()->subDay()]);
        $this->assertFalse($this->bystander->can('accessClass', [ClassBookEntry::class, $this->schoolClass]));
        $this->assertFalse($this->bystander->can('view', $entry));
    }

    public function test_a_confirmed_entry_stays_with_the_class_when_the_teacher_account_is_deleted(): void
    {
        $entry = ClassBookEntry::factory()->confirmed()->create(['school_class_id' => $this->schoolClass->getKey(), 'teacher_id' => $this->assigned->getKey()]);

        $this->assigned->delete();
        $entry->refresh();

        $this->assertNull($entry->teacher_id);
        $this->assertTrue($entry->isConfirmed());
        $this->assertOnly([$this->leader, $this->admin], 'view', $entry);
        $this->assertOnly([$this->admin], 'reopen', $entry);

        $entry->forceFill(['confirmed_at' => null])->save();

        // Nobody is "the teacher" of it any more; only the administration completes it.
        $this->assertOnly([$this->admin], 'update', $entry->fresh());
    }

    public function test_a_draft_is_made_from_a_lesson_by_its_teacher_or_the_administration(): void
    {
        $ofLeader = $this->lessonOf($this->schoolClass, $this->leader);
        $ofAssigned = $this->lessonOf($this->schoolClass, $this->assigned);

        $this->assertOnly([$this->leader, $this->admin], 'createFromLesson', [ClassBookEntry::class, $ofLeader]);
        $this->assertOnly([$this->assigned, $this->admin], 'createFromLesson', [ClassBookEntry::class, $ofAssigned]);
    }

    public function test_a_draft_is_edited_and_confirmed_by_its_teacher_or_the_administration(): void
    {
        $entry = ClassBookEntry::factory()->create(['school_class_id' => $this->schoolClass->getKey(), 'teacher_id' => $this->leader->getKey()]);

        $this->assertOnly([$this->leader, $this->admin], 'update', $entry);
        $this->assertOnly([$this->leader, $this->admin], 'confirm', $entry);
    }

    public function test_a_confirmed_entry_is_locked_for_everybody(): void
    {
        $entry = ClassBookEntry::factory()->confirmed()->create(['school_class_id' => $this->schoolClass->getKey(), 'teacher_id' => $this->leader->getKey()]);

        $this->assertOnly([], 'update', $entry);
        $this->assertOnly([], 'confirm', $entry);
    }

    public function test_only_the_administration_of_the_school_reopens_a_confirmed_entry(): void
    {
        $confirmed = ClassBookEntry::factory()->confirmed()->create(['school_class_id' => $this->schoolClass->getKey(), 'teacher_id' => $this->leader->getKey()]);
        $draft = ClassBookEntry::factory()->create(['school_class_id' => $this->schoolClass->getKey(), 'teacher_id' => $this->leader->getKey()]);

        $this->assertOnly([$this->admin], 'reopen', $confirmed);
        $this->assertOnly([], 'reopen', $draft);
    }

    public function test_a_lesson_of_another_class_gives_a_teacher_no_draft(): void
    {
        [$otherClass, , $otherLeader] = $this->classWithStudents(1, $this->schoolClass->school, 'Fremde Klasse');
        $lesson = Lesson::factory()->create(['school_class_id' => $otherClass->getKey(), 'teacher_id' => $this->leader->getKey()]);

        $this->assertFalse($this->leader->can('createFromLesson', [ClassBookEntry::class, $lesson]));
        $this->assertTrue($otherLeader->can('accessClass', [ClassBookEntry::class, $otherClass]));
    }
}
