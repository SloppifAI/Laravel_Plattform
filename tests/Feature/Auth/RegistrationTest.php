<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\Auth\Register;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function typedClassCodes(): array
    {
        return [
            'lowercase' => ['abcdef'],
            'uppercase with a space' => ['ABC DEF'],
            'padded with a dash' => [' abc-def '],
            'mixed case' => ['AbC-dEf'],
        ];
    }

    #[DataProvider('typedClassCodes')]
    public function test_a_valid_class_code_creates_a_student_in_that_class_and_logs_them_in(string $typedCode): void
    {
        $schoolClass = SchoolClass::factory()->create(['join_code' => 'ABCDEF']);

        Livewire::test(Register::class)
            ->fillForm($this->validInput(['classCode' => $typedCode]))
            ->call('register')
            ->assertHasNoFormErrors();

        $user = User::where('username', 'maxi.muster')->sole();

        $this->assertSame(Role::Student, $user->role);
        $this->assertTrue($user->schoolClass->is($schoolClass));
        $this->assertNull($user->email);
        $this->assertTrue(Hash::check('geheim-2026', $user->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_pupils_without_an_email_address_do_not_collide_on_the_empty_email(): void
    {
        SchoolClass::factory()->create(['join_code' => 'ABCDEF']);

        Livewire::test(Register::class)
            ->fillForm($this->validInput())
            ->call('register')
            ->assertHasNoFormErrors();
        auth()->logout();

        Livewire::test(Register::class)
            ->fillForm($this->validInput(['username' => 'lena.muster']))
            ->call('register')
            ->assertHasNoFormErrors();

        $this->assertSame(2, User::whereNull('email')->count());
    }

    public function test_an_email_address_is_optional_but_stored_when_given(): void
    {
        SchoolClass::factory()->create(['join_code' => 'ABCDEF']);

        Livewire::test(Register::class)
            ->fillForm($this->validInput(['email' => 'maxi@example.test']))
            ->call('register')
            ->assertHasNoFormErrors();

        $this->assertSame('maxi@example.test', User::where('username', 'maxi.muster')->sole()->email);
    }

    public function test_an_unknown_class_code_is_rejected_without_creating_a_user(): void
    {
        SchoolClass::factory()->create(['join_code' => 'ABCDEF']);

        Livewire::test(Register::class)
            ->fillForm($this->validInput(['classCode' => 'ZZZZZZ']))
            ->call('register')
            ->assertHasFormErrors(['classCode'])
            ->assertSee('Diesen Klassencode gibt es nicht. Frag deine Lehrkraft.');

        $this->assertDatabaseMissing(User::class, ['username' => 'maxi.muster']);
        $this->assertGuest();
    }

    public function test_a_class_code_made_only_of_spaces_and_dashes_is_rejected(): void
    {
        SchoolClass::factory()->create(['join_code' => 'ABCDEF']);

        Livewire::test(Register::class)
            ->fillForm($this->validInput(['classCode' => ' - - ']))
            ->call('register')
            ->assertHasFormErrors(['classCode']);

        $this->assertDatabaseMissing(User::class, ['username' => 'maxi.muster']);
    }

    public function test_the_role_and_class_cannot_be_injected_through_the_request(): void
    {
        $schoolClass = SchoolClass::factory()->create(['join_code' => 'ABCDEF']);
        $otherClass = SchoolClass::factory()->create(['join_code' => 'GHJKMN']);

        Livewire::test(Register::class)
            ->fillForm($this->validInput())
            ->set('data.role', Role::Admin->value)
            ->set('data.school_class_id', $otherClass->id)
            ->call('register')
            ->assertHasNoFormErrors();

        $user = User::where('username', 'maxi.muster')->sole();

        $this->assertSame(Role::Student, $user->role);
        $this->assertTrue($user->schoolClass->is($schoolClass));
        $this->assertSame(0, User::withRole(Role::Admin)->count());
    }

    public function test_a_taken_username_is_rejected_whatever_its_case(): void
    {
        SchoolClass::factory()->create(['join_code' => 'ABCDEF']);
        User::factory()->create(['username' => 'maxi.muster']);

        Livewire::test(Register::class)
            ->fillForm($this->validInput(['username' => 'Maxi.Muster']))
            ->call('register')
            ->assertHasFormErrors(['username' => 'unique']);

        $this->assertSame(1, User::where('username', 'maxi.muster')->count());
        $this->assertGuest();
    }

    public function test_usernames_are_lowercased_and_limited_to_simple_characters(): void
    {
        SchoolClass::factory()->create(['join_code' => 'ABCDEF']);

        Livewire::test(Register::class)
            ->fillForm($this->validInput(['username' => 'Maxi Muster!']))
            ->call('register')
            ->assertHasFormErrors(['username' => 'regex']);

        Livewire::test(Register::class)
            ->fillForm($this->validInput(['username' => '  MaxiMuster ']))
            ->call('register')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(User::class, ['username' => 'maximuster']);
    }

    public function test_a_weak_or_unconfirmed_password_is_rejected(): void
    {
        SchoolClass::factory()->create(['join_code' => 'ABCDEF']);

        Livewire::test(Register::class)
            ->fillForm($this->validInput(['password' => 'kurz', 'passwordConfirmation' => 'kurz']))
            ->call('register')
            ->assertHasFormErrors(['password']);

        Livewire::test(Register::class)
            ->fillForm($this->validInput(['passwordConfirmation' => 'etwas-anderes']))
            ->call('register')
            ->assertHasFormErrors(['password' => 'same']);

        $this->assertDatabaseMissing(User::class, ['username' => 'maxi.muster']);
    }

    public function test_a_whole_class_can_register_in_the_same_minute(): void
    {
        SchoolClass::factory()->create(['join_code' => 'ABCDEF']);

        foreach (range(1, 25) as $number) {
            Livewire::test(Register::class)
                ->fillForm($this->validInput(['username' => "schueler.$number"]))
                ->call('register')
                ->assertHasNoFormErrors();
            auth()->logout();
        }

        $this->assertSame(25, User::withRole(Role::Student)->count());
    }

    public function test_registration_is_still_rate_limited(): void
    {
        SchoolClass::factory()->create(['join_code' => 'ABCDEF']);

        $page = Livewire::test(Register::class);

        foreach (range(1, 60) as $ignored) {
            $page->fillForm($this->validInput(['classCode' => 'ZZZZZZ']))->call('register');
        }

        $page->fillForm($this->validInput())->call('register');

        $this->assertDatabaseMissing(User::class, ['username' => 'maxi.muster']);
    }

    public function test_a_student_inherits_the_school_of_the_class(): void
    {
        $school = School::factory()->create();
        SchoolClass::factory()->for($school)->create(['join_code' => 'ABCDEF']);

        Livewire::test(Register::class)
            ->fillForm($this->validInput())
            ->call('register')
            ->assertHasNoFormErrors();

        $this->assertSame($school->id, User::where('username', 'maxi.muster')->sole()->school_id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidClassCodes(): array
    {
        return [
            'unknown' => ['ZZZZZZ'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidClassCodes')]
    public function test_without_a_valid_class_code_nobody_learns_which_accounts_exist(string $classCode): void
    {
        SchoolClass::factory()->create(['join_code' => 'ABCDEF']);
        User::factory()->teacher()->create(['username' => 'herr.lehrer', 'email' => 'lehrer@example.test']);

        Livewire::test(Register::class)
            ->fillForm($this->validInput(['classCode' => $classCode, 'username' => 'herr.lehrer', 'email' => 'lehrer@example.test']))
            ->call('register')
            ->assertHasFormErrors(['classCode'])
            ->assertHasNoFormErrors(['username', 'email'])
            ->assertDontSee('gibt es schon')
            ->assertDontSee('already been taken');
    }

    public function test_with_a_valid_class_code_a_taken_username_is_still_reported(): void
    {
        SchoolClass::factory()->create(['join_code' => 'ABCDEF']);
        User::factory()->create(['username' => 'herr.lehrer']);

        Livewire::test(Register::class)
            ->fillForm($this->validInput(['username' => 'herr.lehrer']))
            ->call('register')
            ->assertHasFormErrors(['username' => 'unique'])
            ->assertSee('Diesen Benutzernamen gibt es schon');
    }

    public function test_the_password_fields_tell_the_browser_not_to_fill_in_a_saved_login(): void
    {
        $html = $this->get(route('filament.lernplattform.auth.register'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'autocomplete="new-password"'));
    }

    public function test_the_minimum_password_length_is_stated_up_front_and_in_german_when_missed(): void
    {
        SchoolClass::factory()->create(['join_code' => 'ABCDEF']);

        $this->get(route('filament.lernplattform.auth.register'))->assertSee('Mindestens 8 Zeichen.');

        $page = Livewire::test(Register::class)
            ->fillForm($this->validInput(['password' => 'max2026', 'passwordConfirmation' => 'max2026']))
            ->call('register')
            ->assertHasFormErrors(['password']);

        $this->assertContains('Dein Passwort braucht mindestens 8 Zeichen.', $page->errors()->get('data.password'));
    }

    public function test_the_page_tells_teachers_where_their_access_comes_from(): void
    {
        $this->get(route('filament.lernplattform.auth.register'))
            ->assertOk()
            ->assertSee('Lehrkräfte erhalten ihren Zugang von der Schulverwaltung.')
            ->assertSee('Klassencode')
            ->assertDontSee('name="data.role"', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validInput(array $overrides = []): array
    {
        return [
            'name' => 'Maxi Muster',
            'username' => 'maxi.muster',
            'classCode' => 'ABCDEF',
            'password' => 'geheim-2026',
            'passwordConfirmation' => 'geheim-2026',
            'email' => null,
            ...$overrides,
        ];
    }
}
