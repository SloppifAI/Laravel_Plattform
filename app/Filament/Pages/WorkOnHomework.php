<?php

namespace App\Filament\Pages;

use App\Ai\LessonAssistant;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\StudentQuestion;
use App\Models\Submission;
use App\Models\User;
use App\QuestionLevel;
use App\Support\AskTeacher;
use App\Support\MistakeDiagnosis;
use App\Support\Notify;
use App\Support\ReviewSchedule;
use App\UnderstandingLevel;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * One homework for the logged-in student: read the teacher's summary of the lesson, answer the questions, write an own
 * summary, hand in once. The AI checks the hand-in; the results page shows the teacher's summary once more.
 */
class WorkOnHomework extends Page
{
    public const int MINIMUM_SUMMARY_LENGTH = 80;

    /**
     * A Förderaufgabe is a short practice for one competency, not a whole lesson to summarise.
     */
    public const int MINIMUM_PRACTICE_SUMMARY_LENGTH = 40;

    /**
     * Texts with fewer words are not checked for copying: a short answer may well repeat a phrase of the summary.
     */
    public const int COPY_MINIMUM_WORDS = 12;

    /**
     * Share of a text's word quadruples found in the teacher's summary from which the text counts as copied.
     */
    public const float COPY_SHARE = 0.7;

    protected static ?string $slug = 'hausaufgaben/{lesson}';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public Lesson $lesson;

    /**
     * @var array{summary?: string|null, answers?: array<int, string|null>, levels?: array<int, string|null>}|null
     */
    public ?array $data = [];

