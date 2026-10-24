<?php

namespace Tests\Feature\Dpa;

use App\Filament\Resources\Schools\Pages\ListSchools;
use App\Models\School;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SchoolsTableDpaTest extends TestCase
{
    use RefreshDatabase;

    private School $current;

    private School $outdated;

    private School $missing;

    protected function setUp(): void
    {
        parent::setUp();

        // The texts are asserted in German, the platform's default language (phpunit.xml runs the suite in English).
        config(['lernplattform.locale.default' => 'de']);
        app()->setLocale('de');

        config(['lernplattform.dpa.version' => '2026-10', 'lernplattform.dpa.final' => true]);

        $this->current = School::factory()->create(['name' => 'Goethe-Gymnasium']);
        $this->current->acceptDpa(User::factory()->for($this->current)->admin()->create());

        $this->outdated = School::factory()->create(['name' => 'Schiller-Realschule']);
        $this->outdated->forceFill(['dpa_version' => '2025-09', 'dpa_accepted_at' => '2025-09-15 10:00:00'])->save();

        $this->missing = School::factory()->create(['name' => 'Lessing-Grundschule']);

        $this->actingAs(User::factory()->operator()->create());
    }

    public function test_the_operator_sees_version_or_a_label_for_missing_and_outdated(): void
    {
        Livewire::test(ListSchools::class)
            ->assertTableColumnExists('dpa_version')
            ->assertTableColumnStateSet('dpa_version', 'Version 2026-10', $this->current)
            ->assertTableColumnStateSet('dpa_version', 'Veraltet (2025-09)', $this->outdated)
            ->assertTableColumnStateSet('dpa_version', 'Fehlt', $this->missing)
            ->assertSee('15.09.2025')
            ->assertSee(now()->format('d.m.Y'));
    }

    public function test_the_column_shows_a_label_and_an_icon_not_only_a_color(): void
    {
        $iconOf = fn (Heroicon $icon): \Closure => fn (TextColumn $column): bool => $column->getIcon($column->getState()) === $icon;

        Livewire::test(ListSchools::class)
            ->assertSeeInOrder(['AVV', 'Version 2026-10'])
            ->assertSee('Fehlt')
            ->assertSee('Veraltet (2025-09)')
            ->assertTableColumnExists('dpa_version', $iconOf(Heroicon::OutlinedCheckCircle), $this->current)
            ->assertTableColumnExists('dpa_version', $iconOf(Heroicon::OutlinedExclamationTriangle), $this->outdated)
            ->assertTableColumnExists('dpa_version', $iconOf(Heroicon::OutlinedExclamationTriangle), $this->missing);
    }

    public function test_the_column_sits_before_the_counts_so_it_stays_in_view_on_a_laptop(): void
    {
        $columns = array_keys(Livewire::test(ListSchools::class)->instance()->getTable()->getColumns());

        $this->assertLessThan(array_search('users_count', $columns, true), array_search('dpa_version', $columns, true));
        $this->assertLessThan(array_search('created_at', $columns, true), array_search('dpa_version', $columns, true));
    }

    public function test_a_changed_setting_behind_the_text_makes_an_accepted_school_outdated(): void
    {
        config(['session.lifetime' => 45]);

        Livewire::test(ListSchools::class)
            ->assertTableColumnStateSet('dpa_version', 'Veraltet (2026-10)', $this->current)
            ->filterTable('dpa', 'outdated')
            ->assertCanSeeTableRecords([$this->current, $this->outdated])
            ->filterTable('dpa', 'current')
            ->assertCanNotSeeTableRecords([$this->current]);
    }

    public function test_an_acceptance_without_a_fingerprint_counts_as_outdated(): void
    {
        $this->current->forceFill(['dpa_fingerprint' => null])->save();

        Livewire::test(ListSchools::class)
            ->assertTableColumnStateSet('dpa_version', 'Veraltet (2026-10)', $this->current)
            ->filterTable('dpa', 'outdated')
            ->assertCanSeeTableRecords([$this->current]);
    }

    public function test_the_operator_can_filter_by_the_agreement_status(): void
    {
        Livewire::test(ListSchools::class)
            ->assertCanSeeTableRecords([$this->current, $this->outdated, $this->missing])
            ->filterTable('dpa', 'current')
            ->assertCanSeeTableRecords([$this->current])
            ->assertCanNotSeeTableRecords([$this->outdated, $this->missing])
            ->filterTable('dpa', 'outdated')
            ->assertCanSeeTableRecords([$this->outdated])
            ->assertCanNotSeeTableRecords([$this->current, $this->missing])
            ->filterTable('dpa', 'missing')
            ->assertCanSeeTableRecords([$this->missing])
            ->assertCanNotSeeTableRecords([$this->current, $this->outdated]);
    }

    public function test_raising_the_version_turns_every_accepted_school_into_outdated(): void
    {
        config(['lernplattform.dpa.version' => '2027-01']);

        Livewire::test(ListSchools::class)
            ->assertTableColumnStateSet('dpa_version', 'Veraltet (2026-10)', $this->current)
            ->filterTable('dpa', 'outdated')
            ->assertCanSeeTableRecords([$this->current, $this->outdated])
            ->assertCanNotSeeTableRecords([$this->missing])
            ->filterTable('dpa', 'current')
            ->assertCanNotSeeTableRecords([$this->current, $this->outdated, $this->missing]);
    }

    public function test_the_operator_sees_no_accepter_name(): void
    {
        $this->current->dpaAcceptedBy->update(['name' => 'Sabine Schulleitung']);

        Livewire::test(ListSchools::class)->assertDontSee('Sabine Schulleitung');
    }

    public function test_a_school_administration_does_not_get_the_school_list(): void
    {
        $this->actingAs(User::factory()->for($this->current)->admin()->create());

        Livewire::test(ListSchools::class)->assertForbidden();
    }
}
