<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Models\User;
use App\Support\LearningInsights;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The student's personal counterpart to the teacher's Förderbedarf view: what already works, what is nearly there and
 * where practising pays off, with a way into the matching homework result and into "Erklär's mir". Own data only.
 */
class MyLearningProgress extends Page
{
    protected string $view = 'filament.pages.my-learning-progress';

    protected static ?string $slug = 'mein-lernstand';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Lernen;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('Mein Lernstand');
    }

    public function getTitle(): string
    {
        return __('Mein Lernstand');
    }

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->isStudent() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User $student */
        $student = Filament::auth()->user();

        $scores = LearningInsights::evaluatedSubmissions($student)->pluck('understanding_score');

        return [
            ...LearningInsights::studentCompetencies($student),
            'evaluatedCount' => $scores->count(),
            'averageScore' => $scores->isEmpty() ? null : (int) round($scores->avg()),
            'trend' => LearningInsights::understandingTrend($scores),
        ];
    }
}
