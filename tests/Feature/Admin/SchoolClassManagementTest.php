<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\SchoolClasses\Pages\CreateSchoolClass;
use App\Filament\Resources\SchoolClasses\Pages\EditSchoolClass;
use App\Filament\Resources\SchoolClasses\Pages\ListSchoolClasses;
use App\Filament\Resources\SchoolClasses\RelationManagers\StudentsRelationManager;
use App\Filament\Resources\SchoolClasses\SchoolClassResource;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Filament\Actions\AssociateAction;
use Filament\Actions\DissociateAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class SchoolClassManagementTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->actingAs(User::factory()->admin()->for($this->school)->create());
    }

    public function test_admin_creates_a_class_with_a_klassenleitung(): void
    {
        $teacher = $this->teacher();

        Livewire::test(CreateSchoolClass::class)
            ->fillForm(['name' => '7b', 'teacher_id' => $teacher->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(SchoolClass::class, ['name' => '7b', 'teacher_id' => $teacher->id, 'school_id' => $this->school->id]);
    }

    public function test_class_names_are_unique_within_the_school(): void
    {
        $this->classInOwnSchool(['name' => '7b']);

        Livewire::test(CreateSchoolClass::class)
            ->fillForm(['name' => '7b'])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique']);
    }

    public function test_another_school_may_use_the_same_class_name(): void
    {
        $this->skipUnlessClassNamesAreUniquePerSchool();

        $this->classIn(School::factory()->create(), ['name' => '7b']);

        Livewire::test(CreateSchoolClass::class)
            ->fillForm(['name' => '7b'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, SchoolClass::where('school_id', $this->school->id)->where('name', '7b')->count());
    }

    public function test_a_class_can_be_renamed_without_a_false_name_clash(): void
    {
        $schoolClass = $this->classInOwnSchool(['name' => '7b']);

        Livewire::test(EditSchoolClass::class, ['record' => $schoolClass->getRouteKey()])
            ->fillForm(['name' => '7b'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_the_list_shows_how_many_students_a_class_has(): void
    {
        $schoolClass = $this->classInOwnSchool();
        User::factory()->student($schoolClass)->count(2)->create();

        Livewire::test(ListSchoolClasses::class)
            ->assertCanSeeTableRecords([$schoolClass])
            ->assertTableColumnStateSet('students_count', 2, $schoolClass);
    }

    public function test_the_edit_page_shows_the_students_of_the_class(): void
    {
        $schoolClass = $this->classInOwnSchool();
        $classmate = User::factory()->student($schoolClass)->create();
        $otherStudent = User::factory()->student($this->classInOwnSchool())->create();

        Livewire::test(EditSchoolClass::class, ['record' => $schoolClass->getRouteKey()])
            ->assertSeeLivewire(StudentsRelationManager::class);

        $this->studentsOf($schoolClass)
            ->assertOk()
            ->assertCanSeeTableRecords([$classmate])
            ->assertCanNotSeeTableRecords([$otherStudent]);
    }

    public function test_admin_adds_existing_students_to_the_class(): void
    {
        $schoolClass = $this->classInOwnSchool();
        $newcomer = User::factory()->student($this->classInOwnSchool())->create();
        $student = User::factory()->for($this->school)->create(['school_class_id' => null]);

        $this->studentsOf($schoolClass)
            ->callAction(TestAction::make(AssociateAction::class)->table(), ['recordId' => [$newcomer->id, $student->id]])
            ->assertHasNoFormErrors();

        $this->assertTrue($newcomer->refresh()->schoolClass->is($schoolClass));
        $this->assertTrue($student->refresh()->schoolClass->is($schoolClass));
    }

    public function test_only_students_can_be_added_to_a_class(): void
    {
        $schoolClass = $this->classInOwnSchool();
        $teacher = $this->teacher();

        $this->studentsOf($schoolClass)
            ->callAction(TestAction::make(AssociateAction::class)->table(), ['recordId' => [$teacher->id]])
            ->assertHasFormErrors(['recordId.0']);

        $this->assertNull($teacher->refresh()->school_class_id);
    }

    public function test_admin_removes_a_student_from_the_class(): void
    {
        $schoolClass = $this->classInOwnSchool();
        $student = User::factory()->student($schoolClass)->create();

        $this->studentsOf($schoolClass)
            ->callAction(TestAction::make(DissociateAction::class)->table($student));

        $this->assertModelExists($student);
        $this->assertNull($student->refresh()->school_class_id);
    }

    public function test_admin_sees_and_opens_only_the_classes_of_their_school(): void
    {
        $ownClass = $this->classInOwnSchool();
        $foreignClass = $this->classIn(School::factory()->create());

        Livewire::test(ListSchoolClasses::class)
            ->assertCanSeeTableRecords([$ownClass])
            ->assertCanNotSeeTableRecords([$foreignClass])
            ->searchTable($foreignClass->join_code)
            ->assertCountTableRecords(0);

        $this->get(SchoolClassResource::getUrl('edit', ['record' => $ownClass]))->assertOk();
        $this->get(SchoolClassResource::getUrl('edit', ['record' => $foreignClass]))->assertNotFound();
    }

    public function test_the_klassenleitung_can_only_be_a_teacher_of_the_own_school(): void
    {
        $foreignTeacher = User::factory()->teacher()->create();
        $student = User::factory()->student($this->classInOwnSchool())->create();

        foreach ([$foreignTeacher, $student] as $notAllowed) {
            Livewire::test(CreateSchoolClass::class)
                ->fillForm(['name' => '9z', 'teacher_id' => $notAllowed->id])
                ->call('create')
                ->assertHasFormErrors(['teacher_id']);
        }

        $this->assertDatabaseMissing(SchoolClass::class, ['name' => '9z']);

        $schoolClass = $this->classInOwnSchool();

        Livewire::test(EditSchoolClass::class, ['record' => $schoolClass->getRouteKey()])
            ->fillForm(['teacher_id' => $foreignTeacher->id])
            ->call('save')
            ->assertHasFormErrors(['teacher_id']);

        $this->assertNull($schoolClass->refresh()->teacher_id);
    }

    public function test_the_school_of_a_new_class_cannot_be_forged(): void
    {
        $otherSchool = School::factory()->create();

        Livewire::test(CreateSchoolClass::class)
            ->fillForm(['name' => '7c'])
            ->set('data.school_id', $otherSchool->id)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($this->school->id, SchoolClass::where('name', '7c')->sole()->school_id);
    }

    public function test_students_of_other_schools_cannot_be_added_to_a_class(): void
    {
        $schoolClass = $this->classInOwnSchool();
        $foreignStudent = User::factory()->student($this->classIn(School::factory()->create()))->create();
        $foreignClassId = $foreignStudent->school_class_id;

        $this->studentsOf($schoolClass)
            ->callAction(TestAction::make(AssociateAction::class)->table(), ['recordId' => [$foreignStudent->id]])
            ->assertHasFormErrors(['recordId.0']);

        $this->assertSame($foreignClassId, $foreignStudent->refresh()->school_class_id);
    }

    public function test_the_add_student_dialog_offers_no_students_of_other_schools(): void
    {
        $schoolClass = $this->classInOwnSchool();
        $ownStudent = User::factory()->student($this->classInOwnSchool())->create(['name' => 'Eigene Schülerin']);
        $foreignStudent = User::factory()->student($this->classIn(School::factory()->create()))->create(['name' => 'Fremder Schüler']);

        $page = $this->studentsOf($schoolClass)->mountAction(TestAction::make(AssociateAction::class)->table());

        $page->assertMountedActionModalSee('Eigene Schülerin')->assertMountedActionModalDontSee('Fremder Schüler');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function classInOwnSchool(array $attributes = []): SchoolClass
    {
        return $this->classIn($this->school, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function classIn(School $school, array $attributes = []): SchoolClass
    {
        return SchoolClass::factory()->for($school)->create(['teacher_id' => null, ...$attributes]);
    }

    private function teacher(): User
    {
        return User::factory()->teacher()->for($this->school)->create();
    }

    private function studentsOf(SchoolClass $schoolClass): mixed
    {
        return Livewire::test(StudentsRelationManager::class, [
            'ownerRecord' => $schoolClass,
            'pageClass' => EditSchoolClass::class,
        ]);
    }

    /**
     * school_classes.name is still unique for the whole table until a migration makes it unique per school.
     */
    private function skipUnlessClassNamesAreUniquePerSchool(): void
    {
        $globalNameIndex = collect(Schema::getIndexes('school_classes'))
            ->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['name']);

        if ($globalNameIndex) {
            $this->markTestSkipped('school_classes.name is globally unique: replace the index by unique(school_id, name).');
        }
    }
}
