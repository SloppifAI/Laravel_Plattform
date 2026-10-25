@php
    use App\Support\LearningInsights;
    use App\Support\LessonReview;
    use App\UnderstandingLevel;

    $evaluatedCount = $review['evaluatedCount'];
    $hasSolutions = collect($review['points'])->contains(fn (array $point): bool => filled($point['expected_answer']));
    $evaluatedSubmissions = trans_choice(':count ausgewertete Abgabe|:count ausgewertete Abgaben', $evaluatedCount);
@endphp

<x-filament-panels::page>
    <style>
        #lp-starter-board:fullscreen {
            overflow-y: auto;
        }

        @media print {
            .fi-sidebar, .fi-topbar-ctn, .fi-layout-sidebar-toggle-btn-ctn, .fi-header, .fi-header-actions-ctn, .fi-sidebar-close-overlay {
                display: none !important;
            }

            .fi-main-ctn, .fi-main {
                margin: 0 !important;
                padding: 0 !important;
                max-width: none !important;
            }

            .fi-body {
                background: #fff !important;
            }

            /* Browsers drop backgrounds when printing and the dark theme would leave white text on white: print dark on white with outlines. */
            #lp-starter-board {
                background: #fff !important;
                box-shadow: none !important;
            }

            #lp-starter-board * {
                background-color: transparent !important;
                border-color: #6b7280 !important;
                box-shadow: none !important;
                color: #111827 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            #lp-starter-board .lp-starter-number {
                border: 3px solid #111827;
            }

            #lp-starter-board li {
                break-inside: avoid;
            }
        }
    </style>

    {{-- The whole board is the fullscreen element, so the solution toggle stays usable on the projector. --}}
    <section
        id="lp-starter-board"
        lang="{{ str_replace('_', '-', app()->getLocale()) }}"
        class="lp-card lp-paper"
        aria-labelledby="lp-starter-topic"
        x-data="{ full: false, canFullscreen: !! document.fullscreenEnabled }"
        x-on:fullscreenchange.window="full = document.fullscreenElement === $root"
    >
        <div class="py-8 ps-10 pe-4 sm:py-10 sm:ps-20 sm:pe-10">
            <header class="min-w-0">
                <p class="text-base font-medium text-primary-700 sm:text-lg dark:text-primary-400">{{ __('Aus der Stunde') }}</p>

                <h2 id="lp-starter-topic" class="mt-1 min-w-0 text-3xl font-semibold tracking-tight text-balance break-words hyphens-auto text-gray-950 sm:text-5xl dark:text-white">
                    {{ $lesson->topic }}
                </h2>

                <p class="mt-3 text-lg break-words text-gray-700 sm:text-xl dark:text-gray-300">
                    {{ collect([$lesson->subject, $schoolClass?->name, __('Stunde vom :date', ['date' => $lesson->held_on->format('d.m.Y')])])->filter()->implode(' · ') }}
                </p>
            </header>

            <div class="mt-6 flex flex-wrap items-center gap-3 print:hidden">
                @if ($hasSolutions)
                    <x-filament::button
                        color="gray"
                        :icon="$showSolutions ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down'"
                        wire:click="$toggle('showSolutions')"
                    >
                        {{ $showSolutions ? __('Lösungshinweise ausblenden') : __('Lösungshinweise einblenden') }}
                    </x-filament::button>
                @endif

                <x-filament::button
                    color="gray"
                    icon="heroicon-m-arrows-pointing-out"
                    x-cloak
                    x-show="canFullscreen"
                    x-on:click="full ? document.exitFullscreen() : $root.requestFullscreen()"
                >
                    <span x-text="full ? @js(__('Vollbild beenden')) : @js(__('Vollbild'))">{{ __('Vollbild') }}</span>
                </x-filament::button>

                <x-filament::button color="gray" icon="heroicon-m-printer" x-on:click="window.print()">
                    {{ __('Drucken') }}
                </x-filament::button>
            </div>

            @if ($review['points'] === [])
                <div class="lp-card mt-8 flex items-start gap-4 p-6 sm:p-8">
                    <x-filament::icon icon="heroicon-o-clipboard-document-check" class="mt-1 size-9 shrink-0 text-primary-600 dark:text-primary-400" />

                    <div class="min-w-0">
                        <h3 class="text-2xl font-semibold text-gray-950 dark:text-white">
                            @if ($evaluatedCount === 0)
                                {{ __('Noch nichts ausgewertet') }}
                            @elseif ($evaluatedCount < LessonReview::MIN_GROUP)
                                {{ __('Noch zu wenige Abgaben für einen Klassen-Einstieg') }}
                            @else
                                {{ __('Nichts, was wiederholt werden müsste') }}
                            @endif
                        </h3>

                        <p class="mt-2 text-lg text-pretty text-gray-700 dark:text-gray-300">
                            @if ($evaluatedCount === 0)
                                {{ __('Sobald Schüler:innen ihre Hausaufgabe abgegeben haben und die KI sie geprüft hat, erscheinen hier die drei Punkte, die sich für eine Wiederholung lohnen. Bis dahin gibt es noch keinen Einstieg zu dieser Stunde.') }}
                            @elseif ($evaluatedCount < LessonReview::MIN_GROUP)
                                {{ __('Ein Einstieg für die Klasse braucht mindestens :min ausgewertete Abgaben, damit er nicht das Ergebnis einzelner Schüler:innen zeigt.', ['min' => LessonReview::MIN_GROUP]) }}
                                {{ trans_choice('Bisher liegt :count ausgewertete Abgabe vor.|Bisher liegen :count ausgewertete Abgaben vor.', $evaluatedCount) }}
                            @else
                                {{ __('Aus den :count ausgewerteten Abgaben ergibt sich weder eine Frage, die die Klasse noch nicht sicher beantwortet, noch eine Lücke, die mehrere Schüler:innen betrifft.', ['count' => $evaluatedCount]) }}
                            @endif
                        </p>
                    </div>
                </div>
            @else
                <p class="mt-8 text-xl font-medium text-gray-950 sm:text-2xl dark:text-white">
                    {{ __('Diese Punkte lohnen eine Wiederholung. Wer kann sie erklären?') }}
                </p>

                <ol class="mt-6 grid list-none gap-4 p-0 sm:gap-6">
                    @foreach ($review['points'] as $point)
                        <li class="lp-card flex items-start gap-3 p-4 sm:gap-6 sm:p-8">
                            <span aria-hidden="true" class="lp-starter-number flex size-10 shrink-0 items-center justify-center rounded-full bg-primary-600 text-xl font-semibold text-white sm:size-16 sm:text-3xl">
                                {{ $loop->iteration }}
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="text-lg font-medium text-primary-700 sm:text-xl dark:text-primary-400">{{ __('Wer kann das erklären?') }}</p>

                                <h3 class="mt-1 text-2xl font-semibold text-balance break-words hyphens-auto text-gray-950 sm:text-3xl dark:text-white">
                                    {{ $point['title'] }}
                                </h3>

                                <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-base text-gray-700 sm:text-lg dark:text-gray-300">
                                    @if ($point['kind'] === 'question')
                                        @php($level = LearningInsights::studentLevel($point['average']))

                                        <x-filament::badge :color="$level['color']" :icon="$level['icon']" size="lg">
                                            {{ $level['label'] }}
                                        </x-filament::badge>

                                        <span>{{ __('Im Schnitt :average %', ['average' => $point['average']]) }}</span>

                                        <span>{{ __(':below von :answered Antworten unter :threshold %', ['below' => $point['below'], 'answered' => $point['answered'], 'threshold' => UnderstandingLevel::SUPPORT_THRESHOLD]) }}</span>
                                    @else
                                        <span>{{ __('In :count von :evaluated Abgaben als Lücke genannt', ['count' => $point['count'], 'evaluated' => $evaluatedCount]) }}</span>
                                    @endif
                                </div>

                                @if ($showSolutions && filled($point['expected_answer']))
                                    <div class="mt-5 rounded-xl border border-primary-200 bg-primary-50 p-4 sm:p-5 dark:border-primary-400/30 dark:bg-primary-400/10">
                                        <p class="text-base font-semibold text-primary-800 sm:text-lg dark:text-primary-300">{{ __('Lösungshinweis') }}</p>

                                        <p class="mt-1 text-lg break-words whitespace-pre-line text-gray-950 sm:text-xl dark:text-gray-100">{{ $point['expected_answer'] }}</p>
                                    </div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif

            <p class="mt-8 max-w-3xl text-sm text-pretty text-gray-600 dark:text-gray-400">
                {{ __('Grundlage:') }}
                @if ($assignedCount >= $evaluatedCount && $assignedCount > 0)
                    {{ __(':evaluated von :assigned Schüler:innen haben abgegeben und wurden ausgewertet.', ['evaluated' => $evaluatedCount, 'assigned' => $assignedCount]) }}
                @else
                    {{ $evaluatedSubmissions }}.
                @endif
                {{ __('Die Punkte sind KI-gestützte Einschätzungen aus den Hausaufgaben, keine Noten. Es werden keine Namen und keine Einzelergebnisse gezeigt.') }}
            </p>
        </div>
    </section>
</x-filament-panels::page>
