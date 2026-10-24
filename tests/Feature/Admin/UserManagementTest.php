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
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->admin = User::factory()->admin()->for($this->school)->create();
        $this->actingAs($this->admin);
    }

    public function test_admin_lists_and_filters_users_by_role(): void
    {
        $teacher = User::factory()->teacher()->for($this->school)->create();
        $student = User::factory()->student($this->ownClass())->create();

        Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$this->admin, $teacher, $student])
            ->filterTable('role', Role::Teacher->value)
            ->assertCanSeeTableRecords([$teacher])
            ->assertCanNotSeeTableRecords([$this->admin, $student]);
    }

    public function test_admin_creates_a_student_who_can_log_in_with_the_new_password(): void
    {
        $schoolClass = $this->ownClass();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Mia Becker',
                'username' => 'mia.becker',
                'password' => 'sicheres-passwort-7',
                'role' => Role::Student->value,
                'school_class_id' => $schoolClass->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('username', 'mia.becker')->sole();
        $this->assertSame(Role::Student, $user->role);
        $this->assertTrue($user->schoolClass->is($schoolClass));
        $this->assertTrue($user->school->is($this->school));
        $this->assertNotSame('sicheres-passwort-7', $user->password);
        $this->assertTrue(Hash::check('sicheres-passwort-7', $user->password));

        Auth::logout();

        $this->assertTrue(Auth::attempt(['username' => 'mia.becker', 'password' => 'sicheres-passwort-7']));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_student_needs_a_class(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Ohne Klasse',
                'username' => 'ohne.klasse',
                'password' => 'sicheres-passwort-7',
                'role' => Role::Student->value,
                'school_class_id' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['school_class_id' => 'required']);

        $this->assertDatabaseMissing(User::class, ['username' => 'ohne.klasse']);
    }

    public function test_a_password_is_required_when_creating_a_user(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Lena Lehrerin',
                'username' => 'lena',
                'role' => Role::Teacher->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['password' => 'required']);
    }

    public function test_usernames_are_unique_across_all_schools(): void
    {
        User::factory()->teacher()->create(['username' => 'lehrer']);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Zweite Lehrkraft',
                'username' => 'lehrer',
                'password' => 'sicheres-passwort-7',
                'role' => Role::Teacher->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['username' => 'unique']);
    }

    public function test_editing_without_a_password_keeps_the_old_one(): void
    {
        $teacher = User::factory()->teacher()->for($this->school)->create(['password' => 'altes-passwort-1']);

        Livewire::test(EditUser::class, ['record' => $teacher->getRouteKey()])
            ->fillForm(['name' => 'Neuer Name', 'password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $teacher->refresh();
        $this->assertSame('Neuer Name', $teacher->name);
        $this->assertTrue(Hash::check('altes-passwort-1', $teacher->password));
    }

    public function test_editing_with_a_password_replaces_it(): void
    {
        $teacher = User::factory()->teacher()->for($this->school)->create(['password' => 'altes-passwort-1']);

        Livewire::test(EditUser::class, ['record' => $teacher->getRouteKey()])
            ->fillForm(['password' => 'neues-passwort-2'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('neues-passwort-2', $teacher->refresh()->password));
    }

    public function test_turning_a_student_into_a_teacher_removes_the_class(): void
    {
        $student = User::factory()->student($this->ownClass())->create();

        Livewire::test(EditUser::class, ['record' => $student->getRouteKey()])
            ->assertFormFieldVisible('school_class_id')
            ->fillForm(['role' => Role::Teacher->value])
            ->assertFormFieldHidden('school_class_id')
            ->call('save')
            ->assertHasNoFormErrors();

        $student->refresh();
        $this->assertSame(Role::Teacher, $student->role);
        $this->assertNull($student->school_class_id);
        $this->assertTrue($student->school->is($this->school));
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $teacher = User::factory()->teacher()->for($this->school)->create();

        Livewire::test(EditUser::class, ['record' => $this->admin->getRouteKey()])
            ->assertActionHidden(DeleteAction::class);

        Livewire::test(EditUser::class, ['record' => $teacher->getRouteKey()])
            ->assertActionVisible(DeleteAction::class);

        Livewire::test(ListUsers::class)
            ->selectTableRecords([$this->admin->getKey(), $teacher->getKey()])
            ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

        $this->assertModelExists($this->admin);
        $this->assertModelMissing($teacher);
    }

    public function test_admin_sees_only_the_users_of_their_own_school(): void
    {
        $colleague = User::factory()->teacher()->for($this->school)->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherTeacher = User::factory()->teacher()->for($otherAdmin->school)->create(['name' => 'Fremde Lehrkraft']);
        $otherStudent = User::factory()->student($this->classOf($otherAdmin->school))->create();
        $operator = User::factory()->operator()->create();

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$this->admin, $colleague])
            ->assertCanNotSeeTableRecords([$otherAdmin, $otherTeacher, $otherStudent, $operator])
            ->searchTable('Fremde Lehrkraft')
            ->assertCountTableRecords(0);
    }

    public function test_admin_cannot_open_users_of_other_schools_or_operators(): void
    {
        $otherAdmin = User::factory()->admin()->create();
        $otherStudent = User::factory()->student($this->classOf($otherAdmin->school))->create();
        $operator = User::factory()->operator()->create();

        foreach ([$otherAdmin, $otherStudent, $operator] as $user) {
            $this->get(UserResource::getUrl('edit', ['record' => $user]))->assertNotFound();
        }

        $this->get(UserResource::getUrl('edit', ['record' => User::factory()->teacher()->for($this->school)->create()]))->assertOk();
    }

    public function test_the_school_filter_and_column_exist_for_operators_only(): void
    {
        Livewire::test(ListUsers::class)
            ->assertTableColumnVisible('schoolClass.name')
            ->assertTableColumnHidden('school.name')
            ->assertTableFilterVisible('school_class_id')
            ->assertTableFilterHidden('school_id');
    }

    public function test_the_class_filter_and_select_offer_only_classes_of_the_own_school(): void
    {
        $ownClass = $this->ownClass();
        $this->classOf(School::factory()->create());
        $ownStudent = User::factory()->student($ownClass)->create();

        Livewire::test(ListUsers::class)
            ->filterTable('school_class_id', $ownClass->id)
            ->assertCanSeeTableRecords([$ownStudent])
            ->assertCanNotSeeTableRecords([$this->admin]);

        Livewire::test(CreateUser::class)
            ->fillForm(['role' => Role::Student->value])
            ->assertFormFieldExists('school_class_id', fn (Select $field): bool => array_map(intval(...), array_keys($field->getOptions())) === [$ownClass->id]);
    }

    public function test_a_new_user_always_belongs_to_the_school_of_the_admin_who_creates_it(): void
    {
        $otherSchool = School::factory()->create();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Lena Lehrerin',
                'username' => 'lena',
                'password' => 'sicheres-passwort-7',
                'role' => Role::Teacher->value,
            ])
            // Admins have no school field, so a school sent along anyway must be ignored.
            ->set('data.school_id', $otherSchool->id)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($this->school->id, User::where('username', 'lena')->sole()->school_id);
    }

    public function test_the_school_select_is_hidden_for_admins(): void
    {
        Livewire::test(CreateUser::class)->assertFormFieldHidden('school_id');
    }

    public function test_admins_cannot_create_operators_or_other_unknown_roles(): void
    {
        foreach ([Role::Operator->value, 'superuser'] as $forgedRole) {
            Livewire::test(CreateUser::class)
                ->fillForm([
                    'name' => 'Falsche Rolle',
                    'username' => 'falsche.rolle',
                    'password' => 'sicheres-passwort-7',
                    'role' => $forgedRole,
                ])
                ->call('create')
                ->assertHasFormErrors(['role']);
        }

        $this->assertDatabaseMissing(User::class, ['username' => 'falsche.rolle']);
    }

    public function test_the_role_options_do_not_offer_operators_to_admins(): void
    {
        $this->assertSame([Role::Admin, Role::Teacher, Role::Student], UserResource::assignableRoles());
    }

    public function test_admins_cannot_promote_a_user_to_operator_by_editing(): void
    {
        $teacher = User::factory()->teacher()->for($this->school)->create();

        Livewire::test(EditUser::class, ['record' => $teacher->getRouteKey()])
            ->fillForm(['role' => Role::Operator->value])
            ->call('save')
            ->assertHasFormErrors(['role']);

        $this->assertSame(Role::Teacher, $teacher->refresh()->role);
    }

    public function test_a_student_cannot_be_put_into_a_class_of_another_school(): void
    {
        $foreignClass = $this->classOf(School::factory()->create());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Fremde Klasse',
                'username' => 'fremde.klasse',
                'password' => 'sicheres-passwort-7',
                'role' => Role::Student->value,
                'school_class_id' => $foreignClass->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['school_class_id']);

        $this->assertDatabaseMissing(User::class, ['username' => 'fremde.klasse']);

        $student = User::factory()->student($this->ownClass())->create();

        Livewire::test(EditUser::class, ['record' => $student->getRouteKey()])
            ->fillForm(['school_class_id' => $foreignClass->id])
            ->call('save')
            ->assertHasFormErrors(['school_class_id']);

        $this->assertNotSame($foreignClass->id, $student->refresh()->school_class_id);
    }

    public function test_a_class_of_another_school_is_rejected_even_when_the_role_is_not_student(): void
    {
        $foreignClass = $this->classOf(School::factory()->create());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Lena Lehrerin',
                'username' => 'lena',
                'password' => 'sicheres-passwort-7',
                'role' => Role::Teacher->value,
            ])
            ->set('data.school_class_id', $foreignClass->id)
            ->call('create')
            ->assertHasFormErrors(['school_class_id']);

        $this->assertDatabaseMissing(User::class, ['username' => 'lena']);
    }

    private function ownClass(): SchoolClass
    {
        return $this->classOf($this->school);
    }

    private function classOf(School $school): SchoolClass
    {
        return SchoolClass::factory()->for($school)->create(['teacher_id' => null]);
    }
}
