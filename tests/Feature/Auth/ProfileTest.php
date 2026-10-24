<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function roles(): array
    {
        return [
            'admin' => ['admin'],
            'teacher' => ['teacher'],
            'student' => ['student'],
        ];
    }

    #[DataProvider('roles')]
    public function test_every_role_can_open_its_profile_from_the_user_menu(string $role): void
    {
        $user = User::factory()->{$role}()->create(['username' => 'profil.test']);
        $profileUrl = route('filament.lernplattform.auth.profile');

        $this->actingAs($user)->get('/')->assertOk()->assertSee($profileUrl, false);

        $this->get($profileUrl)
            ->assertOk()
            ->assertSee('My profile')
            ->assertSee('profil.test');
    }

    public function test_name_and_email_can_be_changed_and_the_email_removed_again(): void
    {
        $user = User::factory()->teacher()->create(['email' => 'alt@example.test', 'password' => 'geheim-2026']);
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm(['name' => 'Neuer Name', 'email' => 'neu@example.test', 'currentPassword' => 'geheim-2026'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Neuer Name', $user->refresh()->name);
        $this->assertSame('neu@example.test', $user->email);

        Livewire::test(EditProfile::class)
            ->fillForm(['email' => '', 'currentPassword' => 'geheim-2026'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($user->refresh()->email);
    }

    public function test_an_email_address_is_not_required(): void
    {
        $user = User::factory()->teacher()->create(['email' => null]);
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm(['name' => 'Nur der Name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nur der Name', $user->refresh()->name);
    }

    public function test_changing_the_password_requires_the_current_one(): void
    {
        $user = User::factory()->create(['password' => 'geheim-2026']);
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm(['password' => 'neues-passwort-1', 'passwordConfirmation' => 'neues-passwort-1'])
            ->call('save')
            ->assertHasFormErrors(['currentPassword' => 'required']);

        $this->assertTrue(Hash::check('geheim-2026', $user->refresh()->password));
    }

    public function test_a_wrong_current_password_does_not_change_the_password(): void
    {
        $user = User::factory()->create(['password' => 'geheim-2026']);
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'password' => 'neues-passwort-1',
                'passwordConfirmation' => 'neues-passwort-1',
                'currentPassword' => 'falsch-geraten',
            ])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        $this->assertTrue(Hash::check('geheim-2026', $user->refresh()->password));
    }

    public function test_the_password_changes_with_the_current_one_and_stays_when_left_empty(): void
    {
        $user = User::factory()->create(['password' => 'geheim-2026']);
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'password' => 'neues-passwort-1',
                'passwordConfirmation' => 'neues-passwort-1',
                'currentPassword' => 'geheim-2026',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('neues-passwort-1', $user->refresh()->password));

        Livewire::test(EditProfile::class)
            ->fillForm(['name' => 'Anderer Name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('neues-passwort-1', $user->refresh()->password));
    }

    public function test_students_cannot_rename_themselves_but_teachers_can(): void
    {
        $student = User::factory()->student()->create(['name' => 'Lena Muster']);
        $this->actingAs($student);

        Livewire::test(EditProfile::class)
            ->assertFormFieldIsDisabled('name')
            ->set('data.name', 'Frau Schmidt (Lehrerin)')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Lena Muster', $student->refresh()->name);

        $teacher = User::factory()->teacher()->create(['name' => 'Herr Alt']);
        $this->actingAs($teacher);

        Livewire::test(EditProfile::class)->assertFormFieldIsEnabled('name');
    }

    public function test_the_minimum_password_length_is_stated_up_front_and_in_words_when_missed(): void
    {
        $user = User::factory()->create(['password' => 'geheim-2026']);
        $this->actingAs($user);

        $page = Livewire::test(EditProfile::class)
            ->assertSee('At least 8 characters.')
            ->fillForm([
                'password' => 'max2026',
                'passwordConfirmation' => 'max2026',
                'currentPassword' => 'geheim-2026',
            ])
            ->call('save')
            ->assertHasFormErrors(['password']);

        $this->assertContains('Your password needs at least 8 characters.', $page->errors()->get('data.password'));
    }

    public function test_username_and_role_are_shown_but_cannot_be_edited(): void
    {
        $user = User::factory()->student()->create(['username' => 'maxi.muster']);
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->assertFormFieldIsDisabled('username')
            ->assertFormFieldIsDisabled('role')
            ->assertFormSet(['username' => 'maxi.muster', 'role' => 'Student'])
            ->set('data.username', 'admin')
            ->set('data.role', Role::Admin->value)
            ->set('data.school_class_id', null)
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertSame('maxi.muster', $user->username);
        $this->assertSame(Role::Student, $user->role);
        $this->assertNotNull($user->school_class_id);
    }
}
