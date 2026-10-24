<?php

namespace Tests\Feature\Schools;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\Register;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BlockedSchoolTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{Role}>
     */
    public static function rolesOfASchool(): array
    {
        return [
            'Schuladministration' => [Role::Admin],
            'Lehrkraft' => [Role::Teacher],
            'Schüler:in' => [Role::Student],
        ];
    }

    #[DataProvider('rolesOfASchool')]
    public function test_a_user_of_a_blocked_school_gets_a_clear_message_instead_of_a_generic_failure(Role $role): void
    {
        $school = School::factory()->blocked()->create();
        User::factory()->for($school)->create(['username' => 'gesperrt', 'role' => $role, 'password' => 'geheim-2026']);

        Livewire::test(Login::class)
            ->fillForm(['username' => 'gesperrt', 'password' => 'geheim-2026'])
            ->call('authenticate')
            ->assertHasFormErrors(['username'])
            ->assertSee('Your school is currently blocked. Please contact the platform administration.');

        $this->assertGuest();
    }

    public function test_the_blocked_message_is_only_shown_to_someone_who_knows_the_password(): void
    {
        $school = School::factory()->blocked()->create();
        User::factory()->for($school)->create(['username' => 'gesperrt', 'password' => 'geheim-2026']);

        Livewire::test(Login::class)
            ->fillForm(['username' => 'gesperrt', 'password' => 'falsch'])
            ->call('authenticate')
            ->assertHasFormErrors(['username'])
            ->assertDontSee('blocked. Please contact', false)
            ->assertSee(__('filament-panels::auth/pages/login.messages.failed'));

        Livewire::test(Login::class)
            ->fillForm(['username' => 'gibt-es-nicht', 'password' => 'geheim-2026'])
            ->call('authenticate')
            ->assertDontSee('blocked. Please contact', false);

        $this->assertGuest();
    }

    public function test_an_unblocked_school_logs_in_normally_while_another_school_is_blocked(): void
    {
        School::factory()->blocked()->create();
        $school = School::factory()->create();
        $user = User::factory()->for($school)->teacher()->create(['username' => 'frei', 'password' => 'geheim-2026']);

        Livewire::test(Login::class)
            ->fillForm(['username' => 'frei', 'password' => 'geheim-2026'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_operator_belongs_to_no_school_and_is_never_locked_out(): void
    {
        School::factory()->blocked()->create();
        $operator = User::factory()->operator()->create(['username' => 'betrieb', 'password' => 'geheim-2026']);

        Livewire::test(Login::class)
            ->fillForm(['username' => 'betrieb', 'password' => 'geheim-2026'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($operator);
    }

    #[DataProvider('rolesOfASchool')]
    public function test_a_user_who_is_signed_in_when_the_school_gets_blocked_is_locked_out_on_the_next_request(Role $role): void
    {
        $school = School::factory()->create();
        $user = User::factory()->for($school)->create(['role' => $role]);

        $this->actingAs($user)->get('/')->assertOk();

        $school->forceFill(['blocked_at' => now()])->save();

        // A real request loads the user afresh; the test's user object would still hold the school from before.
        $this->actingAs($user->fresh())->get('/')->assertRedirect(route('filament.lernplattform.auth.login'));
        $this->assertGuest();

        $school->forceFill(['blocked_at' => null])->save();

        $this->actingAs($user->fresh())->get('/')->assertOk();
    }

    public function test_a_blocked_user_who_is_still_signed_in_is_signed_out_when_they_open_the_login_page(): void
    {
        $school = School::factory()->blocked()->create();
        $user = User::factory()->for($school)->teacher()->create();

        $this->actingAs($user)
            ->get('/login')
            ->assertOk()
            ->assertSee('Your school is currently blocked.');

        $this->assertGuest();
    }

    public function test_a_user_of_an_active_school_who_opens_the_login_page_stays_signed_in(): void
    {
        $user = User::factory()->teacher()->create();

        $this->actingAs($user)->get('/login');

        $this->assertAuthenticatedAs($user);
    }

    public function test_pupils_cannot_register_into_the_class_of_a_blocked_school(): void
    {
        $school = School::factory()->blocked()->create();
        SchoolClass::factory()->for($school)->create(['join_code' => 'ABCDEF']);

        Livewire::test(Register::class)
            ->fillForm([
                'name' => 'Maxi Muster',
                'username' => 'maxi.muster',
                'classCode' => 'ABCDEF',
                'password' => 'geheim-2026',
                'passwordConfirmation' => 'geheim-2026',
            ])
            ->call('register')
            ->assertHasFormErrors(['classCode'])
            ->assertSee('This class code does not exist. Ask your teacher.')
            ->assertDontSee('blocked');

        $this->assertDatabaseMissing(User::class, ['username' => 'maxi.muster']);
        $this->assertGuest();
    }

    public function test_the_class_code_works_again_after_the_school_is_unblocked(): void
    {
        $school = School::factory()->blocked()->create();
        SchoolClass::factory()->for($school)->create(['join_code' => 'ABCDEF']);
        $school->forceFill(['blocked_at' => null])->save();

        Livewire::test(Register::class)
            ->fillForm([
                'name' => 'Maxi Muster',
                'username' => 'maxi.muster',
                'classCode' => 'ABCDEF',
                'password' => 'geheim-2026',
                'passwordConfirmation' => 'geheim-2026',
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $this->assertSame($school->id, User::where('username', 'maxi.muster')->sole()->school_id);
    }
}
