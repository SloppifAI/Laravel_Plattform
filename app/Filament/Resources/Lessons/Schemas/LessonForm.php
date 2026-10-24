<?php

namespace App\Filament\Resources\Lessons\Schemas;

use App\Models\Competency;
use App\Models\Lesson;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LessonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Unterrichtsstunde')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('school_class_id')
                            ->label('Klasse')
                            ->relationship('schoolClass', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        DatePicker::make('held_on')
                            ->label('Gehalten am')
                            ->default(today())
                            ->required(),
                        TextInput::make('subject')
                            ->label('Fach')
                            // Suggesting the framework's subjects keeps the competency mapping working.
                            ->datalist(fn (): array => Competency::query()->whereNotNull('subject')->distinct()->orderBy('subject')->pluck('subject')->all())
                            ->required()
                            ->maxLength(255),
                        TextInput::make('topic')
                            ->label('Thema')
                            ->required()
                            ->maxLength(255),
                    ]),
                Section::make('Inhalt der Stunde')
                    ->description('Daraus erstellt die KI Zusammenfassung und Hausaufgabe. Bitte keine Tonaufnahmen und keine personenbezogenen Daten.')
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('notes')
                            ->label('Notizen')
                            ->helperText('Stichpunkte, Tafelbild oder Transkript')
                            ->rows(12)
                            ->required(),
                        FileUpload::make('attachments')
                            ->label('Arbeitsblätter')
                            ->helperText('Fotos oder Scans analoger Arbeitsblätter (Bilder oder PDF, max. 10 MB je Datei)')
                            ->multiple()
                            ->disk('local')
                            ->directory('worksheets')
                            ->acceptedFileTypes(['image/*', 'application/pdf'])
                            ->maxSize(10240)
                            ->openable()
                            ->downloadable()
                            // The local disk holds every teacher's worksheets, so only this lesson's paths may be submitted.
                            ->preventFilePathTampering(),
                    ]),
                Section::make('Zusammenfassung')
                    ->description('Von der KI erstellt. Du kannst sie vor dem Verteilen anpassen.')
                    ->columnSpanFull()
                    ->visible(fn (?Lesson $record): bool => filled($record?->summary))
                    ->schema([
                        Textarea::make('summary')
                            ->hiddenLabel()
                            ->rows(8),
                    ]),
            ]);
    }
}
