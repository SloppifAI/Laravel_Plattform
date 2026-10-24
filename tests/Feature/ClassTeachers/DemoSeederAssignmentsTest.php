<?php

namespace Tests\Feature\ClassTeachers;

use App\Models\SchoolClass;
use App\Models\User;
use App\Support\LearningInsights;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoSeederAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_teachers_teach_the_classes_the_demo_needs(): void
    {
        $this->seed(DatabaseSeeder::class);

        $anna = $this->user('lehrer');
        $weber = $this->user('weber');
        $gymTeacher = $this->user('gym-lehrer');

        $this->assertSame(['7b'], $this->taughtClassNames($anna));
        $this->assertSame(['7a', '7b'], $this->taughtClassNames($weber));
        $this->assertSame(['8c'], $this->taughtClassNames($gymTeacher));
        $this->assertSame($anna->id, SchoolClass::query()->where('name', '7b')->value('teacher_id'));
        $this->assertSame($weber->id, SchoolClass::query()->where('name', '7a')->value('teacher_id'));
    }

    public function test_the_demo_teachers_see_the_students_of_their_classes(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(LearningInsights::canView($this->user('lehrer'), $this->user('schueler')));
        $this->assertFalse(LearningInsights::canView($this->user('lehrer'), $this->user('paul.richter')));
        $this->assertTrue(LearningInsights::canView($this->user('weber'), $this->user('paul.richter')));
        $this->assertTrue(LearningInsights::canView($this->user('weber'), $this->user('schueler')));
        $this->assertFalse(LearningInsights::canView($this->user('gym-lehrer'), $this->user('schueler')));
    }

    public function test_seeding_again_adds_no_duplicates_and_keeps_assignments_of_the_administration(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(1, DB::table('school_class_teacher')->count());
        $class7a = SchoolClass::query()->where('name', '7a')->sole();
        $class7a->teachers()->attach($this->user('lehrer'));

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(2, DB::table('school_class_teacher')->count());
        $this->assertTrue($class7a->isTaughtBy($this->user('lehrer')));
    }

    /**
     * @return list<string>
     */
    private function taughtClassNames(User $teacher): array
    {
        return SchoolClass::query()->taughtBy($teacher)->orderBy('name')->pluck('name')->all();
    }

    private function user(string $username): User
    {
        return User::query()->where('username', $username)->sole();
    }
}
