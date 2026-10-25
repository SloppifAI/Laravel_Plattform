@php
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-widgets::widget>
    <x-filament::section :heading="__('Neueste Registrierungen')">
        @if ($schoolsUrl)
            <x-slot name="afterHeader">
                <x-filament::button tag="a" :href="$schoolsUrl" color="gray" :icon="Heroicon::OutlinedBuildingLibrary">
                    {{ __('Alle Schulen') }}
                </x-filament::button>
            </x-slot>
        @endif

        @if ($schools->isEmpty())
            <div class="flex items-center gap-4">
                <x-filament::icon :icon="Heroicon::OutlinedBuildingLibrary" class="size-8 shrink-0 text-primary-600 dark:text-primary-400" />

                <p class="text-sm text-gray-700 dark:text-gray-300">
                    {{ __('Noch keine Schule registriert. Sobald sich eine Schule selbst anmeldet, erscheint sie hier.') }}
                </p>
            </div>
        @else
            <ul class="-my-2 divide-y divide-gray-950/5 dark:divide-white/10">
                @foreach ($schools as $school)
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-1.5 py-3">
                        <span class="min-w-48 flex-1">
                            <span class="block text-sm text-gray-600 dark:text-gray-400">
                                {{ collect([$school->school_type, $school->city, $school->state])->filter()->implode(', ') }}
                            </span>
                            <span class="block font-medium text-gray-950 dark:text-white">{{ $school->name }}</span>
                        </span>

                        <span class="text-sm text-gray-700 tabular-nums dark:text-gray-300">
                            {{ __('Registriert am :date', ['date' => $school->created_at->copy()->locale(app()->getLocale())->isoFormat(__('DD.MM.YYYY'))]) }}
                        </span>

                        @if ($school->isBlocked())
                            <x-filament::badge color="danger" :icon="Heroicon::OutlinedNoSymbol">{{ __('Gesperrt') }}</x-filament::badge>
                        @else
                            <x-filament::badge color="success" :icon="Heroicon::OutlinedCheckCircle">{{ __('Aktiv') }}</x-filament::badge>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
