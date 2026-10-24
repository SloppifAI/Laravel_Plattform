<?php

namespace Tests\Feature\Schools;

use App\Filament\Resources\Schools\Pages\EditSchool;
use App\Filament\Resources\Schools\Pages\ListSchools;
use App\Filament\Resources\Schools\SchoolResource;
use App\Models\Lesson;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchoolManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_operator_sees_every_school_with_aggregate_numbers_only(): void
    {
        $school = School::factory()->create(['school_number' => '111111', 'city' => 'Kassel', 'state' => 'Hessen', 'school_type' => 'Gymnasium']);
        $blocked = School::factory()->blocked()->create();
        $schoolClass = SchoolClass::factory()->for($school)->create();
        User::factory()->for($school)->admin()->create();
        User::factory()->student($schoolClass)->count(2)->create();

        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(ListSchools::class)
            ->assertCanSeeTableRecords([$school, $blocked])
            ->assertTableColumnStateSet('users_count', 3, $school)
            ->assertTableColumnStateSet('school_classes_count', 1, $school)
            ->assertTableColumnStateSet('school_number', '111111', $school)
            ->assertTableColumnStateSet('school_type', 'Gymnasium', $school)
            ->assertTableColumnStateSet('blocked_at', 'Aktiv', $school)
            ->assertTableColumnStateSet('blocked_at', 'Gesperrt', $blocked)
            ->assertSee('Kassel')
            ->assertSee('Hessen');
    }

    public function test_the_status_can_be_filtered(): void
    {
        $active = School::factory()->create();
        $blocked = School::factory()->blocked()->create();

        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(ListSchools::class)
            ->filterTable('blocked_at', true)
            ->assertCanSeeTableRecords([$blocked])
            ->assertCanNotSeeTableRecords([$active])
            ->filterTable('blocked_at', false)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$blocked]);
    }

    public function test_the_operator_cannot_reach_people_or_lessons_through_the_school(): void
    {
        $school = School::factory()->create();
        $teacher = User::factory()->for($school)->teacher()->create(['name' => 'Frau Geheimnisvoll']);
        $lesson = Lesson::factory()->for(SchoolClass::factory()->for($school))->create(['topic' => 'Streng vertrauliche Stunde']);
        $this->actingAs(User::factory()->operator()->create());

        $this->assertSame([], SchoolResource::getRelations());
        $this->assertSame(['index', 'edit'], array_keys(SchoolResource::getPages()));

        $this->get(SchoolResource::getUrl())
            ->assertOk()
            ->assertDontSee($teacher->name)
            ->assertDontSee($lesson->topic);
        $this->get(SchoolResource::getUrl('edit', ['record' => $school]))
            ->assertOk()
            ->assertDontSee($teacher->name)
            ->assertDontSee($lesson->topic);
    }

    public function test_the_operator_edits_the_master_data_of_a_school(): void
    {
        $school = School::factory()->create(['school_number' => '111111']);
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(EditSchool::class, ['record' => $school->getRouteKey()])
            ->assertFormSet(['name' => $school->name, 'school_number' => '111111'])
            ->fillForm([
                'name' => 'Neue Gesamtschule',
                'school_number' => '111111',
                'school_type' => 'Gesamtschule',
                'city' => 'Fulda',
                'state' => 'Hessen',
                'contact_email' => 'neu@schule.example.test',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Neue Gesamtschule', $school->refresh()->name);
        $this->assertSame('Fulda', $school->city);
        $this->assertSame('neu@schule.example.test', $school->contact_email);
    }

    public function test_editing_cannot_take_over_the_school_number_of_another_school(): void
    {
        School::factory()->create(['school_number' => '222222']);
        $school = School::factory()->create(['school_number' => '111111']);
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(EditSchool::class, ['record' => $school->getRouteKey()])
            ->fillForm(['school_number' => '222222'])
            ->call('save')
            ->assertHasFormErrors(['school_number' => 'unique']);

        $this->assertSame('111111', $school->refresh()->school_number);
    }

    public function test_the_block_state_cannot_be_changed_through_the_edit_form(): void
    {
        $school = School::factory()->create();
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(EditSchool::class, ['record' => $school->getRouteKey()])
            ->set('data.blocked_at', now()->toDateTimeString())
            ->call('save');

        $this->assertFalse($school->refresh()->isBlocked());
    }

    public function test_the_operator_blocks_and_unblocks_a_school_from_the_list(): void
    {
        $school = School::factory()->create();
        $teacher = User::factory()->for($school)->teacher()->create();
        $bystander = User::factory()->teacher()->create();
        $panel = filament()->getCurrentOrDefaultPanel();
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(ListSchools::class)
            ->assertActionVisible(TestAction::make('block')->table($school))
            ->assertActionHidden(TestAction::make('unblock')->table($school))
            ->callAction(TestAction::make('block')->table($school))
            ->assertNotified('Schule gesperrt');

        $this->assertTrue($school->refresh()->isBlocked());
        $this->assertFalse($teacher->refresh()->canAccessPanel($panel));
        $this->assertTrue($bystander->refresh()->canAccessPanel($panel));

        Livewire::test(ListSchools::class)
            ->assertActionHidden(TestAction::make('block')->table($school))
            ->assertActionVisible(TestAction::make('unblock')->table($school))
            ->callAction(TestAction::make('unblock')->table($school))
            ->assertNotified('Schule entsperrt');

        $this->assertFalse($school->refresh()->isBlocked());
        $this->assertTrue($teacher->refresh()->canAccessPanel($panel));
    }

    public function test_blocking_asks_for_confirmation_and_explains_the_lockout(): void
    {
        $school = School::factory()->create(['name' => 'Goethe-Gymnasium']);
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(ListSchools::class)
            ->assertActionExists(
                TestAction::make('block')->table($school),
                fn (Action $action): bool => $action->isConfirmationRequired()
                    && $action->getModalHeading() === 'Goethe-Gymnasium sperren?'
                    && str_contains($action->getModalDescription(), 'Alle Konten dieser Schule')
                    && str_contains($action->getModalDescription(), 'ausgesperrt'),
            );

        $this->assertFalse($school->refresh()->isBlocked());
    }

    public function test_the_operator_blocks_a_school_from_its_edit_page(): void
    {
        $school = School::factory()->create();
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(EditSchool::class, ['record' => $school->getRouteKey()])
            ->assertSee('Status: Aktiv')
            ->callAction('block')
            ->assertSee('Status: Gesperrt seit')
            ->assertActionHidden('block')
            ->callAction('unblock')
            ->assertSee('Status: Aktiv');

        $this->assertFalse($school->refresh()->isBlocked());
    }

    public function test_schools_cannot_be_deleted(): void
    {
        $school = School::factory()->create();
        $operator = User::factory()->operator()->create();

        $this->assertFalse($operator->can('delete', $school));
        $this->assertFalse($operator->can('deleteAny', School::class));
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function rolesOfASchool(): array
    {
        return [
            'Schuladministration' => [Role::Admin],
            'Lehrkraft' => [Role::Teacher],
            'Schüler:in' => [Role::Student],
        ];
    }

    #[DataProvider('rolesOfASchool')]
    public function test_nobody_below_the_operator_gets_into_the_school_resource(Role $role): void
    {
        $school = School::factory()->create();
        $other = School::factory()->create();
        $user = User::factory()->for($school)->create(['role' => $role]);

        $this->actingAs($user);

        $this->assertFalse(SchoolResource::canAccess());
        $this->get(SchoolResource::getUrl())->assertForbidden();
        $this->get(SchoolResource::getUrl('edit', ['record' => $school]))->assertForbidden();
        $this->get(SchoolResource::getUrl('edit', ['record' => $other]))->assertForbidden();
    }

    public function test_a_school_administration_cannot_use_the_operator_list_or_block_action(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->for($school)->admin()->create();
        $this->actingAs($admin);

        Livewire::test(ListSchools::class)->assertForbidden();
        Livewire::test(EditSchool::class, ['record' => $school->getRouteKey()])->assertForbidden();

        $this->assertFalse($admin->can('block', $school));
        $this->assertFalse($school->refresh()->isBlocked());
    }

    public function test_only_the_operator_has_the_school_resource_in_the_navigation(): void
    {
        $admin = User::factory()->admin()->create();
        $operator = User::factory()->operator()->create();

        $this->actingAs($admin);
        $this->assertFalse(SchoolResource::canViewAny());

        $this->actingAs($operator);
        $this->assertTrue(SchoolResource::canViewAny());
        $this->assertSame('Plattform', SchoolResource::getNavigationGroup());
        $this->get(SchoolResource::getUrl())->assertOk()->assertSee('Plattform')->assertSee('Schulen');
    }
}
