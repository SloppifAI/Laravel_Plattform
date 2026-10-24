<?php

namespace Tests\Feature\ClassBook;

use App\Models\ClassBookEntry;
use App\Models\ClassSubstitution;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\PersonalDataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retention, erasure and access to the data of the Klassenbuch: expired entries are deleted, a deleted student leaves
 * no id behind, and the Auskunft (Art. 15 DSGVO) names the student's absences.
 */
class ClassBookDataProtectionTest extends TestCase
{
    use CreatesClassBookFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'lernplattform.retention.school_years_kept' => 2,
            'lernplattform.retention.school_year_starts' => '08-01',
            'lernplattform.retention.one_year_rule_from' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entryOf(SchoolClass $schoolClass, ?User $teacher, array $attributes = []): ClassBookEntry
    {
        return ClassBookEntry::factory()->create(['school_class_id' => $schoolClass->getKey(), 'teacher_id' => $teacher?->getKey(), ...$attributes]);
    }

    public function test_entries_older_than_the_kept_school_years_are_deleted_with_their_absences(): void
    {
        [$schoolClass, $students, $teacher] = $this->classWithStudents();
        $this->travelTo('2026-10-24');
        $expired = $this->entryOf($schoolClass, $teacher, [
            'held_on' => '2025-07-31', 'created_at' => '2025-07-31', 'updated_at' => '2025-07-31',
            'confirmed_at' => '2025-07-31', 'absent_student_ids' => [$students->first()->getKey()],
        ]);
        $firstKeptDay = $this->entryOf($schoolClass, $teacher, ['held_on' => '2025-08-01', 'created_at' => '2025-08-01', 'updated_at' => '2025-08-01']);
        $current = $this->entryOf($schoolClass, $teacher, ['held_on' => '2026-10-20']);
        // Old date, but changed after the cut-off: a mistyped date must not wipe current work.
        $retyped = $this->entryOf($schoolClass, $teacher, ['held_on' => '2024-01-01', 'created_at' => '2024-01-01']);

        $this->artisan('lernplattform:prune-learning-data')->assertSuccessful();

        $this->assertModelMissing($expired);
        $this->assertModelExists($firstKeptDay);
        $this->assertModelExists($current);
        $this->assertModelExists($retyped);
        $this->assertModelExists($students->first());
    }

    public function test_a_dry_run_counts_the_entries_and_keeps_them(): void
    {
        [$schoolClass, , $teacher] = $this->classWithStudents();
        $this->travelTo('2026-10-24');
        $expired = $this->entryOf($schoolClass, $teacher, ['held_on' => '2025-07-31', 'created_at' => '2025-07-31', 'updated_at' => '2025-07-31']);

        $this->artisan('lernplattform:prune-learning-data --dry-run')
            ->expectsTable(['Gegenstand', 'würde gelöscht'], [
                ['Unterrichtsstunden', 0],
                ['Fragen', 0],
                ['Rückfragen', 0],
                ['Abgaben', 0],
                ['Antworten', 0],
                ['Erklär\'s-mir-Gespräche', 0],
                ['Nachrichten', 0],
                ['Klassenbuch-Einträge', 1],
                ['Arbeitsblatt-Dateien', 0],
            ])
            ->assertSuccessful();

        $this->assertModelExists($expired);
    }

    public function test_the_entry_of_an_expired_lesson_is_deleted_only_by_its_own_date(): void
    {
        [$schoolClass, , $teacher] = $this->classWithStudents();
        $this->travelTo('2026-10-24');
        $lesson = $this->lessonOf($schoolClass, $teacher, ['held_on' => '2025-07-31', 'created_at' => '2025-07-31', 'updated_at' => '2025-07-31']);
        $entry = $this->entryOf($schoolClass, $teacher, ['lesson_id' => $lesson->getKey(), 'held_on' => '2026-10-01']);

        $this->artisan('lernplattform:prune-learning-data')->assertSuccessful();

        $this->assertModelMissing($lesson);
        $this->assertModelExists($entry);
        $this->assertNull($entry->fresh()->lesson_id);
    }

