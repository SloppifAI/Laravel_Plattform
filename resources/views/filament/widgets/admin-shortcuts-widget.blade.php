@php
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-widgets::widget>
    <x-filament::section :heading="__('Verwaltung')" :description="__('Benutzer und Klassen pflegen, das Kompetenzraster einsehen.')">
        @if ($shortcuts->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Für dich sind gerade keine Verwaltungsbereiche freigeschaltet.') }}</p>
        @else
            <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($shortcuts as $shortcut)
                    <li>
                        <a
                            href="{{ $shortcut->getUrl() }}"
                            @if ($shortcut->shouldOpenUrlInNewTab()) target="_blank" rel="noopener" @endif
                            class="lp-focus flex items-center gap-3 rounded-xl p-3 ring-1 ring-gray-950/10 transition hover:bg-primary-50 hover:ring-primary-200 dark:ring-white/10 dark:hover:bg-primary-400/10 dark:hover:ring-primary-400/30"
                        >
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-300">
                                <x-filament::icon :icon="$shortcut->getIcon() ?? Heroicon::OutlinedSquares2x2" class="size-5" />
                            </span>

                            <span class="min-w-0 flex-1 truncate font-medium text-gray-950 dark:text-white">{{ $shortcut->getLabel() }}</span>

                            @if (filled($shortcut->getBadge()))
                                <x-filament::badge :color="$shortcut->getBadgeColor() ?? 'primary'">{{ $shortcut->getBadge() }}</x-filament::badge>
                            @endif

                            <x-filament::icon :icon="Heroicon::ChevronRight" class="size-5 shrink-0 text-gray-400 dark:text-gray-500" />
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
