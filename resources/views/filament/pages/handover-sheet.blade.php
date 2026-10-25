@php
    use Filament\Support\Icons\Heroicon;

    $heading = 'text-lg font-semibold text-gray-950 dark:text-white';
    $muted = 'text-sm text-gray-600 dark:text-gray-400';
    $leader = $class->teacher;
@endphp

<x-filament-panels::page>
    <style>
        @media print {
            .fi-sidebar, .fi-topbar-ctn, .fi-layout-sidebar-toggle-btn-ctn, .fi-header-actions-ctn, .fi-sidebar-close-overlay, .fi-breadcrumbs {
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

            /* Browsers drop backgrounds when printing and the dark theme would leave white text on white: print dark on white. */
            #lp-handover {
                background: #fff !important;
                box-shadow: none !important;
            }

            #lp-handover * {
                background-color: transparent !important;
                border-color: #6b7280 !important;
                box-shadow: none !important;
                color: #111827 !important;
            }

            #lp-handover section, #lp-handover li {
                break-inside: avoid;
            }
        }
    </style>

    <article id="lp-handover" lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="lp-card lp-paper" aria-labelledby="lp-handover-class">
        <div class="space-y-8 py-8 ps-10 pe-4 sm:py-10 sm:ps-20 sm:pe-10">
            <header class="min-w-0">
                <p class="text-base font-medium text-primary-700 dark:text-primary-400">{{ __('Übergabe-Mappe') }}</p>

                <h2 id="lp-handover-class" class="mt-1 min-w-0 text-3xl font-semibold tracking-tight break-words text-gray-950 sm:text-4xl dark:text-white">
                    {{ __('Klasse :class', ['class' => $class->name]) }}
                </h2>

                <p class="mt-2 text-base break-words text-gray-700 dark:text-gray-300">
                    {{ collect([$class->school?->name, __('Stand :date', ['date' => today()->format('d.m.Y')])])->filter()->implode(' · ') }}
                </p>
            </header>

            <section aria-labelledby="lp-handover-contact">
                <h3 id="lp-handover-contact" class="{{ $heading }}">{{ __('Wen du fragen kannst') }}</h3>

                <dl class="mt-3 grid gap-x-8 gap-y-3 text-base sm:grid-cols-2">
                    <div>
                        <dt class="{{ $muted }}">{{ __('Klassenleitung') }}</dt>
                        <dd class="font-medium break-words text-gray-950 dark:text-white">
                            @if ($leader)
                                {{ $leader->name }}
                                @if (filled($leader->email))
                                    <span class="block text-sm font-normal break-all text-gray-700 dark:text-gray-300">{{ $leader->email }}</span>
                                @endif
                            @else
                                {{ __('Noch keine Klassenleitung eingetragen. Die Schuladministration weiß, wer die Klasse betreut.') }}
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="{{ $muted }}">{{ __('Schüler:innen in der Klasse') }}</dt>
                        <dd class="font-medium text-gray-950 dark:text-white">{{ $studentCount }}</dd>
                    </div>
                </dl>
            </section>

            <section aria-labelledby="lp-handover-note">
                <h3 id="lp-handover-note" class="{{ $heading }}">{{ $isManager ? __('Vertretung und Hinweis zur Übergabe') : __('Hinweis zur Übergabe') }}</h3>

                @forelse ($substitutions as $substitution)
                    <div class="mt-3 min-w-0">
                        <p class="font-medium break-words text-gray-950 dark:text-white">
                            @if ($isManager)
                                {{ $substitution->substitute->name }},
                            @endif
                            {{ __(':start bis :end', ['start' => $substitution->starts_on->format('d.m.Y'), 'end' => $substitution->ends_on->format('d.m.Y')]) }}
                            @if ($substitution->isUpcoming())
                                <span class="{{ $muted }}">{{ __('(geplant)') }}</span>
                            @endif
                        </p>

                        <p class="mt-1 text-base break-words whitespace-pre-line text-gray-800 dark:text-gray-200">{{ filled($substitution->note) ? $substitution->note : __('Kein Hinweis hinterlegt.') }}</p>
                    </div>
                @empty
                    <p class="mt-3 text-base text-gray-700 dark:text-gray-300">{{ __('Zurzeit ist keine Vertretung eingerichtet.') }}</p>
                @endforelse
            </section>

            <section aria-labelledby="lp-handover-lessons">
                <h3 id="lp-handover-lessons" class="{{ $heading }}">{{ __('Die letzten Stunden') }}</h3>

                @if ($lessons->isEmpty())
                    <p class="mt-3 text-base text-gray-700 dark:text-gray-300">{{ __('In dieser Klasse wurde noch keine Stunde angelegt.') }}</p>
                @else
                    <ul class="mt-3 grid list-none gap-4 p-0">
                        @foreach ($lessons as $lesson)
                            <li class="min-w-0 border-s-2 border-primary-200 ps-4 dark:border-primary-400/40">
                                <p class="{{ $muted }}">
                                    {{ $lesson->held_on->format('d.m.Y') }} · {{ $lesson->subject }}@if ($lesson->teacher) · {{ $lesson->teacher->name }}@endif
                                </p>

                                <h4 class="font-semibold break-words text-gray-950 dark:text-white">{{ $lesson->topic }}</h4>

                                @if ($lesson->isPublished() && filled($lesson->summary))
                                    {{-- HTML from the rich editor or Markdown from the AI and apps; sanitised like Filament's own Markdown entries. --}}
                                    <div class="fi-prose mt-1 text-base break-words text-gray-800 dark:text-gray-200">{!! str(\App\Models\Lesson::summaryToHtml($lesson->summary))->sanitizeHtml() !!}</div>
                                @else
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Die Zusammenfassung ist noch nicht freigegeben.') }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section aria-labelledby="lp-handover-homework">
                <h3 id="lp-handover-homework" class="{{ $heading }}">{{ __('Offene Hausaufgaben') }}</h3>

                @if ($openHomework->isEmpty())
                    <p class="mt-3 text-base text-gray-700 dark:text-gray-300">{{ __('Zurzeit ist keine Hausaufgabe offen.') }}</p>
                @else
                    <ul class="mt-3 grid list-none gap-4 p-0">
                        @foreach ($openHomework as $homework)
                            <li class="min-w-0">
                                <h4 class="font-semibold break-words text-gray-950 dark:text-white">{{ $homework->topic }}</h4>

                                <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-base text-gray-800 dark:text-gray-200">
                                    <span>{{ $homework->subject }}</span>

                                    @if ($homework->due_on)
                                        <span class="lp-marker">{{ __('Abgabe bis :date', ['date' => $homework->due_on->format('d.m.Y')]) }}</span>
                                    @endif

                                    <span>{{ __(':count von :total abgegeben', ['count' => $homework->submissions_count, 'total' => $homework->students_count]) }}</span>
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section aria-labelledby="lp-handover-starter">
                <h3 id="lp-handover-starter" class="{{ $heading }}">{{ __('Einstieg für die nächste Stunde') }}</h3>

                @if ($reviews->isEmpty())
                    <p class="mt-3 text-base text-gray-700 dark:text-gray-300">
                        {{ __('Noch nichts zu wiederholen: Es liegen noch nicht genug ausgewertete Abgaben vor, um zu sagen, was die Klasse noch nicht sicher kann.') }}
                    </p>
                @else
                    <div class="mt-3 grid gap-5">
                        @foreach ($reviews as $item)
                            <div class="min-w-0">
                                <p class="{{ $muted }}">
                                    {{ __(':subject: Auswertung zur Stunde „:topic“ vom :date', ['subject' => $item['lesson']->subject, 'topic' => $item['lesson']->topic, 'date' => $item['lesson']->held_on->format('d.m.Y')]) }}
                                </p>

                                <ol class="mt-2 grid list-decimal gap-2 ps-6 text-base text-gray-950 dark:text-white">
                                    @foreach ($item['review']['points'] as $point)
                                        <li class="break-words">
                                            <span class="font-medium">{{ $point['title'] }}</span>
                                            <span class="{{ $muted }}">
                                                @if ($point['kind'] === 'question')
                                                    {{ __('Im Schnitt :average %', ['average' => $point['average']]) }}
                                                @else
                                                    {{ __('In :count von :total Abgaben als Lücke genannt', ['count' => $point['count'], 'total' => $item['review']['evaluatedCount']]) }}
                                                @endif
                                            </span>
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <p class="max-w-3xl text-sm text-pretty text-gray-600 dark:text-gray-400">
                <x-filament::icon :icon="Heroicon::OutlinedInformationCircle" class="me-1 inline size-4 align-text-bottom" />
                {{ __('Diese Mappe zeigt nur Zahlen zur ganzen Klasse, keine Namen und keine Einzelergebnisse von Schüler:innen. Die Punkte zum Einstieg sind KI-gestützte Einschätzungen aus den Hausaufgaben, keine Noten.') }}
            </p>
        </div>
    </article>
</x-filament-panels::page>
