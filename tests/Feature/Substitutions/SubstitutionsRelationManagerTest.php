<?php

namespace Tests\Feature\Substitutions;

use App\Filament\Pages\HandoverSheet;
use App\Filament\Resources\SchoolClasses\Pages\EditSchoolClass;
use App\Filament\Resources\SchoolClasses\RelationManagers\SubstitutionsRelationManager;
use App\Models\ClassSubstitution;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SubstitutionsRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private SchoolClass $schoolClass;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->schoolClass = SchoolClass::factory()->for($this->school)->create(['teacher_id' => $this->teacher()->id]);
        $this->admin = User::factory()->admin()->for($this->school)->create();
        $this->actingAs($this->admin);
    }

    public function test_the_edit_page_lists_the_substitutions_of_the_class_with_their_status(): void
    {
        $active = ClassSubstitution::factory()->for($this->schoolClass)->create(['note' => 'Arbeitsblatt liegt im Fach.']);
        $upcoming = ClassSubstitution::factory()->for($this->schoolClass)->upcoming()->create();
        $ended = ClassSubstitution::factory()->for($this->schoolClass)->ended()->create();
        $otherClass = SchoolClass::factory()->for($this->school)->create();
        $ofOtherClass = ClassSubstitution::factory()->for($otherClass)->create();

        Livewire::withQueryParams(['relation' => 2])
            ->test(EditSchoolClass::class, ['record' => $this->schoolClass->getRouteKey()])
            ->assertSeeLivewire(SubstitutionsRelationManager::class);

        $this->substitutions()
            ->assertCanSeeTableRecords([$active, $upcoming, $ended], inOrder: true)
            ->assertCanNotSeeTableRecords([$ofOtherClass])
            ->assertTableColumnStateSet('status', 'active', $active)
            ->assertTableColumnStateSet('status', 'upcoming', $upcoming)
            ->assertTableColumnStateSet('status', 'ended', $ended)
            ->assertSee('Arbeitsblatt liegt im Fach.');
    }

    public function test_admin_sets_up_a_substitution_for_a_teacher_of_the_school(): void
    {
        $substitute = $this->teacher();

        $this->substitutions()
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'substitute_id' => $substitute->id,
                'starts_on' => today()->toDateString(),
                'ends_on' => today()->addDays(4)->toDateString(),
                'note' => 'Wir sind bei Kapitel 3.',
            ])
            ->assertHasNoFormErrors();

        $substitution = $this->schoolClass->substitutions()->sole();

        $this->assertSame($substitute->id, $substitution->substitute_id);
        $this->assertSame($this->admin->id, $substitution->granted_by);
        $this->assertSame(today()->addDays(4)->toDateString(), $substitution->ends_on->toDateString());
        $this->assertSame('Wir sind bei Kapitel 3.', $substitution->note);
        $this->assertTrue($this->schoolClass->isTaughtBy($substitute));
    }

    public function test_the_dialog_offers_only_teachers_of_the_school_who_do_not_teach_the_class_anyway(): void
    {
        $this->teacher(['name' => 'Eigene Lehrerin']);
        $this->schoolClass->teachers()->attach($this->teacher(['name' => 'Schon zugeordnet']));
        User::factory()->teacher()->create(['name' => 'Fremder Lehrer']);
        User::factory()->student($this->schoolClass)->create(['name' => 'Eigener Schüler']);

        $this->substitutions()
            ->mountAction(TestAction::make(CreateAction::class)->table())
            ->assertMountedActionModalSee('Eigene Lehrerin')
            ->assertMountedActionModalDontSee(['Fremder Lehrer', 'Eigener Schüler', 'Schon zugeordnet', $this->schoolClass->teacher->name]);
    }

    public function test_forged_ids_of_other_people_cannot_be_named_as_substitute(): void
    {
        $assigned = $this->teacher();
        $this->schoolClass->teachers()->attach($assigned);
        $notAllowed = [
            User::factory()->teacher()->create(),
            User::factory()->student($this->schoolClass)->create(),
            User::factory()->admin()->for($this->school)->create(),
            User::factory()->teacher()->create(['school_id' => null]),
            $this->schoolClass->teacher,
            $assigned,
        ];

        foreach ($notAllowed as $person) {
            $this->substitutions()
                ->callAction(TestAction::make(CreateAction::class)->table(), $this->validData(['substitute_id' => $person->id]))
                ->assertHasFormErrors(['substitute_id']);
        }

        $this->assertSame(0, $this->schoolClass->substitutions()->count());
    }

    public function test_the_period_must_be_sensible(): void
    {
        $substitute = $this->teacher();
        $max = ClassSubstitution::maxDays();

        $cases = [
            'starts in the past' => [['starts_on' => today()->subDay()->toDateString(), 'ends_on' => today()->addDay()->toDateString()], 'starts_on'],
            'ends before it starts' => [['starts_on' => today()->addDays(3)->toDateString(), 'ends_on' => today()->addDays(2)->toDateString()], 'ends_on'],
            'lasts too long' => [['starts_on' => today()->toDateString(), 'ends_on' => today()->addDays($max)->toDateString()], 'ends_on'],
            'no end' => [['ends_on' => null], 'ends_on'],
            'no start' => [['starts_on' => null], 'starts_on'],
        ];

        foreach ($cases as $name => [$data, $field]) {
            $this->substitutions()
                ->callAction(TestAction::make(CreateAction::class)->table(), $this->validData(['substitute_id' => $substitute->id, ...$data]))
                ->assertHasFormErrors([$field]);
        }

        $this->assertSame(0, $this->schoolClass->substitutions()->count());
    }

    public function test_a_substitution_may_last_exactly_the_maximum_number_of_days(): void
    {
        $substitute = $this->teacher();

        $this->substitutions()
            ->callAction(TestAction::make(CreateAction::class)->table(), $this->validData([
                'substitute_id' => $substitute->id,
                'starts_on' => today()->toDateString(),
                'ends_on' => today()->addDays(ClassSubstitution::maxDays() - 1)->toDateString(),
            ]))
            ->assertHasNoFormErrors();

        $this->assertSame(1, $this->schoolClass->substitutions()->count());
    }

    public function test_periods_of_the_same_teacher_cannot_be_chained_beyond_the_maximum(): void
    {
        $substitute = $this->teacher();
        $max = ClassSubstitution::maxDays();

        $this->substitutions()
            ->callAction(TestAction::make(CreateAction::class)->table(), $this->validData([
                'substitute_id' => $substitute->id,
                'starts_on' => today()->toDateString(),
                'ends_on' => today()->addDays($max - 1)->toDateString(),
            ]))
            ->assertHasNoFormErrors();

        // Directly adjoining, with a gap of one day, overlapping and far ahead: all of them add days on top of the 90.
        foreach ([[$max, $max + 4], [$max + 1, $max + 5], [$max - 10, $max - 5], [$max + 200, $max + 204]] as [$from, $to]) {
            $this->substitutions()
                ->callAction(TestAction::make(CreateAction::class)->table(), $this->validData([
                    'substitute_id' => $substitute->id,
                    'starts_on' => today()->addDays($from)->toDateString(),
                    'ends_on' => today()->addDays($to)->toDateString(),
                ]))
                ->assertHasFormErrors(['ends_on']);
        }

        $this->assertSame(1, $this->schoolClass->substitutions()->count());
    }

    public function test_the_limit_counts_per_teacher_and_class_and_only_the_days_still_to_come(): void
    {
        $substitute = $this->teacher();
        $colleague = $this->teacher();
        $otherClass = SchoolClass::factory()->for($this->school)->create();
        ClassSubstitution::factory()->for($this->schoolClass)->create([
            'substitute_id' => $substitute->id,
            'starts_on' => today(),
            'ends_on' => today()->addDays(59),
        ]);
        ClassSubstitution::factory()->for($otherClass)->create([
            'substitute_id' => $substitute->id,
            'starts_on' => today(),
            'ends_on' => today()->addDays(89),
        ]);

        $this->assertSame(60, ClassSubstitution::daysPlanned($this->schoolClass, $substitute->id));
        $this->assertSame(0, ClassSubstitution::daysPlanned($this->schoolClass, $colleague->id));

        // Another teacher is not affected by the 60 days.
        $this->substitutions()
            ->callAction(TestAction::make(CreateAction::class)->table(), $this->validData([
                'substitute_id' => $colleague->id,
                'starts_on' => today()->toDateString(),
                'ends_on' => today()->addDays(89)->toDateString(),
            ]))
            ->assertHasNoFormErrors();

        // 60 days plus 31 are too many, 60 plus 30 are exactly the maximum.
        foreach ([[60, 90, true], [60, 89, false]] as [$from, $to, $rejected]) {
            $call = $this->substitutions()
                ->callAction(TestAction::make(CreateAction::class)->table(), $this->validData([
                    'substitute_id' => $substitute->id,
                    'starts_on' => today()->addDays($from)->toDateString(),
                    'ends_on' => today()->addDays($to)->toDateString(),
                ]));

            $rejected ? $call->assertHasFormErrors(['ends_on']) : $call->assertHasNoFormErrors();
        }

        // Days that are over do not count: 30 days later 30 days of the first period and all 30 of the second are left.
        $this->travel(30)->days();

        $this->assertSame(60, ClassSubstitution::daysPlanned($this->schoolClass->fresh(), $substitute->id));
    }

    public function test_the_note_is_optional_and_limited(): void
    {
        $substitute = $this->teacher();

        $this->substitutions()
            ->callAction(TestAction::make(CreateAction::class)->table(), $this->validData(['substitute_id' => $substitute->id, 'note' => str_repeat('a', 1001)]))
            ->assertHasFormErrors(['note']);

        $this->substitutions()
            ->callAction(TestAction::make(CreateAction::class)->table(), $this->validData(['substitute_id' => $substitute->id, 'note' => null]))
            ->assertHasNoFormErrors();

        $this->assertNull($this->schoolClass->substitutions()->sole()->note);
    }

    public function test_admin_ends_a_running_substitution_early(): void
    {
        $substitute = $this->teacher();
        $running = ClassSubstitution::factory()->for($this->schoolClass)->create([
            'substitute_id' => $substitute->id,
            'starts_on' => today()->subDays(2),
            'ends_on' => today()->addDays(5),
        ]);

        $this->substitutions()
            ->callAction(TestAction::make('end')->table($running));

        $this->assertSame('ended', $running->fresh()->status());
        $this->assertFalse($this->schoolClass->isTaughtBy($substitute));
    }

    public function test_admin_withdraws_a_planned_substitution(): void
    {
        $upcoming = ClassSubstitution::factory()->for($this->schoolClass)->upcoming()->create();

        $this->substitutions()
            ->assertTableActionHasLabel('end', 'Zurückziehen', $upcoming)
            ->callAction(TestAction::make('end')->table($upcoming));

        $this->assertModelMissing($upcoming);
    }

    public function test_an_ended_substitution_cannot_be_ended_again(): void
    {
        $ended = ClassSubstitution::factory()->for($this->schoolClass)->ended()->create();

        $this->substitutions()->assertActionHidden(TestAction::make('end')->table($ended));
    }

    public function test_the_handover_sheet_is_linked_from_every_row(): void
    {
        $substitution = ClassSubstitution::factory()->for($this->schoolClass)->create();

        $this->substitutions()->assertActionHasUrl(
            TestAction::make('handoverSheet')->table($substitution),
            HandoverSheet::getUrl(['schoolClass' => $this->schoolClass->id]),
        );
    }

    public function test_the_handover_sheet_of_the_class_is_linked_in_the_header_even_without_a_substitution(): void
    {
        $this->substitutions()->assertActionHasUrl(
            TestAction::make('classHandoverSheet')->table(),
            HandoverSheet::getUrl(['schoolClass' => $this->schoolClass->id]),
        );
    }

    public function test_an_administration_cannot_end_a_substitution_of_another_school(): void
    {
        $foreign = ClassSubstitution::factory()->create();

        $this->assertFalse($this->admin->can('update', $foreign));

        $this->substitutions()->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_teachers_cannot_open_the_class_administration(): void
    {
        $substitute = $this->teacher();
        ClassSubstitution::factory()->for($this->schoolClass)->create(['substitute_id' => $substitute->id]);

        foreach ([$this->schoolClass->teacher, $substitute] as $teacher) {
            $this->actingAs($teacher)
                ->get(EditSchoolClass::getUrl(['record' => $this->schoolClass]))
                ->assertForbidden();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function teacher(array $attributes = []): User
    {
        return User::factory()->teacher()->for($this->school)->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        return [
            'substitute_id' => $this->teacher()->id,
            'starts_on' => today()->toDateString(),
            'ends_on' => today()->addDays(4)->toDateString(),
            'note' => null,
            ...$overrides,
        ];
    }

    private function substitutions(): mixed
    {
        return Livewire::test(SubstitutionsRelationManager::class, [
            'ownerRecord' => $this->schoolClass,
            'pageClass' => EditSchoolClass::class,
        ]);
    }
}
