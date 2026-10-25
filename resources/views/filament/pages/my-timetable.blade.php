@php
    use Filament\Support\Icons\Heroicon;

    $muted = 'text-sm text-gray-600 dark:text-gray-400';

    /** Second line of a slot: the class in a teacher's own slots, otherwise the teacher; the room always. */
    $details = fn ($entry): string => collect([
        $showsClass ? __('Klasse :class', ['class' => $entry->schoolClass->name]) : ($entry->teacher?->name ?? __('Lehrkraft noch offen')),
        $entry->room,
    ])->filter()->implode(' · ');
@endphp

<x-filament-panels::page>
    @if ($ledClasses->isNotEmpty())
        <div class="min-w-48 max-w-xs">
            <label for="timetable-view" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">{{ __('Ansicht') }}</label>

            <x-filament::input.wrapper>
                <x-filament::input.select id="timetable-view" wire:model.live="classId">
                    <option value="">{{ __('Meine Stunden') }}</option>
                    @foreach ($ledClasses as $class)
                        <option value="{{ $class->id }}">{{ __('Klasse :class (ganzer Plan)', ['class' => $class->name]) }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    @endif

    @if ($entriesBySlot->isEmpty())
        <x-filament::empty-state
            :heading="__('Noch kein Stundenplan')"
            :description="match (true) {
                $showsClass => __('Für dich sind noch keine Stunden eingetragen. Die Schuladministration pflegt den Stundenplan.'),
                $ledClasses->isNotEmpty() => __('Für diese Klasse ist noch kein Stundenplan eingetragen. Die Schuladministration pflegt ihn.'),
                default => __('Für deine Klasse ist noch kein Stundenplan eingetragen. Die Schuladministration pflegt ihn.'),
            }"
            :icon="Heroicon::OutlinedCalendarDays"
        />
    @else
        {{-- From md up: the week as a grid, periods down, days across. --}}
        <section class="lp-card hidden overflow-hidden md:block" aria-label="{{ __('Stundenplan') }}">
            <table class="w-full table-fixed text-start text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th scope="col" class="w-28 px-3 py-3 text-start font-medium text-gray-600 dark:text-gray-400">{{ __('Stunde') }}</th>
                        @foreach ($weekdays as $weekday => $name)
                            <th
                                scope="col"
                                @if ($weekday === $today) aria-current="date" @endif
                                @class([
                                    'px-3 py-3 text-start font-semibold',
                                    'text-primary-700 dark:text-primary-400' => $weekday === $today,
                                    'text-gray-950 dark:text-white' => $weekday !== $today,
                                ])
                            >
                                {{ $name }}
                                @if ($weekday === $today)
                                    <span class="block text-xs font-normal">{{ __('Heute') }}</span>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($periods as $period => [$start, $end])
                        <tr class="border-b border-gray-100 last:border-b-0 dark:border-white/5">
                            <th scope="row" class="px-3 py-3 text-start align-top font-normal">
                                <span class="block font-medium text-gray-950 dark:text-white">{{ __(':period. Stunde', ['period' => $period]) }}</span>
                                <span class="block text-xs text-gray-600 dark:text-gray-400">{{ $start }}–{{ $end }}</span>
                            </th>
                            @foreach ($weekdays as $weekday => $name)
                                @php($entry = $entriesBySlot->get("{$weekday}-{$period}"))
                                <td @class(['px-2 py-2 align-top', 'bg-primary-50 dark:bg-primary-950/40' => $weekday === $today])>
                                    @if ($entry)
                                        <div class="rounded-lg bg-gray-50 px-2 py-1.5 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                                            <p class="font-medium break-words text-gray-950 dark:text-white">{{ $entry->subject }}</p>
                                            <p class="text-xs break-words text-gray-600 dark:text-gray-400">{{ $details($entry) }}</p>
                                        </div>
                                    @else
                                        <span class="sr-only">{{ __('Keine Stunde') }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        {{-- On a phone: one card per day, so nothing scrolls sideways. --}}
        <div class="grid gap-4 md:hidden">
            @foreach ($weekdays as $weekday => $name)
                <section class="lp-card p-4" aria-labelledby="lp-timetable-day-{{ $weekday }}">
                    <h2 id="lp-timetable-day-{{ $weekday }}" class="text-base font-semibold text-gray-950 dark:text-white">
                        {{ $name }}
                        @if ($weekday === $today)
                            <x-filament::badge color="primary" class="ms-2 inline-flex w-fit align-middle">{{ __('Heute') }}</x-filament::badge>
                        @endif
                    </h2>

                    @if ($entriesByDay->has($weekday))
                        <ol class="mt-3 grid gap-3">
                            @foreach ($entriesByDay->get($weekday) as $entry)
                                <li class="flex gap-3">
                                    <span class="w-24 shrink-0 {{ $muted }}">
                                        {{ __(':period. Stunde', ['period' => $entry->period]) }}
                                        @if ($times = $periods[$entry->period] ?? null)
                                            <span class="block text-xs">{{ $times[0] }}–{{ $times[1] }}</span>
                                        @endif
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block font-medium break-words text-gray-950 dark:text-white">{{ $entry->subject }}</span>
                                        <span class="block text-sm break-words text-gray-600 dark:text-gray-400">{{ $details($entry) }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    @else
                        <p class="mt-2 {{ $muted }}">{{ __('Keine Stunden') }}</p>
                    @endif
                </section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
