<?php

namespace Tests\Feature\Dpa;

use App\Filament\Pages\MySchool;
use App\Models\DpaAcceptance;
use App\Models\School;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MySchoolDpaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lernplattform.dpa.final' => true]);
    }

    private function acceptAction(): TestAction
    {
        return TestAction::make('acceptDpa')->schemaComponent('dpa-actions', schema: 'content');
    }

    private function viewAcceptedAction(): TestAction
    {
        return TestAction::make('viewAcceptedDpa')->schemaComponent('dpa-actions', schema: 'content');
    }

    public function test_the_status_card_shows_version_date_and_accepter_of_the_current_agreement(): void
    {
        $this->travelTo('2026-10-24 14:05:00');
        $school = School::factory()->create();
        $admin = User::factory()->for($school)->admin()->create(['name' => 'Sabine Schulleitung', 'username' => 'sabine.leitung']);
        $school->acceptDpa($admin);
        $this->actingAs($admin);

        Livewire::test(MySchool::class)
            ->assertSee('Data processing agreement')
            ->assertSee('Accepted, version '.config('lernplattform.dpa.version'))
            ->assertSee('Accepted on 24.10.2026 at 14:05 by Sabine Schulleitung (username sabine.leitung).')
            ->assertDontSee('Accept agreement')
            ->assertDontSee('Accept changed version')
            ->assertSee(route('legal.dpa.school'));
    }

    public function test_the_status_badge_carries_an_icon_besides_its_colour(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->for($school)->admin()->create();
        $this->actingAs($admin);

        $missing = (string) Livewire::test(MySchool::class)->html();
        $this->assertMatchesRegularExpression('/fi-badge[^>]*>\s*<svg/s', $missing, 'A missing acceptance shows a warning icon in its badge.');

        $school->acceptDpa($admin);
        $this->actingAs($admin->fresh());

        $accepted = (string) Livewire::test(MySchool::class)->html();
        $this->assertMatchesRegularExpression('/fi-badge[^>]*>\s*<svg/s', $accepted, 'An accepted contract shows a check icon in its badge.');
        $this->assertNotSame(
            $this->badgeIcon($missing),
            $this->badgeIcon($accepted),
            'The two states differ in their icon, not only in their colour.',
        );
    }

    /**
     * The path data of the first svg inside a badge that follows the data processing agreement heading.
     */
    private function badgeIcon(string $html): string
    {
        $section = substr($html, (int) strpos($html, 'Data processing agreement'));

        $this->assertSame(1, preg_match('/fi-badge.*?<svg.*?<path[^>]* d="([^"]+)"/s', $section, $matches));

        return $matches[1];
    }

    public function test_without_an_acceptance_the_page_asks_for_it_and_stores_it_on_confirmation(): void
    {
        $this->freezeTime();
        $school = School::factory()->create();
        $admin = User::factory()->for($school)->admin()->create(['name' => 'Sabine Schulleitung']);
        $this->actingAs($admin);

        Livewire::test(MySchool::class)
            ->assertSee('Not accepted yet')
            ->assertSee('Until it is accepted, your school can only use this page.')
            ->assertSee('The agreement itself is in German.')
            ->assertActionVisible($this->acceptAction())
            ->assertActionDoesNotExist($this->viewAcceptedAction())
            ->callAction($this->acceptAction())
            ->assertNotified('Agreement accepted')
            ->assertSee('Accepted, version '.config('lernplattform.dpa.version'))
            ->assertDontSee('Accept agreement')
            ->assertActionVisible($this->viewAcceptedAction());

        $school->refresh();
        $this->assertSame(config('lernplattform.dpa.version'), $school->dpa_version);
        $this->assertSame(now()->getTimestamp(), $school->dpa_accepted_at->getTimestamp());
        $this->assertSame($admin->id, $school->dpa_accepted_by);
        $this->assertSame('Sabine Schulleitung', $school->dpa_accepted_by_name);
        $this->assertTrue($school->hasAcceptedCurrentDpa());
        $this->assertSame(1, $school->dpaAcceptances()->count());
    }

    public function test_the_action_asks_for_confirmation_before_it_stores_anything(): void
    {
        $school = School::factory()->create();
        $this->actingAs(User::factory()->for($school)->admin()->create());

        $component = Livewire::test(MySchool::class)
            ->mountAction($this->acceptAction())
            ->assertActionMounted($this->acceptAction());

        $description = (string) $component->instance()->getMountedAction()->getModalDescription();

        $this->assertStringContainsString('Read the agreement first', $description);
        $this->assertStringContainsString('The agreement itself is in German.', $description);
        $this->assertStringContainsString('href="'.route('legal.dpa.school').'" target="_blank" rel="noopener"', $description);

        $this->assertNull($school->refresh()->dpa_version);
        $this->assertSame(0, DpaAcceptance::count());
    }

    public function test_the_contract_can_be_read_before_it_is_accepted(): void
    {
        $this->actingAs(User::factory()->for(School::factory()->create())->admin()->create());

        Livewire::test(MySchool::class)
            ->assertSeeInOrder(['View and print the agreement with your school’s data', 'Accept agreement']);
    }

    public function test_a_newer_version_is_announced_and_accepting_it_stores_the_new_version(): void
    {
        config(['lernplattform.dpa.version' => '2026-10']);
        $school = School::factory()->create();
        $admin = User::factory()->for($school)->admin()->create();
        $school->acceptDpa($admin);
        $this->actingAs($admin);

        config(['lernplattform.dpa.version' => '2027-02']);

        Livewire::test(MySchool::class)
            ->assertSee('Changed version awaiting acceptance')
            ->assertSee('Since then there is a new version 2027-02. Until it is accepted, the accepted version (version 2026-10) continues to apply.')
            ->assertActionVisible($this->acceptAction())
            ->assertActionVisible($this->viewAcceptedAction())
            ->assertSee('View accepted version (version 2026-10)')
            ->callAction($this->acceptAction())
            ->assertNotified('Agreement accepted')
            ->assertDontSee('Accept changed version');

        $school->refresh();
        $this->assertSame('2027-02', $school->dpa_version);
        $this->assertTrue($school->hasAcceptedCurrentDpa());
        $this->assertSame(['2027-02', '2026-10'], $school->dpaAcceptances->pluck('version')->all(), 'The earlier acceptance stays as proof.');
    }

    public function test_a_changed_setting_behind_the_text_asks_for_a_new_acceptance_too(): void
    {
        config(['session.lifetime' => 120]);
        $school = School::factory()->create();
        $admin = User::factory()->for($school)->admin()->create();
        $school->acceptDpa($admin);
        $this->actingAs($admin);

        Livewire::test(MySchool::class)->assertSee('Accepted, version '.config('lernplattform.dpa.version'));

        config(['session.lifetime' => 30]);

        Livewire::test(MySchool::class)
            ->assertSee('Changed version awaiting acceptance')
            ->assertSee('Since then the agreement text has changed, for example a deadline or the AI provider.')
            ->assertDontSee('Since then there is a new version')
            ->assertActionVisible($this->acceptAction());
    }

    public function test_a_missing_or_outdated_agreement_comes_first_on_the_page_and_a_current_one_below_the_status(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->for($school)->admin()->create();
        $this->actingAs($admin);

        Livewire::test(MySchool::class)
            ->assertSeeInOrder(['Data processing agreement', 'Registered on']);

        $school->acceptDpa($admin);
        $this->actingAs($admin->fresh());

        Livewire::test(MySchool::class)
            ->assertSeeInOrder(['Registered on', 'Data processing agreement']);
    }

    public function test_an_administration_only_accepts_for_its_own_school(): void
    {
        $school = School::factory()->create();
        $otherSchool = School::factory()->create();
        $admin = User::factory()->for($school)->admin()->create();
        $otherAdmin = User::factory()->for($otherSchool)->admin()->create();
        $otherSchool->acceptDpa($otherAdmin);
        $acceptedAt = $otherSchool->refresh()->dpa_accepted_at;
        $this->actingAs($admin);

        Livewire::test(MySchool::class)
            ->set('data.id', $otherSchool->id)
            ->set('data.dpa_version', 'gefälscht')
            ->callAction($this->acceptAction());

        $this->assertTrue($school->refresh()->hasAcceptedCurrentDpa());
        $this->assertSame($admin->id, $school->dpa_accepted_by);

        $otherSchool->refresh();
        $this->assertSame($otherAdmin->id, $otherSchool->dpa_accepted_by);
        $this->assertTrue($otherSchool->dpa_accepted_at->equalTo($acceptedAt));
        $this->assertSame(1, $otherSchool->dpaAcceptances()->count());
    }

    public function test_the_status_of_another_school_is_never_shown(): void
    {
        $school = School::factory()->create();
        $otherSchool = School::factory()->create();
        $otherAdmin = User::factory()->for($otherSchool)->admin()->create(['name' => 'Peter Fremdleitung']);
        $otherSchool->acceptDpa($otherAdmin);
        $this->actingAs(User::factory()->for($school)->admin()->create());

        Livewire::test(MySchool::class)
            ->assertSee('Not accepted yet')
            ->assertDontSee('Peter Fremdleitung');
    }

    public function test_only_the_school_administration_can_accept(): void
    {
        $school = School::factory()->create();

        foreach (['teacher', 'student'] as $role) {
            $this->actingAs(User::factory()->for($school)->{$role}()->create());

            Livewire::test(MySchool::class)->assertForbidden();
        }

        $this->actingAs(User::factory()->operator()->create());
        Livewire::test(MySchool::class)->assertForbidden();

        $this->assertNull($school->refresh()->dpa_version);
    }

    public function test_the_columns_are_not_mass_assignable(): void
    {
        $school = School::factory()->create();

        $school->update([
            'dpa_version' => 'gefälscht',
            'dpa_accepted_at' => now(),
            'dpa_accepted_by' => 1,
            'dpa_fingerprint' => 'gefälscht',
            'dpa_accepted_by_name' => 'Falscher Name',
            'dpa_accepted_by_username' => 'falsch',
        ]);

        $school->refresh();
        $this->assertNull($school->dpa_version);
        $this->assertNull($school->dpa_accepted_at);
        $this->assertNull($school->dpa_accepted_by);
        $this->assertNull($school->dpa_fingerprint);
        $this->assertNull($school->dpa_accepted_by_name);
        $this->assertNull($school->dpa_accepted_by_username);
    }

    public function test_deleting_the_accepting_account_keeps_the_acceptance_and_who_accepted(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->for($school)->admin()->create(['name' => 'Sabine Schulleitung', 'username' => 'sabine.leitung']);
        $school->acceptDpa($admin);

        $admin->delete();

        $school->refresh();
        $this->assertNull($school->dpa_accepted_by);
        $this->assertTrue($school->hasAcceptedCurrentDpa());
        $this->assertSame('Sabine Schulleitung', $school->dpa_accepted_by_name);
        $this->assertSame('sabine.leitung', $school->dpa_accepted_by_username);

        $acceptance = $school->dpaAcceptances()->sole();
        $this->assertNull($acceptance->accepted_by);
        $this->assertSame('Sabine Schulleitung', $acceptance->accepted_by_name);
        $this->assertSame('sabine.leitung', $acceptance->accepted_by_username);

        $this->actingAs(User::factory()->for($school)->admin()->create());
        Livewire::test(MySchool::class)
            ->assertSee('by Sabine Schulleitung (username sabine.leitung)')
            ->assertDontSee('deleted account');
    }

    public function test_while_the_agreement_is_a_draft_it_can_only_be_read(): void
    {
        config(['lernplattform.dpa.final' => false]);
        $school = School::factory()->create();
        $this->actingAs(User::factory()->for($school)->admin()->create());

        Livewire::test(MySchool::class)
            ->assertSee('Draft, cannot be accepted yet')
            ->assertSee('The agreement is still a draft. It can only be accepted once the operator has released it.')
            ->assertDontSee('Not accepted yet')
            ->assertActionDoesNotExist($this->acceptAction())
            ->assertActionDoesNotExist($this->viewAcceptedAction())
            ->assertSee('View and print the agreement with your school’s data')
            ->assertSeeInOrder(['Registered on', 'Data processing agreement']);

        $this->assertNull($school->refresh()->dpa_version);
    }

    public function test_a_new_draft_does_not_take_back_what_was_accepted(): void
    {
        config(['lernplattform.dpa.version' => '2026-10']);
        $school = School::factory()->create();
        $admin = User::factory()->for($school)->admin()->create();
        $school->acceptDpa($admin);
        $this->actingAs($admin);

        config(['lernplattform.dpa.version' => '2027-02', 'lernplattform.dpa.final' => false]);

        Livewire::test(MySchool::class)
            ->assertSee('Draft, cannot be accepted yet')
            ->assertSee('The new version 2027-02 is still a draft; until it is released, version 2026-10 continues to apply.')
            ->assertActionDoesNotExist($this->acceptAction())
            ->assertActionVisible($this->viewAcceptedAction());
    }

    /**
     * The suite runs in English; German is the platform default, so the administration still gets the German page. The
     * agreement itself is the German text whatever the language of the page.
     */
    public function test_the_status_card_reads_german_when_german_is_the_default(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        app()->setLocale('de');
        $this->travelTo('2026-10-24 14:05:00');
        $school = School::factory()->create();
        $admin = User::factory()->for($school)->admin()->create(['name' => 'Sabine Schulleitung', 'username' => 'sabine.leitung']);
        $this->actingAs($admin);

        Livewire::test(MySchool::class)
            ->assertSee('Auftragsverarbeitungsvertrag (AVV)')
            ->assertSee('Noch nicht angenommen')
            ->assertSee('Bis zur Annahme kann deine Schule nur diese Seite nutzen.')
            ->assertSee('Vertrag mit den Daten deiner Schule ansehen und drucken')
            ->assertSee('Vertrag annehmen')
            ->assertDontSee('Accept agreement')
            ->assertDontSee('The agreement itself is in German.')
            ->callAction($this->acceptAction())
            ->assertNotified('Vertrag angenommen')
            ->assertSee('Angenommen, Version '.config('lernplattform.dpa.version'))
            ->assertSee('Angenommen am 24.10.2026 um 14:05 Uhr durch Sabine Schulleitung (Benutzername sabine.leitung).');
    }
}
