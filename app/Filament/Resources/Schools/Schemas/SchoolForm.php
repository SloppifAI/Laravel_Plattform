<?php

namespace App\Filament\Resources\Schools\Schemas;

use App\Models\School;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The master data of a school. The same fields serve the school registration, "Meine Schule" and the operator's edit page.
 */
class SchoolForm
{
    /**
     * @var list<string>
     */
    public const array SCHOOL_TYPES = [
        'Grundschule',
        'Hauptschule',
        'Realschule',
        'Gesamtschule',
        'Gymnasium',
        'Förderschule',
        'Berufskolleg/Berufsschule',
        'Sonstige',
    ];

    /**
     * @var list<string>
     */
    public const array STATES = [
        'Baden-Württemberg',
        'Bayern',
        'Berlin',
        'Brandenburg',
        'Bremen',
        'Hamburg',
        'Hessen',
        'Mecklenburg-Vorpommern',
        'Niedersachsen',
        'Nordrhein-Westfalen',
        'Rheinland-Pfalz',
        'Saarland',
        'Sachsen',
        'Sachsen-Anhalt',
        'Schleswig-Holstein',
        'Thüringen',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Stammdaten'))
                    ->schema(self::fields())
                    ->columns(2),
            ]);
    }

    /**
     * The German names are what gets stored, the labels follow the language of the user.
     *
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private static function translatedOptions(array $values): array
    {
        return array_combine($values, array_map(fn (string $value): string => __($value), $values));
    }

    /**
     * @return list<Component>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('name')
                ->label(__('Schulname'))
                ->required()
                ->maxLength(255),
            TextInput::make('school_number')
                ->label(__('Amtliche Schulnummer'))
                ->helperText(__('Freiwillig. Sie hilft uns, Doppelungen zu vermeiden.'))
                ->trim()
                ->maxLength(30)
                ->unique(School::class, 'school_number', ignoreRecord: true)
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? $state : null)
                ->validationMessages(['unique' => __('Zu dieser Schulnummer gibt es schon eine Schule auf der Plattform.')]),
            Select::make('school_type')
                ->label(__('Schulform'))
                ->options(self::translatedOptions(self::SCHOOL_TYPES))
                ->required(),
            TextInput::make('city')
                ->label(__('Ort'))
                ->required()
                ->maxLength(255),
            Select::make('state')
                ->label(__('Bundesland'))
                ->options(self::translatedOptions(self::STATES))
                ->required(),
            TextInput::make('contact_email')
                ->label(__('Kontakt-E-Mail der Schule'))
                ->email()
                ->required()
                ->maxLength(255),
        ];
    }
}
