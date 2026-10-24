@php
    use App\UnderstandingLevel;
    use Filament\Support\Icons\Heroicon;

    $strengths = $competencies->filter(fn ($competency) => UnderstandingLevel::fromScore($averages[$competency->id]) === UnderstandingLevel::Understood);
    $weaknesses = $competencies->filter(fn ($competency) => $supportNeeds->has($competency->id));
    $finishedSessions = $sessions->filter->isFinished();
    $trendText = match (true) {
        $trend === null => __('Für eine Aussage zum Verlauf liegen noch zu wenige bewertete Hausaufgaben vor.'),
        $trend >= 5 => __('Steigend: Die späteren Hausaufgaben liegen im Mittel :points Punkte über den früheren.', ['points' => $trend]),
        $trend <= -5 => __('Fallend: Die späteren Hausaufgaben liegen im Mittel :points Punkte unter den früheren.', ['points' => abs($trend)]),
        default => __('Gleichbleibend: Die Bewertungen schwanken um weniger als 5 Punkte.'),
    };
    $trendIcon = match (true) {
        $trend === null => Heroicon::OutlinedMinus,
        $trend >= 5 => Heroicon::OutlinedArrowTrendingUp,
        $trend <= -5 => Heroicon::OutlinedArrowTrendingDown,
        default => Heroicon::OutlinedArrowLongRight,
    };
    $competencyLabel = fn ($competency) => $competency->name.' ('.($competency->subject ?? __('fachübergreifend')).')';
@endphp

