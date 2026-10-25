<?php

namespace Tests\Feature\Lessons;

use App\Filament\Pages\Today;
use App\Filament\Resources\Lessons\Pages\CreateLesson;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Filament\Widgets\TeacherLessonsWidget;
use App\Models\ClassSubstitution;
use App\Models\SchoolClass;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A teacher the administration has not assigned to a class yet has no class to hold a lesson in: where they would
 * otherwise be invited to add one, they are told to ask the school administration.
 */
class TeacherWithoutClassTest extends TestCase
{
    use RefreshDatabase;

    private const HINT = 'The school administration can assign you to a class.';

    public function test_a_teacher_without_a_class_is_told_to_ask_the_administration_instead_of_being_invited_to_add_a_lesson(): void
    {
        $this->actingAs(User::factory()->teacher()->create());

        Livewire::test(Today::class)
            ->assertSee(['No class assigned', self::HINT])
            ->assertDontSee(['No lesson in the last school days', 'Add lesson']);

        Livewire::test(ListLessons::class)
            ->assertSee(['No class assigned', self::HINT])
            ->assertActionHidden('create');

        Livewire::test(CreateLesson::class)->assertSee(self::HINT);

        Livewire::test(TeacherLessonsWidget::class)
            ->assertSee(self::HINT)
            ->assertDontSee(['New lesson', 'No lesson added yet']);
    }

    /**
     * @return array<string, array{0: Closure(User): mixed}>
     */
    public static function waysToTeachAClass(): array
    {
        return [
            'leads a class' => [fn (User $teacher) => SchoolClass::factory()->for($teacher, 'teacher')->create()],
            'is assigned to a class' => [fn (User $teacher) => SchoolClass::factory()->for($teacher->school)->create()->teachers()->attach($teacher)],
            'substitutes in a class' => [fn (User $teacher) => ClassSubstitution::factory()->for(SchoolClass::factory()->for($teacher->school))->create(['substitute_id' => $teacher->id])],
        ];
    }

    #[DataProvider('waysToTeachAClass')]
    public function test_a_teacher_with_a_class_is_still_invited_to_add_a_lesson(Closure $giveClass): void
    {
        $teacher = User::factory()->teacher()->create();
        $giveClass($teacher);
        $this->actingAs($teacher);

        Livewire::test(Today::class)
            ->assertSee(['No lesson in the last school days', 'Add lesson'])
            ->assertDontSee(self::HINT);

        Livewire::test(ListLessons::class)->assertActionVisible('create')->assertDontSee(self::HINT);

        Livewire::test(CreateLesson::class)->assertDontSee(self::HINT);

        Livewire::test(TeacherLessonsWidget::class)->assertSee('New lesson')->assertDontSee(self::HINT);
    }

    public function test_the_administration_of_a_school_without_classes_keeps_the_button(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ListLessons::class)->assertActionVisible('create')->assertDontSee(self::HINT);
    }
}
