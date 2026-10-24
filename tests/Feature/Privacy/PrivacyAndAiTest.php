<?php

namespace Tests\Feature\Privacy;

use App\Filament\Pages\PrivacyAndAi;
use App\Models\User;
use App\Role;
use App\UnderstandingLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrivacyAndAiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{Role}>
     */
    public static function roles(): array
    {
        return [
            'Administration' => [Role::Admin],
            'Lehrkraft' => [Role::Teacher],
            'Schüler:in' => [Role::Student],
        ];
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function nonAdminRoles(): array
    {
        return [
            'Lehrkraft' => [Role::Teacher],
            'Schüler:in' => [Role::Student],
        ];
    }

    #[DataProvider('roles')]
    public function test_every_role_can_open_the_page(Role $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]))
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('Datenschutz & KI')
            ->assertSee('Keine Mitschnitte')
            ->assertSee('Wer sieht das?')
            ->assertSee('keine Note')
            ->assertSee('Schulverwaltung');
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get('/datenschutz')->assertRedirect();
    }

    public function test_the_page_has_no_navigation_group_and_sits_at_the_end(): void
    {
        $this->assertNull(PrivacyAndAi::getNavigationGroup());
        $this->assertGreaterThanOrEqual(100, PrivacyAndAi::getNavigationSort());
    }

    #[DataProvider('roles')]
    public function test_the_ai_is_described_as_not_active_while_it_is_disabled(Role $role): void
    {
        config(['services.anthropic.enabled' => false, 'services.anthropic.model' => 'claude-test-modell']);

        $this->actingAs(User::factory()->create(['role' => $role]))
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('nicht aktiv')
            ->assertSee('Demo-Logik')
            ->assertDontSee('Anthropic (Claude)')
            ->assertDontSee('claude-test-modell');
    }

    public function test_the_enabled_ai_names_provider_and_model_without_sending_a_request(): void
    {
        Http::fake();
        config(['services.anthropic.enabled' => true, 'services.anthropic.model' => 'claude-test-modell']);

        $this->actingAs(User::factory()->teacher()->create())
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('KI-Anbindung aktiv')
            ->assertSee('Anthropic')
            ->assertSee('claude-test-modell')
            ->assertSee('Namen, Benutzernamen oder Benutzer-IDs')
            ->assertDontSee('nicht aktiv');

        Http::assertNothingSent();
    }

    public function test_the_admin_section_with_setup_and_checklist_is_only_shown_to_admins(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/datenschutz')
            ->assertOk()
            ->assertSee('ANTHROPIC_ENABLED=true')
            ->assertSee('ANTHROPIC_API_KEY')
            ->assertSee('ANTHROPIC_MODEL')
            ->assertSee('Auftragsverarbeitungsvertrag')
            ->assertSee('Datenschutz-Folgenabschätzung')
            ->assertSee('Eltern und Schüler:innen informiert')
            ->assertSee('Personalrat')
            ->assertSee('keine Rechtsberatung');
    }

    #[DataProvider('nonAdminRoles')]
    public function test_the_admin_section_is_hidden_from_other_roles(Role $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]))
            ->get('/datenschutz')
            ->assertOk()
            ->assertDontSee('Für die Administration')
            ->assertDontSee('ANTHROPIC_API_KEY')
            ->assertDontSee('Auftragsverarbeitungsvertrag')
            ->assertDontSee('Personalrat');
    }

    public function test_only_students_get_the_tips_for_their_own_data(): void
    {
        $this->actingAs(User::factory()->student()->create())
            ->get('/datenschutz')
            ->assertSee('Tipps für dich')
            ->assertSee('Du darfst deine Lehrkraft jederzeit fragen');

        $this->actingAs(User::factory()->teacher()->create())
            ->get('/datenschutz')
            ->assertDontSee('Tipps für dich');
    }

    public function test_students_are_warned_that_texts_reach_the_ai_once_it_is_enabled(): void
    {
        $student = User::factory()->student()->create();

        config(['services.anthropic.enabled' => false]);
        $this->actingAs($student)->get('/datenschutz')->assertDontSee('zur Auswertung an die KI gesendet');

        config(['services.anthropic.enabled' => true]);
        $this->actingAs($student)->get('/datenschutz')->assertSee('zur Auswertung an die KI gesendet');
    }

    public function test_the_stated_score_ranges_match_the_understanding_levels(): void
    {
        $this->assertSame(UnderstandingLevel::Understood, UnderstandingLevel::fromScore(75));
        $this->assertSame(UnderstandingLevel::Partly, UnderstandingLevel::fromScore(74));
        $this->assertSame(UnderstandingLevel::Partly, UnderstandingLevel::fromScore(50));
        $this->assertSame(UnderstandingLevel::NeedsSupport, UnderstandingLevel::fromScore(49));

        $this->actingAs(User::factory()->teacher()->create())
            ->get('/datenschutz')
            ->assertSee('ab 75 von 100')
            ->assertSee('50 bis 74 von 100')
            ->assertSee('unter 50 von 100')
            ->assertSee(UnderstandingLevel::NeedsSupport->getLabel());
    }
}
