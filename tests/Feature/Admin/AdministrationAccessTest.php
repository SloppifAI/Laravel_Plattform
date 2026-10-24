<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\ImportStudents;
use App\Filament\Pages\RolesAndPermissions;
use App\Filament\Resources\Competencies\CompetencyResource;
use App\Filament\Resources\SchoolClasses\SchoolClassResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Competency;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\Role;
use Closure;
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
        $schoolClass = $this->classOf($admin->school);

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

        $this->actingAs($user);

        foreach ($this->administrationUrls(User::factory()->admin()->for($user->school)->create(), $this->classOf($user->school)) as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_operators_open_the_platform_pages_but_not_the_school_administration(): void
    {
        $operator = User::factory()->operator()->create();
        $schoolAdmin = User::factory()->admin()->create();

        $this->actingAs($operator);

        foreach ([
            UserResource::getUrl('index'),
            UserResource::getUrl('create'),
            UserResource::getUrl('edit', ['record' => $schoolAdmin]),
            CompetencyResource::getUrl(),
            RolesAndPermissions::getUrl(),
        ] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get(SchoolClassResource::getUrl('index'))->assertForbidden();
        $this->get(SchoolClassResource::getUrl('create'))->assertForbidden();
        $this->get(SchoolClassResource::getUrl('edit', ['record' => $this->classOf($schoolAdmin->school)]))->assertNotFound();
        $this->get('/schuelerliste-importieren')->assertForbidden();
        $this->assertFalse(ImportStudents::canAccess());
    }

    public function test_operators_find_the_user_administration_and_competencies_in_the_platform_group(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $this->assertSame('Plattform', UserResource::getNavigationGroup());
        $this->assertSame('Plattform', CompetencyResource::getNavigationGroup());
        $this->assertSame('Plattform', RolesAndPermissions::getNavigationGroup());

        $this->actingAs(User::factory()->admin()->create());
        $this->assertSame('Verwaltung', UserResource::getNavigationGroup());
        $this->assertSame('Verwaltung', CompetencyResource::getNavigationGroup());
        $this->assertSame('Verwaltung', RolesAndPermissions::getNavigationGroup());
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

    public function test_the_roles_page_explains_every_role_for_admins_and_operators(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->operator()->create()] as $user) {
            $this->actingAs($user)
                ->get(RolesAndPermissions::getUrl())
                ->assertOk()
                ->assertSee('Benutzer verwalten')
                ->assertSee('Nur ansehen')
                ->assertSee('Nur eigene Schule')
                ->assertSee('keine Lerndaten')
                ->assertSee(collect(Role::cases())->map->getDescription()->all());
        }
    }

    /**
     * The matrix claims what each role may do; the policies have to agree.
     */
    public function test_the_permission_matrix_matches_the_policies(): void
    {
        $abilities = [
            'schools' => fn (User $user): bool => $user->can('update', $user->school ?? School::factory()->create()),
            'users' => fn (User $user): bool => $user->can('create', User::class),
            'classes' => fn (User $user): bool => $user->can('create', SchoolClass::class),
            'competencies' => fn (User $user): bool => $user->can('create', Competency::class),
            'lessons' => fn (User $user): bool => $user->can('create', Lesson::class),
            'homework' => fn (User $user): bool => $user->can('create', Submission::class),
            'explain' => fn (User $user): bool => $user->can('create', ExplanationSession::class),
        ];
        $matrix = RolesAndPermissions::matrix();

        foreach (Role::cases() as $role) {
            $user = $role === Role::Operator ? User::factory()->operator()->create() : User::factory()->create(['role' => $role]);

            /** @var Closure(User): bool $mayDoIt */
            foreach ($abilities as $capability => $mayDoIt) {
                $this->assertSame(
                    in_array($matrix[$capability][$role->value], ['all', 'own', 'school', 'admins'], true),
                    $mayDoIt($user),
                    "{$role->getLabel()}: {$matrix[$capability]['ability']}",
                );
            }

            $this->assertSame($matrix['competencies'][$role->value] !== 'none', $user->can('viewAny', Competency::class));
        }
    }

    public function test_the_operator_sees_no_learning_data(): void
    {
        $matrix = RolesAndPermissions::matrix();
        $operator = User::factory()->operator()->create();

        foreach (['lessons', 'results', 'homework', 'explain'] as $capability) {
            $this->assertSame('none', $matrix[$capability]['operator'], $capability);
        }

        foreach ([Lesson::class, Submission::class, ExplanationSession::class] as $model) {
            $this->assertFalse($operator->can('viewAny', $model), $model);
        }
    }

    /**
     * "Nur eigene Schule" in the matrix means that an administration is bound to its school by the policies.
     */
    public function test_the_school_scope_of_administrations_holds_in_the_policies(): void
    {
        $admin = User::factory()->admin()->create();
        $ownTeacher = User::factory()->teacher()->for($admin->school)->create();
        $ownClass = $this->classOf($admin->school);
        $foreignTeacher = User::factory()->teacher()->create();
        $foreignClass = $this->classOf($foreignTeacher->school);
        $foreignLesson = Lesson::factory()->create(['school_class_id' => $foreignClass->id, 'teacher_id' => $foreignTeacher->id]);

        $this->assertTrue($admin->can('update', $ownTeacher));
        $this->assertTrue($admin->can('update', $ownClass));
        $this->assertTrue($admin->can('update', $admin->school));

        $this->assertFalse($admin->can('update', $foreignTeacher));
        $this->assertFalse($admin->can('delete', $foreignTeacher));
        $this->assertFalse($admin->can('update', $foreignClass));
        $this->assertFalse($admin->can('update', $foreignLesson));
        $this->assertFalse($admin->can('update', $foreignTeacher->school));
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
            '/schuelerliste-importieren',
        ];
    }

    private function classOf(School $school): SchoolClass
    {
        return SchoolClass::factory()->for($school)->create(['teacher_id' => null]);
    }
}
