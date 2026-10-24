<?php

namespace Tests\Feature\ClassTeachers;

use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClassTeacherBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_assigns_exactly_the_teachers_who_saw_a_class_through_a_published_lesson(): void
    {
        $leader = User::factory()->teacher()->create();
        $schoolClass = SchoolClass::factory()->for($leader, 'teacher')->create();
        $school = $schoolClass->school;
        $viaPublishedLesson = User::factory()->teacher()->for($school)->create();
        $viaDraftOnly = User::factory()->teacher()->for($school)->create();
        $foreignTeacher = User::factory()->teacher()->create();
        $admin = User::factory()->admin()->for($school)->create();
        $classWithoutLeader = SchoolClass::factory()->for($school)->create(['teacher_id' => null]);

        Lesson::factory()->for($schoolClass)->for($leader, 'teacher')->publishedFor()->create();
        Lesson::factory()->for($schoolClass)->for($viaPublishedLesson, 'teacher')->publishedFor()->count(2)->create();
        Lesson::factory()->for($schoolClass)->for($viaDraftOnly, 'teacher')->create();
        Lesson::factory()->for($schoolClass)->for($foreignTeacher, 'teacher')->publishedFor()->create();
        Lesson::factory()->for($schoolClass)->for($admin, 'teacher')->publishedFor()->create();
        Lesson::factory()->for($classWithoutLeader)->for($viaPublishedLesson, 'teacher')->publishedFor()->create();

        Schema::drop('school_class_teacher');
        (require database_path('migrations/2026_10_24_165715_create_school_class_teacher_table.php'))->up();

        $this->assertEqualsCanonicalizing(
            [[$schoolClass->id, $viaPublishedLesson->id], [$classWithoutLeader->id, $viaPublishedLesson->id]],
            DB::table('school_class_teacher')->get()->map(fn (object $row): array => [$row->school_class_id, $row->user_id])->all(),
        );
    }
}
