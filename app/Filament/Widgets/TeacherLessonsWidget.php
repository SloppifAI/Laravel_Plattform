<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\LearningProgress;
use App\Filament\Resources\Lessons\LessonResource;
use App\Models\User;
use App\Support\LessonReview;
use Filament\Widgets\Widget;

/**
 * A teacher's most recent lessons with their status, plus the way to a new lesson and to the class overview.
 */
class TeacherLessonsWidget extends Widget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.teacher-lessons-widget';

    public static function canView(): bool
    {
        return auth()->user()?->isTeacher() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User $teacher */
        $teacher = auth()->user();

        return [
            'lessons' => $teacher->taughtLessons()
                ->with('schoolClass')
                ->withCount([
                    'students',
                    'submissions',
                    'submissions as evaluated_submissions_count' => fn ($query) => $query->whereNotNull('evaluated_at'),
                ])
                ->latest('held_on')
                ->latest('id')
                ->limit(5)
                ->get(),
            'minReviewGroup' => LessonReview::MIN_GROUP,
            'createLessonUrl' => LessonResource::getUrl('create'),
            'learningProgressUrl' => LearningProgress::getUrl(),
        ];
    }
}
