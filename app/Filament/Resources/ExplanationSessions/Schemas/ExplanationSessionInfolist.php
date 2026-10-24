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
                Section::make(__('Sitzung'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('student.name')
                            ->label(__('Schüler:in')),
                        TextEntry::make('student.schoolClass.name')
                            ->label(__('Klasse'))
                            ->placeholder('–'),
                        TextEntry::make('topic')
                            ->label(__('Thema')),
                        TextEntry::make('lesson')
                            ->label(__('Unterrichtsstunde'))
                            ->state(fn (ExplanationSession $record): ?string => $record->lesson ? "{$record->lesson->subject}: {$record->lesson->topic}" : null)
                            ->placeholder(__('Ohne Unterrichtsstunde')),
                        TextEntry::make('created_at')
                            ->label(__('Gestartet'))
                            ->dateTime('d.m.Y H:i'),
                        ExplanationSessionResource::asStatusBadge(TextEntry::make('status')->label(__('Status'))),
                        ExplanationSessionResource::asUnderstandingBadge(TextEntry::make('understanding_score')->label(__('Verständnis laut KI'))),
                        TextEntry::make('feedback')
                            ->label(__('Rückmeldung der KI'))
                            ->placeholder(__('Noch keine Rückmeldung, die Erklärung läuft noch.'))
                            ->columnSpanFull(),
                    ]),
                Section::make(__('Gesprächsverlauf'))
                    ->columnSpanFull()
                    ->schema([
                        ViewEntry::make('messages')
                            ->hiddenLabel()
                            ->view('filament.resources.explanation-sessions.transcript'),
                    ]),
            ]);
    }
}
