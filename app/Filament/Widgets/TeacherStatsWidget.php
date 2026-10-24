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
            ->get();

        [$practiceTasks, $homework] = $openLessons->partition(fn (Lesson $lesson): bool => $lesson->isPracticeTask());

        $description = match ($homework->count()) {
            0 => 'Gerade läuft keine Hausaufgabe',
            1 => 'aus 1 laufenden Hausaufgabe',
            default => "aus {$homework->count()} laufenden Hausaufgaben",
        };

        if ($practiceTasks->isNotEmpty()) {
            $description .= " · Förderaufgaben: {$practiceTasks->sum('submissions_count')} / {$practiceTasks->sum('students_count')}";
        }

        return Stat::make('Abgaben offener Hausaufgaben', "{$homework->sum('submissions_count')} / {$homework->sum('students_count')}")
            ->icon(Heroicon::OutlinedInboxArrowDown)
            ->description($description);
    }

    protected function lastHomeworkStat(User $teacher): Stat
    {
        $lastLesson = $teacher->taughtLessons()->published()->whereNull('practice_competency_id')->latest('published_at')->latest('id')->first();
        $average = $lastLesson?->submissions()->whereNotNull('evaluated_at')->avg('understanding_score');

        $stat = Stat::make('Ø Verständnis der letzten Hausaufgabe', $average === null ? '–' : round($average).' %')
            ->icon(Heroicon::OutlinedLightBulb)
            ->url($lastLesson ? LessonResource::getUrl('edit', ['record' => $lastLesson]) : null);

        if ($average === null) {
            return $stat->description($lastLesson ? "Noch keine Bewertung zu „{$lastLesson->topic}“" : 'Noch keine Hausaufgabe freigegeben');
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

        return Stat::make('Schüler:innen mit Förderbedarf', $count)
            ->icon(Heroicon::OutlinedLifebuoy)
            ->description($count > 0
                ? 'unter '.UnderstandingLevel::SUPPORT_THRESHOLD.' % in mindestens einer Kompetenz'
                : 'Alle liegen über '.UnderstandingLevel::SUPPORT_THRESHOLD.' %')
            ->descriptionIcon($count > 0 ? Heroicon::OutlinedLifebuoy : Heroicon::OutlinedCheckCircle)
            ->color($count > 0 ? 'danger' : 'success')
            ->url(LearningProgress::getUrl());
    }
}
