@php
    use App\Filament\Pages\LessonStarter;
    use App\UnderstandingLevel;
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament::section :heading="__('Zum Einstieg wiederholen')" :icon="Heroicon::OutlinedClipboardDocumentCheck">
    <p class="text-sm text-gray-600 dark:text-gray-400">
        {{ __('Das hat die Klasse in der letzten Stunde „:topic“ vom :date noch nicht sicher verstanden.', ['topic' => $previous->topic, 'date' => $previous->held_on->format('d.m.Y')]) }}
    </p>

    <ol class="mt-4 grid list-decimal gap-3 ps-5 marker:text-gray-500 dark:marker:text-gray-400">
        @foreach ($points as $point)
            <li class="ps-1">
                <span class="font-medium break-words text-gray-950 dark:text-white">{{ $point['title'] }}</span>

                <span class="block text-sm text-gray-600 tabular-nums dark:text-gray-400">
                    @if ($point['kind'] === 'question')
                        {{ __('Im Schnitt :average %, :below von :answered Antworten unter :threshold %', ['average' => $point['average'], 'below' => $point['below'], 'answered' => $point['answered'], 'threshold' => UnderstandingLevel::SUPPORT_THRESHOLD]) }}
                    @else
                        {{ trans_choice('Von :count Schüler:in als Lücke genannt|Von :count Schüler:innen als Lücke genannt', $point['count']) }}
                    @endif
                </span>
            </li>
        @endforeach
    </ol>

    {{-- A new tab, so the lesson being entered here stays untouched. --}}
    <p class="mt-4">
        <x-filament::link
            tag="a"
            :href="LessonStarter::getUrl(['lesson' => $previous])"
            target="_blank"
            rel="noopener"
            :icon="Heroicon::OutlinedPresentationChartBar"
        >
            {{ __('Einstieg der letzten Stunde öffnen') }}
        </x-filament::link>
    </p>
</x-filament::section>
