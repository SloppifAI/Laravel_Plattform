<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\SchoolClasses\Pages\CreateSchoolClass;
use App\Filament\Resources\SchoolClasses\Pages\EditSchoolClass;
use App\Filament\Resources\SchoolClasses\Pages\ListSchoolClasses;
use App\Filament\Resources\SchoolClasses\RelationManagers\StudentsRelationManager;
use App\Models\SchoolClass;
use App\Models\User;
use Filament\Actions\AssociateAction;
use Filament\Actions\DissociateAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SchoolClassManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_admin_creates_a_class_with_a_klassenleitung(): void
    {
        $teacher = User::factory()->teacher()->create();

        Livewire::test(CreateSchoolClass::class)
            ->fillForm(['name' => '7b', 'teacher_id' => $teacher->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(SchoolClass::class, ['name' => '7b', 'teacher_id' => $teacher->id]);
    }

    public function test_class_names_are_unique(): void
    {
        SchoolClass::factory()->create(['name' => '7b']);

        Livewire::test(CreateSchoolClass::class)
            ->fillForm(['name' => '7b'])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique']);
    }

    public function test_the_list_shows_how_many_students_a_class_has(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        User::factory()->student($schoolClass)->count(2)->create();

        Livewire::test(ListSchoolClasses::class)
            ->assertCanSeeTableRecords([$schoolClass])
            ->assertTableColumnStateSet('students_count', 2, $schoolClass);
    }

    public function test_the_edit_page_shows_the_students_of_the_class(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $classmate = User::factory()->student($schoolClass)->create();
        $otherStudent = User::factory()->student()->create();

        Livewire::test(EditSchoolClass::class, ['record' => $schoolClass->getRouteKey()])
            ->assertSeeLivewire(StudentsRelationManager::class);

        $this->studentsOf($schoolClass)
            ->assertOk()
            ->assertCanSeeTableRecords([$classmate])
            ->assertCanNotSeeTableRecords([$otherStudent]);
    }

    public function test_admin_adds_existing_students_to_the_class(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $newcomer = User::factory()->student()->create();
        $student = User::factory()->create(['school_class_id' => null]);

        $this->studentsOf($schoolClass)
            ->callAction(TestAction::make(AssociateAction::class)->table(), ['recordId' => [$newcomer->id, $student->id]])
            ->assertHasNoFormErrors();

        $this->assertTrue($newcomer->refresh()->schoolClass->is($schoolClass));
        $this->assertTrue($student->refresh()->schoolClass->is($schoolClass));
    }

    public function test_only_students_can_be_added_to_a_class(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $teacher = User::factory()->teacher()->create();

        $this->studentsOf($schoolClass)
            ->callAction(TestAction::make(AssociateAction::class)->table(), ['recordId' => [$teacher->id]])
            ->assertHasFormErrors(['recordId.0']);

        $this->assertNull($teacher->refresh()->school_class_id);
    }

    public function test_admin_removes_a_student_from_the_class(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $student = User::factory()->student($schoolClass)->create();

        $this->studentsOf($schoolClass)
            ->callAction(TestAction::make(DissociateAction::class)->table($student));

        $this->assertModelExists($student);
        $this->assertNull($student->refresh()->school_class_id);
    }

    private function studentsOf(SchoolClass $schoolClass): mixed
    {
        return Livewire::test(StudentsRelationManager::class, [
            'ownerRecord' => $schoolClass,
            'pageClass' => EditSchoolClass::class,
        ]);
    }
}
