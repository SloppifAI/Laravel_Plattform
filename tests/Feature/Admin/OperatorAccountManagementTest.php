<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The platform operator sets up the school administrations. It never sees teachers or students.
 */
class OperatorAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->operator()->create();
        $this->actingAs($this->operator);
    }

    public function test_operator_lists_only_administration_accounts_of_all_schools_with_their_school(): void
    {
        $schoolA = School::factory()->create(['name' => 'Goethe-Gymnasium']);
        $schoolB = School::factory()->create(['name' => 'Realschule am Park']);
        $adminA = User::factory()->admin()->for($schoolA)->create();
        $adminB = User::factory()->admin()->for($schoolB)->create();
        $teacher = User::factory()->teacher()->for($schoolA)->create();
        $student = User::factory()->student(SchoolClass::factory()->for($schoolA)->create(['teacher_id' => null]))->create();

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$adminA, $adminB, $this->operator])
            ->assertCanNotSeeTableRecords([$teacher, $student])
            ->assertTableColumnVisible('school.name')
            ->assertTableColumnHidden('schoolClass.name')
            ->assertTableColumnStateSet('school.name', 'Goethe-Gymnasium', $adminA)
            ->assertTableFilterVisible('school_id')
            ->assertTableFilterHidden('school_class_id')
            ->filterTable('school_id', $schoolB->id)
            ->assertCanSeeTableRecords([$adminB])
            ->assertCanNotSeeTableRecords([$adminA, $this->operator]);
    }

    public function test_operator_cannot_find_teachers_or_students_by_searching_or_filtering(): void
    {
        $teacher = User::factory()->teacher()->create(['name' => 'Frau Lehrerin']);

        Livewire::test(ListUsers::class)
            ->searchTable('Frau Lehrerin')
            ->assertCanNotSeeTableRecords([$teacher])
            ->assertCountTableRecords(0);
    }

    public function test_operator_cannot_open_teachers_or_students(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $student = User::factory()->student($schoolClass)->create();

        $this->get(UserResource::getUrl('edit', ['record' => $schoolClass->teacher]))->assertNotFound();
        $this->get(UserResource::getUrl('edit', ['record' => $student]))->assertNotFound();
    }

    public function test_the_operator_may_only_hand_out_the_administration_roles(): void
    {
        $this->assertSame([Role::Admin, Role::Operator], UserResource::assignableRoles());
    }

    public function test_operator_creates_a_school_administration_in_a_school(): void
    {
        $school = School::factory()->create();

        Livewire::test(CreateUser::class)
            ->assertFormFieldVisible('school_id')
            ->fillForm([
                'name' => 'Schulleitung',
                'username' => 'schulleitung',
                'password' => 'sicheres-passwort-7',
                'role' => Role::Admin->value,
                'school_id' => $school->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $admin = User::where('username', 'schulleitung')->sole();
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertTrue($admin->school->is($school));
        $this->assertNull($admin->school_class_id);
    }

    public function test_a_school_administration_needs_a_school_that_exists(): void
    {
        $form = [
            'name' => 'Schulleitung',
            'username' => 'schulleitung',
            'password' => 'sicheres-passwort-7',
            'role' => Role::Admin->value,
        ];

        Livewire::test(CreateUser::class)
            ->fillForm($form + ['school_id' => null])
            ->call('create')
            ->assertHasFormErrors(['school_id' => 'required'])
            ->fillForm($form + ['school_id' => 987654])
            ->call('create')
            ->assertHasFormErrors(['school_id']);

        $this->assertDatabaseMissing(User::class, ['username' => 'schulleitung']);
    }

    public function test_operator_creates_another_operator_without_a_school(): void
    {
        $school = School::factory()->create();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Zweite Plattformleitung',
                'username' => 'plattform.zwei',
                'password' => 'sicheres-passwort-7',
                'role' => Role::Operator->value,
            ])
            ->assertFormFieldHidden('school_id')
            // A school that is still in the state from before the role was switched must not stick to the operator.
            ->set('data.school_id', $school->id)
            ->call('create')
            ->assertHasNoFormErrors();

        $operator = User::where('username', 'plattform.zwei')->sole();
        $this->assertSame(Role::Operator, $operator->role);
        $this->assertNull($operator->school_id);
    }

    public function test_operator_cannot_create_teachers_or_students(): void
    {
        $school = School::factory()->create();

        foreach ([Role::Teacher, Role::Student] as $role) {
            Livewire::test(CreateUser::class)
                ->fillForm([
                    'name' => 'Nicht erlaubt',
                    'username' => 'nicht.erlaubt',
                    'password' => 'sicheres-passwort-7',
                    'role' => $role->value,
                    'school_id' => $school->id,
                ])
                ->call('create')
                ->assertHasFormErrors(['role']);
        }

        $this->assertDatabaseMissing(User::class, ['username' => 'nicht.erlaubt']);
    }

    public function test_usernames_stay_unique_across_schools(): void
    {
        User::factory()->teacher()->create(['username' => 'schulleitung']);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Schulleitung',
                'username' => 'schulleitung',
                'password' => 'sicheres-passwort-7',
                'role' => Role::Admin->value,
                'school_id' => School::factory()->create()->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['username' => 'unique']);
    }

    public function test_operator_resets_the_password_of_a_school_administration(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'altes-passwort-1']);

        Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->assertFormSet(['school_id' => $admin->school_id])
            ->fillForm(['password' => 'neues-passwort-2'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(password_verify('neues-passwort-2', $admin->refresh()->password));
    }

    public function test_role_and_school_of_an_existing_account_cannot_be_changed(): void
    {
        $admin = User::factory()->admin()->create();
        $originalSchoolId = $admin->school_id;

        Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->assertFormFieldIsDisabled('role')
            ->assertFormFieldIsDisabled('school_id')
            ->set('data.role', Role::Operator->value)
            ->set('data.school_id', School::factory()->create()->id)
            ->call('save')
            ->assertHasNoFormErrors();

        $admin->refresh();
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertSame($originalSchoolId, $admin->school_id);
    }

    public function test_operator_deletes_an_administration_account_but_not_their_own(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::test(EditUser::class, ['record' => $this->operator->getRouteKey()])
            ->assertActionHidden(DeleteAction::class);

        Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($admin);
        $this->assertModelExists($this->operator);
    }

    public function test_the_page_calls_the_accounts_by_what_they_are(): void
    {
        Livewire::test(ListUsers::class)->assertSee('Administrationskonten');
    }
}
