<?php

namespace App\Filament\Resources\ExplanationSessions\Schemas;

use App\Filament\Resources\ExplanationSessions\ExplanationSessionResource;
use App\Models\ExplanationSession;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ExplanationSessionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Sitzung')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('student.name')
                            ->label('Schüler:in'),
                        TextEntry::make('student.schoolClass.name')
                            ->label('Klasse')
                            ->placeholder('–'),
                        TextEntry::make('topic')
                            ->label('Thema'),
                        TextEntry::make('lesson')
                            ->label('Unterrichtsstunde')
                            ->state(fn (ExplanationSession $record): ?string => $record->lesson ? "{$record->lesson->subject}: {$record->lesson->topic}" : null)
                            ->placeholder('Ohne Unterrichtsstunde'),
                        TextEntry::make('created_at')
                            ->label('Gestartet')
                            ->dateTime('d.m.Y H:i'),
                        ExplanationSessionResource::asStatusBadge(TextEntry::make('status')->label('Status')),
                        ExplanationSessionResource::asUnderstandingBadge(TextEntry::make('understanding_score')->label('Verständnis laut KI')),
                        TextEntry::make('feedback')
                            ->label('Rückmeldung der KI')
                            ->placeholder('Noch keine Rückmeldung, die Erklärung läuft noch.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Gesprächsverlauf')
                    ->columnSpanFull()
                    ->schema([
                        ViewEntry::make('messages')
                            ->hiddenLabel()
                            ->view('filament.resources.explanation-sessions.transcript'),
                    ]),
            ]);
    }
}
