<?php

namespace Database\Seeders;

use App\Models\School;
use App\Models\SchoolClass;
use App\Models\TimetableEntry;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The weekly timetables of the demo classes made by DemoSchoolSeeder (it runs after it): 7a and 7b of the
 * "Gesamtschule am Lernpark" and 8c of the "Gymnasium Musterstadt". Frau Sommer teaches 7b Mathematik, Biologie and
 * Geschichte (the subjects of her demo lessons), Thomas Weber leads 7a and teaches 7b Deutsch and Englisch, without
 * ever being in two classes at once; Julia Berger teaches 8c Mathematik and Physik. Slots whose teacher the school has
 * not decided yet stay without one, as the administration can leave them.
 *
 * Like the demo lessons, a slot is only created when the class has nothing in it yet, and a teacher is left out where
 * they already teach another class at that time: changes made while trying out the platform survive re-seeding. A demo
 * class that is missing (e.g. deleted) is skipped.
 */
class TimetableSeeder extends Seeder
{
    /**
     * Per school number (PHP turns the numeric keys into ints) and class: weekday => period => [subject, teacher username or
     * null, room or null (the class room)].
     *
     * @var array<int, array<string, array{room: string, week: array<int, array<int, array{0: string, 1: ?string, 2?: string}>>}>>
     */
    private const array TIMETABLES = [
        '4711' => [
            '7b' => [
                'room' => 'R 104',
                'week' => [
                    1 => [1 => ['Mathematik', 'lehrer'], 2 => ['Mathematik', 'lehrer'], 3 => ['Deutsch', 'weber'], 4 => ['Biologie', 'lehrer', 'Bio-Raum'], 5 => ['Sport', null, 'Sporthalle'], 6 => ['Sport', null, 'Sporthalle']],
                    2 => [1 => ['Englisch', 'weber'], 2 => ['Geschichte', 'lehrer'], 3 => ['Mathematik', 'lehrer'], 4 => ['Deutsch', 'weber'], 5 => ['Kunst', null, 'Kunstraum']],
                    3 => [1 => ['Biologie', 'lehrer', 'Bio-Raum'], 2 => ['Biologie', 'lehrer', 'Bio-Raum'], 3 => ['Englisch', 'weber'], 4 => ['Mathematik', 'lehrer'], 5 => ['Musik', null, 'Musikraum']],
                    4 => [1 => ['Deutsch', 'weber'], 2 => ['Mathematik', 'lehrer'], 3 => ['Geschichte', 'lehrer'], 4 => ['Englisch', 'weber'], 5 => ['Sport', null, 'Sporthalle']],
                    5 => [1 => ['Mathematik', 'lehrer'], 2 => ['Deutsch', 'weber'], 3 => ['Englisch', 'weber'], 4 => ['Klassenrat', 'lehrer']],
                ],
            ],
            '7a' => [
                'room' => 'R 102',
                'week' => [
                    1 => [1 => ['Deutsch', 'weber'], 2 => ['Englisch', 'weber'], 3 => ['Mathematik', null], 4 => ['Erdkunde', 'weber'], 5 => ['Physik', null, 'Physikraum']],
                    2 => [1 => ['Mathematik', null], 2 => ['Deutsch', 'weber'], 3 => ['Englisch', 'weber'], 4 => ['Sport', null, 'Sporthalle'], 5 => ['Sport', null, 'Sporthalle']],
                    3 => [1 => ['Englisch', 'weber'], 2 => ['Deutsch', 'weber'], 3 => ['Mathematik', null], 4 => ['Biologie', null, 'Bio-Raum'], 5 => ['Musik', null, 'Musikraum']],
                    4 => [1 => ['Kunst', null, 'Kunstraum'], 2 => ['Deutsch', 'weber'], 3 => ['Englisch', 'weber'], 4 => ['Mathematik', null], 5 => ['Erdkunde', 'weber']],
                    5 => [1 => ['Deutsch', 'weber'], 2 => ['Mathematik', null], 3 => ['Sport', null, 'Sporthalle'], 4 => ['Klassenrat', 'weber']],
                ],
            ],
        ],
        '4712' => [
            '8c' => [
                'room' => 'A 2.03',
                'week' => [
                    1 => [1 => ['Mathematik', 'gym-lehrer'], 2 => ['Mathematik', 'gym-lehrer'], 3 => ['Deutsch', null], 4 => ['Englisch', null]],
                    2 => [1 => ['Physik', 'gym-lehrer', 'Physiksaal'], 2 => ['Englisch', null], 3 => ['Deutsch', null], 4 => ['Latein', null]],
                    3 => [1 => ['Deutsch', null], 2 => ['Mathematik', 'gym-lehrer'], 3 => ['Biologie', null], 4 => ['Sport', null, 'Turnhalle']],
                    4 => [1 => ['Englisch', null], 2 => ['Physik', 'gym-lehrer', 'Physiksaal'], 3 => ['Geschichte', null], 4 => ['Sport', null, 'Turnhalle']],
                    5 => [1 => ['Mathematik', 'gym-lehrer'], 2 => ['Latein', null], 3 => ['Deutsch', null], 4 => ['Kunst', null]],
                ],
            ],
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::TIMETABLES as $schoolNumber => $classes) {
                $school = School::query()->where('school_number', (string) $schoolNumber)->first();

                foreach ($classes as $className => $timetable) {
                    $schoolClass = $school?->schoolClasses()->where('name', $className)->first();

                    if ($schoolClass !== null) {
                        $this->seedWeek($schoolClass, $timetable['room'], $timetable['week']);
                    }
                }
            }
        });
    }

    /**
     * @param  array<int, array<int, array{0: string, 1: ?string, 2?: string}>>  $week
     */
    private function seedWeek(SchoolClass $schoolClass, string $classRoom, array $week): void
    {
        $teachers = User::query()
            ->where('school_id', $schoolClass->school_id)
            ->whereIn('username', collect($week)->flatten(1)->pluck(1)->filter()->unique())
            ->pluck('id', 'username');

        foreach ($week as $weekday => $periods) {
            foreach ($periods as $period => $slot) {
                $entry = TimetableEntry::query()->firstOrNew(['school_class_id' => $schoolClass->id, 'weekday' => $weekday, 'period' => $period]);

                if ($entry->exists) {
                    continue;
                }

                $teacherId = $teachers->get($slot[1] ?? '');

                $entry->forceFill([
                    'subject' => $slot[0],
                    'teacher_id' => $teacherId !== null && ! $this->teachesElsewhere($teacherId, $weekday, $period) ? $teacherId : null,
                    'room' => $slot[2] ?? $classRoom,
                ])->save();
            }
        }
    }

    private function teachesElsewhere(int $teacherId, int $weekday, int $period): bool
    {
        return TimetableEntry::query()->where(['teacher_id' => $teacherId, 'weekday' => $weekday, 'period' => $period])->exists();
    }
}
