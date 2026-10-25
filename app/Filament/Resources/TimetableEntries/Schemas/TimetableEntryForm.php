<?php

namespace App\Filament\Resources\TimetableEntries\Schemas;

use App\Models\SchoolClass;
use App\Models\TimetableEntry;
use App\Models\User;
use App\Role;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

/**
 * One slot of the timetable, in the school of the signed-in administration. Everything is checked on the server: class
 * and teacher must belong to that school (a forged id fails the Select's own check against the options), a class has
 * one entry per slot, and a teacher cannot be entered in two classes at the same time.
 */
class TimetableEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        $schoolId = auth()->user()?->school_id;

        return $schema
            ->components([
                Grid::make(['default' => 1, 'sm' => 2])
                    ->schema([
                        Select::make('school_class_id')
                            ->label(__('Klasse'))
                            ->options(fn (): array => SchoolClass::query()
                                ->where('school_id', $schoolId ?? 0)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required(),
                        TextInput::make('subject')
                            ->label(__('Fach'))
                            ->placeholder(__('z. B. Mathematik'))
                            ->datalist(fn (): array => static::subjects($schoolId))
                            ->required()
                            ->maxLength(100),
                        Select::make('weekday')
                            ->label(__('Wochentag'))
                            ->options(TimetableEntry::weekdays())
                            ->required(),
                        Select::make('period')
                            ->label(__('Stunde'))
                            ->options(fn (): array => collect(TimetableEntry::periods())
                                ->mapWithKeys(fn (array $times, int $period): array => [$period => TimetableEntry::periodLabel($period)])
                                ->all())
                            ->required()
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                                ->where('school_class_id', $get('school_class_id'))
                                ->where('weekday', $get('weekday')))
                            ->validationMessages(['unique' => __('Die Klasse hat in dieser Stunde schon ein Fach. Bearbeite den vorhandenen Eintrag.')]),
                        Select::make('teacher_id')
                            ->label(__('Lehrkraft'))
                            ->options(fn (): array => User::query()
                                ->withRole(Role::Teacher)
                                ->where('school_id', $schoolId ?? 0)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->placeholder(__('Noch offen'))
                            ->rule(fn (Get $get, ?TimetableEntry $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record, $schoolId): void {
                                if (blank($value) || blank($get('weekday')) || blank($get('period'))) {
                                    return;
                                }

                                // Within the school: the message names the other class, which must not be one of another school.
                                $clash = TimetableEntry::query()
                                    ->ofSchool($schoolId)
                                    ->with('schoolClass')
                                    ->where('teacher_id', $value)
                                    ->where('weekday', $get('weekday'))
                                    ->where('period', $get('period'))
                                    ->when($record, fn ($query, TimetableEntry $record) => $query->whereKeyNot($record->getKey()))
                                    ->first();

                                if ($clash !== null) {
                                    $fail(__('Die Lehrkraft unterrichtet in dieser Stunde schon die Klasse :class.', ['class' => $clash->schoolClass->name]));
                                }
                            })
                            ->helperText(__('Der Eintrag gibt der Lehrkraft keinen Zugriff auf die Klasse. Dafür ordnet die Schuladministration sie der Klasse zu.')),
                        TextInput::make('room')
                            ->label(__('Raum'))
                            ->placeholder(__('z. B. R 104'))
                            ->maxLength(50),
                    ]),
            ]);
    }

    /**
     * The subjects the school's timetable already uses, as suggestions so the same subject is spelled the same way.
     *
     * @return array<int, string>
     */
    private static function subjects(?int $schoolId): array
    {
        return TimetableEntry::query()
            ->ofSchool($schoolId)
            ->distinct()
            ->orderBy('subject')
            ->pluck('subject')
            ->all();
    }
}
