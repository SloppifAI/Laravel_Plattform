@php
    use App\Filament\Resources\Lessons\LessonResource;
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-widgets::widget>
    <x-filament::section heading="Deine letzten Unterrichtsstunden">
        <x-slot name="afterHeader">
            <div class="flex flex-wrap gap-2">
                <x-filament::button tag="a" :href="$learningProgressUrl" color="gray" :icon="Heroicon::OutlinedChartBar">
                    Lernstand & Förderbedarf
                </x-filament::button>

                <x-filament::button tag="a" :href="$createLessonUrl" :icon="Heroicon::Plus">
                    Neue Unterrichtsstunde
                </x-filament::button>
            </div>
        </x-slot>

        @if ($lessons->isEmpty())
            <div class="flex items-center gap-4">
                <x-filament::icon :icon="Heroicon::OutlinedPencilSquare" class="size-8 shrink-0 text-primary-600 dark:text-primary-400" />

                <p class="text-sm text-gray-700 dark:text-gray-300">
                    Noch keine Unterrichtsstunde festgehalten. Trag deine erste Stunde ein: Die KI fasst sie zusammen und schlägt passende Hausaufgaben vor.
                </p>
            </div>
        @else
            <ul class="-my-2 divide-y divide-gray-950/5 dark:divide-white/10">
                @foreach ($lessons as $lesson)
                    @php($heldOn = $lesson->held_on->copy()->locale('de'))

                    <li>
                        <a
                            href="{{ LessonResource::getUrl('edit', ['record' => $lesson]) }}"
                            class="lp-focus -mx-3 flex items-center gap-4 rounded-xl px-3 py-3 transition hover:bg-gray-50 dark:hover:bg-white/5"
                        >
                            <span class="flex size-12 shrink-0 flex-col items-center justify-center rounded-xl bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-200" aria-hidden="true">
                                <span class="text-lg leading-none font-semibold tabular-nums">{{ $heldOn->isoFormat('D') }}</span>
                                <span class="mt-0.5 text-xs leading-none">{{ $heldOn->isoFormat('MMM') }}</span>
                            </span>

                            <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-4 gap-y-1.5">
                                <span class="min-w-48 flex-1">
                                    <span class="block text-sm text-gray-600 dark:text-gray-400">
                                        <span class="sr-only">{{ $heldOn->isoFormat('dddd, D. MMMM') }}:</span>
                                        {{ $lesson->subject }}, Klasse {{ $lesson->schoolClass->name }}
                                    </span>
                                    <span class="block font-medium text-gray-950 dark:text-white">{{ $lesson->topic }}</span>
                                </span>

                                @if ($lesson->isPublished())
                                    <span class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                                        <span class="text-sm text-gray-700 tabular-nums dark:text-gray-300">
                                            {{ $lesson->submissions_count }} / {{ $lesson->students_count }} abgegeben
                                        </span>

                                        <x-filament::badge color="success" :icon="Heroicon::OutlinedPaperAirplane">
                                            Verteilt{{ $lesson->due_on ? ', fällig '.$lesson->due_on->copy()->locale('de')->isoFormat('D.M.') : '' }}
                                        </x-filament::badge>
                                    </span>
                                @else
                                    <x-filament::badge color="gray" :icon="Heroicon::OutlinedPencilSquare">
                                        Entwurf
                                    </x-filament::badge>
                                @endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
