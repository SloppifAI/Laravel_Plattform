{{-- The page skeleton of Filament (styles, scripts, Livewire) without the panel chrome: the Beamer display has no menu, no names, no user. --}}
<x-filament-panels::layout.base :livewire="$livewire">
    {{ $slot }}
</x-filament-panels::layout.base>
