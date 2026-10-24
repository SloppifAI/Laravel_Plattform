<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\SchoolClass;
use App\Role;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        $isStudent = fn (Get $get): bool => $get->enum('role', Role::class, isNullable: true) === Role::Student;

        return $schema
            ->components([
                Section::make('Zugang')
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('username')
                            ->label('Benutzername')
                            ->helperText('Damit meldet sich die Person an.')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('email')
                            ->label('E-Mail (optional)')
                            ->email()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->label('Passwort')
                            ->password()
                            ->revealable()
                            ->rule(Password::default())
                            ->required(fn (string $operation): bool => $operation === 'create')
                            // The model casts the password to 'hashed', so it is stored as entered here.
                            ->saved(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Leer lassen, um das bisherige Passwort zu behalten.' : null),
                    ])
                    ->columns(2),
                Section::make('Rolle')
                    ->schema([
                        Radio::make('role')
                            ->label('Rolle')
                            ->hiddenLabel()
                            ->options(Role::class)
                            ->default(Role::Student)
                            ->required()
                            ->live(),
                        Select::make('school_class_id')
                            ->label('Klasse')
                            ->options(fn (): array => SchoolClass::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->visible($isStudent)
                            ->required($isStudent)
                            // Only students belong to a class, so switching the role away from student clears it.
                            ->dehydratedWhenHidden()
                            ->dehydrateStateUsing(fn (mixed $state, Get $get): mixed => $isStudent($get) ? $state : null),
                    ]),
            ]);
    }
}
