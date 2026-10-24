<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Lessons\LessonResource;
use App\Filament\Resources\SchoolClasses\SchoolClassResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\Role;
use Carbon\CarbonInterface;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Figures of the administration's own school. Nothing from other schools is counted.
 */
class AdminStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user?->isAdmin() && $user->school_id !== null;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $schoolId = auth()->user()->school_id;
        $lessons = fn (): Builder => Lesson::query()->whereRelation('schoolClass', 'school_id', $schoolId);

        $usersPerRole = User::query()->where('school_id', $schoolId)->toBase()->selectRaw('role, count(*) as aggregate')->groupBy('role')->pluck('aggregate', 'role');
        $userCount = fn (Role $role): int => (int) ($usersPerRole[$role->value] ?? 0);

        return [
            Stat::make(__('Schüler:innen'), $userCount(Role::Student))
                ->icon(Heroicon::OutlinedAcademicCap)
                ->url(UserResource::getUrl()),
            Stat::make(__('Lehrkräfte'), $userCount(Role::Teacher))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->url(UserResource::getUrl()),
            Stat::make(__('Administration'), $userCount(Role::Admin))
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->url(UserResource::getUrl()),
            Stat::make(__('Klassen'), SchoolClass::query()->where('school_id', $schoolId)->count())
                ->icon(Heroicon::OutlinedUserGroup)
                ->url(SchoolClassResource::getUrl()),
            Stat::make(__('Unterrichtsstunden'), $lessons()->count())
                ->icon(Heroicon::OutlinedPresentationChartBar)
                ->description(__(':count davon mit freigegebener Hausaufgabe', ['count' => $lessons()->published()->count()]))
                ->url(LessonResource::getUrl()),
            Stat::make(__('Abgaben diese Woche'), Submission::query()->whereRelation('lesson.schoolClass', 'school_id', $schoolId)->where('created_at', '>=', now()->startOfWeek(CarbonInterface::MONDAY))->count())
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->description(__('seit Montag, :date', ['date' => now()->startOfWeek(CarbonInterface::MONDAY)->locale(app()->getLocale())->isoFormat(__('D. MMMM'))])),
        ];
    }
}
