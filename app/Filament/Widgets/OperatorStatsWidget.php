<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Schools\SchoolResource;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Platform-wide figures for the operator. Aggregate numbers only: the operator sees no names of teachers or students
 * and no lesson or learning data.
 */
class OperatorStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->isOperator() ?? false;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $schools = School::query()->count();
        $blocked = School::query()->whereNotNull('blocked_at')->count();

        $usersPerRole = User::query()->toBase()->selectRaw('role, count(*) as aggregate')->groupBy('role')->pluck('aggregate', 'role');
        $userCount = fn (Role $role): int => (int) ($usersPerRole[$role->value] ?? 0);

        return [
            Stat::make(__('Schulen'), $schools)
                ->icon(Heroicon::OutlinedBuildingLibrary)
                ->description(__(':active aktiv, :blocked gesperrt', ['active' => $schools - $blocked, 'blocked' => $blocked]))
                ->descriptionIcon($blocked > 0 ? Heroicon::OutlinedNoSymbol : Heroicon::OutlinedCheckCircle)
                ->url(class_exists(SchoolResource::class) && SchoolResource::canViewAny() ? SchoolResource::getUrl() : null),
            Stat::make(__('Schuladministrationen'), $userCount(Role::Admin))
                ->icon(Heroicon::OutlinedCog6Tooth),
            Stat::make(__('Lehrkräfte'), $userCount(Role::Teacher))
                ->icon(Heroicon::OutlinedPencilSquare),
            Stat::make(__('Schüler:innen'), $userCount(Role::Student))
                ->icon(Heroicon::OutlinedAcademicCap),
            Stat::make(__('Klassen'), SchoolClass::query()->count())
                ->icon(Heroicon::OutlinedUserGroup),
        ];
    }
}
