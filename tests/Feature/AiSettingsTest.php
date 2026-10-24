<?php

namespace Tests\Feature;

use App\Filament\Pages\AiSettings;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Ai\EnablesSchoolAi;
use Tests\TestCase;

/**
 * "KI-Funktionen": the school administration opts its school into the AI, with the complete checklist.
 */
class AiSettingsTest extends TestCase
{
    use EnablesSchoolAi;
    use RefreshDatabase;

    private School $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preventRealAiCalls();

        $this->school = School::factory()->create(['name' => 'Goethe-Gymnasium']);
        $this->admin = User::factory()->for($this->school)->admin()->create();
    }

    /**
     * @return array<string, bool>
     */
    private function checklist(bool $value = true): array
    {
        return collect(School::AI_CHECKLIST)->mapWithKeys(fn (string $item): array => ["checklist.{$item}" => $value])->all();
    }

    private function page(?User $user = null)
    {
        return Livewire::actingAs($user ?? $this->admin)->test(AiSettings::class);
    }

    public function test_only_the_school_administration_opens_the_page(): void
    {
        $this->get(AiSettings::getUrl())->assertRedirect();

        foreach (['teacher', 'student', 'operator'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create())->get(AiSettings::getUrl())->assertForbidden();
        }

        $this->actingAs($this->admin)->get(AiSettings::getUrl())->assertOk()->assertSee('AI features');
    }

    public function test_nobody_but_the_administration_sees_the_navigation_entry_or_runs_the_page(): void
    {
        foreach (['teacher', 'student', 'operator'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create());

            $this->assertFalse(AiSettings::canAccess(), $role);
            Livewire::test(AiSettings::class)->assertForbidden();
        }

        $this->actingAs($this->admin);
        $this->assertTrue(AiSettings::canAccess());
    }

    public function test_the_page_starts_switched_off_and_explains_what_goes_to_the_ai(): void
    {
        $this->page()
            ->assertSee('AI features are turned off')
            ->assertSee('What goes to the AI')
            ->assertSee('Names of students and teachers')
            ->assertSee('Worksheets')
            ->assertActionDisabled('enableAi')
            ->assertSee("mountAction('enableAi'", false)
            ->assertDontSee("mountAction('disableAi'", false);

        $this->assertNull($this->school->refresh()->ai_enabled_at);
    }

    public function test_the_page_names_the_provider_the_model_and_the_state_of_the_platform_switch(): void
    {
        config(['lernplattform.ai.provider' => 'gemini', 'lernplattform.ai.model' => 'gemini-test-model', 'lernplattform.ai.enabled' => true]);

        $this->page()->assertSee('Google Gemini')->assertSee('gemini-test-model')->assertSee('Turned on');

        config(['lernplattform.ai.model' => null, 'lernplattform.ai.enabled' => false]);

        $this->page()->assertSee('The provider’s default model')->assertSee('Turned off');
    }

    public function test_every_checklist_item_is_explained(): void
    {
        $page = $this->page();

        $this->assertSame(School::AI_CHECKLIST, array_keys(AiSettings::checklistItems()));

        foreach (AiSettings::checklistItems() as $item) {
            $page->assertSee($item['label'])->assertSee($item['help']);
        }

        $page->assertSee('Data processing agreement with the AI provider concluded')
            ->assertSee('Paid plan in which inputs are not used to improve products');
    }

    public function test_the_ai_needs_every_checklist_item(): void
    {
        foreach (School::AI_CHECKLIST as $missing) {
            $this->page()
                ->fillForm([...$this->checklist(), "checklist.{$missing}" => false], 'settingsForm')
                ->assertActionDisabled('enableAi');
        }

        $this->assertNull($this->school->refresh()->ai_enabled_at);
    }

    public function test_a_forged_enable_call_with_an_incomplete_checklist_changes_nothing(): void
    {
        $this->page()
            ->fillForm([...$this->checklist(), 'checklist.paid_tier' => false], 'settingsForm')
            ->callAction('enableAi');

        $school = $this->school->refresh();
        $this->assertNull($school->ai_enabled_at);
        $this->assertFalse($school->aiEnabled());
    }

    public function test_with_everything_confirmed_the_administration_switches_the_ai_on(): void
    {
        $this->page()
            ->fillForm($this->checklist(), 'settingsForm')
            ->assertActionEnabled('enableAi')
            ->callAction('enableAi')
            ->assertNotified('AI features are turned on')
            ->assertSee('AI features are turned on')
            ->assertSee("mountAction('disableAi'", false)
            ->assertDontSee("mountAction('enableAi'", false);

        $school = $this->school->refresh();
        $this->assertTrue($school->aiEnabled());
        $this->assertTrue($school->aiEnabledBy->is($this->admin));
        $this->assertTrue($school->ai_enabled_at->isToday());
        $this->assertTrue($school->isAiChecklistComplete());
    }

    public function test_the_page_says_when_the_platform_switch_keeps_the_ai_off(): void
    {
        $this->enableAiFor($this->school);
        config(['lernplattform.ai.enabled' => false]);

        $this->page()->assertSee('Turned on, but the platform has turned the AI features off');

        $this->assertFalse($this->school->refresh()->aiEnabled());
    }

    public function test_the_administration_switches_the_ai_off_any_time(): void
    {
        $school = $this->enableAiFor(School::factory()->create());
        $admin = User::factory()->for($school)->admin()->create();

        $this->page($admin)
            ->assertSee('AI features are turned on')
            ->callAction('disableAi')
            ->assertNotified('AI features are turned off');

        $school->refresh();
        $this->assertFalse($school->aiEnabled());
        $this->assertNull($school->ai_enabled_at);
        $this->assertNull($school->ai_enabled_by);
        // The confirmations stay, so switching on again is one click.
        $this->assertTrue($school->isAiChecklistComplete());
    }

    public function test_taking_back_a_confirmation_switches_the_ai_off(): void
    {
        $school = $this->enableAiFor(School::factory()->create());
        $admin = User::factory()->for($school)->admin()->create();

        $this->page($admin)
            ->fillForm(['checklist.paid_tier' => false], 'settingsForm')
            ->call('saveSettings')
            ->assertNotified('AI features turned off');

        $school->refresh();
        $this->assertFalse($school->aiEnabled());
        $this->assertNull($school->ai_enabled_at);
        $this->assertFalse($school->ai_checklist['paid_tier']);
    }

    public function test_the_checklist_is_saved(): void
    {
        $this->page()
            ->fillForm([...$this->checklist(false), 'checklist.dsfa_done' => true], 'settingsForm')
            ->call('saveSettings')
            ->assertNotified('Saved');

        $school = $this->school->refresh();
        $this->assertTrue($school->ai_checklist['dsfa_done']);
        $this->assertFalse($school->ai_checklist['paid_tier']);
        $this->assertFalse($school->isAiChecklistComplete());
        $this->assertFalse($school->aiEnabled());
    }

    public function test_an_administration_only_ever_changes_its_own_school(): void
    {
        $other = $this->enableAiFor(School::factory()->create(['name' => 'Schiller-Schule']));
        $otherChecklist = $other->ai_checklist;

        $this->page()
            ->fillForm($this->checklist(), 'settingsForm')
            ->callAction('enableAi');

        $this->assertTrue($this->school->refresh()->aiEnabled());

        $this->page()->callAction('disableAi');

        $this->assertFalse($this->school->refresh()->aiEnabled());

        // Schiller-Schule never changed, though both administrations worked on the page.
        $other->refresh();
        $this->assertTrue($other->aiEnabled());
        $this->assertSame($otherChecklist, $other->ai_checklist);
        $this->assertTrue($other->aiEnabledBy->isNot($this->admin));
    }

    public function test_the_columns_cannot_be_mass_assigned(): void
    {
        $this->school->fill(['ai_enabled_at' => now(), 'ai_enabled_by' => $this->admin->id, 'ai_checklist' => array_fill_keys(School::AI_CHECKLIST, true)])->save();

        $this->assertFalse($this->school->refresh()->aiEnabled());
        $this->assertNull($this->school->ai_enabled_at);
        $this->assertNull($this->school->ai_checklist);
    }

    public function test_the_callout_says_since_when_and_by_whom_the_ai_is_turned_on(): void
    {
        $school = $this->enableAiFor(School::factory()->create());
        $admin = User::factory()->for($school)->admin()->create();

        $this->page($admin)
            ->assertSee('Since '.today()->format('d.m.Y').', turned on by '.$school->aiEnabledBy->name)
            ->assertSee('go to Google Gemini in pseudonymised form');
    }

    /**
     * The suite runs in English; German is the platform default, so the administration still gets the German page.
     */
    public function test_the_page_reads_german_when_german_is_the_default(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        app()->setLocale('de');
        config(['lernplattform.ai.model' => null, 'lernplattform.ai.enabled' => false]);

        $this->page()
            ->assertSee('KI-Funktionen sind ausgeschaltet')
            ->assertSee('1. Voraussetzungen')
            ->assertSee('Was an die KI geht')
            ->assertSee('Auftragsverarbeitung mit dem KI-Anbieter abgeschlossen')
            ->assertSee('Ausgeschaltet')
            ->assertSee('Standardmodell des Anbieters')
            ->assertDontSee('AI features are turned off');

        config(['lernplattform.ai.enabled' => true]);

        $this->page()
            ->fillForm($this->checklist(), 'settingsForm')
            ->callAction('enableAi')
            ->assertNotified('KI-Funktionen sind eingeschaltet');

        // A fresh request, as the administration reloads the page.
        $this->page($this->admin->fresh())
            ->assertSee('Seit '.today()->format('d.m.Y').', eingeschaltet von '.$this->admin->name)
            ->assertSee('Texte aus dem Unterricht deiner Schule gehen pseudonymisiert an Google Gemini.');
    }
}
