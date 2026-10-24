@php
    use App\Filament\Pages\LessonStarter;
    use App\Filament\Resources\Lessons\LessonResource;
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-widgets::widget>
    <x-filament::section :heading="__('Deine letzten Unterrichtsstunden')">
        <x-slot name="afterHeader">
            <div class="flex flex-wrap gap-2">
                <x-filament::button tag="a" :href="$todayUrl" color="gray" :icon="Heroicon::OutlinedSun">
                    {{ __('Heute: Hausaufgaben freigeben') }}
                </x-filament::button>

                <x-filament::button tag="a" :href="$learningProgressUrl" color="gray" :icon="Heroicon::OutlinedChartBar">
                    {{ __('Lernstand & Förderbedarf') }}
                </x-filament::button>

                <x-filament::button tag="a" :href="$createLessonUrl" :icon="Heroicon::Plus">
                    {{ __('Neue Unterrichtsstunde') }}
                </x-filament::button>
            </div>
        </x-slot>

        @if ($lessons->isEmpty())
            <div class="flex items-center gap-4">
                <x-filament::icon :icon="Heroicon::OutlinedPencilSquare" class="size-8 shrink-0 text-primary-600 dark:text-primary-400" />

                <p class="text-sm text-gray-700 dark:text-gray-300">
                    {{ __('Noch keine Unterrichtsstunde festgehalten. Trag deine erste Stunde ein: Die KI fasst sie zusammen und schlägt passende Hausaufgaben vor.') }}
                </p>
            </div>
        @else
            <ul class="-my-2 divide-y divide-gray-950/5 dark:divide-white/10">
                @foreach ($lessons as $lesson)
                    @php($heldOn = $lesson->held_on->copy()->locale(app()->getLocale()))

                    {{-- The starter board needs enough evaluated hand-ins, is never offered for a Förderaufgabe (see LessonReview) and only to those who may read the results. --}}
                    @php($canViewResults = auth()->user()->can('viewResults', $lesson))
                    @php($hasStarter = ! $lesson->isPracticeTask() && $lesson->evaluated_submissions_count >= $minReviewGroup && $canViewResults)

                    <li class="-mx-3 flex flex-wrap items-center gap-x-4 rounded-xl px-3 transition hover:bg-gray-50 dark:hover:bg-white/5">
                        <a
                            href="{{ LessonResource::getUrl('edit', ['record' => $lesson]) }}"
                            class="lp-focus flex min-w-0 flex-1 basis-80 items-center gap-4 rounded-xl py-3"
                        >
                            <span class="flex size-12 shrink-0 flex-col items-center justify-center rounded-xl bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-200" aria-hidden="true">
                                <span class="text-lg leading-none font-semibold tabular-nums">{{ $heldOn->isoFormat('D') }}</span>
                                <span class="mt-0.5 text-xs leading-none">{{ $heldOn->isoFormat('MMM') }}</span>
                            </span>

                            <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-4 gap-y-1.5">
                                <span class="min-w-48 flex-1">
                                    <span class="block text-sm text-gray-600 dark:text-gray-400">
                                        <span class="sr-only">{{ $heldOn->isoFormat('dddd, '.__('D. MMMM')) }}:</span>
                                        {{ __(':subject, Klasse :class', ['subject' => $lesson->subject, 'class' => $lesson->schoolClass->name]) }}
                                    </span>
                                    <span class="block font-medium text-gray-950 dark:text-white">{{ $lesson->topic }}</span>
                                </span>

                                @if ($lesson->isPublished())
                                    <span class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                                        @if ($canViewResults)
                                            <span class="text-sm text-gray-700 tabular-nums dark:text-gray-300">
                                                {{ __(':submitted / :total abgegeben', ['submitted' => $lesson->submissions_count, 'total' => $lesson->students_count]) }}
                                            </span>
                                        @endif

                                        <x-filament::badge color="primary" :icon="Heroicon::OutlinedCheckCircle">
                                            {{ $lesson->due_on ? __('Freigegeben, fällig :date', ['date' => $lesson->due_on->copy()->locale(app()->getLocale())->isoFormat(__('D.M.'))]) : __('Freigegeben') }}
                                        </x-filament::badge>
                                    </span>
                                @else
                                    <x-filament::badge color="warning" :icon="Heroicon::OutlinedClock">
                                        {{ __('Wartet auf Freigabe') }}
                                    </x-filament::badge>
                                @endif
                            </span>
                        </a>

                        @if ($hasStarter)
                            <x-filament::link
                                tag="a"
                                :href="LessonStarter::getUrl(['lesson' => $lesson])"
                                :icon="Heroicon::OutlinedPresentationChartBar"
                                size="sm"
                                class="shrink-0 pb-3 sm:pb-0"
                            >
                                {{ __('Einstieg nächste Stunde') }}
                            </x-filament::link>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
