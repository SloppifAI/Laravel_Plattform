<?php

namespace Tests\Feature\Substitutions;

use App\Filament\Pages\HandoverSheet;
use App\Filament\Pages\Substitutions;
use App\Models\ClassSubstitution;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SubstitutionsPageTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $leader;

    private SchoolClass $schoolClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->leader = $this->teacher();
        $this->schoolClass = SchoolClass::factory()->for($this->school)->create(['name' => '7b', 'teacher_id' => $this->leader->id]);
    }

    public function test_only_teachers_open_the_page(): void
    {
        $this->actingAs($this->leader)->get(Substitutions::getUrl())->assertOk()->assertSee(['Vertretung', 'Keine Vertretungen']);

        foreach ([
            User::factory()->admin()->for($this->school)->create(),
            User::factory()->student($this->schoolClass)->create(),
            User::factory()->operator()->create(),
        ] as $other) {
            $this->actingAs($other)->get(Substitutions::getUrl())->assertForbidden();
        }
    }

    public function test_the_klassenleitung_sets_up_a_substitution_for_a_colleague(): void
    {
        $colleague = $this->teacher(['name' => 'Frau Weber']);

        Livewire::actingAs($this->leader)
            ->test(Substitutions::class)
            ->callAction('setUp', [
                'school_class_id' => $this->schoolClass->id,
                'substitute_id' => $colleague->id,
                'starts_on' => today()->toDateString(),
                'ends_on' => today()->addDays(6)->toDateString(),
                'note' => "Wir sind bei Kapitel 3.\nHausaufgaben liegen im Fach.",
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Vertretung eingerichtet');

        $substitution = $this->schoolClass->substitutions()->sole();

        $this->assertSame($colleague->id, $substitution->substitute_id);
        $this->assertSame($this->leader->id, $substitution->granted_by);
        $this->assertSame("Wir sind bei Kapitel 3.\nHausaufgaben liegen im Fach.", $substitution->note);
        $this->assertTrue($this->schoolClass->isTaughtBy($colleague));
    }

    public function test_the_class_is_preselected_when_the_teacher_leads_only_one(): void
    {
        $this->actingAs($this->leader);

        Livewire::test(Substitutions::class)
            ->mountAction('setUp')
            ->assertSchemaStateSet(['school_class_id' => $this->schoolClass->id]);
    }

    public function test_the_dialog_offers_only_the_classes_the_teacher_leads_and_their_school_colleagues(): void
    {
        SchoolClass::factory()->for($this->school)->create(['name' => 'Fremde Klasse', 'teacher_id' => $this->teacher()->id]);
        $this->teacher(['name' => 'Kollegin Eins']);
        User::factory()->teacher()->create(['name' => 'Lehrer Anderswo']);
        $secondClass = SchoolClass::factory()->for($this->school)->create(['name' => '8a', 'teacher_id' => $this->leader->id]);

        Livewire::actingAs($this->leader)
            ->test(Substitutions::class)
            ->mountAction('setUp')
            ->fillForm(['school_class_id' => $secondClass->id])
            ->assertMountedActionModalSee(['7b', '8a', 'Kollegin Eins'])
            ->assertMountedActionModalDontSee(['Fremde Klasse', 'Lehrer Anderswo']);
    }

    public function test_a_class_of_a_colleague_or_another_school_cannot_be_chosen(): void
    {
        $colleaguesClass = SchoolClass::factory()->for($this->school)->create(['teacher_id' => $this->teacher()->id]);
        $foreignClass = SchoolClass::factory()->create();
        $substitute = $this->teacher();

        foreach ([$colleaguesClass, $foreignClass] as $forged) {
            Livewire::actingAs($this->leader)
                ->test(Substitutions::class)
                ->callAction('setUp', [
                    'school_class_id' => $forged->id,
                    'substitute_id' => $substitute->id,
                    'starts_on' => today()->toDateString(),
                    'ends_on' => today()->addDays(2)->toDateString(),
                    'note' => null,
                ])
                ->assertHasFormErrors(['school_class_id']);
        }

        $this->assertSame(0, ClassSubstitution::query()->count());
    }

    public function test_the_forged_substitute_of_another_school_is_rejected(): void
    {
        $foreignTeacher = User::factory()->teacher()->create();

        Livewire::actingAs($this->leader)
            ->test(Substitutions::class)
            ->callAction('setUp', [
                'school_class_id' => $this->schoolClass->id,
                'substitute_id' => $foreignTeacher->id,
                'starts_on' => today()->toDateString(),
                'ends_on' => today()->addDays(2)->toDateString(),
                'note' => null,
            ])
            ->assertHasFormErrors(['substitute_id']);

        $this->assertSame(0, ClassSubstitution::query()->count());
    }

    public function test_the_period_rules_apply_here_too(): void
    {
        $colleague = $this->teacher();

        foreach ([
            ['starts_on' => today()->subDay()->toDateString(), 'ends_on' => today()->addDay()->toDateString()],
            ['starts_on' => today()->addDays(3)->toDateString(), 'ends_on' => today()->addDays(2)->toDateString()],
            ['starts_on' => today()->toDateString(), 'ends_on' => today()->addDays(ClassSubstitution::maxDays())->toDateString()],
        ] as $period) {
            Livewire::actingAs($this->leader)
                ->test(Substitutions::class)
                ->callAction('setUp', [
                    'school_class_id' => $this->schoolClass->id,
                    'substitute_id' => $colleague->id,
                    'note' => null,
                    ...$period,
                ])
                ->assertHasFormErrors();
        }

        $this->assertSame(0, ClassSubstitution::query()->count());
    }

    public function test_the_klassenleitung_cannot_chain_periods_into_a_permanent_substitution(): void
    {
        $colleague = $this->teacher();
        $max = ClassSubstitution::maxDays();

        foreach ([[0, $max - 1, false], [$max, 2 * $max - 1, true], [2 * $max, 3 * $max - 1, true]] as [$from, $to, $rejected]) {
            $call = Livewire::actingAs($this->leader)
                ->test(Substitutions::class)
                ->callAction('setUp', [
                    'school_class_id' => $this->schoolClass->id,
                    'substitute_id' => $colleague->id,
                    'starts_on' => today()->addDays($from)->toDateString(),
                    'ends_on' => today()->addDays($to)->toDateString(),
                    'note' => null,
                ]);

            $rejected ? $call->assertHasFormErrors(['ends_on']) : $call->assertHasNoFormErrors();
        }

        $this->assertSame(1, $this->schoolClass->substitutions()->count());
    }

    public function test_the_texts_name_everything_a_substitution_grants(): void
    {
        $this->actingAs($this->leader)
            ->get(Substitutions::getUrl())
            ->assertSee(['Klassenbuch mit allen Fehlzeiten', 'Einwilligungen der Eltern erfassen', 'Tuto-Mikro nutzen', 'Lernstände der Klasse sehen'], false);
    }

    public function test_the_klassenleitung_opens_the_handover_sheet_of_every_class_they_lead_before_any_substitution_exists(): void
    {
        Livewire::actingAs($this->leader)
            ->test(Substitutions::class)
            ->assertActionHasUrl("handoverSheet{$this->schoolClass->id}", HandoverSheet::getUrl(['schoolClass' => $this->schoolClass->id]));

        SchoolClass::factory()->for($this->school)->create(['name' => '8a', 'teacher_id' => $this->leader->id]);
        SchoolClass::factory()->for($this->school)->create(['name' => 'Fremde Klasse', 'teacher_id' => $this->teacher()->id]);

        $this->actingAs($this->leader)
            ->get(Substitutions::getUrl())
            ->assertSee(['Übergabe-Mappe 7b', 'Übergabe-Mappe 8a'])
            ->assertDontSee('Übergabe-Mappe Fremde Klasse');

    }

    public function test_a_teacher_without_a_class_gets_no_handover_sheet_link(): void
    {
        $this->actingAs($this->teacher())->get(Substitutions::getUrl())->assertOk()->assertDontSee('Übergabe-Mappe');
    }

    public function test_the_action_is_hidden_for_a_teacher_without_a_class(): void
    {
        $withoutClass = $this->teacher();

        Livewire::actingAs($withoutClass)
            ->test(Substitutions::class)
            ->assertActionHidden('setUp');
    }

    public function test_the_list_shows_the_substitutions_of_my_classes_and_those_i_substitute_in(): void
    {
        $colleague = $this->teacher();
        $mine = ClassSubstitution::factory()->for($this->schoolClass)->create(['substitute_id' => $colleague->id]);
        $colleaguesClass = SchoolClass::factory()->for($this->school)->create(['name' => '9c', 'teacher_id' => $colleague->id]);
        $iSubstitute = ClassSubstitution::factory()->for($colleaguesClass)->create(['substitute_id' => $this->leader->id]);
        $unrelated = ClassSubstitution::factory()->for(
            SchoolClass::factory()->for($this->school)->create(['teacher_id' => $this->teacher()->id]),
        )->create();
        $foreign = ClassSubstitution::factory()->create();

        Livewire::actingAs($this->leader)
            ->test(Substitutions::class)
            ->assertCanSeeTableRecords([$mine, $iSubstitute])
            ->assertCanNotSeeTableRecords([$unrelated, $foreign])
            ->assertSee('Du vertrittst diese Klasse');
    }

    public function test_the_list_puts_active_before_upcoming_before_ended_substitutions(): void
    {
        $ended = ClassSubstitution::factory()->for($this->schoolClass)->ended(1)->create();
        $upcoming = ClassSubstitution::factory()->for($this->schoolClass)->upcoming(10)->create();
        $active = ClassSubstitution::factory()->for($this->schoolClass)->create(['starts_on' => today()->subDays(20), 'ends_on' => today()->addDays(20)]);
        $laterEnded = ClassSubstitution::factory()->for($this->schoolClass)->ended(30)->create();

        Livewire::actingAs($this->leader)
            ->test(Substitutions::class)
            ->assertCanSeeTableRecords([$active, $upcoming, $ended, $laterEnded], inOrder: true)
            ->assertTableColumnStateSet('status', 'active', $active)
            ->assertTableColumnStateSet('status', 'upcoming', $upcoming)
            ->assertTableColumnStateSet('status', 'ended', $ended);
    }

    public function test_the_klassenleitung_ends_a_substitution_early(): void
    {
        $colleague = $this->teacher();
        $running = ClassSubstitution::factory()->for($this->schoolClass)->create([
            'substitute_id' => $colleague->id,
            'starts_on' => today()->subDay(),
            'ends_on' => today()->addDays(5),
        ]);

        Livewire::actingAs($this->leader)
            ->test(Substitutions::class)
            ->callAction(TestAction::make('end')->table($running))
            ->assertNotified('Vertretung beendet');

        $this->assertSame('ended', $running->fresh()->status());
        $this->assertFalse($this->schoolClass->isTaughtBy($colleague));
    }

    public function test_the_substitute_can_open_the_handover_sheet_but_not_end_the_substitution(): void
    {
        $colleague = $this->teacher();
        $colleaguesClass = SchoolClass::factory()->for($this->school)->create(['teacher_id' => $colleague->id]);
        $substitution = ClassSubstitution::factory()->for($colleaguesClass)->create(['substitute_id' => $this->leader->id]);

        Livewire::actingAs($this->leader)
            ->test(Substitutions::class)
            ->assertActionVisible(TestAction::make('handoverSheet')->table($substitution))
            ->assertActionHidden(TestAction::make('end')->table($substitution))
            ->assertActionHasUrl(
                TestAction::make('handoverSheet')->table($substitution),
                HandoverSheet::getUrl(['schoolClass' => $colleaguesClass->id]),
            );
    }

    public function test_a_substitute_cannot_end_the_substitution_by_calling_the_action_anyway(): void
    {
        $colleague = $this->teacher();
        $colleaguesClass = SchoolClass::factory()->for($this->school)->create(['teacher_id' => $colleague->id]);
        $substitution = ClassSubstitution::factory()->for($colleaguesClass)->create(['substitute_id' => $this->leader->id]);

        Livewire::actingAs($this->leader)
            ->test(Substitutions::class)
            ->call('mountAction', 'end', [], ['table' => true, 'recordKey' => $substitution->getKey()])
            ->call('callMountedAction');

        $this->assertSame('active', $substitution->fresh()->status());

        // The same raw calls work for the Klassenleitung, so the check above is not passing for another reason.
        Livewire::actingAs($colleague)
            ->test(Substitutions::class)
            ->call('mountAction', 'end', [], ['table' => true, 'recordKey' => $substitution->getKey()])
            ->call('callMountedAction');

        // It started today: it stays as a record that ended before it began.
        $this->assertSame('ended', $substitution->fresh()->status());
    }

    public function test_an_ended_substitution_offers_no_handover_sheet_to_the_substitute(): void
    {
        $colleague = $this->teacher();
        $colleaguesClass = SchoolClass::factory()->for($this->school)->create(['teacher_id' => $colleague->id]);
        $ended = ClassSubstitution::factory()->for($colleaguesClass)->ended()->create(['substitute_id' => $this->leader->id]);

        Livewire::actingAs($this->leader)
            ->test(Substitutions::class)
            ->assertCanSeeTableRecords([$ended])
            ->assertActionHidden(TestAction::make('handoverSheet')->table($ended));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function teacher(array $attributes = []): User
    {
        return User::factory()->teacher()->for($this->school)->create($attributes);
    }
}
