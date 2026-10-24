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

    <article id="lp-handover" lang="de" class="lp-card lp-paper" aria-labelledby="lp-handover-class">
        <div class="space-y-8 py-8 ps-10 pe-4 sm:py-10 sm:ps-20 sm:pe-10">
            <header class="min-w-0">
                <p class="text-base font-medium text-primary-700 dark:text-primary-400">Übergabe-Mappe</p>

                <h2 id="lp-handover-class" class="mt-1 min-w-0 text-3xl font-semibold tracking-tight break-words text-gray-950 sm:text-4xl dark:text-white">
                    Klasse {{ $class->name }}
                </h2>

                <p class="mt-2 text-base break-words text-gray-700 dark:text-gray-300">
                    {{ collect([$class->school?->name, 'Stand '.today()->format('d.m.Y')])->filter()->implode(' · ') }}
                </p>
            </header>

            <section aria-labelledby="lp-handover-contact">
                <h3 id="lp-handover-contact" class="{{ $heading }}">Wen du fragen kannst</h3>

                <dl class="mt-3 grid gap-x-8 gap-y-3 text-base sm:grid-cols-2">
                    <div>
                        <dt class="{{ $muted }}">Klassenleitung</dt>
                        <dd class="font-medium break-words text-gray-950 dark:text-white">
                            @if ($leader)
                                {{ $leader->name }}
                                @if (filled($leader->email))
                                    <span class="block text-sm font-normal break-all text-gray-700 dark:text-gray-300">{{ $leader->email }}</span>
                                @endif
                            @else
                                Noch keine Klassenleitung eingetragen. Die Schuladministration weiß, wer die Klasse betreut.
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="{{ $muted }}">Schüler:innen in der Klasse</dt>
                        <dd class="font-medium text-gray-950 dark:text-white">{{ $studentCount }}</dd>
                    </div>
                </dl>
            </section>

            <section aria-labelledby="lp-handover-note">
                <h3 id="lp-handover-note" class="{{ $heading }}">{{ $isManager ? 'Vertretung und Hinweis zur Übergabe' : 'Hinweis zur Übergabe' }}</h3>

                @forelse ($substitutions as $substitution)
                    <div class="mt-3 min-w-0">
                        <p class="font-medium break-words text-gray-950 dark:text-white">
                            @if ($isManager)
                                {{ $substitution->substitute->name }},
                            @endif
                            {{ $substitution->starts_on->format('d.m.Y') }} bis {{ $substitution->ends_on->format('d.m.Y') }}
                            @if ($substitution->isUpcoming())
                                <span class="{{ $muted }}">(geplant)</span>
                            @endif
                        </p>

                        <p class="mt-1 text-base break-words whitespace-pre-line text-gray-800 dark:text-gray-200">{{ filled($substitution->note) ? $substitution->note : 'Kein Hinweis hinterlegt.' }}</p>
                    </div>
                @empty
                    <p class="mt-3 text-base text-gray-700 dark:text-gray-300">Zurzeit ist keine Vertretung eingerichtet.</p>
                @endforelse
            </section>

            <section aria-labelledby="lp-handover-lessons">
                <h3 id="lp-handover-lessons" class="{{ $heading }}">Die letzten Stunden</h3>

                @if ($lessons->isEmpty())
                    <p class="mt-3 text-base text-gray-700 dark:text-gray-300">In dieser Klasse wurde noch keine Stunde angelegt.</p>
                @else
                    <ul class="mt-3 grid list-none gap-4 p-0">
                        @foreach ($lessons as $lesson)
                            <li class="min-w-0 border-s-2 border-primary-200 ps-4 dark:border-primary-400/40">
                                <p class="{{ $muted }}">
                                    {{ $lesson->held_on->format('d.m.Y') }} · {{ $lesson->subject }}@if ($lesson->teacher) · {{ $lesson->teacher->name }}@endif
                                </p>

                                <h4 class="font-semibold break-words text-gray-950 dark:text-white">{{ $lesson->topic }}</h4>

                                @if ($lesson->isPublished() && filled($lesson->summary))
                                    <p class="mt-1 text-base break-words whitespace-pre-line text-gray-800 dark:text-gray-200">{{ $lesson->summary }}</p>
                                @else
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Die Zusammenfassung ist noch nicht freigegeben.</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section aria-labelledby="lp-handover-homework">
                <h3 id="lp-handover-homework" class="{{ $heading }}">Offene Hausaufgaben</h3>

                @if ($openHomework->isEmpty())
                    <p class="mt-3 text-base text-gray-700 dark:text-gray-300">Zurzeit ist keine Hausaufgabe offen.</p>
                @else
                    <ul class="mt-3 grid list-none gap-4 p-0">
                        @foreach ($openHomework as $homework)
                            <li class="min-w-0">
                                <h4 class="font-semibold break-words text-gray-950 dark:text-white">{{ $homework->topic }}</h4>

                                <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-base text-gray-800 dark:text-gray-200">
                                    <span>{{ $homework->subject }}</span>

                                    @if ($homework->due_on)
                                        <span class="lp-marker">Abgabe bis {{ $homework->due_on->format('d.m.Y') }}</span>
                                    @endif

                                    <span>{{ $homework->submissions_count }} von {{ $homework->students_count }} abgegeben</span>
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section aria-labelledby="lp-handover-starter">
                <h3 id="lp-handover-starter" class="{{ $heading }}">Einstieg für die nächste Stunde</h3>

                @if ($reviews->isEmpty())
                    <p class="mt-3 text-base text-gray-700 dark:text-gray-300">
                        Noch nichts zu wiederholen: Es liegen noch nicht genug ausgewertete Abgaben vor, um zu sagen, was die Klasse noch nicht sicher kann.
                    </p>
                @else
                    <div class="mt-3 grid gap-5">
                        @foreach ($reviews as $item)
                            <div class="min-w-0">
                                <p class="{{ $muted }}">
                                    {{ $item['lesson']->subject }}: Auswertung zur Stunde „{{ $item['lesson']->topic }}“ vom {{ $item['lesson']->held_on->format('d.m.Y') }}
                                </p>

                                <ol class="mt-2 grid list-decimal gap-2 ps-6 text-base text-gray-950 dark:text-white">
                                    @foreach ($item['review']['points'] as $point)
                                        <li class="break-words">
                                            <span class="font-medium">{{ $point['title'] }}</span>
                                            <span class="{{ $muted }}">
                                                @if ($point['kind'] === 'question')
                                                    Im Schnitt {{ $point['average'] }} %
                                                @else
                                                    In {{ $point['count'] }} von {{ $item['review']['evaluatedCount'] }} Abgaben als Lücke genannt
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
                Diese Mappe zeigt nur Zahlen zur ganzen Klasse, keine Namen und keine Einzelergebnisse von Schüler:innen. Die Punkte zum Einstieg sind KI-gestützte Einschätzungen aus den Hausaufgaben, keine Noten.
            </p>
        </div>
    </article>
</x-filament-panels::page>
