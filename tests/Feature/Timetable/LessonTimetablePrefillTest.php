<?php

namespace Tests\Feature\Timetable;

use App\Filament\Resources\Lessons\Pages\CreateLesson;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\TimetableEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Stunde erfassen" takes class and subject from the teacher's timetable: the slot that fits the time is preselected,
 * the other slots of the day can be picked, and class and subject stay free to change.
 */
class LessonTimetablePrefillTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private SchoolClass $ownClass;

    private SchoolClass $otherClass;

    private TimetableEntry $firstPeriod;

    private TimetableEntry $thirdPeriod;

    protected function setUp(): void
    {
        parent::setUp();

        // A Monday; period 3 runs from 09:50 to 10:35 (config lernplattform.timetable.periods), in school time.
        $this->travelToSchoolTime('2026-10-26 10:00');

        $this->teacher = User::factory()->teacher()->create();
        $this->ownClass = SchoolClass::factory()->create(['school_id' => $this->teacher->school_id, 'teacher_id' => $this->teacher->id, 'name' => '7b']);
        $this->otherClass = SchoolClass::factory()->create(['school_id' => $this->teacher->school_id, 'name' => '7a']);
        $this->otherClass->teachers()->attach($this->teacher);
        $this->firstPeriod = TimetableEntry::factory()->at(1, 1)->create(['school_class_id' => $this->otherClass->id, 'teacher_id' => $this->teacher->id, 'subject' => 'Deutsch']);
        $this->thirdPeriod = TimetableEntry::factory()->at(1, 3)->create(['school_class_id' => $this->ownClass->id, 'teacher_id' => $this->teacher->id, 'subject' => 'Mathematik']);

        $this->actingAs($this->teacher);
    }

    public function test_the_slot_running_now_is_preselected(): void
    {
        Livewire::test(CreateLesson::class)
            ->assertFormFieldVisible('timetable_entry_id')
            ->assertFormSet([
                'timetable_entry_id' => $this->thirdPeriod->id,
                'school_class_id' => $this->ownClass->id,
                'subject' => 'Mathematik',
            ]);
    }

    public function test_the_bell_times_are_school_time_not_utc(): void
    {
        // 10:00 in Berlin is 09:00 in UTC, where it would still be period 2 (Deutsch in 7a).
        $this->assertSame('2026-10-26 09:00', now()->utc()->format('Y-m-d H:i'));

        Livewire::test(CreateLesson::class)
            ->assertFormSet(['timetable_entry_id' => $this->thirdPeriod->id, 'school_class_id' => $this->ownClass->id]);

        // In summer time the offset is two hours: 10:00 in Berlin is 08:00 in UTC, before the first bell there.
        $this->travelToSchoolTime('2026-10-19 10:00');
        $this->assertSame('2026-10-19 08:00', now()->utc()->format('Y-m-d H:i'));

        Livewire::test(CreateLesson::class)
            ->assertFormSet(['timetable_entry_id' => $this->thirdPeriod->id, 'subject' => 'Mathematik']);
    }

    public function test_just_after_midnight_at_the_school_the_slots_of_the_day_before_are_not_offered(): void
    {
        // Tuesday 00:30 in Berlin is still Monday 23:30 in UTC, where the last period of Monday would be preselected.
        $this->travelToSchoolTime('2026-10-27 00:30');

        Livewire::test(CreateLesson::class)
            ->assertFormSet(['timetable_entry_id' => null, 'school_class_id' => null, 'subject' => null]);
    }

    public function test_after_the_lesson_the_last_slot_that_began_stays_preselected(): void
    {
        $this->travelToSchoolTime('2026-10-26 13:30');

        Livewire::test(CreateLesson::class)
            ->assertFormSet(['timetable_entry_id' => $this->thirdPeriod->id, 'subject' => 'Mathematik']);
    }

    public function test_before_the_first_period_nothing_is_preselected(): void
    {
        $this->travelToSchoolTime('2026-10-26 07:30');

        Livewire::test(CreateLesson::class)
            ->assertFormSet(['timetable_entry_id' => null, 'school_class_id' => null, 'subject' => null]);
    }

    public function test_another_slot_of_the_day_fills_in_its_class_and_subject(): void
    {
        Livewire::test(CreateLesson::class)
            ->fillForm(['timetable_entry_id' => $this->firstPeriod->id])
            ->assertFormSet(['school_class_id' => $this->otherClass->id, 'subject' => 'Deutsch']);
    }

    public function test_class_and_subject_stay_free_to_change_and_the_slot_is_not_saved(): void
    {
        Livewire::test(CreateLesson::class)
            ->fillForm([
                'school_class_id' => $this->otherClass->id,
                'subject' => 'Kunst',
                'topic' => 'Farbkreis',
                'notes' => 'Primär- und Sekundärfarben.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(Lesson::class, ['school_class_id' => $this->otherClass->id, 'subject' => 'Kunst', 'teacher_id' => $this->teacher->id]);
    }

    public function test_another_date_offers_the_slots_of_that_weekday(): void
    {
        $tuesday = TimetableEntry::factory()->at(2, 4)->create(['school_class_id' => $this->ownClass->id, 'teacher_id' => $this->teacher->id, 'subject' => 'Biologie']);

        Livewire::test(CreateLesson::class)
            ->fillForm(['held_on' => '2026-10-27'])
            ->assertFormSet(['timetable_entry_id' => null])
            ->fillForm(['timetable_entry_id' => $tuesday->id])
            ->assertFormSet(['subject' => 'Biologie'])
            ->fillForm(['timetable_entry_id' => $this->thirdPeriod->id])
            ->call('create')
            ->assertHasFormErrors(['timetable_entry_id']);
    }

    public function test_slots_in_classes_the_teacher_does_not_teach_are_not_offered(): void
    {
        $this->thirdPeriod->update(['school_class_id' => SchoolClass::factory()->create(['school_id' => $this->teacher->school_id])->id]);

        // The earlier slot in a taught class is the last offered one that began.
        Livewire::test(CreateLesson::class)
            ->assertFormSet(['timetable_entry_id' => $this->firstPeriod->id, 'school_class_id' => $this->otherClass->id])
            ->fillForm(['timetable_entry_id' => $this->thirdPeriod->id])
            ->assertFormSet(['school_class_id' => $this->otherClass->id]);
    }

    public function test_without_slots_on_the_day_the_field_is_hidden(): void
    {
        $this->travelToSchoolTime('2026-10-28 10:00');

        Livewire::test(CreateLesson::class)
            ->assertFormFieldHidden('timetable_entry_id');
    }

    public function test_editing_a_lesson_does_not_offer_the_timetable(): void
    {
        $lesson = Lesson::factory()->for($this->ownClass)->for($this->teacher, 'teacher')->create(['held_on' => today()]);

        Livewire::test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->assertFormFieldHidden('timetable_entry_id');
    }

    public function test_an_administration_gets_no_timetable_suggestion(): void
    {
        $this->actingAs(User::factory()->admin()->for($this->teacher->school)->create());

        Livewire::test(CreateLesson::class)
            ->assertFormFieldHidden('timetable_entry_id')
            ->assertFormSet(['school_class_id' => null]);
    }

    /**
     * Travels to a wall-clock time at the school (Europe/Berlin), which is not the application's UTC.
     */
    private function travelToSchoolTime(string $time): void
    {
        $this->travelTo(Carbon::parse($time, 'Europe/Berlin'));
    }
}
