<?php

namespace Tests\Feature\Lessons;

use App\Filament\Resources\Lessons\LessonResource;
use App\Filament\Resources\Lessons\Pages\CreateLesson;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LessonResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_teacher_creates_a_lesson_that_belongs_to_them(): void
    {
        $teacher = User::factory()->teacher()->create();
        $schoolClass = SchoolClass::factory()->for($teacher, 'teacher')->create();
        $this->actingAs($teacher);

        Livewire::test(CreateLesson::class)
            ->fillForm($this->lessonFormData($schoolClass))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Lesson::class, [
            'topic' => 'Fotosynthese',
            'teacher_id' => $teacher->id,
            'school_class_id' => $schoolClass->id,
        ]);
    }

    public function test_teachers_only_see_their_own_lessons(): void
    {
        $teacher = User::factory()->teacher()->create();
        $ownLesson = Lesson::factory()->for($teacher, 'teacher')->create();
        $foreignLesson = Lesson::factory()->create();
        $this->actingAs($teacher);

        Livewire::test(ListLessons::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$ownLesson])
            ->assertCanNotSeeTableRecords([$foreignLesson]);
    }

    public function test_teachers_cannot_open_another_teachers_lesson(): void
    {
        $this->actingAs(User::factory()->teacher()->create())
            ->get(LessonResource::getUrl('edit', ['record' => Lesson::factory()->create()]))
            ->assertNotFound();
    }

    public function test_admins_see_and_open_every_lesson_of_their_school(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $admin = User::factory()->admin()->for($schoolClass->school)->create();
        $lessons = Lesson::factory()->for($schoolClass)->count(2)->create();
        $this->actingAs($admin);

        Livewire::test(ListLessons::class)->assertCanSeeTableRecords($lessons);
        $this->get(LessonResource::getUrl('edit', ['record' => $lessons->first()]))->assertOk();
    }

    public function test_admins_do_not_see_or_open_lessons_of_another_school(): void
    {
        $ownClass = SchoolClass::factory()->create();
        $admin = User::factory()->admin()->for($ownClass->school)->create();
        $ownLesson = Lesson::factory()->for($ownClass)->create();
        $foreignLesson = Lesson::factory()->create();
        $this->actingAs($admin);

        Livewire::test(ListLessons::class)
            ->assertCanSeeTableRecords([$ownLesson])
            ->assertCanNotSeeTableRecords([$foreignLesson]);
        $this->get(LessonResource::getUrl('edit', ['record' => $foreignLesson]))->assertNotFound();
    }

    public function test_admins_cannot_open_the_submissions_of_another_school(): void
    {
        $admin = User::factory()->admin()->create();
        $foreignLesson = Lesson::factory()->publishedFor()->create();
        $submission = Submission::factory()->for($foreignLesson)->for(User::factory()->student($foreignLesson->schoolClass), 'student')->create();
        $this->actingAs($admin);

        $this->get(LessonResource::getUrl('edit', ['record' => $foreignLesson]))->assertNotFound();
        $this->assertFalse($admin->can('view', $submission));
    }

    public function test_the_class_filter_only_offers_classes_of_the_own_school(): void
    {
        $teacher = User::factory()->teacher()->create();
        SchoolClass::factory()->for($teacher, 'teacher')->create(['name' => '5a eigene']);
        SchoolClass::factory()->create(['name' => '9z fremde']);
        $this->actingAs($teacher);

        Livewire::test(ListLessons::class)
            ->assertSee('5a eigene')
            ->assertDontSee('9z fremde');
    }

    public function test_a_teacher_cannot_create_a_lesson_for_a_class_of_another_school(): void
    {
        $teacher = User::factory()->teacher()->create();
        $foreignClass = SchoolClass::factory()->create();
        $this->actingAs($teacher);

        Livewire::test(CreateLesson::class)
            ->fillForm($this->lessonFormData($foreignClass))
            ->call('create')
            ->assertHasFormErrors(['school_class_id']);

        $this->assertDatabaseCount(Lesson::class, 0);
    }

    public function test_an_admin_cannot_create_a_lesson_for_a_class_of_another_school(): void
    {
        $admin = User::factory()->admin()->create();
        $foreignClass = SchoolClass::factory()->create();
        $this->actingAs($admin);

        Livewire::test(CreateLesson::class)
            ->fillForm($this->lessonFormData($foreignClass))
            ->call('create')
            ->assertHasFormErrors(['school_class_id']);

        $this->assertDatabaseCount(Lesson::class, 0);
    }

    public function test_an_admin_creates_a_lesson_for_a_class_of_the_own_school(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $admin = User::factory()->admin()->for($schoolClass->school)->create();
        $this->actingAs($admin);

        Livewire::test(CreateLesson::class)
            ->fillForm($this->lessonFormData($schoolClass))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Lesson::class, ['topic' => 'Fotosynthese', 'teacher_id' => $admin->id, 'school_class_id' => $schoolClass->id]);
    }

    public function test_admins_only_see_the_class_codes_of_their_school(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $admin = User::factory()->admin()->for($schoolClass->school)->create();
        $foreignClass = SchoolClass::factory()->create();
        $this->actingAs($admin);

        Livewire::test(ListLessons::class)
            ->mountAction('classCodes')
            ->assertMountedActionModalSee([$schoolClass->name, $schoolClass->join_code])
            ->assertMountedActionModalDontSee([$foreignClass->name, $foreignClass->join_code]);
    }

    public function test_teachers_only_see_the_codes_of_the_classes_they_lead(): void
    {
        $teacher = User::factory()->teacher()->create();
        $ledClass = SchoolClass::factory()->for($teacher, 'teacher')->create();
        $otherClassOfSchool = SchoolClass::factory()->create(['school_id' => $teacher->school_id]);
        $this->actingAs($teacher);

        Livewire::test(ListLessons::class)
            ->mountAction('classCodes')
            ->assertMountedActionModalSee([$ledClass->name, $ledClass->join_code])
            ->assertMountedActionModalDontSee([$otherClassOfSchool->name, $otherClassOfSchool->join_code]);
    }

    public function test_students_cannot_use_the_lesson_resource(): void
    {
        $this->actingAs(User::factory()->student()->create())
            ->get(LessonResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_operators_cannot_use_the_lesson_resource(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->get(LessonResource::getUrl('index'))
            ->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function lessonFormData(SchoolClass $schoolClass): array
    {
        return [
            'school_class_id' => $schoolClass->id,
            'subject' => 'Biologie',
            'topic' => 'Fotosynthese',
            'held_on' => '2026-10-20',
            'notes' => 'Tafelbild: Licht + CO2 + Wasser ergibt Glucose + Sauerstoff.',
        ];
    }
}
