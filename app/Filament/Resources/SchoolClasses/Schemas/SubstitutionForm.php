<?php

namespace App\Filament\Resources\SchoolClasses\Schemas;

use App\Models\ClassSubstitution;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use Carbon\Carbon;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The fields for setting up a substitution, shared by the administration (on the class) and the Klassenleitung (page
 * "Vertretung"). Everything is validated on the server: the substitute must be a teacher of the class's school (a
 * forged id of anybody else fails the Select's own check against the options), the period must not start in the past
 * and lasts at most ClassSubstitution::maxDays() days; together with the teacher's other running or planned
 * substitutions in the class it may not exceed that either, so chaining periods cannot turn it into an assignment.
 */
class SubstitutionForm
{
    /**
     * @param  Closure(Get): ?SchoolClass  $schoolClass  the class the substitution is for, e.g. the owner record or the one chosen in the form
     * @return list<Select|DatePicker|Textarea>
     */
    public static function components(Closure $schoolClass): array
    {
        $maxDays = ClassSubstitution::maxDays();

        return [
            Select::make('substitute_id')
                ->label(__('Wer vertritt?'))
                ->options(fn (Get $get): array => static::substituteOptions($schoolClass($get)))
                ->searchable()
                ->required()
                ->helperText(__('Lehrkräfte der Schule. Die Klassenleitung und bereits zugeordnete Lehrkräfte unterrichten die Klasse ohnehin. Im Zeitraum kann die Lehrkraft :grants.', ['grants' => ClassSubstitution::grants()])),
            DatePicker::make('starts_on')
                ->label(__('Erster Tag'))
                ->default(today())
                ->required()
                ->native(false)
                ->displayFormat('d.m.Y')
                ->minDate(today())
                ->live(),
            DatePicker::make('ends_on')
                ->label(__('Letzter Tag'))
                ->required()
                ->native(false)
                ->displayFormat('d.m.Y')
                ->minDate(fn (Get $get): Carbon => static::startOf($get) ?? today())
                ->maxDate(fn (Get $get): ?Carbon => static::startOf($get)?->addDays($maxDays - 1))
                ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($schoolClass, $get, $maxDays): void {
                    $startsOn = static::startOf($get);
                    $class = $schoolClass($get);

                    if ($startsOn === null || blank($value) || $class === null || blank($get('substitute_id'))) {
                        return;
                    }

                    $planned = ClassSubstitution::daysPlanned($class, (int) $get('substitute_id'));
                    $requested = ClassSubstitution::countDays($startsOn, Carbon::parse($value));

                    if ($planned + $requested > $maxDays) {
                        $fail(__('Diese Lehrkraft vertritt die Klasse schon :planned (laufend oder geplant). Zusammen dürfen es höchstens :max sein. Für länger kann die Schuladministration die Lehrkraft der Klasse fest zuordnen.', [
                            'planned' => trans_choice(':count Tag|:count Tage', $planned),
                            'max' => trans_choice(':count Tag|:count Tage', $maxDays),
                        ]));
                    }
                })
                ->validationMessages([
                    'after_or_equal' => __('Der letzte Tag darf nicht vor dem ersten Tag liegen.'),
                    'before_or_equal' => __('Eine Vertretung kann höchstens :days dauern. Danach kann die Schuladministration die Lehrkraft der Klasse fest zuordnen.', ['days' => trans_choice(':count Tag|:count Tage', $maxDays)]),
                ]),
            Textarea::make('note')
                ->label(__('Hinweis zur Übergabe'))
                ->helperText(__('Was soll die Vertretung wissen? Zum Beispiel Stand im Stoff, Absprachen, Besonderheiten der Klasse. Der Hinweis steht in der Übergabe-Mappe. Bitte keine Namen oder persönlichen Angaben zu einzelnen Schüler:innen eintragen.'))
                ->rows(4)
                ->maxLength(1000)
                ->columnSpanFull(),
        ];
    }

    /**
     * The teachers of the class's school who may be named as substitute: not the Klassenleitung and nobody who is
     * assigned to the class anyway.
     *
     * @return array<int, string> names by user id
     */
    public static function substituteOptions(?SchoolClass $schoolClass): array
    {
        if ($schoolClass === null || $schoolClass->school_id === null) {
            return [];
        }

        return User::query()
            ->withRole(Role::Teacher)
            ->where('school_id', $schoolClass->school_id)
            ->when($schoolClass->teacher_id, fn ($query, int $leaderId) => $query->whereKeyNot($leaderId))
            ->whereDoesntHave('taughtClasses', fn ($classes) => $classes->whereKey($schoolClass->getKey()))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function startOf(Get $get): ?Carbon
    {
        $startsOn = $get('starts_on');

        return filled($startsOn) ? Carbon::parse($startsOn)->startOfDay() : null;
    }
}
