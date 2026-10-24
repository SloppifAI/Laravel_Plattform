<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Competencies\CompetencyResource;
use App\Filament\Resources\Competencies\Pages\ManageCompetencies;
use App\Models\Competency;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The competency framework is shared by all schools: the platform operator maintains it, everybody else only reads.
 */
class CompetencyFrameworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_teachers_can_look_up_competencies_but_not_change_them(): void
    {
        $competency = Competency::factory()->create(['subject' => 'Mathematik']);
        $this->actingAs(User::factory()->teacher()->create());

        Livewire::test(ManageCompetencies::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$competency])
            ->assertActionVisible(TestAction::make('view')->table($competency))
            ->assertActionHidden(TestAction::make('edit')->table($competency))
            ->assertActionHidden(TestAction::make('delete')->table($competency))
            ->assertActionHidden(CreateAction::class);
    }

    public function test_school_administrations_look_up_competencies_but_cannot_change_them(): void
    {
        $competency = Competency::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageCompetencies::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$competency])
            ->assertActionVisible(TestAction::make('view')->table($competency))
            ->assertActionHidden(TestAction::make('edit')->table($competency))
            ->assertActionHidden(TestAction::make('delete')->table($competency))
            ->assertActionHidden(CreateAction::class);
    }

    public function test_operator_adds_a_cross_subject_competency(): void
    {
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(ManageCompetencies::class)
            ->callAction(CreateAction::class, [
                'subject' => '',
                'area' => 'Kommunizieren',
                'name' => 'Fachbegriffe sachgerecht verwenden',
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Competency::class, [
            'subject' => null,
            'area' => 'Kommunizieren',
            'name' => 'Fachbegriffe sachgerecht verwenden',
        ]);
    }

    public function test_operator_edits_and_deletes_a_competency(): void
    {
        $competency = Competency::factory()->create();
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(ManageCompetencies::class)
            ->callAction(TestAction::make('edit')->table($competency), ['subject' => 'Deutsch', 'name' => 'Texte planen'])
            ->assertHasNoFormErrors();

        $competency->refresh();
        $this->assertSame('Deutsch', $competency->subject);
        $this->assertSame('Texte planen', $competency->name);

        Livewire::test(ManageCompetencies::class)
            ->callAction(TestAction::make('delete')->table($competency));

        $this->assertModelMissing($competency);
    }

    public function test_competencies_can_be_filtered_to_cross_subject_ones(): void
    {
        $crossSubject = Competency::factory()->create(['subject' => null]);
        $maths = Competency::factory()->create(['subject' => 'Mathematik']);
        $this->actingAs(User::factory()->teacher()->create());

        Livewire::test(ManageCompetencies::class)
            ->filterTable('subject', CompetencyResource::CROSS_SUBJECT)
            ->assertCanSeeTableRecords([$crossSubject])
            ->assertCanNotSeeTableRecords([$maths])
            ->filterTable('subject', 'Mathematik')
            ->assertCanSeeTableRecords([$maths])
            ->assertCanNotSeeTableRecords([$crossSubject]);
    }
}