<x-filament-panels::page>
    <style>
        @media print {
            .fi-sidebar, .fi-topbar-ctn, .fi-layout-sidebar-toggle-btn-ctn, .fi-header-actions-ctn, .fi-sidebar-close-overlay {
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

            .fi-section, tr {
                break-inside: avoid;
            }
        }
    </style>

    <x-filament::section :icon="Heroicon::OutlinedDocumentText" :heading="__('Grunddaten')">
        <dl class="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('Schüler:in') }}</dt>
                <dd class="font-medium text-gray-950 dark:text-white">{{ $student->name }}</dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('Klasse') }}</dt>
                <dd class="font-medium text-gray-950 dark:text-white">
                    {{ $student->schoolClass?->name ?? __('keiner Klasse zugeordnet') }}
                    @if ($student->schoolClass?->teacher)
                        <span class="font-normal text-gray-600 dark:text-gray-400">{{ __('(Klassenleitung: :name)', ['name' => $student->schoolClass->teacher->name]) }}</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('Zeitraum') }}</dt>
                <dd class="font-medium text-gray-950 dark:text-white">
                    {{ $periodStart ? __(':start bis :end', ['start' => $periodStart->format('d.m.Y'), 'end' => today()->format('d.m.Y')]) : __('noch keine Hausaufgaben') }}
                </dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">{{ __('Erstellt') }}</dt>
                <dd class="font-medium text-gray-950 dark:text-white">{{ __(':date von :name', ['date' => now()->format('d.m.Y'), 'name' => auth()->user()->name]) }}</dd>
            </div>
        </dl>

        <x-filament::callout
            class="mt-6"
            color="info"
            :icon="Heroicon::OutlinedInformationCircle"
            :heading="__('Hinweis zur Einordnung')"
            :description="__('Alle Punktwerte (0–100) sind KI-gestützte Einschätzungen des Verständnisses auf Grundlage der abgegebenen Hausaufgaben und „Erklär’s mir“-Sitzungen. Sie sind keine Noten und ersetzen nicht die pädagogische Beurteilung der Lehrkraft.')"
        />
    </x-filament::section>

    <x-filament::section :icon="Heroicon::OutlinedPresentationChartLine" :heading="__('Gesamtbild und Verlauf')">
        <div class="grid gap-6 sm:grid-cols-3">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Durchschnitt aller bewerteten Hausaufgaben') }}</p>
                <div class="mt-2">
                    @if ($average !== null)
                        @include('filament.pages.insights.score', ['score' => $average, 'withLabel' => true])
                    @else
                        <span class="text-sm text-gray-600 dark:text-gray-400">{{ __('noch keine Bewertung') }}</span>
                    @endif
                </div>
            </div>
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Abgabequote') }}</p>
                <p class="mt-1 text-sm text-gray-950 dark:text-white">
                    @if ($submissionRate !== null)
                        <span class="text-2xl font-semibold">{{ $submissionRate }} %</span>
                        <span class="block">{{ __(':submitted von :counted abgegeben, davon :onTime pünktlich', ['submitted' => $submittedCount, 'counted' => $countedCount, 'onTime' => $onTimeCount]) }}</span>
                    @else
                        {{ __('Noch keine Hausaufgabe fällig.') }}
                    @endif
                </p>
            </div>
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Verlauf (chronologisch)') }}</p>
                <p class="mt-1 flex items-start gap-2 text-sm text-gray-950 dark:text-white">
                    <x-filament::icon :icon="$trendIcon" class="mt-0.5 size-5 shrink-0 text-gray-500 dark:text-gray-400" />
                    <span>{{ $trendText }}</span>
                </p>
                @if ($scores->isNotEmpty())
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Einzelwerte: :values', ['values' => $scores->join(' → ')]) }}</p>
                @endif
            </div>
        </div>

        <div class="mt-6">
            @include('filament.pages.insights.legend')
        </div>
    </x-filament::section>

    <x-filament::section :icon="Heroicon::OutlinedChartBar" :heading="__('Kompetenzen')" :description="__('Durchschnitt aller bewerteten Antworten je Kompetenz des Referenzrahmens.')">
        @if ($competencies->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Noch keine bewerteten Antworten.') }}</p>
        @else
            {{-- Below md cards with a label per value, from md (and in print) the table. display:none keeps the inactive variant out of the accessibility tree. --}}
            <ul class="grid gap-3 md:hidden print:hidden">
                @foreach ($competencies as $competency)
                    <li class="grid gap-3 rounded-xl p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
                        <p class="font-medium text-gray-950 dark:text-white">{{ $competency->name }}</p>

                        <dl class="grid gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <div class="flex items-start justify-between gap-x-4">
                                <dt class="shrink-0">{{ __('Fach') }}</dt>
                                <dd class="min-w-0 break-words text-end">{{ $competency->subject ?? __('Fachübergreifend') }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-x-4">
                                <dt class="shrink-0">{{ __('Bereich') }}</dt>
                                <dd class="min-w-0 break-words text-end">{{ $competency->area }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-x-4">
                                <dt class="shrink-0">{{ __('Antworten') }}</dt>
                                <dd class="min-w-0 break-words text-end">{{ $answerCounts[$competency->id] }}</dd>
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                                <dt>{{ __('Durchschnitt') }}</dt>
                                <dd class="ms-auto">@include('filament.pages.insights.score', ['score' => $averages[$competency->id], 'withLabel' => true])</dd>
                            </div>
                        </dl>
                    </li>
                @endforeach
            </ul>

            <div class="hidden overflow-x-auto md:block print:block">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-950/5 dark:border-white/10">
                            <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">{{ __('Fach') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">{{ __('Bereich') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">{{ __('Kompetenz') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">{{ __('Antworten') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">{{ __('Durchschnitt') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-950/5 text-gray-600 dark:divide-white/10 dark:text-gray-400">
                        @foreach ($competencies as $competency)
                            <tr>
                                <td class="px-3 py-2">{{ $competency->subject ?? __('Fachübergreifend') }}</td>
                                <td class="px-3 py-2">{{ $competency->area }}</td>
                                <td class="px-3 py-2 text-gray-950 dark:text-white">{{ $competency->name }}</td>
                                <td class="px-3 py-2">{{ $answerCounts[$competency->id] }}</td>
                                <td class="px-3 py-2">@include('filament.pages.insights.score', ['score' => $averages[$competency->id], 'withLabel' => true])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section :icon="Heroicon::OutlinedPencilSquare" :heading="__('Hausaufgaben')" :description="__('Alle zugewiesenen Hausaufgaben mit Abgabestatus und Rückmeldung der KI.')">
        @if ($homework->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Bisher wurden keine Hausaufgaben zugewiesen.') }}</p>
        @else
            <ul class="grid gap-3 md:hidden print:hidden">
                @foreach ($homework as ['lesson' => $lesson, 'submission' => $submission, 'status' => [$statusLabel, $statusColor]])
                    <li class="grid gap-3 rounded-xl p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
                        <p class="font-medium text-gray-950 dark:text-white">{{ $lesson->topic }}</p>

                        <dl class="grid gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <div class="flex items-start justify-between gap-x-4">
                                <dt class="shrink-0">{{ __('Stunde') }}</dt>
                                <dd class="min-w-0 break-words text-end">
                                    {{ $lesson->held_on->format('d.m.Y') }}
                                    @if ($lesson->due_on)
                                        <span class="block text-xs">{{ __('fällig :date', ['date' => $lesson->due_on->format('d.m.Y')]) }}</span>
                                    @endif
                                </dd>
                            </div>
                            <div class="flex items-start justify-between gap-x-4">
                                <dt class="shrink-0">{{ __('Fach') }}</dt>
                                <dd class="min-w-0 break-words text-end">{{ $lesson->subject }} · {{ $lesson->teacher->name }}</dd>
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                                <dt>{{ __('Abgabe') }}</dt>
                                <dd class="ms-auto grid justify-items-end gap-1">
                                    <x-filament::badge :color="$statusColor" class="w-fit">{{ $statusLabel }}</x-filament::badge>
                                    @if ($submission)
                                        <span class="text-xs">{{ $submission->created_at->format('d.m.Y') }}</span>
                                    @endif
                                </dd>
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                                <dt>{{ __('Bewertung') }}</dt>
                                <dd class="ms-auto">
                                    @if ($submission?->understanding_score !== null)
                                        @include('filament.pages.insights.score', ['score' => $submission->understanding_score, 'withLabel' => true])
                                    @elseif ($submission)
                                        {{ __('noch nicht bewertet') }}
                                    @else
                                        –
                                    @endif
                                </dd>
                            </div>
                            @if ($submission?->feedback || filled($submission?->gaps))
                                <div class="grid gap-1">
                                    <dt>{{ __('Rückmeldung und Lücken') }}</dt>
                                    <dd class="min-w-0 break-words">
                                        @if ($submission->feedback)
                                            <p>{{ $submission->feedback }}</p>
                                        @endif
                                        @if (filled($submission->gaps))
                                            <p class="mt-2 text-xs font-medium text-gray-950 dark:text-white">{{ __('Lücken:') }}</p>
                                            <ul class="list-disc ps-4 text-xs">
                                                @foreach ($submission->gaps as $gap)
                                                    <li>{{ $gap }}</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </dd>
                                </div>
                            @endif
                        </dl>
                    </li>
                @endforeach
            </ul>

            <div class="hidden overflow-x-auto md:block print:block">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-950/5 dark:border-white/10">
                            <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">{{ __('Stunde') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">{{ __('Fach / Thema') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">{{ __('Abgabe') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">{{ __('Bewertung') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">{{ __('Rückmeldung und Lücken') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-950/5 text-gray-600 align-top dark:divide-white/10 dark:text-gray-400">
                        @foreach ($homework as ['lesson' => $lesson, 'submission' => $submission, 'status' => [$statusLabel, $statusColor]])
                            <tr>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    {{ $lesson->held_on->format('d.m.Y') }}
                                    @if ($lesson->due_on)
                                        <span class="block text-xs">{{ __('fällig :date', ['date' => $lesson->due_on->format('d.m.Y')]) }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <span class="block text-xs">{{ $lesson->subject }} · {{ $lesson->teacher->name }}</span>
                                    <span class="text-gray-950 dark:text-white">{{ $lesson->topic }}</span>
                                </td>
                                <td class="px-3 py-2">
                                    <x-filament::badge :color="$statusColor" class="w-fit">{{ $statusLabel }}</x-filament::badge>
                                    @if ($submission)
                                        <span class="mt-1 block text-xs">{{ $submission->created_at->format('d.m.Y') }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    @if ($submission?->understanding_score !== null)
                                        @include('filament.pages.insights.score', ['score' => $submission->understanding_score, 'withLabel' => true])
                                    @elseif ($submission)
                                        {{ __('noch nicht bewertet') }}
                                    @else
                                        –
                                    @endif
                                </td>
                                <td class="max-w-md px-3 py-2">
                                    @if ($submission?->feedback)
                                        <p>{{ $submission->feedback }}</p>
                                    @endif
                                    @if (filled($submission?->gaps))
                                        <p class="mt-2 text-xs font-medium text-gray-950 dark:text-white">{{ __('Lücken:') }}</p>
                                        <ul class="list-disc ps-4 text-xs">
                                            @foreach ($submission->gaps as $gap)
                                                <li>{{ $gap }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section :icon="Heroicon::OutlinedChatBubbleLeftRight" :heading="__('„Erklär’s mir“-Sitzungen')" :description="__('Die Schülerin bzw. der Schüler erklärt ein Thema aus dem Unterricht, die KI fragt nach und schätzt das Verständnis ein. Gespräche zu frei gewählten Themen sind privat und erscheinen hier nicht.')">
        @if ($sessions->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Bisher keine Sitzungen.') }}</p>
        @else
            <ul class="grid gap-3 md:hidden print:hidden">
                @foreach ($sessions as $session)
                    <li class="grid gap-3 rounded-xl p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
                        <p class="font-medium text-gray-950 dark:text-white">{{ $session->topic }}</p>

                        <dl class="grid gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <div class="flex items-start justify-between gap-x-4">
                                <dt class="shrink-0">{{ __('Datum') }}</dt>
                                <dd class="min-w-0 break-words text-end">{{ $session->created_at->format('d.m.Y') }}</dd>
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                                <dt>{{ __('Bewertung') }}</dt>
                                <dd class="ms-auto">
                                    @if ($session->isFinished() && $session->understanding_score !== null)
                                        @include('filament.pages.insights.score', ['score' => $session->understanding_score, 'withLabel' => true])
                                    @else
                                        <x-filament::badge color="gray" :icon="Heroicon::OutlinedClock" class="w-fit">{{ __('Noch nicht abgeschlossen') }}</x-filament::badge>
                                    @endif
                                </dd>
                            </div>
                            <div class="grid gap-1">
                                <dt>{{ __('Rückmeldung') }}</dt>
                                <dd class="min-w-0 break-words">{{ $session->feedback ?? '–' }}</dd>
                            </div>
                        </dl>
                    </li>
                @endforeach
            </ul>

            <div class="hidden overflow-x-auto md:block print:block">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-950/5 dark:border-white/10">
                            <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">{{ __('Datum') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">{{ __('Thema') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">{{ __('Bewertung') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">{{ __('Rückmeldung') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-950/5 text-gray-600 align-top dark:divide-white/10 dark:text-gray-400">
                        @foreach ($sessions as $session)
                            <tr>
                                <td class="px-3 py-2 whitespace-nowrap">{{ $session->created_at->format('d.m.Y') }}</td>
                                <td class="px-3 py-2 text-gray-950 dark:text-white">{{ $session->topic }}</td>
                                <td class="px-3 py-2">
                                    @if ($session->isFinished() && $session->understanding_score !== null)
                                        @include('filament.pages.insights.score', ['score' => $session->understanding_score, 'withLabel' => true])
                                    @else
                                        <x-filament::badge color="gray" :icon="Heroicon::OutlinedClock" class="w-fit">{{ __('Noch nicht abgeschlossen') }}</x-filament::badge>
                                    @endif
                                </td>
                                <td class="max-w-md px-3 py-2">{{ $session->feedback ?? '–' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section :icon="Heroicon::OutlinedClipboardDocumentList" :heading="__('Gesprächsgrundlage')" :description="__('Automatisch aus den obigen Daten zusammengestellt.')">
        <dl class="grid gap-4 text-sm">
            <div>
                <dt class="font-medium text-gray-950 dark:text-white">{{ __('Stärken (Kompetenzen ab 75 Punkten)') }}</dt>
                <dd class="mt-1 text-gray-600 dark:text-gray-400">
                    @forelse ($strengths as $competency)
                        <span class="block">{{ __(':competency: :score Punkte', ['competency' => $competencyLabel($competency), 'score' => $averages[$competency->id]]) }}</span>
                    @empty
                        {{ __('Derzeit liegt keine Kompetenz bei 75 Punkten oder mehr.') }}
                    @endforelse
                </dd>
            </div>
            <div>
                <dt class="font-medium text-gray-950 dark:text-white">{{ __('Förderbedarf (Kompetenzen unter :threshold Punkten)', ['threshold' => UnderstandingLevel::SUPPORT_THRESHOLD]) }}</dt>
                <dd class="mt-1 text-gray-600 dark:text-gray-400">
                    @forelse ($weaknesses as $competency)
                        <span class="block">
                            {{ __(':competency: :score Punkte, gezeigt in :lessons', [
                                'competency' => $competencyLabel($competency),
                                'score' => $averages[$competency->id],
                                'lessons' => collect($supportNeeds[$competency->id]['lesson_ids'])->map(fn ($lessonId) => $lessonsById[$lessonId]->topic.' ('.$lessonsById[$lessonId]->held_on->format('d.m.Y').')')->join(', '),
                            ]) }}
                        </span>
                    @empty
                        {{ __('Derzeit liegt keine Kompetenz unter der Förderschwelle.') }}
                    @endforelse
                </dd>
            </div>
            <div>
                <dt class="font-medium text-gray-950 dark:text-white">{{ __('Abgabeverhalten') }}</dt>
                <dd class="mt-1 text-gray-600 dark:text-gray-400">
                    @if ($submissionRate !== null)
                        {{ __('Abgabequote :rate %: :submitted von :counted Hausaufgaben abgegeben, davon :onTime pünktlich.', ['rate' => $submissionRate, 'submitted' => $submittedCount, 'counted' => $countedCount, 'onTime' => $onTimeCount]) }}
                    @else
                        {{ __('Bisher war keine Hausaufgabe fällig.') }}
                    @endif
                    @if ($notDueCount > 0)
                        {{ trans_choice('Nicht mitgezählt: :count noch nicht fällige Hausaufgabe.|Nicht mitgezählt: :count noch nicht fällige Hausaufgaben.', $notDueCount) }}
                    @endif
                </dd>
            </div>
            <div>
                <dt class="font-medium text-gray-950 dark:text-white">{{ __('Verlauf') }}</dt>
                <dd class="mt-1 text-gray-600 dark:text-gray-400">{{ $trendText }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-950 dark:text-white">{{ __('„Erklär’s mir“') }}</dt>
                <dd class="mt-1 text-gray-600 dark:text-gray-400">
                    @if ($finishedSessions->isNotEmpty())
                        {{ trans_choice(':count abgeschlossene Sitzung, durchschnittlich :average Punkte.|:count abgeschlossene Sitzungen, durchschnittlich :average Punkte.', $finishedSessions->count(), ['average' => round($finishedSessions->avg('understanding_score'))]) }}
                    @else
                        {{ __('Noch keine abgeschlossene Sitzung.') }}
                    @endif
                </dd>
            </div>
        </dl>
    </x-filament::section>
</x-filament-panels::page>
