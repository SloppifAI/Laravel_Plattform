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

        return $schema
            ->components([
                Section::make('Zugang')
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),
                        // Usernames are unique across all schools because the login has no school field.
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
                            ->options($roles->mapWithKeys(fn (Role $role): array => [$role->value => $role->getLabel()])->all())
                            ->descriptions($roles->mapWithKeys(fn (Role $role): array => [$role->value => $role->getDescription()])->all())
                            // Radio does not check the submitted value against its options, so a forged role is rejected here.
                            ->in($roles->pluck('value')->all())
                            ->default($isOperator ? Role::Admin->value : Role::Student->value)
                            ->required()
                            ->live()
                            // An operator account has no school, so switching between administration and operator would need a school change.
                            ->disabled(fn (string $operation): bool => $isOperator && $operation === 'edit')
                            ->helperText(fn (string $operation): ?string => $isOperator && $operation === 'edit' ? 'Die Rolle lässt sich nach dem Anlegen nicht mehr ändern.' : null),
                        Select::make('school_id')
                            ->label('Schule')
                            ->options(fn (): array => School::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            // Only operators choose a school, and only for a school administration. Everyone else's
                            // accounts get the school of the signed-in administrator in CreateUser.
                            ->visible(fn (Get $get): bool => $isOperator && $isSchoolAdmin($get))
                            ->required()
                            ->exists(School::class, 'id')
                            // Moving an account to another school is not offered.
                            ->disabledOn('edit'),
                        Select::make('school_class_id')
                            ->label('Klasse')
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
