<?php

namespace Tests\Feature\ClassTeachers;

use App\Filament\Resources\Lessons\Pages\CreateLesson;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A lesson reaches the class's students and learning data, so teachers may only place lessons in classes they teach:
 * when creating one, when moving one to another class and when publishing one (see LessonPolicy).
 */
class LessonPlacementTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $teacher;

    private User $colleague;

    private SchoolClass $ownClass;

    private SchoolClass $colleagueClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->teacher = User::factory()->teacher()->for($this->school)->create();
        $this->colleague = User::factory()->teacher()->for($this->school)->create();
        $this->ownClass = SchoolClass::factory()->for($this->school)->create(['teacher_id' => $this->teacher->id]);
        $this->colleagueClass = SchoolClass::factory()->for($this->school)->create(['teacher_id' => $this->colleague->id]);
    }

    public function test_a_teacher_cannot_create_a_lesson_in_a_colleagues_class(): void
    {
        $this->actingAs($this->teacher);

        Livewire::test(CreateLesson::class)
            ->fillForm($this->lessonFormData($this->colleagueClass))
            ->call('create')
            ->assertHasFormErrors(['school_class_id']);

        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_a_teacher_creates_lessons_in_classes_they_lead_or_are_assigned_to(): void
    {
        $this->colleagueClass->teachers()->attach($this->teacher);
        $this->actingAs($this->teacher);

        foreach ([$this->ownClass, $this->colleagueClass] as $schoolClass) {
            Livewire::test(CreateLesson::class)
                ->fillForm($this->lessonFormData($schoolClass))
                ->call('create')
                ->assertHasNoFormErrors();

            $this->assertDatabaseHas(Lesson::class, ['school_class_id' => $schoolClass->id, 'teacher_id' => $this->teacher->id]);
        }
    }

    public function test_a_teacher_cannot_move_a_lesson_into_a_colleagues_class(): void
    {
        $lesson = Lesson::factory()->for($this->ownClass)->for($this->teacher, 'teacher')->create();
        $this->actingAs($this->teacher);

        Livewire::test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->fillForm(['school_class_id' => $this->colleagueClass->id])
            ->call('save')
            ->assertHasFormErrors(['school_class_id']);

        $this->assertTrue($lesson->refresh()->schoolClass->is($this->ownClass));
    }

    public function test_a_lesson_can_still_be_saved_after_the_teacher_stopped_teaching_its_class(): void
    {
        $lesson = Lesson::factory()->for($this->colleagueClass)->for($this->teacher, 'teacher')->create();
        $this->actingAs($this->teacher);

        Livewire::test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->fillForm(['topic' => 'Neues Thema'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Neues Thema', $lesson->refresh()->topic);
        $this->assertTrue($lesson->schoolClass->is($this->colleagueClass));
    }

    public function test_the_administration_places_lessons_in_every_class_of_its_school(): void
    {
        $this->actingAs(User::factory()->admin()->for($this->school)->create());

        Livewire::test(CreateLesson::class)
            ->fillForm($this->lessonFormData($this->colleagueClass))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Lesson::class, ['school_class_id' => $this->colleagueClass->id]);
    }

    public function test_the_administration_cannot_move_a_lesson_into_a_class_of_another_school(): void
    {
        $lesson = Lesson::factory()->for($this->ownClass)->for($this->teacher, 'teacher')->create();
        $foreignClass = SchoolClass::factory()->create();
        $this->actingAs(User::factory()->admin()->for($this->school)->create());

        Livewire::test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->fillForm(['school_class_id' => $foreignClass->id])
            ->call('save')
            ->assertHasFormErrors(['school_class_id']);

        $this->assertTrue($lesson->refresh()->schoolClass->is($this->ownClass));
    }

    public function test_the_policy_check_stands_behind_the_class_select_when_the_form_state_is_forged(): void
    {
        $this->actingAs($this->teacher);
        $lesson = Lesson::factory()->for($this->ownClass)->for($this->teacher, 'teacher')->create();
        $forged = ['school_class_id' => $this->colleagueClass->id];

        $create = Livewire::test(CreateLesson::class)->instance();
        $edit = Livewire::test(EditLesson::class, ['record' => $lesson->getRouteKey()])->instance();

        $this->assertForbidden(fn () => (fn (array $data) => $this->mutateFormDataBeforeCreate($data))->call($create, $forged));
        $this->assertForbidden(fn () => (fn (array $data) => $this->mutateFormDataBeforeSave($data))->call($edit, $forged));

        // Saving without a class change needs no check, also for a class the teacher no longer teaches.
        $unchanged = ['school_class_id' => $this->ownClass->id];
        $this->assertSame($unchanged, (fn (array $data) => $this->mutateFormDataBeforeSave($data))->call($edit, $unchanged));
    }

    public function test_a_teacher_who_lost_a_class_cannot_publish_a_draft_there_to_its_current_students(): void
    {
        $this->colleagueClass->teachers()->attach($this->teacher);
        $draft = Lesson::factory()->for($this->colleagueClass)->for($this->teacher, 'teacher')->has(Question::factory())->create();
        $this->colleagueClass->teachers()->detach($this->teacher);
        $newStudent = User::factory()->student($this->colleagueClass)->create();
        $this->actingAs($this->teacher);

        Livewire::test(EditLesson::class, ['record' => $draft->getRouteKey()])
            ->assertActionHidden('delegate');

        $this->assertFalse($draft->refresh()->isPublished());
        $this->assertFalse($draft->students()->whereKey($newStudent->id)->exists());
    }

    public function test_a_teacher_publishes_a_draft_in_a_class_they_teach(): void
    {
        // The notification is German; the suite runs in English wherever a text is translated.
        config(['lernplattform.locale.default' => 'de']);
        app()->setLocale('de');

        $student = User::factory()->student($this->ownClass)->create();
        $draft = Lesson::factory()->for($this->ownClass)->for($this->teacher, 'teacher')->has(Question::factory())->create();
        $this->actingAs($this->teacher);

        Livewire::test(EditLesson::class, ['record' => $draft->getRouteKey()])
            ->callAction('delegate', data: ['due_on' => today()->addDays(3)->toDateString(), 'audience' => 'class'])
            ->assertNotified('Hausaufgabe freigegeben');

        $this->assertTrue($draft->refresh()->isPublished());
        $this->assertTrue($draft->students()->whereKey($student->id)->exists());
    }

    private function assertForbidden(callable $callback): void
    {
        try {
            $callback();
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('The class of a colleague was let through.');
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
