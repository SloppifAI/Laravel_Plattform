<?php

namespace Tests\Feature\ClassBook;

use App\Models\ClassBookEntry;
use App\Support\ClassBookCsv;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassBookCsvTest extends TestCase
{
    use CreatesClassBookFixtures;
    use RefreshDatabase;

    /**
     * @return list<list<string|null>>
     */
    private function parse(string $csv): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);

        $rows = [];

        while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function test_the_export_is_excel_friendly_and_lists_the_days_of_the_range_oldest_first(): void
    {
        [$schoolClass, $students, $teacher] = $this->classWithStudents(3);
        [$anna, $ben] = $students;
        $teacher->update(['name' => 'Frau Schmidt']);
        ClassBookEntry::factory()->confirmed()->absent([$ben, $anna])->create([
            'school_class_id' => $schoolClass->getKey(), 'teacher_id' => $teacher->getKey(),
            'held_on' => '2026-10-05', 'period' => 2, 'subject' => 'Biologie', 'topic' => 'Das Herz',
            'content' => 'Aufbau des Herzens', 'homework' => 'Arbeitsblatt 3', 'remarks' => 'Gute Mitarbeit',
        ]);
        ClassBookEntry::factory()->create([
            'school_class_id' => $schoolClass->getKey(), 'teacher_id' => $teacher->getKey(),
            'held_on' => '2026-10-02', 'subject' => 'Mathe', 'topic' => 'Brüche', 'content' => 'Kürzen',
        ]);
        ClassBookEntry::factory()->create(['school_class_id' => $schoolClass->getKey(), 'teacher_id' => $teacher->getKey(), 'held_on' => '2026-09-30', 'topic' => 'Zu früh']);
        ClassBookEntry::factory()->create(['school_class_id' => $schoolClass->getKey(), 'teacher_id' => $teacher->getKey(), 'held_on' => '2026-11-01', 'topic' => 'Zu spät']);
        ClassBookEntry::factory()->create(['topic' => 'Andere Klasse', 'held_on' => '2026-10-03']);

        $csv = ClassBookCsv::render($schoolClass, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $rows = $this->parse($csv);

        $this->assertStringStartsWith("\xEF\xBB\xBFDatum;Stunde;Fach;Thema;", $csv);
        $this->assertStringContainsString("\r\n", $csv);
        $this->assertCount(3, $rows);
        $this->assertSame(['Datum', 'Stunde', 'Fach', 'Thema', 'Unterrichtsinhalt', 'Hausaufgabe', 'Fehlende Schüler:innen', 'Bemerkungen', 'Lehrkraft', 'Status'], $rows[0]);
        $this->assertSame(['02.10.2026', '', 'Mathe', 'Brüche', 'Kürzen', '', '', '', 'Frau Schmidt', 'Entwurf'], $rows[1]);
        $this->assertSame(
            ['05.10.2026', '2', 'Biologie', 'Das Herz', 'Aufbau des Herzens', 'Arbeitsblatt 3', collect([$anna->name, $ben->name])->sort()->implode(', '), 'Gute Mitarbeit', 'Frau Schmidt', 'Bestätigt'],
            $rows[2],
        );
        $this->assertStringNotContainsString('Zu früh', $csv);
        $this->assertStringNotContainsString('Zu spät', $csv);
        $this->assertStringNotContainsString('Andere Klasse', $csv);
    }

    public function test_the_first_and_the_last_day_of_the_range_are_included(): void
    {
        [$schoolClass, , $teacher] = $this->classWithStudents();
        foreach (['2026-10-01', '2026-10-31'] as $day) {
            ClassBookEntry::factory()->create(['school_class_id' => $schoolClass->getKey(), 'teacher_id' => $teacher->getKey(), 'held_on' => $day]);
        }

        $rows = $this->parse(ClassBookCsv::render($schoolClass, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')->endOfDay()));

        $this->assertSame(['01.10.2026', '31.10.2026'], array_column(array_slice($rows, 1), 0));
    }

    public function test_cells_that_look_like_formulas_are_defused_and_quotes_and_line_breaks_survive(): void
    {
        [$schoolClass, , $teacher] = $this->classWithStudents();
        ClassBookEntry::factory()->create([
            'school_class_id' => $schoolClass->getKey(), 'teacher_id' => $teacher->getKey(), 'held_on' => '2026-10-05',
            'subject' => '=HYPERLINK("http://boese.example";"Klick")',
            'topic' => '+1+1',
            'content' => "-2 Grad; \"zitiert\"\nzweite Zeile",
            'homework' => '@SUM(A1)',
            'remarks' => "\tTabulator",
        ]);

        $row = $this->parse(ClassBookCsv::render($schoolClass, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')))[1];

        $this->assertSame('\'=HYPERLINK("http://boese.example";"Klick")', $row[2]);
        $this->assertSame("'+1+1", $row[3]);
        $this->assertSame("'-2 Grad; \"zitiert\"\nzweite Zeile", $row[4]);
        $this->assertSame("'@SUM(A1)", $row[5]);
        $this->assertSame("'\tTabulator", $row[7]);
    }

    public function test_an_absent_student_who_has_left_the_class_is_still_named(): void
    {
        [$schoolClass, $students, $teacher] = $this->classWithStudents(2);
        $gone = $students->first();
        ClassBookEntry::factory()->absent([$gone])->create(['school_class_id' => $schoolClass->getKey(), 'teacher_id' => $teacher->getKey(), 'held_on' => '2026-10-05']);
        $gone->update(['school_class_id' => null]);

        $row = $this->parse(ClassBookCsv::render($schoolClass, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')))[1];

        $this->assertSame($gone->name, $row[6]);
    }

    public function test_an_entry_of_a_deleted_teacher_account_stays_in_the_export(): void
    {
        [$schoolClass, , $teacher] = $this->classWithStudents();
        ClassBookEntry::factory()->confirmed()->create(['school_class_id' => $schoolClass->getKey(), 'teacher_id' => $teacher->getKey(), 'held_on' => '2026-10-05', 'topic' => 'Das Herz']);

        $teacher->delete();

        $row = $this->parse(ClassBookCsv::render($schoolClass, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')))[1];

        $this->assertSame('Das Herz', $row[3]);
        $this->assertSame(['gelöschtes Konto', 'Bestätigt'], array_slice($row, 8));
    }

    public function test_an_empty_range_gives_the_header_only(): void
    {
        [$schoolClass] = $this->classWithStudents();

        $rows = $this->parse(ClassBookCsv::render($schoolClass, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')));

        $this->assertCount(1, $rows);
    }
}
