<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\LearningProgress;
use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Lesson;
use App\Models\User;
use App\Support\LearningInsights;
use App\UnderstandingLevel;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Key figures of a teacher's own lessons: hand-ins of running homework, how well the last one was understood
 * and how many of their students need support. Förderaufgaben go to a few students only, so they never count as the
 * ordinary homework here; their hand-ins are listed beside the hand-ins figure.
 */
class TeacherStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->isTeacher() ?? false;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        /** @var User $teacher */
        $teacher = auth()->user();

        return [
            $this->handInsStat($teacher),
            $this->lastHomeworkStat($teacher),
            $this->supportStat($teacher),
        ];
    }

    protected function handInsStat(User $teacher): Stat
    {
        $openLessons = $teacher->taughtLessons()
            ->published()
            ->where(fn (Builder $query) => $query->whereNull('due_on')->orWhereDate('due_on', '>=', today()))
            ->withCount(['students', 'submissions'])
            ->with('schoolClass')
            ->get()
            // Hand-in counts are results: not of a class whose results the teacher may no longer read (an ended substitution).
            ->filter(fn (Lesson $lesson): bool => $teacher->can('viewResults', $lesson));

        [$practiceTasks, $homework] = $openLessons->partition(fn (Lesson $lesson): bool => $lesson->isPracticeTask());

        $description = match ($homework->count()) {
            0 => __('Gerade läuft keine Hausaufgabe'),
            default => trans_choice('aus :count laufenden Hausaufgabe|aus :count laufenden Hausaufgaben', $homework->count()),
        };

        if ($practiceTasks->isNotEmpty()) {
            $description .= ' · '.__('Förderaufgaben: :submitted / :total', ['submitted' => $practiceTasks->sum('submissions_count'), 'total' => $practiceTasks->sum('students_count')]);
        }

        return Stat::make(__('Abgaben offener Hausaufgaben'), "{$homework->sum('submissions_count')} / {$homework->sum('students_count')}")
            ->icon(Heroicon::OutlinedInboxArrowDown)
            ->description($description);
    }

    protected function lastHomeworkStat(User $teacher): Stat
    {
        // The newest whose results the teacher may read (not one of an ended substitution); lazy, so it stops at the first match.
        $lastLesson = $teacher->taughtLessons()->published()->whereNull('practice_competency_id')->with('schoolClass')->latest('published_at')->latest('id')
            ->lazy(20)
            ->first(fn (Lesson $lesson): bool => $teacher->can('viewResults', $lesson));
        $average = $lastLesson?->submissions()->whereNotNull('evaluated_at')->avg('understanding_score');

        $stat = Stat::make(__('Ø Verständnis der letzten Hausaufgabe'), $average === null ? '–' : round($average).' %')
            ->icon(Heroicon::OutlinedLightBulb)
            ->url($lastLesson ? LessonResource::getUrl('edit', ['record' => $lastLesson]) : null);

        if ($average === null) {
            return $stat->description($lastLesson ? __('Noch keine Bewertung zu „:topic“', ['topic' => $lastLesson->topic]) : __('Noch keine Hausaufgabe freigegeben'));
        }

        $level = UnderstandingLevel::fromScore((int) round($average));

        return $stat
            ->description("{$level->getLabel()}: {$lastLesson->topic}")
            ->descriptionIcon($level->getIcon())
            ->color($level->getColor());
    }

    /**
     * Counted like on "Lernstand & Förderbedarf": a competency average below the threshold.
     */
    protected function supportStat(User $teacher): Stat
    {
        $studentIds = LearningInsights::visibleStudents($teacher)->pluck('id')->all();
        $count = count(LearningInsights::supportNeeds(LearningInsights::scoredAnswers($studentIds)));

        return Stat::make(__('Schüler:innen mit Förderbedarf'), $count)
            ->icon(Heroicon::OutlinedLifebuoy)
            ->description($count > 0
                ? __('unter :threshold % in mindestens einer Kompetenz', ['threshold' => UnderstandingLevel::SUPPORT_THRESHOLD])
                : __('Alle liegen über :threshold %', ['threshold' => UnderstandingLevel::SUPPORT_THRESHOLD]))
            ->descriptionIcon($count > 0 ? Heroicon::OutlinedLifebuoy : Heroicon::OutlinedCheckCircle)
            ->color($count > 0 ? 'danger' : 'success')
            ->url(LearningProgress::getUrl());
    }
}
