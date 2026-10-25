<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\Auth\EditProfile;
use App\Http\Controllers\Api\V1\TokenController;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "Verbundene Apps" in the profile: teachers and school administrations see the apps they connected and revoke them.
 */
class ConnectedAppsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_teacher_sees_the_connected_apps(): void
    {
        $teacher = User::factory()->teacher()->create();
        $teacher->createToken('Tablet im Lehrerzimmer', TokenController::ABILITIES);
        User::factory()->teacher()->create()->createToken('Fremdes Handy', TokenController::ABILITIES);

        $this->actingAs($teacher)->get(EditProfile::getUrl())
            ->assertOk()
            ->assertSee(__('Verbundene Apps'))
            ->assertSee('Tablet im Lehrerzimmer')
            ->assertSee(__('Apps, denen du in Tuto den Zugang erlaubt hast. Eine App sieht die Stunden und Klassen, die du in Tuto bearbeiten darfst, und deinen Stundenplan, legt neue Stunden an und setzt die Zusammenfassung einer Stunde. Von Schüler:innen sieht sie nur die Namen, damit sie sie auf deinem Gerät pseudonymisieren kann.'))
            ->assertSee(__('Zugang entziehen'))
            ->assertDontSee('Fremdes Handy');
    }

    public function test_the_dates_of_an_app_are_all_written_the_same_way_in_both_languages(): void
    {
        $this->travelTo('2026-04-03 10:00:00');
        $teacher = User::factory()->teacher()->create();
        $teacher->createToken('Tablet im Lehrerzimmer', TokenController::ABILITIES, now()->addDays(20));

        $this->actingAs($teacher)->get(EditProfile::getUrl())
            ->assertSee('Connected on 3 Apr 2026')
            ->assertSee('valid until 23 Apr 2026');

        app()->setLocale('de');
        $teacher->update(['locale' => 'de']);

        $this->actingAs($teacher->fresh())->get(EditProfile::getUrl())
            ->assertSee('Verbunden am 03.04.2026')
            ->assertSee('gültig bis 23.04.2026')
            ->assertDontSee('gültig bis 23.4.2026');
    }

    public function test_an_administration_without_apps_is_told_so(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(EditProfile::getUrl())
            ->assertOk()
            ->assertSee(__('Verbundene Apps'))
            ->assertSee(__('Noch keine App verbunden.'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rolesWithoutApps(): array
    {
        return ['student' => ['student'], 'operator' => ['operator']];
    }

    #[DataProvider('rolesWithoutApps')]
    public function test_students_and_the_operator_do_not_see_the_section(string $role): void
    {
        $this->actingAs(User::factory()->{$role}()->create())->get(EditProfile::getUrl())
            ->assertOk()
            ->assertDontSee(__('Verbundene Apps'));
    }

    public function test_revoking_deletes_only_that_token_of_the_user(): void
    {
        $teacher = User::factory()->teacher()->create();
        $revoked = $teacher->createToken('Tablet', TokenController::ABILITIES)->accessToken;
        $kept = $teacher->createToken('Handy', TokenController::ABILITIES)->accessToken;
        $othersToken = User::factory()->teacher()->create()->createToken('Fremd', TokenController::ABILITIES)->accessToken;
        $this->actingAs($teacher);

        Livewire::test(EditProfile::class)
            ->assertActionDoesNotExist(TestAction::make('revokeToken'.$othersToken->getKey())->schemaComponent('connectedApp'.$othersToken->getKey(), 'content'))
            ->callAction(TestAction::make('revokeToken'.$revoked->getKey())->schemaComponent('connectedApp'.$revoked->getKey(), 'content'))
            ->assertNotified(__('Zugang entzogen'));

        $this->assertModelMissing($revoked);
        $this->assertModelExists($kept);
        $this->assertModelExists($othersToken);
    }
}
