<?php

namespace App\Filament\Resources\Lessons\Schemas;

use App\Models\Competency;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
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
                Section::make(__('Unterrichtsstunde'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('school_class_id')
                            ->label(__('Klasse'))
                            // Admins: the classes of their school. Teachers: the classes they teach (see LessonPolicy::createFor) plus the
                            // class an existing lesson already sits in, so it stays editable after the assignment ended. Also on the
                            // server: a forged id fails validation.
                            ->relationship('schoolClass', 'name', fn (Builder $query, ?Lesson $record): Builder => static::placeableClasses($query, auth()->user(), $record))
                            ->searchable()
                            ->preload()
                            ->live()
                            // Released or used by students, the lesson's students and results belong to its class (LessonPolicy::changeClass). Not saved while disabled.
                            ->disabled(fn (?Lesson $record): bool => $record !== null && auth()->user()->cannot('changeClass', $record))
                            ->helperText(fn (Select $component): ?string => $component->isDisabled() ? __('Die Schüler:innen der Klasse haben diese Stunde schon bekommen, deshalb bleibt sie in ihrer Klasse.') : null)
                            ->required(),
                        DatePicker::make('held_on')
                            ->label(__('Gehalten am'))
                            ->default(today())
                            ->live()
                            ->required(),
                        TextInput::make('subject')
                            ->label(__('Fach'))
                            // Suggesting the framework's subjects keeps the competency mapping working.
                            ->datalist(fn (): array => Competency::query()->whereNotNull('subject')->distinct()->orderBy('subject')->pluck('subject')->all())
                            ->live(onBlur: true)
                            ->required()
                            ->maxLength(255),
                        TextInput::make('topic')
                            ->label(__('Thema'))
                            ->required()
                            ->maxLength(255),
                    ]),
                View::make('filament.resources.lessons.follow-up-panel')
                    ->columnSpanFull()
                    ->visible(fn (Get $get, ?Lesson $record): bool => static::previousLessonReview($get, $record) !== null)
                    ->viewData(fn (Get $get, ?Lesson $record): array => static::previousLessonReview($get, $record) ?? []),
                Section::make(__('Inhalt der Stunde'))
                    ->description(__('Daraus erstellt die KI Zusammenfassung und Hausaufgabe. Bitte keine personenbezogenen Daten eintragen.'))
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('notes')
                            ->label(__('Notizen'))
                            ->helperText(__('Stichpunkte, Tafelbild oder Notizen aus der Stunde'))
                            ->rows(12)
                            ->required(),
                        FileUpload::make('attachments')
                            ->label(__('Arbeitsblätter'))
                            ->helperText(__('Fotos oder Scans analoger Arbeitsblätter (JPG, PNG, WebP, HEIC oder PDF, max. 10 MB je Datei)'))
                            ->multiple()
                            ->disk('local')
                            ->directory('worksheets')
                            // Only formats the AI reads, so no worksheet is silently left out of the draft.
                            ->acceptedFileTypes([...Lesson::WORKSHEET_IMAGE_TYPES, 'application/pdf'])
                            // Browsers outside Apple's report HEIC photos without a type.
                            ->mimeTypeMap(['heic' => 'image/heic', 'heif' => 'image/heif'])
                            ->maxSize(10240)
                            ->openable()
                            ->downloadable()
                            // The local disk holds every teacher's worksheets, so only this lesson's paths may be submitted.
                            ->preventFilePathTampering(),
                    ]),
                Section::make(__('Zusammenfassung'))
                    ->description(__('Von der KI erstellt. Die Schüler:innen lesen sie vor den Fragen, du kannst sie vor der Freigabe anpassen.'))
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
     * @param  Builder<SchoolClass>  $query
     * @return Builder<SchoolClass>
     */
    private static function placeableClasses(Builder $query, User $user, ?Lesson $record): Builder
    {
        return $query
            ->where('school_classes.school_id', $user->school_id)
            ->unless($user->isAdmin(), fn (Builder $query): Builder => $query->where(fn (Builder $query) => $query
                ->taughtBy($user)
                ->when($record !== null, fn (Builder $query) => $query->orWhere('school_classes.id', $record->school_class_id))));
    }

    /**
     * What the class had not understood in the previous lesson of the chosen subject, to repeat at the start of this
     * one. Only lessons the acting user may review count (LessonReview checks that) and whose results they may read
     * (LessonPolicy::viewResults, like the starter board this panel links to); the lesson being edited is no "previous"
     * lesson of itself.
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

        if ($previous === null || $previous->is($record) || $user->cannot('viewResults', $previous)) {
            return null;
        }

        $points = LessonReview::forLesson($previous)['points'];

        return $points === [] ? null : ['previous' => $previous, 'points' => $points];
    }
}
