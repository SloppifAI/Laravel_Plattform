<?php

namespace App\Filament\Pages;

use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Submission;
use App\Models\User;
use App\Role;
use App\Support\LearningInsights;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;

/**
 * Printable evidence report on one student for a parent conversation. Everything in it is derived from stored data.
 */
class ParentReport extends Page
{
    protected string $view = 'filament.pages.parent-report';

    protected static ?string $slug = 'elterngespraech/{student}';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * Bound from the {student} route parameter by Livewire.
     */
    public User $student;

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, [Role::Admin, Role::Teacher], true);
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'elterngespraech';
    }

    public function mount(): void
    {
        abort_unless(LearningInsights::canView(auth()->user(), $this->student), 403);
    }

    public function getTitle(): string
    {
        return 'Elterngespräch: '.$this->student->name;
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Drucken')
                ->icon(Heroicon::OutlinedPrinter)
                ->alpineClickHandler('window.print()'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $submissions = $this->student->submissions()->with('lesson')->get()->keyBy('lesson_id');
        $lessons = $this->student->assignedLessons()->published()->with('teacher')->orderBy('held_on')->get();

        $homework = $lessons->map(fn (Lesson $lesson): array => [
            'lesson' => $lesson,
            'submission' => $submissions->get($lesson->id),
            'status' => $this->homeworkStatus($lesson, $submissions->get($lesson->id)),
        ]);

        $scores = $homework->pluck('submission.understanding_score')->filter(fn (?int $score): bool => $score !== null)->values();
        $half = intdiv($scores->count(), 2);

        $answers = LearningInsights::scoredAnswers([$this->student->id]);
        $averages = LearningInsights::competencyAverages($answers)[$this->student->id] ?? [];

        // Homework that is neither handed in nor due yet does not count towards the submission rate.
        $submittedCount = $submissions->only($lessons->modelKeys())->count();
        $notDueCount = $homework->where('status.0', 'Offen')->count();
        $countedCount = $homework->count() - $notDueCount;

        return [
            'homework' => $homework,
            'periodStart' => $lessons->first()?->held_on,
            'average' => $scores->isEmpty() ? null : (int) round($scores->avg()),
            'scores' => $scores,
            'trend' => $scores->count() < 2 ? null : (int) round($scores->slice($half)->avg() - $scores->take($half)->avg()),
            'competencies' => Competency::query()->whereKey(array_keys($averages))->orderBy('subject')->orderBy('area')->orderBy('name')->get(),
            'averages' => $averages,
            'answerCounts' => $answers->countBy('competency_id'),
            'supportNeeds' => collect(LearningInsights::supportNeeds($answers)[$this->student->id] ?? [])->keyBy('competency_id'),
            'lessonsById' => $submissions->pluck('lesson', 'lesson_id'),
            'submittedCount' => $submittedCount,
            'countedCount' => $countedCount,
            'submissionRate' => $countedCount > 0 ? (int) round(100 * $submittedCount / $countedCount) : null,
            'onTimeCount' => $homework->where('status.0', 'Pünktlich abgegeben')->count(),
            'notDueCount' => $notDueCount,
            'sessions' => $this->student->explanationSessions()->with('lesson')->latest()->get(),
        ];
    }

    /**
     * @return array{0: string, 1: string} label and badge colour
     */
    private function homeworkStatus(Lesson $lesson, ?Submission $submission): array
    {
        $dueOn = $lesson->due_on?->toDateString();

        return match (true) {
            $submission === null && ($dueOn === null || $dueOn >= today()->toDateString()) => ['Offen', 'gray'],
            $submission === null => ['Nicht abgegeben', 'danger'],
            $dueOn !== null && $submission->created_at->toDateString() > $dueOn => ['Verspätet abgegeben', 'warning'],
            default => ['Pünktlich abgegeben', 'success'],
        };
    }
}
