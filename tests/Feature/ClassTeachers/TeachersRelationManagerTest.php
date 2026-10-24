<?php

namespace Tests\Feature\ClassTeachers;

use App\Filament\Resources\SchoolClasses\Pages\EditSchoolClass;
use App\Filament\Resources\SchoolClasses\Pages\ListSchoolClasses;
use App\Filament\Resources\SchoolClasses\RelationManagers\TeachersRelationManager;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TeachersRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private SchoolClass $schoolClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->schoolClass = SchoolClass::factory()->for($this->school)->create(['teacher_id' => $this->teacher()->id]);
        $this->actingAs(User::factory()->admin()->for($this->school)->create());
    }

    public function test_the_edit_page_lists_the_assigned_teachers_and_names_the_klassenleitung(): void
    {
        $assigned = $this->teacher();
        $notAssigned = $this->teacher();
        $this->schoolClass->teachers()->attach($assigned);

        Livewire::withQueryParams(['relation' => 1])
            ->test(EditSchoolClass::class, ['record' => $this->schoolClass->getRouteKey()])
            ->assertSeeLivewire(TeachersRelationManager::class);

        $this->teachersOfClass()
            ->assertOk()
            ->assertSee($this->schoolClass->teacher->name)
            ->assertCanSeeTableRecords([$assigned])
            ->assertCanNotSeeTableRecords([$notAssigned]);
    }

    public function test_the_klassenleitung_is_marked_when_they_are_assigned_as_well(): void
    {
        $other = $this->teacher();
        $this->schoolClass->teachers()->attach([$this->schoolClass->teacher_id, $other->id]);

        $this->teachersOfClass()
            ->assertTableColumnStateSet('is_klassenleitung', true, $this->schoolClass->teacher)
            ->assertTableColumnStateSet('is_klassenleitung', false, $other);
    }

    public function test_the_class_list_shows_how_many_further_teachers_a_class_has(): void
    {
        $this->schoolClass->teachers()->attach($this->teacher());

        Livewire::test(ListSchoolClasses::class)
            ->assertTableColumnStateSet('teachers_count', 1, $this->schoolClass);
    }

    public function test_admin_assigns_teachers_of_the_own_school(): void
    {
        $first = $this->teacher();
        $second = $this->teacher();

        $this->teachersOfClass()
            ->callAction(TestAction::make(AttachAction::class)->table(), ['recordId' => [$first->id, $second->id]])
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->schoolClass->teachers()->pluck('users.id')->all());
        $this->assertTrue($this->schoolClass->isTaughtBy($first));
    }

    public function test_the_dialog_offers_only_teachers_of_the_own_school_who_are_not_assigned_yet(): void
    {
        $this->teacher(['name' => 'Eigene Lehrerin']);
        User::factory()->teacher()->create(['name' => 'Fremder Lehrer']);
        User::factory()->student($this->schoolClass)->create(['name' => 'Eigener Schüler']);
        $this->schoolClass->teachers()->attach($this->teacher(['name' => 'Schon zugeordnet']));

        $this->teachersOfClass()
            ->mountAction(TestAction::make(AttachAction::class)->table())
            ->assertMountedActionModalSee('Eigene Lehrerin')
            ->assertMountedActionModalDontSee(['Fremder Lehrer', 'Eigener Schüler', 'Schon zugeordnet']);
    }

    public function test_forged_ids_of_other_people_cannot_be_assigned(): void
    {
        $foreignTeacher = User::factory()->teacher()->create();
        $student = User::factory()->student($this->schoolClass)->create();
        $admin = User::factory()->admin()->for($this->school)->create();
        $teacherWithoutSchool = User::factory()->teacher()->create(['school_id' => null]);

        foreach ([$foreignTeacher, $student, $admin, $teacherWithoutSchool] as $notAllowed) {
            $this->teachersOfClass()
                ->callAction(TestAction::make(AttachAction::class)->table(), ['recordId' => [$notAllowed->id]])
                ->assertHasFormErrors(['recordId.0']);
        }

        $this->assertSame(0, $this->schoolClass->teachers()->count());
    }

    public function test_assigning_an_already_assigned_teacher_does_not_duplicate_the_assignment(): void
    {
        $assigned = $this->teacher();
        $this->schoolClass->teachers()->attach($assigned);

        $this->teachersOfClass()
            ->callAction(TestAction::make(AttachAction::class)->table(), ['recordId' => [$assigned->id]]);

        $this->assertSame(1, $this->schoolClass->teachers()->count());
    }

    public function test_admin_removes_a_teacher_from_the_class_without_deleting_the_account(): void
    {
        $assigned = $this->teacher();
        $this->schoolClass->teachers()->attach($assigned);

        $this->teachersOfClass()
            ->callAction(TestAction::make(DetachAction::class)->table($assigned));

        $this->assertModelExists($assigned);
        $this->assertSame(0, $this->schoolClass->teachers()->count());
        $this->assertFalse($this->schoolClass->isTaughtBy($assigned));
    }

    public function test_teachers_cannot_open_the_class_administration(): void
    {
        $teacher = $this->teacher();
        $this->schoolClass->teachers()->attach($teacher);

        $this->actingAs($teacher)
            ->get(EditSchoolClass::getUrl(['record' => $this->schoolClass]))
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function teacher(array $attributes = []): User
    {
        return User::factory()->teacher()->for($this->school)->create($attributes);
    }

    private function teachersOfClass(): mixed
    {
        return Livewire::test(TeachersRelationManager::class, [
            'ownerRecord' => $this->schoolClass,
            'pageClass' => EditSchoolClass::class,
        ]);
    }
}