    public function test_a_deleted_student_is_taken_out_of_every_absence_list_even_of_a_locked_entry(): void
    {
        [$schoolClass, $students, $teacher] = $this->classWithStudents(3);
        [$anna, $ben, $clara] = $students;
        $locked = $this->entryOf($schoolClass, $teacher, ['confirmed_at' => now(), 'absent_student_ids' => [$anna->getKey(), $ben->getKey()]]);
        $draft = $this->entryOf($schoolClass, $teacher, ['absent_student_ids' => [$ben->getKey()]]);
        $untouched = $this->entryOf($schoolClass, $teacher, ['absent_student_ids' => [$anna->getKey(), $clara->getKey()]]);
        $nobody = $this->entryOf($schoolClass, $teacher, ['absent_student_ids' => null]);
        // The student has left the class since; the entries of the old class name them still.
        [$otherClass, , $otherTeacher] = $this->classWithStudents(1, $schoolClass->school, '5b');
        $ofOtherClass = $this->entryOf($otherClass, $otherTeacher, ['absent_student_ids' => [$ben->getKey(), $anna->getKey()]]);
        $ben->update(['school_class_id' => $otherClass->getKey()]);

        $ben->delete();

        $this->assertSame([$anna->getKey()], $locked->fresh()->absent_student_ids);
        $this->assertSame([], $draft->fresh()->absent_student_ids);
        $this->assertSame([$anna->getKey(), $clara->getKey()], $untouched->fresh()->absent_student_ids);
        $this->assertNull($nobody->fresh()->absent_student_ids);
        $this->assertSame([$anna->getKey()], $ofOtherClass->fresh()->absent_student_ids);
        $this->assertTrue($locked->fresh()->isConfirmed());
    }

    public function test_deleting_a_teacher_leaves_the_absence_lists_alone(): void
    {
        [$schoolClass, $students, $teacher] = $this->classWithStudents(2);
        $entry = $this->entryOf($schoolClass, $teacher, ['absent_student_ids' => [$students->first()->getKey()]]);

        $teacher->delete();

        $this->assertSame([$students->first()->getKey()], $entry->fresh()->absent_student_ids);
    }

