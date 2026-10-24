<?php

namespace App\Filament\Resources\SchoolClasses\Schemas;

use App\Role;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class SchoolClassForm
{
    public static function configure(Schema $schema): Schema
    {
        $schoolId = auth()->user()?->school_id;

        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->placeholder(__('z. B. 7b'))
                            ->required()
                            ->maxLength(255)
                            // Class names only have to be unique within the school.
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('school_id', $schoolId)),
                        Select::make('teacher_id')
                            ->label(__('Klassenleitung'))
                            // Filament validates the submitted value against this query: only teachers of the own school pass.
                            ->relationship('teacher', 'name', fn (Builder $query): Builder => $query->withRole(Role::Teacher)->where('school_id', $schoolId))
                            ->searchable()
                            ->preload(),
                        TextInput::make('join_code')
                            ->label(__('Klassencode'))
                            ->helperText(__('Diesen Code geben Schüler:innen bei der Registrierung ein. Mit „Neuen Code erzeugen“ oben rechts wird er ersetzt.'))
                            ->copyable(copyMessage: __('Code kopiert'))
                            ->disabled()
                            ->dehydrated(false)
                            ->visibleOn('edit'),
                    ])
                    ->columns(2),
            ]);
    }
}
