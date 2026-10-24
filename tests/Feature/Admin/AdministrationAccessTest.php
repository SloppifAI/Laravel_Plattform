<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\RolesAndPermissions;
use App\Filament\Resources\Competencies\CompetencyResource;
use App\Filament\Resources\SchoolClasses\SchoolClassResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Competency;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdministrationAccessTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_admins_open_every_administration_page(): void
    {
        $admin = User::factory()->admin()->create();
        $schoolClass = SchoolClass::factory()->create();

        $this->actingAs($admin);

        foreach ($this->administrationUrls($admin, $schoolClass) as $url) {
            $this->get($url)->assertOk();
        }

        $this->get(CompetencyResource::getUrl())->assertOk();
    }

    #[DataProvider('nonAdminRoles')]
    public function test_administration_pages_are_forbidden_for_other_roles(Role $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $schoolClass = SchoolClass::factory()->create();

        $this->actingAs($user);

        foreach ($this->administrationUrls(User::factory()->admin()->create(), $schoolClass) as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_teachers_may_open_the_competency_framework_but_students_may_not(): void
    {
        $this->actingAs(User::factory()->teacher()->create())
            ->get(CompetencyResource::getUrl())
            ->assertOk()
            ->assertSee('Kompetenzraster');

        $this->actingAs(User::factory()->student()->create())
            ->get(CompetencyResource::getUrl())
            ->assertForbidden();
    }

    public function test_the_roles_page_explains_every_role(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(RolesAndPermissions::getUrl())
            ->assertOk()
            ->assertSee('Benutzer verwalten')
            ->assertSee('Nur ansehen')
            ->assertSee(collect(Role::cases())->map->getDescription()->all());
    }

    /**
     * The matrix claims what each role may create; the policies have to agree.
     */
    public function test_the_permission_matrix_matches_the_policies(): void
    {
        $modelsByCapability = [
            'users' => User::class,
            'classes' => SchoolClass::class,
            'competencies' => Competency::class,
            'lessons' => Lesson::class,
            'homework' => Submission::class,
            'explain' => ExplanationSession::class,
        ];
        $matrix = RolesAndPermissions::matrix();

        foreach (Role::cases() as $role) {
            $user = User::factory()->create(['role' => $role]);

            foreach ($modelsByCapability as $capability => $model) {
                $this->assertSame(
                    in_array($matrix[$capability][$role->value], ['all', 'own'], true),
                    $user->can('create', $model),
                    "{$role->getLabel()}: {$matrix[$capability]['ability']}",
                );
            }

            $this->assertSame($matrix['competencies'][$role->value] !== 'none', $user->can('viewAny', Competency::class));
        }
    }

    /**
     * @return list<string>
     */
    private function administrationUrls(User $someUser, SchoolClass $schoolClass): array
    {
        return [
            UserResource::getUrl('index'),
            UserResource::getUrl('create'),
            UserResource::getUrl('edit', ['record' => $someUser]),
            SchoolClassResource::getUrl('index'),
            SchoolClassResource::getUrl('create'),
            SchoolClassResource::getUrl('edit', ['record' => $schoolClass]),
            RolesAndPermissions::getUrl(),
        ];
    }
}
