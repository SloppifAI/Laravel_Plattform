<?php

namespace Tests\Feature\Schools;

use App\Filament\Pages\MySchool;
use App\Filament\Resources\Schools\SchoolResource;
use App\Models\School;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MySchoolTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_administration_sees_the_data_and_status_of_its_own_school(): void
    {
        $school = School::factory()->create(['name' => 'Goethe-Gymnasium', 'school_number' => '111111', 'city' => 'Kassel']);
        $otherSchool = School::factory()->create(['name' => 'Schiller-Realschule']);
        $this->actingAs(User::factory()->for($school)->admin()->create());

        $this->get(MySchool::getUrl())
            ->assertOk()
            ->assertSee('Meine Schule')
            ->assertSee('Aktiv')
            ->assertDontSee($otherSchool->name);

        Livewire::test(MySchool::class)
            ->assertFormSet([
                'name' => 'Goethe-Gymnasium',
                'school_number' => '111111',
                'city' => 'Kassel',
                'state' => $school->state,
                'contact_email' => $school->contact_email,
            ]);
    }

    public function test_the_administration_edits_the_master_data_of_its_own_school(): void
    {
        $school = School::factory()->create(['school_number' => '111111']);
        $otherSchool = School::factory()->create(['name' => 'Schiller-Realschule', 'school_number' => '222222']);
        $this->actingAs(User::factory()->for($school)->admin()->create());

        Livewire::test(MySchool::class)
            ->fillForm([
                'name' => 'Goethe-Gesamtschule',
                'school_number' => '111111',
                'school_type' => 'Gesamtschule',
                'city' => 'Fulda',
                'state' => 'Hessen',
                'contact_email' => 'neu@schule.example.test',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Gespeichert');

        $school->refresh();
        $this->assertSame('Goethe-Gesamtschule', $school->name);
        $this->assertSame('Fulda', $school->city);
        $this->assertSame('neu@schule.example.test', $school->contact_email);
        $this->assertSame('Schiller-Realschule', $otherSchool->refresh()->name);
    }

    public function test_the_school_is_always_the_one_of_the_signed_in_administration(): void
    {
        $school = School::factory()->create();
        $otherSchool = School::factory()->create(['name' => 'Schiller-Realschule', 'school_number' => '222222']);
        $this->actingAs(User::factory()->for($school)->admin()->create());

        Livewire::test(MySchool::class)
            ->set('data.id', $otherSchool->id)
            ->set('data.blocked_at', now()->toDateTimeString())
            ->set('data.name', 'Übernommen')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Übernommen', $school->refresh()->name);
        $this->assertFalse($school->isBlocked());
        $this->assertSame('Schiller-Realschule', $otherSchool->refresh()->name);
        $this->assertFalse($otherSchool->isBlocked());
    }

    public function test_the_school_number_of_another_school_is_rejected(): void
    {
        School::factory()->create(['school_number' => '222222']);
        $school = School::factory()->create(['school_number' => '111111']);
        $this->actingAs(User::factory()->for($school)->admin()->create());

        Livewire::test(MySchool::class)
            ->fillForm(['school_number' => '222222'])
            ->call('save')
            ->assertHasFormErrors(['school_number' => 'unique']);

        $this->assertSame('111111', $school->refresh()->school_number);
    }

    public function test_the_required_data_cannot_be_emptied(): void
    {
        $school = School::factory()->create();
        $this->actingAs(User::factory()->for($school)->admin()->create());

        Livewire::test(MySchool::class)
            ->fillForm(['name' => '', 'city' => '', 'contact_email' => 'keine-adresse'])
            ->call('save')
            ->assertHasFormErrors(['name' => 'required', 'city' => 'required', 'contact_email' => 'email']);
    }

    public function test_the_administration_cannot_open_or_edit_another_school_through_the_school_resource(): void
    {
        $school = School::factory()->create();
        $otherSchool = School::factory()->create();
        $admin = User::factory()->for($school)->admin()->create();
        $this->actingAs($admin);

        $this->assertFalse($admin->can('update', $otherSchool));
        $this->get(SchoolResource::getUrl('edit', ['record' => $otherSchool]))->assertForbidden();
        $this->get(SchoolResource::getUrl('edit', ['record' => $school]))->assertForbidden();
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function rolesWithoutSchoolAdministration(): array
    {
        return [
            'Plattform-Administration' => [Role::Operator],
            'Lehrkraft' => [Role::Teacher],
            'Schüler:in' => [Role::Student],
        ];
    }

    #[DataProvider('rolesWithoutSchoolAdministration')]
    public function test_only_the_school_administration_gets_the_page(Role $role): void
    {
        $user = $role === Role::Operator
            ? User::factory()->operator()->create()
            : User::factory()->create(['role' => $role]);
        $this->actingAs($user);

        $this->assertFalse(MySchool::canAccess());
        $this->get(MySchool::getUrl())->assertForbidden();
        Livewire::test(MySchool::class)->assertForbidden();
    }

    public function test_the_page_sits_in_the_administration_navigation_group(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->assertTrue(MySchool::canAccess());
        $this->assertSame('Verwaltung', MySchool::getNavigationGroup());
        $this->assertSame('Meine Schule', MySchool::getNavigationLabel());
    }
}
