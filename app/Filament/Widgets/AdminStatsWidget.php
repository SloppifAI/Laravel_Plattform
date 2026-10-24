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

/**
 * School-wide figures for the administration.
 */
class AdminStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $usersPerRole = User::query()->toBase()->selectRaw('role, count(*) as aggregate')->groupBy('role')->pluck('aggregate', 'role');
        $userCount = fn (Role $role): int => (int) ($usersPerRole[$role->value] ?? 0);

        return [
            Stat::make('Schüler:innen', $userCount(Role::Student))
                ->icon(Heroicon::OutlinedAcademicCap)
                ->url(UserResource::getUrl()),
            Stat::make('Lehrkräfte', $userCount(Role::Teacher))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->url(UserResource::getUrl()),
            Stat::make('Administration', $userCount(Role::Admin))
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->url(UserResource::getUrl()),
            Stat::make('Klassen', SchoolClass::query()->count())
                ->icon(Heroicon::OutlinedUserGroup)
                ->url(SchoolClassResource::getUrl()),
            Stat::make('Unterrichtsstunden', Lesson::query()->count())
                ->icon(Heroicon::OutlinedPresentationChartBar)
                ->description(Lesson::query()->published()->count().' davon mit verteilter Hausaufgabe')
                ->url(LessonResource::getUrl()),
            Stat::make('Abgaben diese Woche', Submission::query()->where('created_at', '>=', now()->startOfWeek(CarbonInterface::MONDAY))->count())
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->description('seit Montag, '.now()->startOfWeek(CarbonInterface::MONDAY)->locale('de')->isoFormat('D. MMMM')),
        ];
    }
}
