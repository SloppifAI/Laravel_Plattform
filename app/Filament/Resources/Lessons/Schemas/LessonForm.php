<?php

namespace App\Filament\Resources\Lessons\Schemas;

use App\Models\Competency;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Support\LessonReview;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

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
                            // Only classes of the own school, also on the server: a forged id of another school fails validation.
                            ->relationship('schoolClass', 'name', fn (Builder $query): Builder => $query->where('school_id', auth()->user()->school_id))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->required(),
                        DatePicker::make('held_on')
                            ->label('Gehalten am')
                            ->default(today())
                            ->live()
                            ->required(),
                        TextInput::make('subject')
                            ->label('Fach')
                            // Suggesting the framework's subjects keeps the competency mapping working.
                            ->datalist(fn (): array => Competency::query()->whereNotNull('subject')->distinct()->orderBy('subject')->pluck('subject')->all())
                            ->live(onBlur: true)
                            ->required()
                            ->maxLength(255),
                        TextInput::make('topic')
                            ->label('Thema')
                            ->required()
                            ->maxLength(255),
                    ]),
                View::make('filament.resources.lessons.follow-up-panel')
                    ->columnSpanFull()
                    ->visible(fn (Get $get, ?Lesson $record): bool => static::previousLessonReview($get, $record) !== null)
                    ->viewData(fn (Get $get, ?Lesson $record): array => static::previousLessonReview($get, $record) ?? []),
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

    /**
     * What the class had not understood in the previous lesson of the chosen subject, to repeat at the start of this
     * one. Only lessons the acting user may review count (LessonReview checks that); the lesson being edited is no
     * "previous" lesson of itself.
     *
     * @return array{previous: Lesson, points: list<array<string, mixed>>}|null
     */
    private static function previousLessonReview(Get $get, ?Lesson $record): ?array
    {
        $user = auth()->user();
        $subject = trim((string) $get('subject'));
        $heldOn = rescue(fn (): ?Carbon => filled($get('held_on')) ? Carbon::parse($get('held_on')) : null, report: false);
        $class = filled($get('school_class_id'))
            ? SchoolClass::query()->where('school_id', $user->school_id)->whereKey($get('school_class_id'))->first()
            : null;

        if ($class === null || $subject === '' || $heldOn === null) {
            return null;
        }

        $previous = LessonReview::previousLessonFor($class, $subject, $heldOn, $user);

        if ($previous === null || $previous->is($record)) {
            return null;
        }

        $points = LessonReview::forLesson($previous)['points'];

        return $points === [] ? null : ['previous' => $previous, 'points' => $points];
    }
}
