<?php

namespace Tests\Feature\Lessons;

use App\Filament\Resources\Lessons\LessonResource;
use App\Filament\Resources\Lessons\Pages\CreateLesson;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Models\Lesson;
use App\Models\SchoolClass;
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
        $schoolClass = SchoolClass::factory()->create();
        $this->actingAs($teacher);

        Livewire::test(CreateLesson::class)
            ->fillForm([
                'school_class_id' => $schoolClass->id,
                'subject' => 'Biologie',
                'topic' => 'Fotosynthese',
                'held_on' => '2026-10-20',
                'notes' => 'Tafelbild: Licht + CO2 + Wasser ergibt Glucose + Sauerstoff.',
            ])
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

    public function test_admins_see_and_open_every_lesson(): void
    {
        $lessons = Lesson::factory()->count(2)->create();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ListLessons::class)->assertCanSeeTableRecords($lessons);
        $this->get(LessonResource::getUrl('edit', ['record' => $lessons->first()]))->assertOk();
    }

    public function test_students_cannot_use_the_lesson_resource(): void
    {
        $this->actingAs(User::factory()->student()->create())
            ->get(LessonResource::getUrl('index'))
            ->assertForbidden();
    }
}
