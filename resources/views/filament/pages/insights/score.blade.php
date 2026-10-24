{{-- A 0–100 score as UnderstandingLevel badge: number, icon and (visible or screen-reader) label, never colour alone. --}}
@php
    $level = \App\UnderstandingLevel::fromScore($score);
@endphp

<x-filament::badge :color="$level->getColor()" :icon="$level->getIcon()" :tooltip="$level->getLabel()" class="w-fit">
    {{ $score }}@if ($withLabel ?? false) · {{ $level->getLabel() }}@else<span class="sr-only"> {{ __('Punkte, :level', ['level' => $level->getLabel()]) }}</span>@endif
</x-filament::badge>
