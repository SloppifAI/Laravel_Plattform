<?php

namespace App\Filament\Pages;

use App\Ai\LessonAssistant;
use App\Filament\NavigationGroup;
use App\MessageAuthor;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\StudentQuestion;
use App\Models\User;
use App\Support\AskTeacher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Throwable;
use UnitEnum;

/**
 * "Erklär's mir" (Lernen durch Lehren): the student explains a topic to a curious tutor persona,
 * who asks one follow-up question at a time, and gets an assessment once they end the session.
 */
class ExplainIt extends Page
{
    /**
     * The classmate persona the tutor plays.
     */
    public const string TUTOR_NAME = 'Tuto';

    protected static ?string $slug = 'erklaer-es-mir';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Lernen;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected string $view = 'filament.pages.explain-it';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * The session shown on the page. Client-controlled, so every lookup is scoped to the logged-in student.
     */
    public ?int $sessionId = null;

    public string $newMessage = '';

    public static function getNavigationLabel(): string
    {
        return __('Erklär’s mir');
    }

    public function getTitle(): string
    {
        return __('Erklär’s mir');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isStudent() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'lesson_id' => Lesson::assignedTo($this->student())->find(request()->integer('lesson'))?->getKey(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('lesson_id')
                    ->label(__('Thema aus deinen Hausaufgaben'))
                    ->placeholder(__('Stunde auswählen'))
                    ->options(fn (): array => $this->lessonOptions())
                    ->hidden(fn (Select $component): bool => $component->getOptions() === [])
                    ->validationMessages(['in' => __('Diese Stunde gehört nicht zu deinen Hausaufgaben.')]),
                TextInput::make('topic')
                    ->label(__('Oder ein eigenes Thema'))
                    ->placeholder(__('z. B. Fotosynthese'))
                    ->maxLength(255)
                    ->requiredWithout('lesson_id')
                    ->validationMessages(['required_without' => __('Wähl eine Stunde aus oder schreib ein eigenes Thema auf.')]),
            ])
            ->statePath('data');
    }

    public function start(): void
    {
        $data = $this->form->getState();

        $lesson = filled($data['lesson_id'] ?? null)
            ? Lesson::assignedTo($this->student())->findOrFail($data['lesson_id'])
            : null;

        $session = $this->student()->explanationSessions()->create([
            'lesson_id' => $lesson?->getKey(),
            'topic' => filled($data['topic'] ?? null) ? $data['topic'] : $lesson->topic,
        ]);

        $session->messages()->create([
            'author' => MessageAuthor::Tutor,
            'content' => __('Hi, ich bin :tutor und habe von „:topic“ noch nie gehört. Erklär’s mir so, dass ich es verstehe!', ['tutor' => self::TUTOR_NAME, 'topic' => $session->topic]),
        ]);

        $this->sessionId = $session->getKey();
        $this->form->fill();
    }

    public function send(): void
    {
        $session = $this->writableSession();

        $validated = $this->validate(
            ['newMessage' => ['required', 'string', 'max:5000']],
            ['newMessage.required' => __('Schreib zuerst deine Erklärung.'), 'newMessage.max' => __('Das ist zu lang. Teil es lieber in mehrere Nachrichten auf.')],
        );

        $session->messages()->create(['author' => MessageAuthor::Student, 'content' => trim($validated['newMessage'])]);
        $this->reset('newMessage');

        try {
            $question = app(LessonAssistant::class)->askFollowUp($session);
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title(__(':tutor antwortet gerade nicht.', ['tutor' => self::TUTOR_NAME]))
                ->body(__('Deine Nachricht ist gespeichert. Schreib gleich weiter, dann fragt sie wieder nach.'))
                ->send();

            return;
        }

        $session->messages()->create(['author' => MessageAuthor::Tutor, 'content' => $question]);
    }

    public function finishAction(): Action
    {
        return Action::make('finish')
            ->label(__('Erklärung beenden'))
            ->icon(Heroicon::OutlinedFlag)
            ->color('gray')
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedFlag)
            ->modalHeading(__('Erklärung beenden?'))
            ->modalDescription(__(':tutor sagt dir dann, wie gut du das Thema erklärt hast. Danach kannst du hier nichts mehr schreiben.', ['tutor' => self::TUTOR_NAME]))
            ->modalSubmitActionLabel(__('Ja, beenden'))
            ->modalCancelActionLabel(__('Weiter erklären'))
            ->action(function (): void {
                $session = $this->writableSession();

                try {
                    $assessment = app(LessonAssistant::class)->assessExplanation($session);
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->danger()
                        ->title(__('Die Auswertung hat gerade nicht geklappt.'))
                        ->body(__('Deine Erklärung ist gespeichert. Versuch es gleich noch einmal.'))
                        ->send();

                    return;
                }

                $session->update([
                    'understanding_score' => $assessment['understanding_score'],
                    'feedback' => $assessment['feedback'],
                    'finished_at' => now(),
                ]);
            });
    }

    /**
     * For questions to the teacher of the session's lesson. Only a session about a lesson that still belongs to
     * the student's homework has a teacher to ask, and the session is looked up among the student's own, finished ones included.
     */
    public function askTeacherAction(): Action
    {
        return AskTeacher::action(function (): array {
            $session = $this->student()->explanationSessions()->findOrFail($this->sessionId);

            Gate::authorize('createFor', [StudentQuestion::class, $session]);

            return [$session->lesson, $session];
        });
    }

    /**
     * Mounted from the list and from the open session with the id as argument, so the id is client-controlled:
     * it is looked up among the student's own sessions only.
     */
    public function deleteSessionAction(): Action
    {
        return Action::make('deleteSession')
            ->label(__('Gespräch löschen'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Gespräch löschen?'))
            ->modalDescription(__('Das Gespräch wird endgültig gelöscht.'))
            ->modalSubmitActionLabel(__('Ja, löschen'))
            ->modalCancelActionLabel(__('Behalten'))
            ->action(function (array $arguments): void {
                $session = $this->student()->explanationSessions()->findOrFail($arguments['session'] ?? null);

                Gate::authorize('delete', $session);

                $session->delete();
                $this->startOver();

                Notification::make()->success()->title(__('Gespräch gelöscht'))->send();
            });
    }

    public function openSession(int $sessionId): void
    {
        $this->sessionId = $this->student()->explanationSessions()->findOrFail($sessionId)->getKey();
        $this->reset('newMessage');
    }

    public function startOver(): void
    {
        $this->reset('sessionId', 'newMessage');
        $this->form->fill();
    }

    /**
     * @return array{explanationSession: ExplanationSession|null, canAskTeacher: bool, earlierSessions: Collection<int, ExplanationSession>}
     */
    protected function getViewData(): array
    {
        $explanationSession = $this->sessionId === null
            ? null
            : $this->student()->explanationSessions()->with(['lesson', 'messages'])->withCount('studentQuestions')->find($this->sessionId);

        return [
            'explanationSession' => $explanationSession,
            'canAskTeacher' => $explanationSession !== null && $this->student()->can('createFor', [StudentQuestion::class, $explanationSession]),
            'earlierSessions' => $this->student()->explanationSessions()->latest('id')->get(),
        ];
    }

    /**
     * Re-read on every write, because the student may only write into their own session until it is finished.
     */
    protected function writableSession(): ExplanationSession
    {
        $session = $this->student()->explanationSessions()->findOrFail($this->sessionId);

        Gate::authorize('update', $session);

        return $session;
    }

    /**
     * @return array<int, string>
     */
    protected function lessonOptions(): array
    {
        return Lesson::assignedTo($this->student())
            ->latest('held_on')
            ->get()
            ->mapWithKeys(fn (Lesson $lesson): array => [$lesson->getKey() => "{$lesson->subject}: {$lesson->topic}"])
            ->all();
    }

    protected function student(): User
    {
        return auth()->user();
    }
}