    public function test_the_student_export_lists_the_days_of_absence_without_the_other_students_or_the_teachers_texts(): void
    {
        [$schoolClass, $students, $teacher] = $this->classWithStudents(3);
        [$anna, $ben] = $students;
        $this->entryOf($schoolClass, $teacher, ['held_on' => '2026-10-07', 'subject' => 'Mathe', 'confirmed_at' => now(), 'absent_student_ids' => [$anna->getKey(), $ben->getKey()], 'remarks' => 'Nur für Lehrkräfte']);
        $this->entryOf($schoolClass, $teacher, ['held_on' => '2026-10-05', 'subject' => 'Biologie', 'absent_student_ids' => [$anna->getKey()]]);
        $this->entryOf($schoolClass, $teacher, ['held_on' => '2026-10-06', 'absent_student_ids' => [$ben->getKey()]]);
        $this->entryOf($schoolClass, $teacher, ['held_on' => '2026-10-08', 'absent_student_ids' => null]);

        $export = PersonalDataExport::for($anna);

        $this->assertSame([
            ['datum' => '2026-10-05', 'fach' => 'Biologie', 'klasse' => '5a', 'status' => 'Entwurf'],
            ['datum' => '2026-10-07', 'fach' => 'Mathe', 'klasse' => '5a', 'status' => 'Bestätigt'],
        ], $export['klassenbuch_fehlzeiten']);
        $this->assertStringNotContainsString($ben->name, json_encode($export, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('Nur für Lehrkräfte', json_encode($export, JSON_UNESCAPED_UNICODE));
        $this->assertSame([], PersonalDataExport::for($students->last())['klassenbuch_fehlzeiten']);
    }

    public function test_an_export_requested_by_somebody_else_holds_the_absences_only_for_those_who_see_the_klassenbuch(): void
    {
        [$schoolClass, $students, $teacher] = $this->classWithStudents(2);
        $anna = $students->first();
        $this->entryOf($schoolClass, $teacher, ['absent_student_ids' => [$anna->getKey()]]);
        $admin = User::factory()->for($schoolClass->school)->admin()->create();
        $bystander = User::factory()->for($schoolClass->school)->teacher()->create();
        $foreignAdmin = User::factory()->admin()->create();
        $substitute = User::factory()->for($schoolClass->school)->teacher()->create();
        ClassSubstitution::factory()->create(['school_class_id' => $schoolClass->getKey(), 'substitute_id' => $substitute->getKey()]);

        $this->assertCount(1, PersonalDataExport::for($anna, $anna)['klassenbuch_fehlzeiten']);
        $this->assertCount(1, PersonalDataExport::for($anna, $admin)['klassenbuch_fehlzeiten']);
        $this->assertCount(1, PersonalDataExport::for($anna, $teacher)['klassenbuch_fehlzeiten']);
        $this->assertCount(1, PersonalDataExport::for($anna, $substitute)['klassenbuch_fehlzeiten']);
        $this->assertSame([], PersonalDataExport::for($anna, $bystander)['klassenbuch_fehlzeiten']);
        $this->assertSame([], PersonalDataExport::for($anna, $foreignAdmin)['klassenbuch_fehlzeiten']);
    }

    public function test_the_teacher_export_holds_the_entries_they_wrote_with_the_number_of_absent_students_only(): void
    {
        [$schoolClass, $students, $teacher] = $this->classWithStudents(3);
        $this->entryOf($schoolClass, $teacher, [
            'held_on' => '2026-10-07', 'period' => 2, 'subject' => 'Mathe', 'topic' => 'Brüche', 'content' => 'Kürzen geübt',
            'homework' => 'Seite 12', 'remarks' => 'Test folgt', 'confirmed_at' => '2026-10-07 14:00:00',
            'absent_student_ids' => [$students[0]->getKey(), $students[1]->getKey()],
        ]);
        [$otherClass, , $colleague] = $this->classWithStudents(1, $schoolClass->school, '5b');
        $this->entryOf($otherClass, $colleague, ['topic' => 'Von der Kollegin']);

        $export = PersonalDataExport::for($teacher);

        $this->assertCount(1, $export['klassenbuch_eintraege']);
        $this->assertSame(
            [
                'klasse' => '5a', 'datum' => '2026-10-07', 'stunde' => 2, 'fach' => 'Mathe', 'thema' => 'Brüche',
                'unterrichtsinhalt' => 'Kürzen geübt', 'hausaufgabe' => 'Seite 12', 'bemerkungen' => 'Test folgt',
                'anzahl_fehlende_schueler' => 2, 'status' => 'Bestätigt', 'bestaetigt_am' => '2026-10-07T14:00:00+00:00',
            ],
            $export['klassenbuch_eintraege'][0],
        );
        $json = json_encode($export, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($students[0]->name, $json);
        $this->assertStringNotContainsString('Von der Kollegin', $json);
        $this->assertArrayNotHasKey('klassenbuch_fehlzeiten', $export);
    }

    public function test_the_teacher_export_requested_by_somebody_else_holds_only_entries_that_person_may_see(): void
    {
        [$schoolClass, , $teacher] = $this->classWithStudents(1);
        $this->entryOf($schoolClass, $teacher, ['topic' => 'Mein Eintrag']);
        $admin = User::factory()->for($schoolClass->school)->admin()->create();
        $colleague = User::factory()->for($schoolClass->school)->teacher()->create();
        $foreignAdmin = User::factory()->admin()->create();

        $this->assertCount(1, PersonalDataExport::for($teacher, $admin)['klassenbuch_eintraege']);
        $this->assertSame([], PersonalDataExport::for($teacher, $colleague)['klassenbuch_eintraege']);
        $this->assertSame([], PersonalDataExport::for($teacher, $foreignAdmin)['klassenbuch_eintraege']);
    }

    public function test_admins_and_operators_get_no_klassenbuch_sections(): void
    {
        $school = School::factory()->create();

        foreach ([User::factory()->for($school)->admin()->create(), User::factory()->operator()->create()] as $user) {
            $export = PersonalDataExport::for($user);

            $this->assertArrayNotHasKey('klassenbuch_fehlzeiten', $export);
            $this->assertArrayNotHasKey('klassenbuch_eintraege', $export);
        }
    }
}
