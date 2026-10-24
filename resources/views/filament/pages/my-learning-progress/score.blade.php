{{-- A 0–100 score for students: number with unit, icon and label, never colour alone. Wording and colour come from LearningInsights::studentLevel(). --}}
@php
    $level = \App\Support\LearningInsights::studentLevel($score);
@endphp

<x-filament::badge :color="$level['color']" :icon="$level['icon']" class="w-fit">
    {{ $score }} % · {{ $level['label'] }}
</x-filament::badge>
