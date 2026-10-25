<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Models\SchoolClass;
use App\Models\TimetableEntry;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * "Stundenplan": the week of the signed-in student (the timetable of their class) or teacher (the slots they are
 * entered for, in any class). A Klassenleitung can switch to the whole timetable of a class they lead. It only shows;
 * the school administration maintains the entries (TimetableEntryResource).
 */
class MyTimetable extends Page
{
    protected string $view = 'filament.pages.my-timetable';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $slug = 'stundenplan';

    /**
     * The led class whose whole timetable a Klassenleitung looks at; null for the own slots.
     */
    #[Url(as: 'klasse')]
    public ?int $classId = null;

    public static function getNavigationLabel(): string
    {
        return __('Stundenplan');
    }

    public function getTitle(): string
    {
        return __('Stundenplan');
    }

    public function getSubheading(): string
    {
        return match (true) {
            $this->schoolClass() !== null => __('Alle Stunden der Klasse :class in der Woche. Änderungen trägt die Schuladministration ein.', ['class' => $this->schoolClass()->name]),
            self::user()->isTeacher() => __('Deine Stunden in der Woche, in allen Klassen. Änderungen trägt die Schuladministration ein.'),
            default => __('Die Stunden deiner Klasse in der Woche. Änderungen trägt die Schuladministration ein.'),
        };
    }

    public static function canAccess(): bool
    {
        return self::user()?->can('viewOwn', TimetableEntry::class) ?? false;
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return self::user()?->isTeacher() ? NavigationGroup::Unterricht : NavigationGroup::Lernen;
    }

    /**
     * First in the students' "Lernen"; for teachers after the daily lesson tools.
     */
    public static function getNavigationSort(): ?int
    {
        return self::user()?->isTeacher() ? 5 : 0;
    }

    public function mount(): void
    {
        // A class the teacher does not lead in the URL is a 403, not someone else's timetable.
        $this->schoolClass();
    }

    /**
     * The user's entries: a teacher's own slots, a student's class. A student without a class has none.
     *
     * @return Builder<TimetableEntry>
     */
    public static function entriesFor(User $user): Builder
    {
        return TimetableEntry::query()
            ->ofSchool($user->school_id)
            ->when(
                $user->isTeacher(),
                fn (Builder $entries): Builder => $entries->where('teacher_id', $user->getKey()),
                fn (Builder $entries): Builder => $entries->where('school_class_id', $user->school_class_id ?? 0),
            )
            ->inWeekOrder();
    }

    /**
     * The chosen led class, authorised on every call: the id comes from the URL and from Livewire state.
     */
    public function schoolClass(): ?SchoolClass
    {
        if ($this->classId === null) {
            return null;
        }

        $schoolClass = SchoolClass::query()->with('school')->find($this->classId);

        abort_if($schoolClass === null || ! Gate::allows('viewClass', [TimetableEntry::class, $schoolClass]), 403);

        return $schoolClass;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = self::user();
        $schoolClass = $this->schoolClass();
        $entries = ($schoolClass !== null ? $schoolClass->timetableEntries()->inWeekOrder() : static::entriesFor($user))
            ->with(['schoolClass', 'teacher'])
            ->get();

        return [
            // The own slots of a teacher name the class, a class's timetable names the teachers.
            'showsClass' => $user->isTeacher() && $schoolClass === null,
            'ledClasses' => $user->isTeacher() ? $user->ledClasses()->where('school_id', $user->school_id)->orderBy('name')->get(['id', 'name']) : collect(),
            'weekdays' => TimetableEntry::weekdays(),
            'periods' => $this->periodsToShow($entries),
            'entriesBySlot' => $entries->keyBy(fn (TimetableEntry $entry): string => "{$entry->weekday}-{$entry->period}"),
            'entriesByDay' => $entries->groupBy('weekday'),
            'today' => TimetableEntry::schoolNow()->dayOfWeekIso,
        ];
    }

    /**
     * The periods from the first one of the day up to the last one anybody has, so an afternoon without lessons does not
     * fill the page with empty rows.
     *
     * @param  Collection<int, TimetableEntry>  $entries
     * @return array<int, array{0: string, 1: string}> start and end by period number
     */
    private function periodsToShow(Collection $entries): array
    {
        $last = $entries->max('period') ?? 0;

        return collect(TimetableEntry::periods())
            ->filter(fn (array $times, int $period): bool => $period <= $last)
            ->all();
    }

    private static function user(): ?User
    {
        return auth()->user();
    }
}
