<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    public function test_admin_lists_and_filters_users_by_role(): void
    {
        $teacher = User::factory()->teacher()->create();
        $student = User::factory()->student()->create();

        Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$this->admin, $teacher, $student])
            ->filterTable('role', Role::Teacher->value)
            ->assertCanSeeTableRecords([$teacher])
            ->assertCanNotSeeTableRecords([$this->admin, $student]);
    }

    public function test_admin_creates_a_student_who_can_log_in_with_the_new_password(): void
    {
        $schoolClass = SchoolClass::factory()->create();

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

    public function test_usernames_are_unique(): void
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
        $teacher = User::factory()->teacher()->create(['password' => 'altes-passwort-1']);

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
        $teacher = User::factory()->teacher()->create(['password' => 'altes-passwort-1']);

        Livewire::test(EditUser::class, ['record' => $teacher->getRouteKey()])
            ->fillForm(['password' => 'neues-passwort-2'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('neues-passwort-2', $teacher->refresh()->password));
    }

    public function test_turning_a_student_into_a_teacher_removes_the_class(): void
    {
        $student = User::factory()->student()->create();

        Livewire::test(EditUser::class, ['record' => $student->getRouteKey()])
            ->assertFormFieldVisible('school_class_id')
            ->fillForm(['role' => Role::Teacher->value])
            ->assertFormFieldHidden('school_class_id')
            ->call('save')
            ->assertHasNoFormErrors();

        $student->refresh();
        $this->assertSame(Role::Teacher, $student->role);
        $this->assertNull($student->school_class_id);
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $teacher = User::factory()->teacher()->create();

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
}
