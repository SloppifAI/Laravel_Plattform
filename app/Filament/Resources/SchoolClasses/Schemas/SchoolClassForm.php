<?php

namespace App\Filament\Resources\SchoolClasses\Schemas;

use App\Role;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class SchoolClassForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->placeholder('z. B. 7b')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Select::make('teacher_id')
                            ->label('Klassenleitung')
                            ->relationship('teacher', 'name', fn (Builder $query): Builder => $query->withRole(Role::Teacher))
                            ->searchable()
                            ->preload(),
                        TextInput::make('join_code')
                            ->label('Klassencode')
                            ->helperText('Diesen Code geben Schüler:innen bei der Registrierung ein. Mit „Neuen Code erzeugen“ oben rechts wird er ersetzt.')
                            ->copyable(copyMessage: 'Code kopiert')
                            ->disabled()
                            ->dehydrated(false)
                            ->visibleOn('edit'),
                    ])
                    ->columns(2),
            ]);
    }
}
