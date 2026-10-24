@php
    use Filament\Support\Icons\Heroicon;

    $areas = [
        ['icon' => Heroicon::OutlinedBookOpen, 'title' => 'Kurse', 'description' => 'Lerneinheiten Schritt für Schritt durcharbeiten.'],
        ['icon' => Heroicon::OutlinedPencilSquare, 'title' => 'Übungen', 'description' => 'Wissen mit kurzen Aufgaben und Quizfragen festigen.'],
        ['icon' => Heroicon::OutlinedChartBar, 'title' => 'Lernfortschritt', 'description' => 'Sehen, was du schon geschafft hast.'],
    ];
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <div class="max-w-2xl">
            <h2 class="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">
                Hallo {{ filament()->getUserName(filament()->auth()->user()) }}
            </h2>

            <p class="mt-2 text-gray-600 dark:text-gray-400">
                Die Lerninhalte werden gerade vorbereitet. Sobald sie freigeschaltet sind, findest du sie hier.
            </p>
        </div>

        <ul class="mt-8 divide-y divide-gray-950/5 border-t border-gray-950/5 dark:divide-white/10 dark:border-white/10">
            @foreach ($areas as $area)
                <li class="flex items-center gap-4 py-4">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                        <x-filament::icon :icon="$area['icon']" class="size-5" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-gray-950 dark:text-white">{{ $area['title'] }}</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ $area['description'] }}</p>
                    </div>

                    <x-filament::badge color="gray">Bald verfügbar</x-filament::badge>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
