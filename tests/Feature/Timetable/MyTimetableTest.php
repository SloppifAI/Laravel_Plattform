<?php

namespace Tests\Feature\Timetable;

use App\Filament\NavigationGroup;
use App\Filament\Pages\MyTimetable;
use App\Models\SchoolClass;
use App\Models\TimetableEntry;
use App\Models\User;
use App\Support\PersonalDataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Stundenplan": students see the week of their class, teachers their own slots in every class; only to look at.
 */
class MyTimetableTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_sees_the_timetable_of_the_own_class(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $teacher = User::factory()->teacher()->for($schoolClass->school)->create(['name' => 'Frau Schulze']);
        TimetableEntry::factory()->at(1, 2)->create(['school_class_id' => $schoolClass->id, 'teacher_id' => $teacher->id, 'subject' => 'Mathematik', 'room' => 'R 104']);
        TimetableEntry::factory()->at(1, 2)->create(['subject' => 'Fremdes Fach']);
        $student = User::factory()->student($schoolClass)->create();

        $this->actingAs($student)
            ->get(MyTimetable::getUrl())
            ->assertOk()
            ->assertSee(['Timetable', 'Monday', 'Lesson 2', '08:45–09:30', 'Mathematik', 'Frau Schulze', 'R 104'])
            ->assertDontSee('Fremdes Fach');

        $this->assertSame(NavigationGroup::Lernen, MyTimetable::getNavigationGroup());
    }

    public function test_a_teacher_sees_the_own_slots_in_every_class(): void
    {
        $teacher = User::factory()->teacher()->create();
        $classA = SchoolClass::factory()->create(['school_id' => $teacher->school_id, 'name' => '7a']);
        $classB = SchoolClass::factory()->create(['school_id' => $teacher->school_id, 'name' => '9c']);
        TimetableEntry::factory()->at(2, 1)->create(['school_class_id' => $classA->id, 'teacher_id' => $teacher->id, 'subject' => 'Deutsch']);
        TimetableEntry::factory()->at(4, 3)->create(['school_class_id' => $classB->id, 'teacher_id' => $teacher->id, 'subject' => 'Englisch']);
        TimetableEntry::factory()->at(3, 1)->create(['school_class_id' => $classA->id, 'subject' => 'Fach der Kollegin']);

        $this->actingAs($teacher)
            ->get(MyTimetable::getUrl())
            ->assertOk()
            ->assertSee(['Deutsch', 'Class 7a', 'Englisch', 'Class 9c'])
            ->assertDontSee('Fach der Kollegin');

        $this->assertSame(NavigationGroup::Unterricht, MyTimetable::getNavigationGroup());
    }

    public function test_a_klassenleitung_switches_to_the_whole_timetable_of_the_class(): void
    {
        $leader = User::factory()->teacher()->create();
        $colleague = User::factory()->teacher()->for($leader->school)->create(['name' => 'Herr Kollege']);
        $schoolClass = SchoolClass::factory()->create(['school_id' => $leader->school_id, 'teacher_id' => $leader->id, 'name' => '7b']);
        TimetableEntry::factory()->at(1, 1)->create(['school_class_id' => $schoolClass->id, 'teacher_id' => $leader->id, 'subject' => 'Mathematik']);
        TimetableEntry::factory()->at(1, 2)->create(['school_class_id' => $schoolClass->id, 'teacher_id' => $colleague->id, 'subject' => 'Englisch']);
        $this->actingAs($leader);

        Livewire::test(MyTimetable::class)
            ->assertSee('Class 7b (whole timetable)')
            ->assertSee('Mathematik')
            ->assertDontSee('Englisch')
            ->set('classId', $schoolClass->id)
            ->assertSee(['Mathematik', 'Englisch', 'Herr Kollege'])
            ->set('classId', '')
            ->assertDontSee('Englisch');

        $this->get(MyTimetable::getUrl(['klasse' => $schoolClass->id]))
            ->assertOk()
            ->assertSee('All lessons of class 7b in the week.');
    }

    public function test_only_the_klassenleitung_opens_the_whole_timetable_of_a_class(): void
    {
        $assigned = User::factory()->teacher()->create();
        $schoolClass = SchoolClass::factory()->create(['school_id' => $assigned->school_id]);
        $schoolClass->teachers()->attach($assigned);
        $foreignLeader = User::factory()->teacher()->create();
        $foreignClass = SchoolClass::factory()->create(['school_id' => $foreignLeader->school_id, 'teacher_id' => $foreignLeader->id]);

        $this->actingAs($assigned)
            ->get(MyTimetable::getUrl(['klasse' => $schoolClass->id]))
            ->assertForbidden();

        $this->actingAs($assigned)
            ->get(MyTimetable::getUrl())
            ->assertOk()
            ->assertDontSee('whole timetable');

        $this->assertFalse(User::factory()->student($foreignClass)->create()->can('viewClass', [TimetableEntry::class, $foreignClass]));
        $this->assertFalse(User::factory()->admin()->for($foreignClass->school)->create()->can('viewClass', [TimetableEntry::class, $foreignClass]));
        $this->assertTrue($foreignLeader->can('viewClass', [TimetableEntry::class, $foreignClass]));
    }

    public function test_today_is_the_weekday_at_the_school_not_in_utc(): void
    {
        // Monday 00:30 in Berlin is still Sunday 23:30 in UTC.
        $this->travelTo(Carbon::parse('2026-10-26 00:30', 'Europe/Berlin'));
        $this->assertSame('Sunday', now()->utc()->englishDayOfWeek);

        $schoolClass = SchoolClass::factory()->create();
        TimetableEntry::factory()->at(1, 2)->create(['school_class_id' => $schoolClass->id]);

        $this->actingAs(User::factory()->student($schoolClass)->create());

        Livewire::test(MyTimetable::class)
            ->assertViewHas('today', 1);
    }

    public function test_the_page_shows_no_management_actions(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        TimetableEntry::factory()->create(['school_class_id' => $schoolClass->id]);

        $this->actingAs(User::factory()->student($schoolClass)->create())
            ->get(MyTimetable::getUrl())
            ->assertOk()
            ->assertDontSee(['Add lesson', 'Edit', 'Delete']);
    }

    public function test_without_entries_the_page_says_who_maintains_the_timetable(): void
    {
        $this->actingAs(User::factory()->student()->create())
            ->get(MyTimetable::getUrl())
            ->assertOk()
            ->assertSee('No timetable yet')
            ->assertSee('The school administration maintains it.');
    }

    public function test_only_students_and_teachers_have_a_timetable_page(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->operator()->create()] as $user) {
            $this->actingAs($user)
                ->get(MyTimetable::getUrl())
                ->assertForbidden();
        }
    }

    public function test_the_teachers_slots_are_part_of_their_data_export(): void
    {
        $teacher = User::factory()->teacher()->create();
        $schoolClass = SchoolClass::factory()->create(['school_id' => $teacher->school_id, 'name' => '8b']);
        TimetableEntry::factory()->at(3, 4)->create(['school_class_id' => $schoolClass->id, 'teacher_id' => $teacher->id, 'subject' => 'Biologie', 'room' => 'Bio-Raum']);

        $this->assertSame([[
            'klasse' => '8b',
            'wochentag' => 'Mittwoch',
            'stunde' => 4,
            'fach' => 'Biologie',
            'raum' => 'Bio-Raum',
        ]], PersonalDataExport::for($teacher)['stundenplan']);
    }
}
