<?php

namespace Tests\Feature\Timetable;

use App\Filament\NavigationGroup;
use App\Filament\Resources\TimetableEntries\Pages\ManageTimetableEntries;
use App\Filament\Resources\TimetableEntries\TimetableEntryResource;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\TimetableEntry;
use App\Models\User;
use App\Role;
use Filament\Actions\CreateAction;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The school administration maintains the timetables of its own school; teachers, students and the platform operator
 * never reach the management.
 */
class TimetableManagementTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private SchoolClass $schoolClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->schoolClass = SchoolClass::factory()->create(['school_id' => $this->school->id, 'teacher_id' => $this->teacher()->id]);
    }

    public function test_admin_enters_a_lesson_for_a_class_of_the_own_school(): void
    {
        $this->actingAsAdmin();
        $teacher = $this->teacher();

        Livewire::test(ManageTimetableEntries::class)
            ->callAction(CreateAction::class, [
                'school_class_id' => $this->schoolClass->id,
                'weekday' => 2,
                'period' => 3,
                'subject' => 'Mathematik',
                'teacher_id' => $teacher->id,
                'room' => 'R 104',
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(TimetableEntry::class, [
            'school_class_id' => $this->schoolClass->id,
            'weekday' => 2,
            'period' => 3,
            'subject' => 'Mathematik',
            'teacher_id' => $teacher->id,
            'room' => 'R 104',
        ]);
    }

    public function test_the_teacher_may_be_left_open(): void
    {
        $this->actingAsAdmin();

        Livewire::test(ManageTimetableEntries::class)
            ->callAction(CreateAction::class, ['school_class_id' => $this->schoolClass->id, 'weekday' => 1, 'period' => 5, 'subject' => 'Sport'])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(TimetableEntry::class, ['school_class_id' => $this->schoolClass->id, 'subject' => 'Sport', 'teacher_id' => null, 'room' => null]);
    }

    public function test_class_and_teacher_of_another_school_are_rejected(): void
    {
        $this->actingAsAdmin();
        $foreignClass = SchoolClass::factory()->create();

        Livewire::test(ManageTimetableEntries::class)
            ->callAction(CreateAction::class, [
                'school_class_id' => $foreignClass->id,
                'weekday' => 1,
                'period' => 1,
                'subject' => 'Deutsch',
                'teacher_id' => $foreignClass->teacher_id,
            ])
            ->assertHasFormErrors(['school_class_id', 'teacher_id']);

        $this->assertDatabaseCount(TimetableEntry::class, 0);
    }

    public function test_a_class_has_one_entry_per_slot(): void
    {
        $this->actingAsAdmin();
        TimetableEntry::factory()->at(3, 2)->create(['school_class_id' => $this->schoolClass->id]);

        Livewire::test(ManageTimetableEntries::class)
            ->callAction(CreateAction::class, ['school_class_id' => $this->schoolClass->id, 'weekday' => 3, 'period' => 2, 'subject' => 'Musik'])
            ->assertHasFormErrors(['period' => 'unique']);

        // The same period on another day, or in another class, is free.
        Livewire::test(ManageTimetableEntries::class)
            ->callAction(CreateAction::class, ['school_class_id' => $this->schoolClass->id, 'weekday' => 4, 'period' => 2, 'subject' => 'Musik'])
            ->assertHasNoFormErrors();

        Livewire::test(ManageTimetableEntries::class)
            ->callAction(CreateAction::class, ['school_class_id' => $this->otherClass()->id, 'weekday' => 3, 'period' => 2, 'subject' => 'Musik'])
            ->assertHasNoFormErrors();
    }

    public function test_a_teacher_is_never_in_two_classes_at_once(): void
    {
        $this->actingAsAdmin();
        $teacher = $this->teacher();
        TimetableEntry::factory()->at(1, 4)->create(['school_class_id' => $this->otherClass()->id, 'teacher_id' => $teacher->id]);

        Livewire::test(ManageTimetableEntries::class)
            ->callAction(CreateAction::class, ['school_class_id' => $this->schoolClass->id, 'weekday' => 1, 'period' => 4, 'subject' => 'Englisch', 'teacher_id' => $teacher->id])
            ->assertHasFormErrors(['teacher_id']);

        $this->assertSame(1, TimetableEntry::query()->where('teacher_id', $teacher->id)->count());
    }

    public function test_editing_an_entry_does_not_clash_with_itself(): void
    {
        $this->actingAsAdmin();
        $entry = TimetableEntry::factory()->at(5, 1)->create(['school_class_id' => $this->schoolClass->id, 'subject' => 'Mathematik']);

        Livewire::test(ManageTimetableEntries::class)
            ->callAction(TestAction::make('edit')->table($entry), ['subject' => 'Physik', 'room' => 'Physikraum'])
            ->assertHasNoFormErrors();

        $entry->refresh();
        $this->assertSame('Physik', $entry->subject);
        $this->assertSame('Physikraum', $entry->room);

        Livewire::test(ManageTimetableEntries::class)
            ->callAction(TestAction::make('delete')->table($entry));

        $this->assertModelMissing($entry);
    }

    public function test_an_entry_cannot_be_moved_onto_an_occupied_slot(): void
    {
        $this->actingAsAdmin();
        $otherClass = $this->otherClass();
        TimetableEntry::factory()->at(1, 1)->create(['school_class_id' => $otherClass->id, 'teacher_id' => null]);
        $entry = TimetableEntry::factory()->at(1, 1)->create(['school_class_id' => $this->schoolClass->id, 'teacher_id' => null]);
        $other = TimetableEntry::factory()->at(1, 2)->create(['school_class_id' => $this->schoolClass->id, 'teacher_id' => null]);

        Livewire::test(ManageTimetableEntries::class)
            ->callAction(TestAction::make('edit')->table($entry), ['school_class_id' => $otherClass->id])
            ->assertHasFormErrors(['period' => 'unique']);

        Livewire::test(ManageTimetableEntries::class)
            ->callAction(TestAction::make('edit')->table($other), ['period' => 1])
            ->assertHasFormErrors(['period' => 'unique']);

        $this->assertSame($this->schoolClass->id, $entry->refresh()->school_class_id);
        $this->assertSame(2, $other->refresh()->period);
    }

    public function test_an_edit_may_not_book_a_teacher_twice(): void
    {
        $this->actingAsAdmin();
        $teacher = $this->teacher();
        TimetableEntry::factory()->at(2, 3)->create(['school_class_id' => $this->otherClass()->id, 'teacher_id' => $teacher->id]);
        $entry = TimetableEntry::factory()->at(2, 3)->create(['school_class_id' => $this->schoolClass->id, 'teacher_id' => null]);

        Livewire::test(ManageTimetableEntries::class)
            ->callAction(TestAction::make('edit')->table($entry), ['teacher_id' => $teacher->id])
            ->assertHasFormErrors(['teacher_id']);

        $this->assertNull($entry->refresh()->teacher_id);
    }

    public function test_the_database_refuses_a_teacher_in_two_classes_at_once(): void
    {
        $teacher = $this->teacher();
        TimetableEntry::factory()->at(4, 2)->create(['school_class_id' => $this->schoolClass->id, 'teacher_id' => $teacher->id]);

        $this->expectException(UniqueConstraintViolationException::class);

        TimetableEntry::factory()->at(4, 2)->create(['school_class_id' => $this->otherClass()->id, 'teacher_id' => $teacher->id]);
    }

    public function test_slots_without_a_teacher_never_collide(): void
    {
        TimetableEntry::factory()->at(4, 2)->create(['school_class_id' => $this->schoolClass->id, 'teacher_id' => null]);
        TimetableEntry::factory()->at(4, 2)->create(['school_class_id' => $this->otherClass()->id, 'teacher_id' => null]);

        $this->assertSame(2, TimetableEntry::query()->whereNull('teacher_id')->count());
    }

    public function test_a_weekday_or_period_outside_the_timetable_is_rejected(): void
    {
        $this->actingAsAdmin();

        Livewire::test(ManageTimetableEntries::class)
            ->callAction(CreateAction::class, ['school_class_id' => $this->schoolClass->id, 'weekday' => 6, 'period' => 99, 'subject' => 'Deutsch'])
            ->assertHasFormErrors(['weekday', 'period']);

        $this->assertDatabaseCount(TimetableEntry::class, 0);
    }

    public function test_an_entry_of_another_school_cannot_be_edited_through_the_page(): void
    {
        $this->actingAsAdmin();
        $foreign = TimetableEntry::factory()->create(['subject' => 'Deutsch']);

        try {
            Livewire::test(ManageTimetableEntries::class)
                ->callAction(TestAction::make('edit')->table($foreign), ['subject' => 'Geändert']);
            $this->fail('The edit action resolved a record of another school.');
        } catch (ActionNotResolvableException) {
            // The record is outside the page's query, so the action finds nothing to work on.
        }

        $this->assertSame('Deutsch', $foreign->refresh()->subject);
    }

    public function test_a_bulk_delete_leaves_entries_of_another_school_alone(): void
    {
        $this->actingAsAdmin();
        $own = TimetableEntry::factory()->create(['school_class_id' => $this->schoolClass->id]);
        $foreign = TimetableEntry::factory()->create();

        Livewire::test(ManageTimetableEntries::class)
            ->callTableBulkAction('delete', [$own->id, $foreign->id]);

        $this->assertModelMissing($own);
        $this->assertModelExists($foreign);
    }

    public function test_a_teacher_who_is_no_longer_one_leaves_the_slots_open(): void
    {
        $teacher = $this->teacher();
        $entry = TimetableEntry::factory()->create(['school_class_id' => $this->schoolClass->id, 'teacher_id' => $teacher->id]);

        $teacher->update(['role' => Role::Admin]);

        $this->assertNull($entry->refresh()->teacher_id);
    }

    public function test_entries_outlive_a_deleted_teacher_but_not_a_deleted_class(): void
    {
        $teacher = $this->teacher();
        $entry = TimetableEntry::factory()->create(['school_class_id' => $this->schoolClass->id, 'teacher_id' => $teacher->id]);

        $teacher->delete();
        $this->assertNull($entry->refresh()->teacher_id);

        $this->schoolClass->delete();
        $this->assertModelMissing($entry);
    }

    public function test_admin_sees_only_the_timetables_of_the_own_school(): void
    {
        $admin = $this->actingAsAdmin();
        $own = TimetableEntry::factory()->create(['school_class_id' => $this->schoolClass->id]);
        $foreign = TimetableEntry::factory()->create();

        Livewire::test(ManageTimetableEntries::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);

        $this->assertTrue($admin->can('update', $own));
        $this->assertFalse($admin->can('update', $foreign));
        $this->assertFalse($admin->can('delete', $foreign));
    }

    /**
     * The platform operator sees no lesson data, the timetables included.
     */
    public function test_the_operator_neither_sees_nor_changes_timetables(): void
    {
        $operator = User::factory()->operator()->create();
        $entry = TimetableEntry::factory()->create(['school_class_id' => $this->schoolClass->id]);

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertFalse($operator->can($ability, $entry), $ability);
        }
    }

    public function test_teachers_students_and_the_operator_do_not_reach_the_management(): void
    {
        foreach ([$this->teacher(), User::factory()->student($this->schoolClass)->create(), User::factory()->operator()->create()] as $user) {
            $this->actingAs($user)
                ->get(TimetableEntryResource::getUrl('index'))
                ->assertForbidden();

            $this->assertFalse($user->can('create', TimetableEntry::class));
        }
    }

    public function test_admin_finds_the_timetables_in_the_administration(): void
    {
        $this->actingAsAdmin();

        $this->get(TimetableEntryResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Timetables');

        $this->assertSame(NavigationGroup::Verwaltung, TimetableEntryResource::getNavigationGroup());
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->for($this->school)->create();
        $this->actingAs($admin);

        return $admin;
    }

    private function teacher(): User
    {
        return User::factory()->teacher()->for($this->school)->create();
    }

    private function otherClass(): SchoolClass
    {
        return SchoolClass::factory()->create(['school_id' => $this->school->id, 'teacher_id' => $this->teacher()->id]);
    }
}
