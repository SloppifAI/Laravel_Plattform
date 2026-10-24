@php
    use App\Filament\Pages\ParentReport;
    use App\UnderstandingLevel;
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-panels::page>
    @if ($classes->isEmpty())
        <x-filament::empty-state
            heading="Keine Klassen"
            description="Sobald Sie eine Klasse leiten oder in einer Klasse unterrichten, erscheint hier deren Lernstand."
            :icon="Heroicon::OutlinedUserGroup"
        />
    @else
        <div class="flex flex-wrap items-end justify-between gap-4">
            <label class="grid gap-1 text-sm font-medium text-gray-950 dark:text-white">
                Klasse
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="schoolClassId">
                        @foreach ($classes as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>

            @include('filament.pages.insights.legend')
        </div>

        <x-filament::section
            heading="Kompetenzmatrix"
            description="Durchschnitt aller KI-bewerteten Antworten je Kompetenz des Referenzrahmens (0–100 Punkte). Ein Strich bedeutet: noch keine bewertete Antwort. Der Name führt zum Bericht für das Elterngespräch."
            :icon="Heroicon::OutlinedChartBar"
        >
            @if ($competencies->isEmpty())
                <p class="text-sm text-gray-600 dark:text-gray-400">Für diese Klasse liegen noch keine bewerteten Antworten vor.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-950/5 dark:border-white/10">
                                <th scope="col" class="px-3 py-2 text-start align-bottom font-medium text-gray-950 dark:text-white">Schüler:in</th>
                                @foreach ($competencies as $competency)
                                    <th scope="col" class="min-w-32 px-3 py-2 text-start align-bottom font-medium text-gray-950 dark:text-white" title="{{ $competency->description }}">
                                        <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $competency->subject ?? 'Fachübergreifend' }}</span>
                                        {{ $competency->name }}
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                            @foreach ($students as $student)
                                <tr wire:key="matrix-{{ $student->id }}">
                                    <th scope="row" class="px-3 py-2 text-start font-normal whitespace-nowrap">
                                        <x-filament::link :href="ParentReport::getUrl(['student' => $student])">{{ $student->name }}</x-filament::link>
                                    </th>
                                    @foreach ($competencies as $competency)
                                        <td class="px-3 py-2">
                                            @isset($averages[$student->id][$competency->id])
                                                @include('filament.pages.insights.score', ['score' => $averages[$student->id][$competency->id]])
                                            @else
                                                <span class="text-gray-400 dark:text-gray-500">–<span class="sr-only">keine Daten</span></span>
                                            @endisset
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section
            heading="Förderbedarf"
            description="Schüler:innen mit mindestens einer Kompetenz unter {{ UnderstandingLevel::SUPPORT_THRESHOLD }} Punkten, mit den Stunden, in denen sich die Lücke gezeigt hat."
            :icon="Heroicon::OutlinedLifebuoy"
        >
            @if (empty($supportNeeds))
                <p class="text-sm text-gray-600 dark:text-gray-400">Derzeit liegt bei niemandem in dieser Klasse eine Kompetenz unter der Förderschwelle.</p>
            @else
                <ul class="divide-y divide-gray-950/5 dark:divide-white/10">
                    @foreach ($students->filter(fn ($student) => isset($supportNeeds[$student->id])) as $student)
                        <li wire:key="support-{{ $student->id }}" class="py-4 first:pt-0 last:pb-0">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="font-medium text-gray-950 dark:text-white">{{ $student->name }}</p>
                                <x-filament::link :href="ParentReport::getUrl(['student' => $student])" :icon="Heroicon::OutlinedDocumentText" size="sm">
                                    Bericht für das Elterngespräch
                                </x-filament::link>
                            </div>

                            <ul class="mt-2 grid gap-2">
                                @foreach ($supportNeeds[$student->id] as $need)
                                    @php
                                        $competency = $competencies[$need['competency_id']];
                                    @endphp

                                    <li class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-600 dark:text-gray-400">
                                        @include('filament.pages.insights.score', ['score' => $need['average']])
                                        <span class="font-medium text-gray-950 dark:text-white">{{ $competency->name }}</span>
                                        <span>({{ $competency->subject ?? 'Fachübergreifend' }})</span>
                                        <span>
                                            gezeigt in:
                                            {{ collect($need['lesson_ids'])->map(fn ($lessonId) => $supportLessons[$lessonId]->topic.' am '.$supportLessons[$lessonId]->held_on->format('d.m.Y'))->join(', ') }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        <x-filament::section
            heading="Klassendurchschnitt je Stunde"
            description="Durchschnitt der KI-Gesamtbewertung aller bewerteten Abgaben einer Hausaufgabe."
            :icon="Heroicon::OutlinedPresentationChartLine"
        >
            @if ($lessons->isEmpty())
                <p class="text-sm text-gray-600 dark:text-gray-400">In dieser Klasse wurden noch keine Hausaufgaben veröffentlicht.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-950/5 text-start dark:border-white/10">
                                <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">Datum</th>
                                <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">Fach</th>
                                <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">Thema</th>
                                <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">Abgaben</th>
                                <th scope="col" class="px-3 py-2 text-start font-medium text-gray-950 dark:text-white">Durchschnitt</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-950/5 text-gray-600 dark:divide-white/10 dark:text-gray-400">
                            @foreach ($lessons as $lesson)
                                <tr wire:key="lesson-{{ $lesson->id }}">
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $lesson->held_on->format('d.m.Y') }}</td>
                                    <td class="px-3 py-2">{{ $lesson->subject }}</td>
                                    <td class="px-3 py-2 text-gray-950 dark:text-white">{{ $lesson->topic }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $lesson->submissions_count }} von {{ $lesson->students_count }}</td>
                                    <td class="px-3 py-2">
                                        @if ($lesson->submissions_avg_understanding_score !== null)
                                            @include('filament.pages.insights.score', ['score' => (int) round($lesson->submissions_avg_understanding_score)])
                                        @else
                                            <span class="text-gray-400 dark:text-gray-500">noch nicht bewertet</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
