{{-- Marks a Förderaufgabe under its topic in the lists of lessons and homework; $class adds responsive utilities. --}}
<x-filament::badge color="info" icon="heroicon-o-lifebuoy" size="sm" class="mt-1 w-fit min-w-max {{ $class ?? '' }}">
    {{ __('Förderaufgabe') }}
</x-filament::badge>
