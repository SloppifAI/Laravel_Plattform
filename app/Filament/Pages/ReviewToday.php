<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Models\ReviewItem;
use App\Models\User;
use App\ReviewRating;
use App\Support\ReviewSchedule;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use UnitEnum;

/**
 * "Heute wiederholen" for students: a few questions from earlier homework, one at a time, just before they would be
 * forgotten (ReviewSchedule). The student answers from memory in a field that is never sent or stored, reveals the
 * solution, then rates themselves. The solution is not on the page until it is revealed. Only the signed-in student's
 * own items are ever read, and a rating only moves a question between the boxes: it is no score.
 */
class ReviewToday extends Page
{
    protected string $view = 'filament.pages.review-today';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Lernen;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'heute-wiederholen';

    /**
     * The item whose solution is on the page; nothing about any question lives in the Livewire state but this number.
     */
    #[Locked]
    public ?int $revealedItemId = null;

    public static function getNavigationLabel(): string
    {
        return __('Heute wiederholen');
    }

    public function getTitle(): string
    {
        return __('Heute wiederholen');
    }

    public function getSubheading(): string
    {
        return __('Ein paar Fragen aus früheren Hausaufgaben, kurz bevor du sie vergessen würdest. Antworte erst aus dem Kopf, schau dir dann die Lösung an und sag dir selbst, wie es lief.');
    }

    public static function canAccess(): bool
    {
        return self::student()?->isStudent() ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = self::canAccess() ? ReviewSchedule::todaysCount(self::student()) : 0;

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): string|Htmlable|null
    {
        return __('Fragen zum Wiederholen');
    }

    /**
     * Shows the solution of the question on the page, if it is the one the student is on.
     */
    public function reveal(int $itemId): void
    {
        abort_unless(static::canAccess(), 403);

        if ($this->nextItem()?->getKey() === $itemId) {
            $this->revealedItemId = $itemId;
        }
    }

    /**
     * Takes the student's rating of the question they are on, once they have seen its solution; anything else (a
     * question that was already rated in another tab, an unknown rating) changes nothing.
     */
    public function rate(int $itemId, string $rating): void
    {
        abort_unless(static::canAccess(), 403);

        $item = $this->nextItem();
        $rating = ReviewRating::tryFrom($rating);

        if ($item !== null && $rating !== null && $item->getKey() === $itemId && $this->revealedItemId === $itemId) {
            Gate::authorize('rate', $item);

            ReviewSchedule::rate($item, $rating);
        }

        $this->revealedItemId = null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $queue = ReviewSchedule::todaysQueue(self::student());
        $item = $queue->first();
        $reviewedToday = ReviewSchedule::reviewedToday(self::student());

        return [
            'item' => $item,
            'prompt' => $item?->askedPrompt(),
            'revealed' => $item !== null && $this->revealedItemId === $item->getKey(),
            'number' => $reviewedToday + 1,
            'total' => $reviewedToday + $queue->count(),
            'reviewedToday' => $reviewedToday,
            'ratings' => ReviewRating::cases(),
            'dashboardUrl' => Dashboard::getUrl(),
        ];
    }

    private function nextItem(): ?ReviewItem
    {
        return ReviewSchedule::todaysQueue(self::student())->first();
    }

    private static function student(): ?User
    {
        /** @var User|null */
        return Filament::auth()->user();
    }
}
