<?php

namespace App\Support;

use App\Models\ClassBookEntry;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * The entries of a class between two days as a CSV for Excel: semicolon-separated, UTF-8 with BOM, oldest day first.
 * It names absent students; the caller has to make sure the user may see the class's Klassenbuch.
 */
class ClassBookCsv
{
    public static function render(SchoolClass $schoolClass, CarbonInterface $from, CarbonInterface $until): string
    {
        $entries = ClassBookEntry::query()
            ->with('teacher')
            ->where('school_class_id', $schoolClass->getKey())
            ->whereDate('held_on', '>=', $from->toDateString())
            ->whereDate('held_on', '<=', $until->toDateString())
            ->orderBy('held_on')
            ->orderBy('period')
            ->orderBy('id')
            ->get();

        $studentNames = User::query()
            ->where('school_id', $schoolClass->school_id)
            ->whereKey($entries->pluck('absent_student_ids')->flatten()->unique()->all())
            ->pluck('name', 'id');

        $lines = [['Datum', 'Stunde', 'Fach', 'Thema', 'Unterrichtsinhalt', 'Hausaufgabe', 'Fehlende Schüler:innen', 'Bemerkungen', 'Lehrkraft', 'Status']];

        foreach ($entries as $entry) {
            $lines[] = [
                $entry->held_on->format('d.m.Y'),
                (string) $entry->period,
                $entry->subject,
                $entry->topic,
                $entry->content,
                (string) $entry->homework,
                collect($entry->absent_student_ids ?? [])->map(fn (int $id): ?string => $studentNames->get($id))->filter()->sort()->implode(', '),
                (string) $entry->remarks,
                $entry->teacher?->name ?? 'gelöschtes Konto',
                $entry->status()->getLabel(),
            ];
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");

        foreach ($lines as $line) {
            fputcsv($stream, array_map(self::neutralizeFormula(...), $line), ';', '"', '', "\r\n");
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * Stops spreadsheet software from running a cell that starts like a formula (same rule as the student import).
     */
    private static function neutralizeFormula(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'{$value}" : $value;
    }
}
