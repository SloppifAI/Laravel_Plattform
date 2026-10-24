<?php

namespace Tests\Feature\Schools;

use App\Filament\Pages\Auth\RegisterSchool;
use App\Models\School;
use App\Models\User;
use App\Role;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SchoolRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The acceptance box is only there for a released contract; tests/Feature/Dpa covers the draft.
        config(['lernplattform.dpa.final' => true]);
    }

    public function test_registering_creates_the_school_and_its_administration_and_logs_in(): void
    {
        Livewire::test(RegisterSchool::class)
            ->fillForm($this->validInput())
            ->call('register')
            ->assertHasNoFormErrors()
            ->assertRedirect()
            ->assertNotified('Welcome to the learning platform!');

        $school = School::sole();
        $this->assertSame('Goethe-Gymnasium', $school->name);
        $this->assertSame('123456', $school->school_number);
        $this->assertSame('Gymnasium', $school->school_type);
        $this->assertSame('Kassel', $school->city);
        $this->assertSame('Hessen', $school->state);
        $this->assertSame('sekretariat@goethe.example.test', $school->contact_email);
        $this->assertFalse($school->isBlocked());

        $admin = User::where('username', 'sabine.schulleitung')->sole();
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertTrue($admin->school->is($school));
        $this->assertNull($admin->school_class_id);
        $this->assertSame('sabine@goethe.example.test', $admin->email);
        $this->assertTrue(Hash::check('geheim-2026', $admin->password));
        $this->assertAuthenticatedAs($admin);

        $this->get('/')->assertOk();
    }

    public function test_the_welcome_notification_points_to_classes_and_the_student_list(): void
    {
        Livewire::test(RegisterSchool::class)
            ->fillForm($this->validInput())
            ->call('register')
            ->assertNotified(
                Notification::make()
                    ->title('Welcome to the learning platform!')
                    ->body('Set up classes now or import a student list.')
                    ->success(),
            );
    }

    public function test_role_and_school_cannot_be_injected_through_the_livewire_state(): void
    {
        $otherSchool = School::factory()->create();

        Livewire::test(RegisterSchool::class)
            ->fillForm($this->validInput())
            ->set('data.role', Role::Operator->value)
            ->set('data.school_id', $otherSchool->id)
            ->set('data.school_class_id', 1)
            ->set('data.school.blocked_at', now()->toDateTimeString())
            ->set('data.school.id', $otherSchool->id)
            ->call('register')
            ->assertHasNoFormErrors();

        $admin = User::where('username', 'sabine.schulleitung')->sole();

        $this->assertSame(Role::Admin, $admin->role);
        $this->assertFalse($admin->school->is($otherSchool));
        $this->assertSame('Goethe-Gymnasium', $admin->school->name);
        $this->assertFalse($admin->school->isBlocked());
        $this->assertSame(2, School::count());
        $this->assertSame(0, User::withRole(Role::Operator)->count());
        $this->assertSame($otherSchool->name, $otherSchool->fresh()->name);
    }

    public function test_a_taken_school_number_is_rejected(): void
    {
        School::factory()->create(['school_number' => '123456']);

        Livewire::test(RegisterSchool::class)
            ->fillForm($this->validInput())
            ->call('register')
            ->assertHasFormErrors(['school.school_number' => 'unique']);

        $this->assertSame(1, School::count());
        $this->assertGuest();
    }

    public function test_the_school_number_is_optional_and_two_schools_may_leave_it_empty(): void
    {
        foreach (['sabine.schulleitung', 'peter.leitung'] as $username) {
            Livewire::test(RegisterSchool::class)
                ->fillForm($this->validInput([
                    'school' => ['school_number' => ''],
                    'username' => $username,
                    'email' => "$username@example.test",
                ]))
                ->call('register')
                ->assertHasNoFormErrors();
            auth()->logout();
        }

        $this->assertSame(2, School::whereNull('school_number')->count());
    }

    public function test_a_taken_username_is_rejected_whatever_its_case(): void
    {
        User::factory()->create(['username' => 'sabine.schulleitung']);

        Livewire::test(RegisterSchool::class)
            ->fillForm($this->validInput(['username' => 'Sabine.Schulleitung']))
            ->call('register')
            ->assertHasFormErrors(['username' => 'unique'])
            ->assertSee('This username is already taken.');

        $this->assertSame(1, School::count(), 'Only the school of the existing user.');
    }

    public function test_usernames_are_lowercased_and_limited_to_simple_characters(): void
    {
        Livewire::test(RegisterSchool::class)
            ->fillForm($this->validInput(['username' => 'Sabine Schulleitung!']))
            ->call('register')
            ->assertHasFormErrors(['username' => 'regex']);

        Livewire::test(RegisterSchool::class)
            ->fillForm($this->validInput(['username' => '  SabineS ']))
            ->call('register')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(User::class, ['username' => 'sabines', 'role' => Role::Admin->value]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function consentCheckboxes(): array
    {
        return [
            'authorized to represent the school' => ['isAuthorizedToRepresentSchool'],
            'privacy policy' => ['hasAcceptedPrivacyPolicy'],
            'data processing agreement' => ['hasAcceptedDpa'],
        ];
    }

    #[DataProvider('consentCheckboxes')]
    public function test_all_consent_checkboxes_are_required(string $checkbox): void
    {
        Livewire::test(RegisterSchool::class)
            ->fillForm($this->validInput([$checkbox => false]))
            ->call('register')
            ->assertHasFormErrors([$checkbox => 'accepted']);

        $this->assertSame(0, School::count());
        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function missingInput(): array
    {
        return [
            'school name' => [['school' => ['name' => '']], 'school.name'],
            'school type' => [['school' => ['school_type' => null]], 'school.school_type'],
            'city' => [['school' => ['city' => '']], 'school.city'],
            'state' => [['school' => ['state' => null]], 'school.state'],
            'contact email' => [['school' => ['contact_email' => '']], 'school.contact_email'],
            'name' => [['name' => ''], 'name'],
            'username' => [['username' => ''], 'username'],
            'email' => [['email' => ''], 'email'],
            'password' => [['password' => '', 'passwordConfirmation' => ''], 'password'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('missingInput')]
    public function test_required_fields_are_enforced(array $overrides, string $field): void
    {
        Livewire::test(RegisterSchool::class)
            ->fillForm($this->validInput($overrides))
            ->call('register')
            ->assertHasFormErrors([$field => 'required']);

        $this->assertSame(0, School::count());
    }

    public function test_school_type_and_state_must_come_from_the_lists(): void
    {
        Livewire::test(RegisterSchool::class)
            ->fillForm($this->validInput(['school' => ['school_type' => 'Zauberschule', 'state' => 'Atlantis']]))
            ->call('register')
            ->assertHasFormErrors(['school.school_type', 'school.state']);

        $this->assertSame(0, School::count());
    }

    public function test_a_weak_or_unconfirmed_password_is_rejected(): void
    {
        Livewire::test(RegisterSchool::class)
            ->fillForm($this->validInput(['password' => 'kurz', 'passwordConfirmation' => 'kurz']))
            ->call('register')
            ->assertHasFormErrors(['password']);

        Livewire::test(RegisterSchool::class)
            ->fillForm($this->validInput(['passwordConfirmation' => 'etwas-anderes']))
            ->call('register')
            ->assertHasFormErrors(['password' => 'same']);

        $this->assertSame(0, School::count());
    }

    public function test_school_and_administration_are_created_together_or_not_at_all(): void
    {
        User::creating(fn () => throw new RuntimeException('Konto konnte nicht angelegt werden'));

        try {
            Livewire::test(RegisterSchool::class)
                ->fillForm($this->validInput())
                ->call('register');
            $this->fail('The failing user insert should surface.');
        } catch (RuntimeException) {
            // Expected: the school inserted just before must be rolled back with it.
        }

        $this->assertSame(0, School::count());
    }

    public function test_a_school_may_retry_after_typos_without_being_locked_out_by_the_email_limit(): void
    {
        $page = Livewire::test(RegisterSchool::class);

        foreach (range(1, 4) as $ignored) {
            $page->fillForm($this->validInput(['isAuthorizedToRepresentSchool' => false]))->call('register');
        }

        $page->fillForm($this->validInput())->call('register')->assertHasNoFormErrors();

        $this->assertSame(1, School::count());
    }

    public function test_registration_is_rate_limited(): void
    {
        $page = Livewire::test(RegisterSchool::class);

        foreach (range(1, 10) as $ignored) {
            $page->fillForm($this->validInput(['isAuthorizedToRepresentSchool' => false]))->call('register');
        }

        $page->fillForm($this->validInput())->call('register');

        $this->assertSame(0, School::count());
        $this->assertGuest();
    }

    public function test_the_page_is_open_for_guests_and_links_to_the_privacy_policy_and_back_to_login(): void
    {
        $this->get(route('filament.lernplattform.auth.register-school'))
            ->assertOk()
            ->assertSee('Register a new school')
            ->assertSee('Official school number')
            // The JavaScript select ships its options as JSON (with an escaped slash), so check the option name only.
            ->assertSee('Vocational college')
            ->assertSee('I am authorized to represent the school.')
            ->assertSee('Privacy policy')
            ->assertSee(route('legal.privacy'), false)
            ->assertSee(route('filament.lernplattform.auth.login'), false)
            ->assertDontSee('name="data.role"', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validInput(array $overrides = []): array
    {
        return array_replace_recursive([
            'school' => [
                'name' => 'Goethe-Gymnasium',
                'school_number' => '123456',
                'school_type' => 'Gymnasium',
                'city' => 'Kassel',
                'state' => 'Hessen',
                'contact_email' => 'sekretariat@goethe.example.test',
            ],
            'name' => 'Sabine Schulleitung',
            'username' => 'sabine.schulleitung',
            'email' => 'sabine@goethe.example.test',
            'password' => 'geheim-2026',
            'passwordConfirmation' => 'geheim-2026',
            'isAuthorizedToRepresentSchool' => true,
            'hasAcceptedPrivacyPolicy' => true,
            'hasAcceptedDpa' => true,
        ], $overrides);
    }
}
