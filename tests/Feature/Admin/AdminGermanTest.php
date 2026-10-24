<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Competencies\Pages\ManageCompetencies;
use App\Filament\Resources\SchoolClasses\Pages\EditSchoolClass;
use App\Filament\Resources\SchoolClasses\RelationManagers\TeachersRelationManager;
use App\Filament\Resources\Schools\Pages\EditSchool;
use App\Filament\Resources\Schools\Pages\ListSchools;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The tests of the administration run in English; this one makes sure the German interface (the default) still reads German.
 */
class AdminGermanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('de');
    }

    public function test_the_user_management_reads_german(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ListUsers::class)
            ->assertSee('Benutzername')
            ->assertSee('Rolle')
            ->assertDontSee('Username');

        Livewire::test(CreateUser::class)
            ->assertSee('Zugang')
            ->assertSee('Damit meldet sich die Person an.')
            ->assertSee('Passwort');
    }

    public function test_the_operator_sees_the_administration_accounts_and_the_schools_in_german(): void
    {
        $operator = User::factory()->operator()->create();
        $school = School::factory()->blocked()->create(['school_type' => 'Gymnasium', 'state' => 'Bayern']);
        $this->actingAs($operator);

        Livewire::test(ListUsers::class)->assertSee('Administrationskonten');

        Livewire::test(ListSchools::class)
            ->assertSee('Schulen')
            ->assertSee('Registriert am')
            ->assertSee('Gesperrt')
            ->assertSee('Bayern')
            ->assertSee('Fehlt')
            ->assertDontSee('Blocked');

        Livewire::test(EditSchool::class, ['record' => $school->getRouteKey()])
            ->assertSee('Status: Gesperrt seit '.$school->blocked_at->format('d.m.Y'))
            ->assertSee('Amtliche Schulnummer')
            ->assertSee('Entsperren');
    }

    public function test_the_competency_framework_reads_german(): void
    {
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(ManageCompetencies::class)
            ->assertSee('Kompetenzraster')
            ->assertSee('Die Kompetenzen des Referenzrahmens, denen Hausaufgabenfragen zugeordnet werden.')
            ->assertDontSee('Competency framework');
    }

    public function test_the_class_administration_reads_german(): void
    {
        $admin = User::factory()->admin()->create();
        $schoolClass = SchoolClass::factory()->for($admin->school)->create(['teacher_id' => null]);
        $this->actingAs($admin);

        Livewire::test(EditSchoolClass::class, ['record' => $schoolClass->getRouteKey()])
            ->assertSee('Klassenleitung')
            ->assertSee('Neuen Code erzeugen')
            ->assertSee('Klassencode');

        Livewire::test(TeachersRelationManager::class, ['ownerRecord' => $schoolClass, 'pageClass' => EditSchoolClass::class])
            ->assertSee('Die Klasse hat noch keine Klassenleitung.')
            ->assertSee('Lehrkräfte hinzufügen');
    }
}