    /**
     * Shows the teacher's summary and questions in Einfache Sprache. The switch remembers itself in the student's browser.
     */
    public bool $simpleLanguage = false;

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->isStudent() ?? false;
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'hausaufgaben.bearbeiten';
    }

    /**
     * The lesson is a public property: on every later request (switch, level choice, hand-in) re-check that it is
     * still delegated to this student, before anything is sent to the assistant or stored.
     */
    public function hydrate(): void
    {
        Gate::authorize('view', $this->lesson);
    }

    public function mount(Lesson $lesson): void
    {
        // Custom pages don't apply policies: only published homework delegated to this student may be opened.
        Gate::authorize('view', $lesson);

        $this->lesson = $lesson;
        $this->form->fill();
    }

    public function getTitle(): string
    {
        return $this->lesson->topic;
    }

    public function getSubheading(): string
    {
        return collect([
            $this->lesson->subject,
            $this->lesson->isPracticeTask() ? __('Förderaufgabe zu „:competency“', ['competency' => $this->competencyName()]) : __('Stunde vom :date', ['date' => $this->lesson->held_on->format('d.m.Y')]),
            $this->lesson->due_on ? __('Abgabe bis :date', ['date' => $this->lesson->due_on->format('d.m.Y')]) : null,
        ])->filter()->implode(' · ');
    }

    /**
     * @return array<string>
     */
    public function getBreadcrumbs(): array
    {
        return [MyHomework::getUrl() => __('Meine Hausaufgaben'), $this->lesson->isPracticeTask() ? __('Förderaufgabe') : __('Hausaufgabe')];
    }

    /**
     * The questions first, the student's own summary last: the teacher's summary above them is the material to work with.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                ...$this->lesson->questions->values()->map(fn (Question $question, int $index): Section => Section::make(__('Frage :number', ['number' => $index + 1]))
                    ->description($question->type->getDescription())
                    ->afterHeader([$this->typeBadge($question)])
                    ->schema([
                        ToggleButtons::make("levels.{$question->id}")
                            ->label(__('Stufe'))
                            ->helperText(__('Such dir die Fassung aus, die zu dir passt. Du kannst jederzeit wechseln.'))
                            ->options(collect($question->availableLevels())->mapWithKeys(fn (QuestionLevel $level): array => [$level->value => $level->getLabel()])->all())
                            ->default(QuestionLevel::Standard->value)
                            ->inline()
                            ->live()
                            ->visible($question->hasLevels()),
                        Textarea::make("answers.{$question->id}")
                            ->label(fn (Get $get): ?string => $this->promptText($question, $question->levelFor($get("levels.{$question->id}"))))
                            ->required()
                            ->maxLength(3000)
                            ->rows(4)
                            ->autosize()
                            ->rules([fn (): Closure => $this->notCopiedFromTheTeacherSummary()])
                            ->validationMessages(['required' => __('Bitte beantworte diese Frage.')]),
                    ]))->all(),
                Section::make(__('In deinen Worten'))
                    ->description($this->lesson->isPracticeTask()
                        ? __('Schreib es so, dass es jemand versteht, der das Thema nicht kennt. Nicht abschreiben, sondern in deinen eigenen Worten.')
                        : __('Was war wichtig in der Stunde? Schreib es so, dass es jemand versteht, der nicht dabei war. Nicht abschreiben, sondern in deinen eigenen Worten.'))
                    ->schema([
                        Textarea::make('summary')
                            ->label($this->lesson->isPracticeTask() ? __('Was du jetzt über „:competency“ weißt', ['competency' => $this->competencyName()]) : __('Zusammenfassung in deinen Worten'))
                            ->helperText(__('Mindestens :count Zeichen. Ein paar ganze Sätze reichen.', ['count' => $this->minimumSummaryLength()]))
                            ->required()
                            ->minLength($this->minimumSummaryLength())
                            ->maxLength(5000)
                            ->rows(6)
                            ->autosize()
                            ->rules([fn (): Closure => $this->notCopiedFromTheTeacherSummary()])
                            ->validationMessages([
                                'required' => __('Bitte schreib deine Zusammenfassung.'),
                                'min' => __('Schreib bitte etwas mehr, mindestens :min Zeichen.'),
                            ]),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        $submission = $this->submission();

        if ($submission !== null) {
            return $schema->components([$this->simpleLanguageSwitch(), ...$this->resultComponents($submission)]);
        }

        return $schema->components([
            $this->simpleLanguageSwitch(),
            Callout::make($this->lesson->isPracticeTask()
                ? __('Schreib in ein paar Sätzen auf, was du jetzt über „:competency“ weißt – die KI prüft danach, ob du es verstanden hast.', ['competency' => $this->competencyName()])
                : __('Fasse die Stunde in deinen eigenen Worten zusammen – die KI prüft danach, ob du alles verstanden hast.'))
                ->description(__('So gehst du vor: Lies zuerst die Zusammenfassung deiner Lehrkraft, beantworte dann die Fragen und schreib deine eigene Zusammenfassung ganz unten.'))
                ->info()
                ->icon(Heroicon::OutlinedLightBulb),
            Section::make($this->lesson->isPracticeTask() ? __('Zusammenfassung zu „:competency“', ['competency' => $this->competencyName()]) : __('Zusammenfassung der Stunde'))
                ->icon(Heroicon::OutlinedAcademicCap)
                ->schema([$this->teacherSummary()]),
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('submit')
                ->footer([
                    Actions::make([
                        Action::make('submit')
                            ->label(__('Abgeben und prüfen lassen'))
                            ->icon(Heroicon::OutlinedPaperAirplane)
                            ->submit('submit'),
                    ]),
                    Text::make(__('Du kannst nur einmal abgeben. Lies deine Antworten vorher in Ruhe durch.')),
                ]),
        ]);
    }

    public function submit(): void
    {
        Gate::authorize('create', Submission::class);

        $data = $this->form->getState();

        try {
            $submission = DB::transaction(function () use ($data): Submission {
                $submission = $this->lesson->submissions()->create([
                    'student_id' => $this->student()->getKey(),
                    'summary' => $data['summary'],
                ]);

                $submission->answers()->createMany($this->lesson->questions->map(fn (Question $question): array => [
                    'question_id' => $question->getKey(),
                    'answer' => $data['answers'][$question->getKey()],
                    // Only a question with levels records which one was chosen; a level the question does not have counts as Standard.
                    'level' => $question->hasLevels() ? $question->levelFor($data['levels'][$question->getKey()] ?? null)->value : null,
                ]));

                return $submission;
            });
        } catch (UniqueConstraintViolationException) {
            // A second request (double click, other tab) handed in first: show that hand-in instead of a duplicate.
            $this->redirect(static::getUrl(['lesson' => $this->lesson]));

            return;
        }

        // The hand-in is stored: a failing bell (e.g. a deadlock when a whole class hands in) must not turn it into an error.
        try {
            Notify::submissionReceived($submission);
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->evaluateAndNotify($submission);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('evaluate')
                ->label(__('Jetzt auswerten lassen'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (): bool => $this->submission()?->isEvaluated() === false)
                ->action(fn () => $this->evaluateAndNotify($this->submission())),
            // A hidden action cannot be mounted or called, so no question can be asked before the evaluation.
            AskTeacher::action(function (): array {
                Gate::authorize('createForLesson', [StudentQuestion::class, $this->lesson]);

                return [$this->lesson, null];
            })->visible(fn (): bool => $this->submission()?->isEvaluated() === true
                && $this->student()->can('createForLesson', [StudentQuestion::class, $this->lesson])),
            Action::make('explain')
                ->label(__('Thema :tutor erklären', ['tutor' => ExplainIt::TUTOR_NAME]))
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->color('gray')
                ->url(fn (): string => ExplainIt::getUrl(['lesson' => $this->lesson->getKey()])),
        ];
    }

    private function evaluateAndNotify(Submission $submission): void
    {
        if ($this->evaluate($submission)) {
            Notification::make()
                ->title(__('Geschafft!'))
                ->body($this->lesson->isPracticeTask()
                    ? __('Die KI hat deine Förderaufgabe geprüft. Schau dir an, wie es gelaufen ist.')
                    : __('Die KI hat deine Hausaufgabe geprüft. Schau dir an, wie es gelaufen ist.'))
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(__('Abgegeben – die Auswertung kommt später'))
                ->body(__('Deine Antworten sind sicher gespeichert. Die KI konnte sie gerade nicht prüfen.'))
                ->warning()
                ->send();
        }

        // Reload so the page switches to the result view.
        $this->redirect(static::getUrl(['lesson' => $this->lesson]));
    }

    /**
     * Runs the AI check and stores it. On failure the submission stays unevaluated, so nothing the student wrote is lost.
     */
    private function evaluate(Submission $submission): bool
    {
        try {
            $evaluation = app(LessonAssistant::class)->evaluateSubmission($submission->load('answers'));

            DB::transaction(function () use ($submission, $evaluation): void {
                $submission->update([
                    'understanding_score' => Number::clamp($evaluation['understanding_score'], 0, 100),
                    'feedback' => $evaluation['feedback'],
                    'gaps' => $evaluation['gaps'],
                    'evaluated_at' => now(),
                ]);

                foreach ($submission->answers as $answer) {
                    $result = $evaluation['answers'][$answer->question_id] ?? null;

                    if ($result !== null) {
                        $answer->update([
                            'score' => Number::clamp($result['score'], 0, 100),
                            'feedback' => $result['feedback'],
                        ]);
                    }
                }
            });

            // The evaluation stands: revising the questions later ("Heute wiederholen") must never turn it into a failure.
            rescue(fn () => ReviewSchedule::enqueueFor($submission));

            // Only a hint, stored after the evaluation: it can neither undo nor delay it (fail closed).
            rescue(fn () => MistakeDiagnosis::record($submission, $evaluation['answers']));
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return true;
    }

    /**
     * @return list<Component>
     */
    private function resultComponents(Submission $submission): array
    {
        $answers = $submission->answers->keyBy('question_id');

        return [
            $submission->isEvaluated()
                ? Section::make(__('Deine Auswertung'))
                    ->icon(Heroicon::OutlinedSparkles)
                    ->afterHeader([$this->scoreBadge($submission->understanding_score)])
                    ->schema([$this->plainText('feedback', $submission->feedback, __('Rückmeldung der KI'))->hiddenLabel()])
                : Callout::make(__('Deine Abgabe wird noch ausgewertet'))
                    ->description(__('Deine Antworten sind gespeichert. Sobald die KI sie geprüft hat, siehst du hier deine Auswertung.'))
                    ->warning()
                    ->icon(Heroicon::OutlinedClock),
            Section::make(__('Darauf solltest du noch einmal schauen'))
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->visible(filled($submission->gaps))
                ->schema([UnorderedList::make($submission->gaps ?? [])->columns(1)]),
            Section::make(__('Deine Zusammenfassung'))
                ->schema([$this->plainText('summary', $submission->summary, __('Deine Zusammenfassung'))->hiddenLabel()]),
            ...$this->lesson->questions->values()->map(function (Question $question, int $index) use ($answers): Section {
                $answer = $answers->get($question->getKey());
                $level = $answer?->level === null ? QuestionLevel::Standard : $question->levelFor($answer->level);

                return Section::make(__('Frage :number', ['number' => $index + 1]))
                    ->afterHeader(array_filter([
                        $this->typeBadge($question),
                        $level === QuestionLevel::Standard ? null : Text::make(__('Stufe :level', ['level' => $level->getLabel()]))->badge()->color('gray'),
                        $answer?->score === null ? null : $this->scoreBadge($answer->score),
                    ]))
                    ->schema([
                        Text::make($this->promptText($question, $level)),
                        $this->plainText("answer_{$question->getKey()}", $answer?->answer, __('Deine Antwort')),
                        $this->plainText("answer_feedback_{$question->getKey()}", $answer?->feedback, __('Rückmeldung'))
                            ->visible(filled($answer?->feedback)),
                        // The tip for the kind of mistake (Fehlerdiagnose), never its label: it speaks about the answer, not the student.
                        Callout::make(__('Tipp für dich'))
                            ->description($answer?->mistake_type?->studentHint())
                            ->info()
                            ->icon(Heroicon::OutlinedLightBulb)
                            ->visible($answer?->mistake_type !== null),
                    ]);
            })->all(),
            Section::make($this->lesson->isPracticeTask() ? __('So fasst deine Lehrkraft „:competency“ zusammen', ['competency' => $this->competencyName()]) : __('So fasst deine Lehrkraft die Stunde zusammen'))
                ->icon(Heroicon::OutlinedAcademicCap)
                ->schema([$this->teacherSummary()]),
        ];
    }

    /**
     * The teacher's official summary, shown before the questions and again with the result.
     */
    private function teacherSummary(): TextEntry
    {
        return TextEntry::make('lesson_summary')
            ->label(__('Zusammenfassung deiner Lehrkraft'))
            ->hiddenLabel()
            ->state(fn (): ?string => $this->simplified($this->lesson, 'summary_simple', $this->lesson->summary))
            ->markdown()
            ->placeholder(__('Deine Lehrkraft hat noch keine Zusammenfassung hinterlegt.'));
    }

    private function simpleLanguageSwitch(): View
    {
        return View::make('filament.pages.homework-simple-language')
            ->viewData(['storageKey' => 'tuto-einfache-sprache-'.$this->student()->getKey(), 'aiActive' => $this->lesson->school?->aiEnabled() ?? false]);
    }

    /**
     * The text of a question for the level the student chose, in Einfache Sprache when the switch is on.
     */
    private function promptText(Question $question, QuestionLevel $level): ?string
    {
        return $this->simplified($question, $level->simpleColumn(), $question->promptFor($level));
    }

    /**
     * With the switch on, the "Einfache Sprache" version of a teacher's text: the copy cached in `$column`, which the
     * assistant writes on first request for every student after. Only the teacher's text is sent, never a student's own.
     * If the assistant fails, the original stays and the switch goes off, so the next render does not try again.
     */
    private function simplified(Model $model, string $column, ?string $source): ?string
    {
        if (! $this->simpleLanguage || blank($source)) {
            return $source;
        }

        if (filled($model->getAttribute($column))) {
            return $model->getAttribute($column);
        }

        try {
            $simple = app(LessonAssistant::class)->simplify($source, $this->lesson);
        } catch (Throwable $exception) {
            report($exception);

            $this->simpleLanguage = false;

            Notification::make()
                ->warning()
                ->title(__('Einfache Sprache ist gerade nicht verfügbar'))
                ->body(__('Du siehst die Texte deiner Lehrkraft so, wie sie geschrieben sind.'))
                ->send();

            return $source;
        }

        // Compare-and-set on the source: if the teacher corrected the text while the assistant worked, the correction has
        // already cleared the copy and the stale one must not be written back. shortcut: compared with the database
        // collation, so a correction that differs only in case stays unnoticed.
        $sourceColumn = Str::beforeLast($column, '_simple');

        Model::withoutTimestamps(fn (): int => $model->newQuery()->whereKey($model->getKey())->where($sourceColumn, $source)->update([$column => $simple]));
        $model->forceFill([$column => $simple])->syncOriginalAttribute($column);

        return $simple;
    }

    /**
     * The teacher's summary is on screen while the student writes, so text taken over from it word for word shows no
     * understanding: a text of at least COPY_MINIMUM_WORDS words is refused when COPY_SHARE of its word quadruples
     * occur in the summary. Linear in the length of both texts.
     *
     * @return Closure(string, mixed, Closure): void
     */
    private function notCopiedFromTheTeacherSummary(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $words = $this->words((string) $value);
            // The student may have read the simple version of the summary instead of the original.
            $teacherQuadruples = $this->quadruples($this->words($this->lesson->summary.' '.$this->lesson->summary_simple));

            if (count($words) < self::COPY_MINIMUM_WORDS || $teacherQuadruples === []) {
                return;
            }

            $quadruples = $this->quadruples($words);

            if (count(array_intersect_key($quadruples, $teacherQuadruples)) / count($quadruples) >= self::COPY_SHARE) {
                $fail(__('Das sieht aus wie aus der Zusammenfassung deiner Lehrkraft abgeschrieben. Schreib es bitte in deinen eigenen Worten.'));
            }
        };
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param  list<string>  $words
     * @return array<string, true>
     */
    private function quadruples(array $words): array
    {
        $quadruples = [];

        for ($index = 0; $index + 3 < count($words); $index++) {
            $quadruples[implode(' ', array_slice($words, $index, 4))] = true;
        }

        return $quadruples;
    }

    private function minimumSummaryLength(): int
    {
        return $this->lesson->isPracticeTask() ? self::MINIMUM_PRACTICE_SUMMARY_LENGTH : self::MINIMUM_SUMMARY_LENGTH;
    }

    /**
     * The competency a Förderaufgabe practises; the topic is only a fallback for the type.
     */
    private function competencyName(): string
    {
        return $this->lesson->practiceCompetency?->name ?? $this->lesson->topic;
    }

    private function typeBadge(Question $question): Text
    {
        return Text::make($question->type->getLabel())->badge()->color('gray');
    }

    /**
     * Label and icon carry the meaning too, so it doesn't depend on colour alone.
     */
    private function scoreBadge(int $score): Text
    {
        $level = UnderstandingLevel::fromScore($score);

        return Text::make(__(':level · :score von 100', ['level' => $level->getLabel(), 'score' => $score]))
            ->badge()
            ->color($level->getColor())
            ->icon($level->getIcon());
    }

    /**
     * Shows text the way it was typed, line breaks included.
     */
    private function plainText(string $name, ?string $text, string $label): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->state($text)
            ->placeholder('–')
            ->extraAttributes(['class' => 'whitespace-pre-line']);
    }

    private function submission(): ?Submission
    {
        return $this->lesson->submissions()
            ->whereBelongsTo($this->student(), 'student')
            ->with('answers')
            ->first();
    }

    private function student(): User
    {
        /** @var User */
        return Filament::auth()->user();
    }
}
