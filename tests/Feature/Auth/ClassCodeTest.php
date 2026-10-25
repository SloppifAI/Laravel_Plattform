<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\Auth\Register;
use App\Filament\Resources\Lessons\LessonResource;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Filament\Resources\SchoolClasses\Pages\CreateSchoolClass;
use App\Filament\Resources\SchoolClasses\Pages\EditSchoolClass;
use App\Filament\Resources\SchoolClasses\Pages\ListSchoolClasses;
use App\Filament\Resources\SchoolClasses\SchoolClassResource;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClassCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_see_the_class_code_in_the_table_and_on_the_edit_page(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $schoolClass = SchoolClass::factory()->for($admin->school)->create(['join_code' => 'ABCDEF', 'teacher_id' => null]);

        Livewire::test(ListSchoolClasses::class)
            ->assertTableColumnStateSet('join_code', 'ABCDEF', $schoolClass);

        Livewire::test(EditSchoolClass::class, ['record' => $schoolClass->getRouteKey()])
            ->assertFormSet(['join_code' => 'ABCDEF'])
            ->assertFormFieldIsDisabled('join_code');
    }

    public function test_admin_regenerates_the_code_and_the_old_one_stops_working(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $schoolClass = SchoolClass::factory()->for($admin->school)->create(['join_code' => 'ABCDEF', 'teacher_id' => null]);

        $page = Livewire::test(EditSchoolClass::class, ['record' => $schoolClass->getRouteKey()])
            ->callAction('regenerateJoinCode')
            ->assertNotified();

        $newCode = $schoolClass->refresh()->join_code;

        $this->assertNotSame('ABCDEF', $newCode);
        $page->assertFormSet(['join_code' => $newCode]);

        auth()->logout();

        Livewire::test(Register::class)
            ->fillForm([
                'name' => 'Maxi Muster',
                'username' => 'maxi.muster',
                'classCode' => 'ABCDEF',
                'password' => 'geheim-2026',
                'passwordConfirmation' => 'geheim-2026',
            ])
            ->call('register')
            ->assertHasFormErrors(['classCode']);
    }

    public function test_teachers_see_only_the_codes_of_the_classes_they_lead_with_the_registration_url(): void
    {
        $teacher = User::factory()->teacher()->create();
        SchoolClass::factory()->for($teacher, 'teacher')->create(['name' => 'Klasse 7b', 'join_code' => 'ABCDEF']);
        SchoolClass::factory()->create(['name' => 'Klasse 8c', 'join_code' => 'GHJKMN']);
        $this->actingAs($teacher);

        Livewire::test(ListLessons::class)
            ->mountAction('classCodes')
            ->assertMountedActionModalSee(['ABCDEF', 'Klasse 7b', route('filament.lernplattform.auth.register')])
            ->assertMountedActionModalDontSee(['GHJKMN', 'Klasse 8c']);
    }

    public function test_teachers_do_not_see_the_codes_of_colleagues_or_of_other_schools(): void
    {
        $teacher = User::factory()->teacher()->create();
        $colleague = User::factory()->teacher()->for($teacher->school)->create();
        SchoolClass::factory()->for($teacher, 'teacher')->create(['name' => 'Klasse 7b', 'join_code' => 'ABCDEF']);
        SchoolClass::factory()->for($colleague, 'teacher')->create(['name' => 'Klasse 8c', 'join_code' => 'GHJKMN']);
        SchoolClass::factory()->create(['name' => 'Klasse 9d', 'join_code' => 'PQRSTU']);
        $this->actingAs($teacher);

        Livewire::test(ListLessons::class)
            ->mountAction('classCodes')
            ->assertMountedActionModalSee(['ABCDEF', 'Klasse 7b'])
            ->assertMountedActionModalDontSee(['GHJKMN', 'Klasse 8c', 'PQRSTU', 'Klasse 9d']);
    }

    public function test_operators_have_no_access_to_class_codes(): void
    {
        SchoolClass::factory()->create(['join_code' => 'ABCDEF']);
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(ListLessons::class)->assertForbidden();
        Livewire::test(ListSchoolClasses::class)->assertForbidden();
    }

    public function test_a_teacher_without_a_class_is_told_whom_to_ask(): void
    {
        $this->actingAs(User::factory()->teacher()->create());
        SchoolClass::factory()->create(['join_code' => 'GHJKMN']);

        Livewire::test(ListLessons::class)
            ->mountAction('classCodes')
            ->assertMountedActionModalSee('You have not been assigned to a class yet')
            ->assertMountedActionModalDontSee('GHJKMN');
    }

    public function test_admins_see_the_codes_of_the_classes_of_their_school_only(): void
    {
        [$ownSchool, $otherSchool] = School::factory()->count(2)->create();
        SchoolClass::factory()->for($ownSchool)->create(['name' => 'Klasse 7a', 'join_code' => 'ABCDEF']);
        SchoolClass::factory()->for($ownSchool)->create(['name' => 'Klasse 7b', 'join_code' => 'GHJKMN']);
        SchoolClass::factory()->for($otherSchool)->create(['name' => 'Klasse 8c', 'join_code' => 'PQRSTU']);
        $this->actingAs(User::factory()->admin()->create(['school_id' => $ownSchool->id]));

        Livewire::test(ListLessons::class)
            ->mountAction('classCodes')
            ->assertMountedActionModalSee(['ABCDEF', 'GHJKMN'])
            ->assertMountedActionModalDontSee(['PQRSTU', 'Klasse 8c']);
    }

    public function test_admins_see_and_open_only_the_classes_of_their_school_in_the_administration(): void
    {
        [$ownSchool, $otherSchool] = School::factory()->count(2)->create();
        $ownClass = SchoolClass::factory()->for($ownSchool)->create(['join_code' => 'ABCDEF']);
        $otherClass = SchoolClass::factory()->for($otherSchool)->create(['join_code' => 'PQRSTU']);
        $this->actingAs(User::factory()->admin()->create(['school_id' => $ownSchool->id]));

        Livewire::test(ListSchoolClasses::class)
            ->assertCanSeeTableRecords([$ownClass])
            ->assertCanNotSeeTableRecords([$otherClass])
            ->assertDontSee('PQRSTU');

        $this->get(SchoolClassResource::getUrl('edit', ['record' => $ownClass]))->assertOk();
        $this->get(SchoolClassResource::getUrl('edit', ['record' => $otherClass]))->assertNotFound();
    }

    public function test_a_new_class_belongs_to_the_school_of_the_admin_who_creates_it(): void
    {
        $school = School::factory()->create();
        $this->actingAs(User::factory()->admin()->create(['school_id' => $school->id]));

        Livewire::test(CreateSchoolClass::class)
            ->fillForm(['name' => '7c'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($school->id, SchoolClass::where('name', '7c')->sole()->school_id);
    }

    public function test_students_cannot_open_the_lessons_list_or_its_class_codes(): void
    {
        $student = User::factory()->student()->create();
        $this->actingAs($student);

        $this->get(LessonResource::getUrl('index'))->assertForbidden();

        Livewire::test(ListLessons::class)->assertForbidden();
    }

    public function test_students_cannot_open_the_class_administration(): void
    {
        $this->actingAs(User::factory()->student()->create());

        Livewire::test(ListSchoolClasses::class)->assertForbidden();
    }
}
