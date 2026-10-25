<?php

namespace Tests\Feature\Timetable;

use App\Filament\Pages\MyTimetable;
use App\Models\SchoolClass;
use App\Models\TimetableEntry;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\TimetableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TimetableSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_demo_class_gets_a_week(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            ['7a' => 24, '7b' => 25, '8c' => 20],
            SchoolClass::query()->withCount('timetableEntries')->orderBy('name')->pluck('timetable_entries_count', 'name')->all(),
        );
    }

    public function test_no_teacher_is_in_two_classes_at_once_and_every_teacher_belongs_to_the_school(): void
    {
        $this->seed(DatabaseSeeder::class);

        $clashes = TimetableEntry::query()
            ->whereNotNull('teacher_id')
            ->select('teacher_id', 'weekday', 'period', DB::raw('count(*) as entries'))
            ->groupBy('teacher_id', 'weekday', 'period')
            ->having('entries', '>', 1)
            ->get();

        $this->assertCount(0, $clashes);

        TimetableEntry::query()->with(['schoolClass', 'teacher'])->whereNotNull('teacher_id')->get()
            ->each(fn (TimetableEntry $entry) => $this->assertSame($entry->schoolClass->school_id, $entry->teacher->school_id));
    }

    public function test_reseeding_keeps_what_the_administration_changed(): void
    {
        $this->seed(DatabaseSeeder::class);
        $count = TimetableEntry::query()->count();
        $entry = TimetableEntry::query()->first();
        $entry->update(['subject' => 'Geändert']);

        $this->seed(TimetableSeeder::class);

        $this->assertSame($count, TimetableEntry::query()->count());
        $this->assertSame('Geändert', $entry->refresh()->subject);
    }

    public function test_the_demo_student_sees_the_week_of_7b(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->actingAs(User::query()->where('username', 'schueler')->firstOrFail())
            ->get(MyTimetable::getUrl())
            ->assertOk()
            ->assertSee(['Mathematik', 'Frau Sommer', 'Sporthalle']);
    }

    public function test_the_demo_teacher_of_two_classes_sees_both(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->actingAs(User::query()->where('username', 'weber')->firstOrFail())
            ->get(MyTimetable::getUrl())
            ->assertOk()
            ->assertSee(['Class 7a', 'Class 7b', 'Erdkunde']);
    }
}
