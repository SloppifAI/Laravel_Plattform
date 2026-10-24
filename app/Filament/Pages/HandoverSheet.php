<?php

namespace App\Filament\Pages;

use App\Filament\Resources\SchoolClasses\SchoolClassResource;
use App\Models\ClassSubstitution;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Support\LessonReview;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * "Übergabe-Mappe": one printable sheet to hand a class over to a substitute: the last lessons with their summaries,
 * the open homework with hand-in counts, what the class should repeat at the start of the next lesson and the
 * handover note, plus the Klassenleitung to ask. It carries class-wide numbers only, never a student's name or
 * individual result, and leaves out Förderaufgaben, which are delegated to a few named students.
 *
 * Open to the Klassenleitung, the administration of the school and the substitute while the substitution lasts
 * (ClassSubstitutionPolicy::viewHandoverSheet()); the rights are checked again on every render, so a sheet left open
 * after the substitution ended shows nothing more.
 */
class HandoverSheet extends Page
{
    /**
     * How many of the last lessons the sheet lists.
     */
    private const int LESSON_COUNT = 5;

    protected string $view = 'filament.pages.handover-sheet';

    protected static ?string $slug = 'uebergabe/{schoolClass}';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public SchoolClass $schoolClass;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isAdmin() || $user->isTeacher());
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'uebergabe';
    }

    public function mount(SchoolClass $schoolClass): void
    {
        // Custom pages don't apply policies, and the class comes from the URL.
        Gate::authorize('viewHandoverSheet', [ClassSubstitution::class, $schoolClass]);

        $this->schoolClass = $schoolClass;
    }

    public function getTitle(): string
    {
        return __('Übergabe-Mappe: :class', ['class' => $this->schoolClass->name]);
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return auth()->user()?->isAdmin()
            ? [SchoolClassResource::getUrl('edit', ['record' => $this->schoolClass]) => __('Klasse :class', ['class' => $this->schoolClass->name]), __('Übergabe-Mappe')]
            : [Substitutions::getUrl() => __('Vertretung'), __('Übergabe-Mappe')];
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label(__('Drucken'))
                ->icon(Heroicon::OutlinedPrinter)
                ->alpineClickHandler('window.print()'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        // The class survives in the Livewire snapshot, so every render re-checks the rights.
        Gate::authorize('viewHandoverSheet', [ClassSubstitution::class, $this->schoolClass]);

        $schoolClass = $this->schoolClass->load(['school', 'teacher']);
        $isManager = $this->isManager();

        // Lessons dated in the future are plans, possibly of a colleague's draft: they are not handed over.
        $lessons = $this->heldLessons($schoolClass)
            ->whereDate('held_on', '<=', today())
            ->with('teacher:id,name')
            ->orderByDesc('held_on')
            ->orderByDesc('id')
            ->limit(self::LESSON_COUNT)
            ->get();

        return [
            'class' => $schoolClass,
            'studentCount' => $schoolClass->students()->count(),
            'isManager' => $isManager,
            'substitutions' => $this->relevantSubstitutions($schoolClass, $isManager),
            'lessons' => $lessons,
            'openHomework' => $this->heldLessons($schoolClass)
                ->published()
                ->where(fn (Builder $query): Builder => $query->whereNull('due_on')->orWhereDate('due_on', '>=', today()))
                ->withCount(['students', 'submissions'])
                ->orderBy('due_on')
                ->orderByDesc('held_on')
                ->get(),
            'reviews' => $this->reviews($schoolClass, $lessons),
        ];
    }

    /**
     * The lessons of the class without the Förderaufgaben.
     *
     * @return Builder<Lesson>
     */
    private function heldLessons(SchoolClass $schoolClass): Builder
    {
        return Lesson::query()
            ->where('school_class_id', $schoolClass->getKey())
            ->whereNull('practice_competency_id');
    }

    /**
     * Klassenleitung and administration, as opposed to the substitute.
     */
    private function isManager(): bool
    {
        return Gate::allows('createFor', [ClassSubstitution::class, $this->schoolClass]);
    }

    /**
     * The managers see every running or planned substitution with its note, the substitute only their own running one:
     * a note written for somebody else is not meant for them.
     *
     * @return EloquentCollection<int, ClassSubstitution>
     */
    private function relevantSubstitutions(SchoolClass $schoolClass, bool $isManager): EloquentCollection
    {
        return $schoolClass->substitutions()
            ->with('substitute:id,name')
            ->when(
                $isManager,
                fn (Builder $query) => $query->notEnded(),
                fn (Builder $query) => $query->active()->where('substitute_id', auth()->id()),
            )
            ->orderBy('starts_on')
            ->get();
    }

    /**
     * For every subject of the last lessons the latest evaluated lesson of the class and the points to repeat from it;
     * subjects without enough hand-ins for a class-wide statement are left out.
     *
     * @param  Collection<int, Lesson>  $lessons
     * @return Collection<int, array{lesson: Lesson, review: array<string, mixed>}>
     */
    private function reviews(SchoolClass $schoolClass, Collection $lessons): Collection
    {
        return $lessons->pluck('subject')->unique()->values()
            ->map(function (string $subject) use ($schoolClass): ?array {
                $lesson = LessonReview::previousLessonFor($schoolClass, $subject);
                $review = $lesson ? LessonReview::forLesson($lesson) : null;

                return $review !== null && $review['points'] !== [] ? ['lesson' => $lesson, 'review' => $review] : null;
            })
            ->filter()
            ->values();
    }
}
