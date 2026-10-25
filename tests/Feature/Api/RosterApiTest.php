<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\V1\TokenController;
use App\Models\ClassSubstitution;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/roster: the display names of the students in the classes the account teaches today (led, assigned or
 * substituted) or, for an administration, in every class of its school, for pseudonymising on the device. Nothing but
 * the class and the names, nothing of other classes, and only with the ability roster:read.
 */
class RosterApiTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->teacher()->create();
    }

    /**
     * @param  list<string>  $abilities
     */
    private function token(?User $user = null, array $abilities = TokenController::ABILITIES): string
    {
        return ($user ?? $this->teacher)->createToken('Laptop', $abilities)->plainTextToken;
    }

    /**
     * @param  list<string>  $students
     */
    private function classWith(string $name, array $students): SchoolClass
    {
        // Led by a colleague of the same school, so only the user's own relation to the class decides.
        $class = SchoolClass::factory()
            ->for($this->teacher->school)
            ->for(User::factory()->teacher()->for($this->teacher->school), 'teacher')
            ->create(['name' => $name]);

        foreach ($students as $student) {
            User::factory()->student($class)->create(['name' => $student]);
        }

        return $class;
    }

    public function test_a_teacher_gets_the_classes_they_lead_are_assigned_to_or_substitute_today(): void
    {
        $led = SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => '5a']);
        User::factory()->student($led)->create(['name' => 'Tom Becker']);
        User::factory()->student($led)->create(['name' => 'Lena Wagner']);
        $assigned = $this->classWith('6c', ['Mia Schulz']);
        $assigned->teachers()->attach($this->teacher);
        $covered = $this->classWith('8d', ['Ali Yilmaz']);
        ClassSubstitution::factory()->for($covered)->create(['substitute_id' => $this->teacher->id]);
        ClassSubstitution::factory()->ended()->for($this->classWith('9a', ['Ehemals Vertreten']))->create(['substitute_id' => $this->teacher->id]);
        ClassSubstitution::factory()->upcoming()->for($this->classWith('9b', ['Bald Vertreten']))->create(['substitute_id' => $this->teacher->id]);
        $this->classWith('7b', ['Fremde Klasse']);
        User::factory()->student()->create(['name' => 'Andere Schule']);

        $this->withToken($this->token())->getJson(route('api.roster.index'))
            ->assertOk()
            ->assertExactJson(['data' => [
                ['class' => ['id' => $led->id, 'name' => '5a'], 'students' => ['Lena Wagner', 'Tom Becker']],
                ['class' => ['id' => $assigned->id, 'name' => '6c'], 'students' => ['Mia Schulz']],
                ['class' => ['id' => $covered->id, 'name' => '8d'], 'students' => ['Ali Yilmaz']],
            ]]);
    }

    public function test_an_administration_gets_every_class_of_its_school(): void
    {
        $admin = User::factory()->admin()->for($this->teacher->school)->create();
        $first = $this->classWith('5a', ['Tom Becker']);
        $empty = $this->classWith('6b', []);
        User::factory()->student()->create(['name' => 'Andere Schule']);

        $this->withToken($this->token($admin))->getJson(route('api.roster.index'))
            ->assertOk()
            ->assertExactJson(['data' => [
                ['class' => ['id' => $first->id, 'name' => '5a'], 'students' => ['Tom Becker']],
                ['class' => ['id' => $empty->id, 'name' => '6b'], 'students' => []],
            ]]);
    }

    public function test_only_the_class_and_the_display_names_leave_the_platform(): void
    {
        $class = SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => '7b']);
        User::factory()->student($class)->create(['name' => 'Lena Wagner', 'username' => 'lena.w', 'email' => 'lena@example.test']);

        $response = $this->withToken($this->token())->getJson(route('api.roster.index'))->assertOk();

        $this->assertSame(['data' => [['class' => ['id' => $class->id, 'name' => '7b'], 'students' => ['Lena Wagner']]]], $response->json());
        $response->assertDontSee('lena.w')->assertDontSee('lena@example.test')->assertDontSee($this->teacher->name);
    }

    public function test_only_students_count_even_if_another_account_points_at_the_class(): void
    {
        $class = SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => '7b']);
        User::factory()->student($class)->create(['name' => 'Lena Wagner']);
        User::factory()->teacher()->for($this->teacher->school)->create(['name' => 'Kollegin Mit Klasse', 'school_class_id' => $class->id]);

        $this->withToken($this->token())->getJson(route('api.roster.index'))
            ->assertOk()
            ->assertExactJson(['data' => [['class' => ['id' => $class->id, 'name' => '7b'], 'students' => ['Lena Wagner']]]]);
    }

    public function test_accounts_without_a_school_get_no_school_less_classes(): void
    {
        $schoolLess = SchoolClass::factory()->create(['school_id' => null]);
        User::factory()->student($schoolLess)->create(['name' => 'Ohne Schule']);
        $teacher = User::factory()->teacher()->create(['school_id' => null]);
        $admin = User::factory()->admin()->create(['school_id' => null]);

        foreach ([$teacher, $admin] as $user) {
            $this->withToken($this->token($user))->getJson(route('api.roster.index'))
                ->assertOk()
                ->assertExactJson(['data' => []]);
        }
    }

    public function test_a_token_without_roster_read_is_refused(): void
    {
        SchoolClass::factory()->for($this->teacher, 'teacher')->create();
        $token = $this->token(abilities: array_values(array_diff(TokenController::ABILITIES, ['roster:read'])));

        $this->withToken($token)->getJson(route('api.roster.index'))->assertForbidden();
    }

    public function test_a_student_token_gets_nothing(): void
    {
        $student = User::factory()->student()->create();

        $this->withToken($this->token($student))->getJson(route('api.roster.index'))->assertForbidden();
    }
}
