<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Filament\Resources\Users\UserResource;
use App\Models\School;
use App\Models\SchoolClass;
use App\Role;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        $actor = auth()->user();
        $isOperator = $actor?->isOperator() ?? false;
        $schoolId = $actor?->school_id;
        $roles = collect(UserResource::assignableRoles());

        $roleOf = fn (Get $get): ?Role => $get->enum('role', Role::class, isNullable: true);
        $isStudent = fn (Get $get): bool => $roleOf($get) === Role::Student;
        $isSchoolAdmin = fn (Get $get): bool => $roleOf($get) === Role::Admin;
        // Whoever sets the password or the e-mail address (the reset link goes there) of a school's administration can sign in
        // as it and read the school's learning data, which the operator must not see. So the operator leaves both to the school.
        $operatorEditsSchoolAccount = fn (?Model $record): bool => $isOperator && $record?->school_id !== null;
        // A school that has an administration runs its own accounts, so the operator only sets up the first one. An existing
        // account keeps its school (and shows it), so only the choice for a new account is narrowed.
        $schoolsToChoose = fn (Builder $query, string $operation): Builder => $query->when($operation === 'create', fn (Builder $schools) => $schools->withoutAdministration());

        return $schema
            ->components([
                Section::make(__('Zugang'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255),
                        // Usernames are unique across all schools because the login has no school field.
                        TextInput::make('username')
                            ->label(__('Benutzername'))
                            ->helperText(__('Damit meldet sich die Person an.'))
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('email')
                            ->label(__('E-Mail (optional)'))
                            ->email()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->disabled($operatorEditsSchoolAccount)
                            ->helperText(fn (?Model $record): ?string => $operatorEditsSchoolAccount($record) ? __('E-Mail-Adresse und Passwort ändert die Schuladministration selbst. Wer sich ausgesperrt hat, nutzt „Passwort vergessen?“ auf der Anmeldeseite.') : null),
                        TextInput::make('password')
                            ->label(__('Passwort'))
                            ->hidden($operatorEditsSchoolAccount)
                            ->password()
                            ->revealable()
                            ->rule(Password::default())
                            ->required(fn (string $operation): bool => $operation === 'create')
                            // The model casts the password to 'hashed', so it is stored as entered here.
                            ->saved(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $operation): ?string => $operation === 'edit' ? __('Leer lassen, um das bisherige Passwort zu behalten.') : null),
                    ])
                    ->columns(2),
                Section::make(__('Rolle'))
                    ->schema([
                        Radio::make('role')
                            ->label(__('Rolle'))
                            ->hiddenLabel()
                            ->options($roles->mapWithKeys(fn (Role $role): array => [$role->value => $role->getLabel()])->all())
                            ->descriptions($roles->mapWithKeys(fn (Role $role): array => [$role->value => $role->getDescription()])->all())
                            // Radio does not check the submitted value against its options, so a forged role is rejected here.
                            ->in($roles->pluck('value')->all())
                            ->default($isOperator ? Role::Admin->value : Role::Student->value)
                            ->required()
                            ->live()
                            // An operator account has no school, so switching between administration and operator would need a school change.
                            ->disabled(fn (string $operation): bool => $isOperator && $operation === 'edit')
                            ->helperText(fn (string $operation): ?string => $isOperator && $operation === 'edit' ? __('Die Rolle lässt sich nach dem Anlegen nicht mehr ändern.') : null),
                        Select::make('school_id')
                            ->label(__('Schule'))
                            ->options(fn (string $operation): array => $schoolsToChoose(School::query(), $operation)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            // Only operators choose a school, and only for a school administration. Everyone else's
                            // accounts get the school of the signed-in administrator in CreateUser.
                            ->visible(fn (Get $get): bool => $isOperator && $isSchoolAdmin($get))
                            ->required()
                            ->scopedExists(School::class, 'id', $schoolsToChoose)
                            ->helperText(fn (string $operation): ?string => $operation === 'create' ? __('Nur Schulen ohne Schuladministration. Weitere Konten legt die Schuladministration selbst an.') : null)
                            // Moving an account to another school is not offered.
                            ->disabledOn('edit'),
                        Select::make('school_class_id')
                            ->label(__('Klasse'))
                            ->options(fn (): array => SchoolClass::query()->where('school_id', $schoolId)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->visible($isStudent)
                            ->required($isStudent)
                            ->scopedExists(SchoolClass::class, 'id', fn (Builder $query): Builder => $query->where('school_id', $schoolId))
                            // Only students belong to a class, so switching the role away from student clears it.
                            ->dehydratedWhenHidden()
                            ->dehydrateStateUsing(fn (mixed $state, Get $get): mixed => $isStudent($get) ? $state : null),
                    ]),
            ]);
    }
}
