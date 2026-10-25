<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use App\Support\LearningInsights;
use App\Support\PracticeTasks;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Throwable;
use UnitEnum;

/**
 * Class overview for teachers: competency matrix, concrete tutoring needs and class averages per lesson.
 * From a tutoring need, a Förderaufgabe can be drafted with one click.
 */
class LearningProgress extends Page
{
    protected string $view = 'filament.pages.learning-progress';

    protected static ?string $slug = 'lernstand';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Unterricht;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    #[Url(as: 'klasse')]
    public ?int $schoolClassId = null;

    public static function getNavigationLabel(): string
    {
        return __('Lernstand & Förderbedarf');
    }

    public function getTitle(): string
    {
        return __('Lernstand & Förderbedarf');
    }

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, [Role::Admin, Role::Teacher], true);
    }

    public function mount(): void
    {
        $this->schoolClassId ??= LearningInsights::visibleClasses(auth()->user())->orderBy('name')->value('id');
    }

    /**
     * "Förderaufgabe erstellen" for one competency of the selected class (argument `competency`): the AI drafts the
     * task, which stays a draft for review or, with "Direkt freigeben", goes to the chosen students right away.
     */
    public function createPracticeTaskAction(): Action
    {
        return Action::make('createPracticeTask')
            ->label(__('Förderaufgabe erstellen'))
            ->icon(Heroicon::OutlinedSparkles)
            ->size(Size::Small)
            ->authorize(fn (): bool => $this->canCreatePracticeTasks())
            ->modalHeading(fn (array $arguments): string => __('Förderaufgabe: :competency', ['competency' => $this->practiceTaskContext($arguments['competency'] ?? null)['competency']->name]))
            ->modalDescription($this->isAiActive()
                ? __('Die KI entwirft aus den Stunden, in denen sich die Lücke gezeigt hat, drei bis vier Übungsfragen von leicht bis schwer. Sie erhält Fach, Kompetenz, Notizen aus der Stunde und anonyme Hinweise, keine Namen.')
                : __('Demo-Modus: Der Entwurf entsteht lokal aus den Notizen aus der Stunde, es wird nichts an eine KI gesendet. Drei bis vier Übungsfragen von leicht bis schwer.'))
            ->modalSubmitActionLabel(__('Förderaufgabe erstellen'))
            ->fillForm(fn (array $arguments): array => [
                // Whoever still has an open task for this competency does not get a second one by default.
                'students' => array_values(array_diff(
                    $this->practiceTaskContext($arguments['competency'] ?? null)['support']['student_ids'],
                    $this->studentsWithOpenPracticeTask($arguments['competency'] ?? null),
                )),
                'distribute' => false,
                'due_on' => today()->addDays(7)->toDateString(),
            ])
            ->schema([
                CheckboxList::make('students')
                    ->label(__('Schüler:innen'))
                    ->helperText(__('Vorausgewählt sind alle, die in dieser Kompetenz unter der Förderschwelle liegen und noch keine offene Förderaufgabe dazu haben.'))
                    ->options(fn (): array => $this->classStudents()->pluck('name', 'id')->all())
                    ->descriptions(fn (): array => collect($this->studentsWithOpenPracticeTask($this->getMountedAction()?->getArguments()['competency'] ?? null))
                        ->mapWithKeys(fn (int $id): array => [$id => __('hat bereits eine offene Förderaufgabe zu dieser Kompetenz')])
                        ->all())
                    ->validationMessages(['in' => __('Bitte wähle nur Schüler:innen dieser Klasse aus.')])
                    ->required()
                    ->bulkToggleable()
                    ->columns(2),
                Toggle::make('distribute')
                    ->label(__('Direkt freigeben'))
                    ->helperText(__('Aus: Du prüfst die Förderaufgabe zuerst als Entwurf und gibst sie danach selbst frei. An: Die Schüler:innen sehen sie sofort in ihrem Lernbereich.').($this->isAiActive() ? '' : ' '.__('Im Demo-Modus sind die Fragen Beispielentwürfe – besser erst als Entwurf prüfen.')))
                    ->live(),
                DatePicker::make('due_on')
                    ->label(__('Abgabe bis'))
                    ->minDate(today())
                    ->required()
                    ->visible(fn (Get $get): bool => (bool) $get('distribute')),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                ['schoolClass' => $schoolClass, 'students' => $classStudents, 'competency' => $competency, 'support' => $support] = $this->practiceTaskContext($arguments['competency'] ?? null);

                $studentIds = collect($data['students'] ?? [])->map(fn (mixed $id): int => (int) $id)->unique()->values();

                // The form only offers the class's students: anything else is forged.
                abort_if($studentIds->isEmpty() || $studentIds->diff($classStudents->modelKeys())->isNotEmpty(), 403);

                $dueOn = ($data['distribute'] ?? false) ? Carbon::parse($data['due_on']) : null;

                try {
                    $lesson = PracticeTasks::create(auth()->user(), $schoolClass, $competency, $support, $studentIds->all(), $dueOn);
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->danger()
                        ->title(__('KI-Entwurf fehlgeschlagen'))
                        ->body(__('Es wurde nichts angelegt. Bitte versuche es gleich noch einmal.').($this->isAiActive() ? '' : ' '.__('Die KI-Anbindung ist nicht aktiv, die Plattform arbeitet im Demo-Modus.')))
                        ->persistent()
                        ->send();

                    // The modal stays open, so the selection is not lost.
                    $action->halt();
                }

                $demoHint = $this->isAiActive() ? '' : ' '.__('Demo-Modus – KI-Anbindung vorbereitet, aber nicht aktiv.');

                if ($dueOn !== null) {
                    Notification::make()
                        ->success()
                        ->title(__('Förderaufgabe freigegeben'))
                        ->body(trans_choice('An :count Schüler:in, Abgabe bis :date.|An :count Schüler:innen, Abgabe bis :date.', $studentIds->count(), ['date' => $dueOn->format('d.m.Y')]).$demoHint)
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('Förderaufgabe als Entwurf angelegt'))
                    ->body(__('Prüfe Zusammenfassung und Fragen und gib die Förderaufgabe dann für die ausgewählten Schüler:innen frei.').$demoHint.($demoHint === '' ? '' : ' '.__('Die Fragen sind Beispielentwürfe, bitte prüfe und passe sie an.')))
                    ->persistent()
                    ->send();

                $this->redirect(LessonResource::getUrl('edit', ['record' => $lesson]));
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = auth()->user();
        $classes = LearningInsights::visibleClasses($user)->orderBy('name')->pluck('name', 'id');
        $isAdmin = $user->isAdmin();

        if ($this->schoolClassId === null) {
            return ['classes' => $classes, 'isAdmin' => $isAdmin];
        }

        abort_unless($classes->has($this->schoolClassId), 403);

        $students = $this->classStudents();
        $answers = LearningInsights::scoredAnswers($students->modelKeys());
        $supportNeeds = LearningInsights::supportNeeds($answers);
        $competencies = Competency::query()
            ->whereKey($answers->pluck('competency_id')->unique()->all())
            ->orderBy('subject')->orderBy('area')->orderBy('name')
            ->get()
            ->keyBy('id');
        $supportCounts = collect($supportNeeds)->flatten(1)->countBy('competency_id');

        return [
            'classes' => $classes,
            'isAdmin' => $isAdmin,
            'students' => $students,
            'competencies' => $competencies,
            'averages' => LearningInsights::competencyAverages($answers),
            'supportNeeds' => $supportNeeds,
            'supportCounts' => $supportCounts,
            'supportCompetencies' => $competencies->filter(fn (Competency $competency): bool => $supportCounts->has($competency->id)),
            'practiceTasks' => PracticeTasks::forClass($user, $this->schoolClassId),
            'supportLessons' => Lesson::query()
                ->whereKey(collect($supportNeeds)->flatten(1)->pluck('lesson_ids')->flatten()->unique()->all())
                ->get()
                ->keyBy('id'),
            // Förderaufgaben have their own list above and reach only some of the class.
            'lessons' => Lesson::query()
                ->where('school_class_id', $this->schoolClassId)
                ->whereNull('practice_competency_id')
                ->published()
                ->withCount(['students', 'submissions'])
                ->withAvg('submissions', 'understanding_score')
                ->orderByDesc('held_on')
                ->get(),
        ];
    }

    /**
     * Whether the signed-in user's school has the AI switched on; otherwise the offline demo logic drafts the tasks.
     */
    private function isAiActive(): bool
    {
        return auth()->user()?->school?->aiEnabled() ?? false;
    }

    /**
     * The visible students of the selected class.
     *
     * @return Collection<int, User>
     */
    private function classStudents(): Collection
    {
        return LearningInsights::visibleStudents(auth()->user())
            ->where('school_class_id', $this->schoolClassId)
            ->orderBy('name')
            ->get();
    }

    /**
     * Whoever may create lessons may do so for the selected class if it is one of their visible classes.
     */
    private function canCreatePracticeTasks(): bool
    {
        $user = auth()->user();

        return $this->schoolClassId !== null
            && Gate::forUser($user)->allows('create', Lesson::class)
            && LearningInsights::visibleClasses($user)->whereKey($this->schoolClassId)->exists();
    }

    /**
     * Students of the selected class who still have an open Förderaufgabe (delegated, not yet handed in) for the competency.
     *
     * @return list<int>
     */
    private function studentsWithOpenPracticeTask(mixed $competencyId): array
    {
        return is_numeric($competencyId)
            ? PracticeTasks::studentIdsWithOpenTask($this->classStudents()->modelKeys(), (int) $competencyId)
            : [];
    }

    /**
     * Everything a Förderaufgabe for the given competency needs, authorized against the selected class: the class
     * must be visible and have at least one student below the support threshold in that competency.
     * Both the competency (an action argument) and the class (a URL property) are user input.
     *
     * @return array{schoolClass: SchoolClass, students: Collection<int, User>, competency: Competency, support: array{student_ids: list<int>, lesson_ids: list<int>}}
     */
    private function practiceTaskContext(mixed $competencyId): array
    {
        abort_unless(is_numeric($competencyId) && $this->canCreatePracticeTasks(), 403);

        $students = $this->classStudents();
        $support = LearningInsights::competencySupport(
            LearningInsights::supportNeeds(LearningInsights::scoredAnswers($students->modelKeys())),
            (int) $competencyId,
        );

        abort_if($support['student_ids'] === [], 403);

        return [
            'schoolClass' => SchoolClass::query()->findOrFail($this->schoolClassId),
            'students' => $students,
            'competency' => Competency::query()->findOrFail((int) $competencyId),
            'support' => $support,
        ];
    }
}
