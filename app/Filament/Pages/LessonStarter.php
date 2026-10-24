<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Lesson;
use App\Role;
use App\Support\LessonReview;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * "Einstieg für die nächste Stunde": a board for the projector with the three points the class understood least in a
 * lesson. Shows class-wide aggregates only, never names or individual results (LessonReview::MIN_GROUP keeps it from
 * showing a single student's result as the class's); the solution hints are revealed by the teacher on demand, so they
 * are not even rendered while hidden.
 */
class LessonStarter extends Page
{
    protected string $view = 'filament.pages.lesson-starter';

    protected static ?string $slug = 'einstieg/{lesson}';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public Lesson $lesson;

    public bool $showSolutions = false;

    public function getTitle(): string
    {
        return __('Einstieg für die nächste Stunde');
    }

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, [Role::Admin, Role::Teacher], true);
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'einstieg';
    }

    public function mount(Lesson $lesson): void
    {
        // Custom pages don't apply policies: the board shows what the class got wrong, so only those who may read the lesson's results get in.
        Gate::authorize('viewResults', $lesson);

        // A Förderaufgabe is delegated to a few named students, so its results would be individual results.
        abort_if($lesson->isPracticeTask(), 404);

        $this->lesson = $lesson;
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [LessonResource::getUrl() => LessonResource::getBreadcrumb(), __('Einstieg')];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        // The lesson survives in the Livewire snapshot, so every render re-checks the rights.
        Gate::authorize('viewResults', $this->lesson);

        return [
            'review' => LessonReview::forLesson($this->lesson),
            'schoolClass' => $this->lesson->schoolClass,
            'assignedCount' => $this->lesson->students()->count(),
        ];
    }
}
