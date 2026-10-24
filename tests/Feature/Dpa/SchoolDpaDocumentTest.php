<?php

namespace Tests\Feature\Dpa;

use App\Filament\Pages\MySchool;
use App\Models\School;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolDpaDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['lernplattform.dpa.final' => true]);
    }

    public function test_the_route_lives_under_my_school(): void
    {
        $this->assertSame('/meine-schule/auftragsverarbeitung', route('legal.dpa.school', absolute: false));
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get(route('legal.dpa.school'))->assertRedirect(route('filament.lernplattform.auth.login'));
    }

    public function test_the_administration_gets_the_agreement_with_the_data_of_its_own_school(): void
    {
        $school = School::factory()->create([
            'name' => 'Goethe-Gymnasium',
            'school_number' => '111111',
            'school_type' => 'Gymnasium',
            'city' => 'Kassel',
            'state' => 'Hessen',
            'contact_email' => 'sekretariat@goethe.example.test',
        ]);
        $admin = User::factory()->for($school)->admin()->create(['name' => 'Sabine Schulleitung', 'username' => 'sabine.leitung']);
        $this->travelTo('2026-10-24 14:05:00');
        $school->acceptDpa($admin);

        $response = $this->actingAs($admin)
            ->get(route('legal.dpa.school'))
            ->assertOk()
            ->assertSee('Ihre Fassung für Goethe-Gymnasium')
            ->assertSee('Goethe-Gymnasium')
            ->assertSee('Amtliche Schulnummer: 111111')
            ->assertSee('Kassel, Hessen')
            ->assertSee('sekretariat@goethe.example.test')
            ->assertDontSee('Entwurf – vor Vertragsschluss rechtlich prüfen lassen', false)
            ->assertSee('Anlage 3: Unterauftragsverarbeiter');

        $text = preg_replace('/\s+/', ' ', strip_tags($response->getContent()));
        $this->assertStringContainsString('hat diesen Vertrag in der Version '.config('lernplattform.dpa.version').' am 24.10.2026 um 14:05 Uhr durch Sabine Schulleitung (Benutzername sabine.leitung) elektronisch angenommen', $text);
    }

    public function test_the_document_says_when_the_agreement_is_open_or_outdated(): void
    {
        $school = School::factory()->create(['name' => 'Goethe-Gymnasium']);
        $admin = User::factory()->for($school)->admin()->create();
        $this->actingAs($admin);

        $this->get(route('legal.dpa.school'))
            ->assertSee('Status: noch nicht angenommen.')
            ->assertSee('Goethe-Gymnasium hat diesen Vertrag noch nicht angenommen');

        $school->forceFill(['dpa_version' => '2025-01', 'dpa_accepted_at' => now()])->save();
        $this->actingAs($admin->fresh());

        $this->get(route('legal.dpa.school'))
            ->assertSee('Status: Version 2025-01 angenommen, die aktuelle Version '.config('lernplattform.dpa.version').' ist noch offen.');
    }

    public function test_the_document_links_to_my_school_where_the_agreement_is_accepted(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->for($school)->admin()->create();
        $this->actingAs($admin);

        $html = $this->get(route('legal.dpa.school'))->getContent();

        $this->assertMatchesRegularExpression('/<span class="avv-noprint">Das geht unter <a href="'.preg_quote(MySchool::getUrl(), '/').'">„Meine Schule“<\/a>\.<\/span>/', $html);

        $school->forceFill(['dpa_version' => '2025-01', 'dpa_accepted_at' => now(), 'dpa_accepted_by_name' => 'Sabine Schulleitung', 'dpa_accepted_by_username' => 'sabine'])->save();
        $this->actingAs($admin->fresh());

        $this->assertStringContainsString('<a href="'.MySchool::getUrl().'">„Meine Schule“</a>', $this->get(route('legal.dpa.school'))->getContent());
    }

    public function test_a_draft_cannot_be_accepted_and_the_document_says_so(): void
    {
        config(['lernplattform.dpa.final' => false]);
        $school = School::factory()->create(['name' => 'Goethe-Gymnasium']);
        $this->actingAs(User::factory()->for($school)->admin()->create());

        $text = html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($this->get(route('legal.dpa.school'))->assertOk()->getContent())));

        $this->assertStringContainsString('Status: noch nicht angenommen. Der Vertrag ist noch ein Entwurf und kann erst nach der Freigabe angenommen werden.', $text);
        $this->assertStringContainsString('Goethe-Gymnasium hat diesen Vertrag noch nicht angenommen. Er ist noch ein Entwurf und kann erst nach der Freigabe angenommen werden.', $text);
        $this->assertStringNotContainsString('Das geht unter', $text);
    }

    public function test_the_accepted_text_stays_readable_after_the_current_text_changed(): void
    {
        config(['lernplattform.dpa.version' => '2026-10', 'session.lifetime' => 45]);
        $school = School::factory()->create(['name' => 'Goethe-Gymnasium', 'city' => 'Kassel', 'state' => 'Hessen']);
        $admin = User::factory()->for($school)->admin()->create(['name' => 'Sabine Schulleitung', 'username' => 'sabine.leitung']);
        $this->travelTo('2026-10-24 14:05:00');
        $school->acceptDpa($admin);
        $this->actingAs($admin);

        config(['lernplattform.dpa.version' => '2027-02', 'session.lifetime' => 90]);
        $school->update(['city' => 'Marburg']);

        $current = $this->get(route('legal.dpa.school'))->assertOk();
        $current->assertSee('nach 90 Minuten ohne Aktivität')
            ->assertSee('Marburg, Hessen')
            ->assertSee('Status: Version 2026-10 angenommen, die aktuelle Version 2027-02 ist noch offen.');

        $accepted = $this->get(route('legal.dpa.school.accepted'))->assertOk();
        $accepted->assertSee('Angenommene Fassung für Goethe-Gymnasium')
            ->assertSee('Stand/Version: <strong>2026-10</strong>', false)
            ->assertSee('nach 45 Minuten ohne Aktivität')
            ->assertSee('Kassel, Hessen')
            ->assertDontSee('nach 90 Minuten ohne Aktivität')
            ->assertDontSee('Marburg')
            ->assertSee('href="'.route('legal.dpa.school').'"', false)
            ->assertSee('Anlage 3: Unterauftragsverarbeiter');

        $text = preg_replace('/\s+/', ' ', strip_tags($accepted->getContent()));
        $this->assertStringContainsString('wie Ihre Schule ihn am 24.10.2026 gesehen und angenommen hat', $text);
        $this->assertStringContainsString('Goethe-Gymnasium hat die Version 2026-10 am 24.10.2026 angenommen (durch Sabine Schulleitung, Benutzername sabine.leitung)', $text);
    }

    public function test_the_accepted_text_is_the_latest_acceptance_and_does_not_depend_on_the_current_settings(): void
    {
        config(['lernplattform.dpa.version' => '2026-10', 'services.anthropic.enabled' => false]);
        $school = School::factory()->create();
        $admin = User::factory()->for($school)->admin()->create();
        $school->acceptDpa($admin);

        config(['lernplattform.dpa.version' => '2027-02', 'services.anthropic.enabled' => true]);
        $school->acceptDpa($admin);
        $this->actingAs($admin);

        config(['services.anthropic.enabled' => false]);

        $html = $this->get(route('legal.dpa.school.accepted'))
            ->assertOk()
            ->assertSee('Stand/Version: <strong>2027-02</strong>', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'vorbereitet, nicht aktiv'), 'The stored text lists the AI provider as active, only the transcription is still inactive.');
    }

    public function test_there_is_no_accepted_text_before_the_first_acceptance(): void
    {
        $school = School::factory()->create();
        $this->actingAs(User::factory()->for($school)->admin()->create());

        $this->get(route('legal.dpa.school.accepted'))->assertNotFound();
    }

    public function test_the_accepted_text_is_only_for_the_administration_of_that_school(): void
    {
        $this->assertSame('/meine-schule/auftragsverarbeitung/angenommen', route('legal.dpa.school.accepted', absolute: false));
        $this->get(route('legal.dpa.school.accepted'))->assertRedirect(route('filament.lernplattform.auth.login'));

        $school = School::factory()->create(['name' => 'Goethe-Gymnasium']);
        $otherSchool = School::factory()->create(['name' => 'Schiller-Realschule']);
        $admin = User::factory()->for($school)->admin()->create();
        $school->acceptDpa($admin);

        foreach ([Role::Teacher, Role::Student] as $role) {
            $this->actingAs(User::factory()->for($school)->create(['role' => $role]))
                ->get(route('legal.dpa.school.accepted'))
                ->assertForbidden();
        }

        $this->actingAs(User::factory()->operator()->create())->get(route('legal.dpa.school.accepted'))->assertForbidden();

        $this->actingAs(User::factory()->for($otherSchool)->admin()->create())
            ->get(route('legal.dpa.school.accepted', ['schule' => $school->id, 'school_id' => $school->id]))
            ->assertNotFound();
    }

    public function test_the_administration_of_another_school_gets_only_its_own_version(): void
    {
        $school = School::factory()->create(['name' => 'Goethe-Gymnasium', 'school_number' => '111111', 'contact_email' => 'goethe@example.test']);
        $otherSchool = School::factory()->create(['name' => 'Schiller-Realschule', 'school_number' => '222222', 'contact_email' => 'schiller@example.test']);
        User::factory()->for($school)->admin()->create();

        $this->actingAs(User::factory()->for($otherSchool)->admin()->create())
            ->get(route('legal.dpa.school'))
            ->assertOk()
            ->assertSee('Schiller-Realschule')
            ->assertSee('schiller@example.test')
            ->assertDontSee('Goethe-Gymnasium')
            ->assertDontSee('111111')
            ->assertDontSee('goethe@example.test');
    }

    public function test_no_parameter_can_select_another_school(): void
    {
        $school = School::factory()->create(['name' => 'Goethe-Gymnasium']);
        $otherSchool = School::factory()->create(['name' => 'Schiller-Realschule']);
        $this->actingAs(User::factory()->for($school)->admin()->create());

        foreach ([['schule' => $otherSchool->id], ['school' => $otherSchool->id], ['school_id' => $otherSchool->id]] as $query) {
            $this->get(route('legal.dpa.school', $query))
                ->assertOk()
                ->assertSee('Goethe-Gymnasium')
                ->assertDontSee('Schiller-Realschule');
        }
    }

    public function test_the_school_data_is_escaped(): void
    {
        $school = School::factory()->create(['name' => '<script>alert(1)</script>']);

        $this->actingAs(User::factory()->for($school)->admin()->create())
            ->get(route('legal.dpa.school'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_only_the_school_administration_gets_the_personalised_version(): void
    {
        $school = School::factory()->create(['name' => 'Goethe-Gymnasium']);

        foreach ([Role::Teacher, Role::Student] as $role) {
            $this->actingAs(User::factory()->for($school)->create(['role' => $role]))
                ->get(route('legal.dpa.school'))
                ->assertForbidden();
        }

        $this->actingAs(User::factory()->operator()->create())
            ->get(route('legal.dpa.school'))
            ->assertForbidden();
    }

    public function test_the_personalised_version_sits_in_the_same_layout_as_the_public_one(): void
    {
        $school = School::factory()->create();

        $this->actingAs(User::factory()->for($school)->admin()->create())
            ->get(route('legal.dpa.school'))
            ->assertSee('href="'.route('legal.dpa').'"', false)
            ->assertSee('class="avv-annex"', false)
            ->assertSee('data-print', false);
    }
}
