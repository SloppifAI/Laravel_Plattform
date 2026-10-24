<?php

namespace App\Filament\Pages;

use App\Models\Competency;
use App\Models\Lesson;
use App\Models\User;
use App\Role;
use App\Support\LearningInsights;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Class overview for teachers: competency matrix, concrete tutoring needs and class averages per lesson.
 */
class LearningProgress extends Page
{
    protected string $view = 'filament.pages.learning-progress';

    protected static ?string $title = 'Lernstand & Förderbedarf';

    protected static ?string $slug = 'lernstand';

    protected static string|UnitEnum|null $navigationGroup = 'Unterricht';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    #[Url(as: 'klasse')]
    public ?int $schoolClassId = null;

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, [Role::Admin, Role::Teacher], true);
    }

    public function mount(): void
    {
        $this->schoolClassId ??= LearningInsights::visibleClasses(auth()->user())->orderBy('name')->value('id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $classes = LearningInsights::visibleClasses(auth()->user())->orderBy('name')->pluck('name', 'id');

        if ($this->schoolClassId === null) {
            return ['classes' => $classes];
        }

        abort_unless($classes->has($this->schoolClassId), 403);

        $students = User::query()->withRole(Role::Student)->where('school_class_id', $this->schoolClassId)->orderBy('name')->get();
        $answers = LearningInsights::scoredAnswers($students->modelKeys());
        $supportNeeds = LearningInsights::supportNeeds($answers);

        return [
            'classes' => $classes,
            'students' => $students,
            'competencies' => Competency::query()
                ->whereKey($answers->pluck('competency_id')->unique()->all())
                ->orderBy('subject')->orderBy('area')->orderBy('name')
                ->get()
                ->keyBy('id'),
            'averages' => LearningInsights::competencyAverages($answers),
            'supportNeeds' => $supportNeeds,
            'supportLessons' => Lesson::query()
                ->whereKey(collect($supportNeeds)->flatten(1)->pluck('lesson_ids')->flatten()->unique()->all())
                ->get()
                ->keyBy('id'),
            'lessons' => Lesson::query()
                ->where('school_class_id', $this->schoolClassId)
                ->published()
                ->withCount(['students', 'submissions'])
                ->withAvg('submissions', 'understanding_score')
                ->orderByDesc('held_on')
                ->get(),
        ];
    }
}
