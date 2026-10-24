<?php

namespace Tests\Feature;

use App\Filament\Pages\MyHomework;
use App\Filament\Pages\RolesAndPermissions;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Filament\Resources\SchoolClasses\Pages\ListSchoolClasses;
use App\Filament\Resources\Schools\Pages\ListSchools;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Lesson;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tables are stacked on mobile by default (AppServiceProvider), so no table scrolls sideways on a phone.
 * The class is only rendered together with rows; theme.css turns it into cards below md.
 */
class MobileTablesTest extends TestCase
{
    use RefreshDatabase;

    private const string STACKED_CLASS = 'fi-ta-table-stacked-on-mobile';

    public function test_admin_tables_are_stacked_on_mobile(): void
    {
        $school = School::factory()->create();
        SchoolClass::factory()->for($school)->create();
        $this->actingAs(User::factory()->admin()->for($school)->create());

        Livewire::test(ListUsers::class)->assertSeeHtml(self::STACKED_CLASS);
        Livewire::test(ListSchoolClasses::class)->assertSeeHtml(self::STACKED_CLASS);
        Livewire::test(RolesAndPermissions::class)->assertSeeHtml(self::STACKED_CLASS);
    }

    public function test_teacher_tables_are_stacked_on_mobile(): void
    {
        $teacher = User::factory()->teacher()->create();
        Lesson::factory()->for($teacher, 'teacher')->create();
        $this->actingAs($teacher);

        Livewire::test(ListLessons::class)->assertSeeHtml(self::STACKED_CLASS);
    }

    public function test_student_tables_are_stacked_on_mobile(): void
    {
        $student = User::factory()->student()->create();
        Lesson::factory()->publishedFor([$student])->create();
        $this->actingAs($student);

        Livewire::test(MyHomework::class)->assertSeeHtml(self::STACKED_CLASS);
    }

    public function test_operator_tables_are_stacked_on_mobile(): void
    {
        School::factory()->create();
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(ListSchools::class)->assertSeeHtml(self::STACKED_CLASS);
    }
}
