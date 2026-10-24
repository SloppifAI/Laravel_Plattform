@php
    use App\UnderstandingLevel;

    $threshold = UnderstandingLevel::SUPPORT_THRESHOLD;
    $ranges = [[75, 'ab 75 Punkte'], [$threshold, $threshold.'–74 Punkte'], [0, 'unter '.$threshold.' Punkte']];
@endphp

<ul class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-gray-600 dark:text-gray-400" aria-label="Legende der Bewertungsstufen">
    @foreach ($ranges as [$score, $range])
        @php
            $level = UnderstandingLevel::fromScore($score);
        @endphp

        <li class="flex items-center gap-1.5">
            <x-filament::badge :color="$level->getColor()" :icon="$level->getIcon()" size="sm">{{ $level->getLabel() }}</x-filament::badge>
            {{ $range }}
        </li>
    @endforeach
</ul>
