<?php

namespace Tests\Feature\Dpa;

use App\Filament\Pages\Auth\RegisterSchool;
use App\Models\DpaAcceptance;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DpaRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The texts are asserted in German, the platform's default language (phpunit.xml runs the suite in English).
        config(['lernplattform.locale.default' => 'de']);
        app()->setLocale('de');

        config(['lernplattform.dpa.final' => true]);
    }

    public function test_a_school_cannot_register_without_accepting_the_agreement(): void
    {
        Livewire::test(RegisterSchool::class)
            ->fillForm($this->validInput(['hasAcceptedDpa' => false]))
            ->call('register')
            ->assertHasFormErrors(['hasAcceptedDpa' => 'accepted'])
            ->assertSee('Bitte akzeptiere den Auftragsverarbeitungsvertrag');

        $this->assertSame(0, School::count());
        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    public function test_registering_stores_version_time_and_the_new_administration_as_accepter(): void
    {
        $this->freezeTime();
        config(['lernplattform.dpa.version' => '2031-04']);

        Livewire::test(RegisterSchool::class)
            ->fillForm($this->validInput())
            ->call('register')
            ->assertHasNoFormErrors();

        $school = School::sole();
        $admin = User::where('username', 'sabine.schulleitung')->sole();

        $this->assertSame('2031-04', $school->dpa_version);
        $this->assertSame(now()->getTimestamp(), $school->dpa_accepted_at->getTimestamp());
        $this->assertSame($admin->id, $school->dpa_accepted_by);
        $this->assertTrue($school->dpaAcceptedBy->is($admin));
        $this->assertSame('Sabine Schulleitung', $school->dpa_accepted_by_name);
        $this->assertSame('sabine.schulleitung', $school->dpa_accepted_by_username);
        $this->assertSame(DpaAcceptance::fingerprint(), $school->dpa_fingerprint);
        $this->assertTrue($school->hasAcceptedCurrentDpa());
    }

    public function test_registering_keeps_the_contract_text_the_school_accepted(): void
    {
        Livewire::test(RegisterSchool::class)
            ->fillForm($this->validInput())
            ->call('register')
            ->assertHasNoFormErrors();

        $acceptance = DpaAcceptance::sole();
        $school = School::sole();

        $this->assertTrue($acceptance->school->is($school));
        $this->assertSame($school->dpa_version, $acceptance->version);
        $this->assertSame($school->dpa_fingerprint, $acceptance->fingerprint);
        $this->assertSame('Sabine Schulleitung', $acceptance->accepted_by_name);
        $this->assertSame('sabine.schulleitung', $acceptance->accepted_by_username);
        $this->assertStringContainsString('<strong>Goethe-Gymnasium</strong>', $acceptance->content, 'The school sees its own master data.');
        $this->assertStringContainsString('Amtliche Schulnummer: 123456', $acceptance->content);
        $this->assertStringContainsString('Auftragnehmer (Auftragsverarbeiter)', $acceptance->content);
        $this->assertStringContainsString('Anlage 3: Unterauftragsverarbeiter', $acceptance->content);
        $this->assertStringNotContainsString('<html', $acceptance->content, 'Only the contract, not the page around it.');
    }

    public function test_the_acceptance_cannot_be_injected_through_the_form_state(): void
    {
        $otherAdmin = User::factory()->admin()->create();

        Livewire::test(RegisterSchool::class)
            ->fillForm($this->validInput())
            ->set('data.school.dpa_version', 'gefälscht')
            ->set('data.school.dpa_accepted_at', '2001-01-01 00:00:00')
            ->set('data.school.dpa_accepted_by', $otherAdmin->id)
            ->set('data.school.dpa_accepted_by_name', 'Falscher Name')
            ->set('data.school.dpa_fingerprint', 'gefälscht')
            ->call('register')
            ->assertHasNoFormErrors();

        $school = School::where('name', 'Goethe-Gymnasium')->sole();

        $this->assertSame(config('lernplattform.dpa.version'), $school->dpa_version);
        $this->assertTrue($school->dpa_accepted_at->isToday());
        $this->assertSame(User::where('username', 'sabine.schulleitung')->value('id'), $school->dpa_accepted_by);
        $this->assertSame('Sabine Schulleitung', $school->dpa_accepted_by_name);
        $this->assertSame(DpaAcceptance::fingerprint(), $school->dpa_fingerprint);
    }

    public function test_the_checkbox_links_to_the_agreement_in_a_new_tab(): void
    {
        Livewire::test(RegisterSchool::class)
            ->assertSee('Auftragsverarbeitungsvertrag (AVV)')
            ->assertSee('Ich akzeptiere den')
            ->assertSeeHtml('href="'.route('legal.dpa').'" target="_blank" rel="noopener"');
    }

    public function test_a_failed_registration_stores_no_acceptance(): void
    {
        User::factory()->create(['username' => 'sabine.schulleitung']);

        Livewire::test(RegisterSchool::class)
            ->fillForm($this->validInput())
            ->call('register')
            ->assertHasFormErrors(['username' => 'unique']);

        $this->assertSame(0, School::whereNotNull('dpa_version')->count());
        $this->assertSame(0, DpaAcceptance::count());
    }

    public function test_while_the_agreement_is_a_draft_nothing_has_to_be_accepted_and_nothing_is_stored(): void
    {
        config(['lernplattform.dpa.final' => false]);

        $input = $this->validInput();
        unset($input['hasAcceptedDpa']);

        Livewire::test(RegisterSchool::class)
            ->assertDontSee('Ich akzeptiere den')
            ->assertSee('ist noch ein Entwurf und kann deshalb noch nicht angenommen werden')
            ->assertSee('Du kannst ihn schon lesen. Sobald er freigegeben ist, nimmst du ihn unter „Meine Schule“ an.')
            ->assertSeeHtml('href="'.route('legal.dpa').'" target="_blank" rel="noopener"')
            ->fillForm($input)
            ->call('register')
            ->assertHasNoFormErrors();

        $school = School::sole();

        $this->assertNotNull(User::where('username', 'sabine.schulleitung')->first());
        $this->assertNull($school->dpa_version);
        $this->assertNull($school->dpa_accepted_at);
        $this->assertNull($school->dpa_fingerprint);
        $this->assertFalse($school->hasAcceptedDpa());
        $this->assertSame(0, DpaAcceptance::count());
        $this->assertFalse($school->mustAcceptDpa(), 'A draft cannot be required, so the school is not held back.');
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
