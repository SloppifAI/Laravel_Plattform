@php
    use App\UnderstandingLevel;
    use Filament\Support\Icons\Heroicon;
    use Illuminate\Support\Str;

    /** Ring strokes need 3:1 against the card, so warning uses 600 instead of the lighter 500. */
    $ringColors = [
        'success' => 'text-success-600 dark:text-success-400',
        'warning' => 'text-warning-600 dark:text-warning-400',
        'danger' => 'text-danger-600 dark:text-danger-400',
    ];
@endphp

<x-filament-widgets::widget>
    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Hero: a page of the exercise book. --}}
        <section class="lp-card lp-paper lg:col-span-3" aria-labelledby="lp-student-greeting">
            <div class="grid gap-8 py-8 ps-12 pe-6 sm:ps-20 md:grid-cols-[minmax(0,1fr)_auto] md:items-center md:pe-10">
                <div>
                    <h2 id="lp-student-greeting" class="text-3xl font-semibold tracking-tight text-gray-950 sm:text-4xl dark:text-white">
                        Hallo {{ $firstName }}!
                    </h2>

                    <p class="mt-3 max-w-xl text-base text-pretty text-gray-700 sm:text-lg dark:text-gray-300">
                        @if ($openCount === 0)
                            Gerade ist nichts offen. Neue Hausaufgaben erscheinen hier, sobald deine Lehrkraft sie verteilt.
                        @else
                            Du hast
                            <strong class="font-semibold text-gray-950 dark:text-white">{{ $openCount }} {{ $openCount === 1 ? 'offene Hausaufgabe' : 'offene Hausaufgaben' }}</strong>.
                            @if ($nextHomework->first()['due']['date'])
                                Die nächste ist bis <mark class="lp-marker">{{ $nextHomework->first()['due']['date'] }}</mark> fällig.
                            @endif
                        @endif
                    </p>
                </div>

                {{-- The caption wraps below the ring when there is no room beside it. --}}
                <figure class="flex flex-wrap items-center gap-x-5 gap-y-3 md:flex-col md:flex-nowrap md:text-center">
                    <div class="relative size-28 shrink-0 sm:size-32">
                        <svg viewBox="0 0 120 120" class="size-full -rotate-90" aria-hidden="true" focusable="false">
                            <circle cx="60" cy="60" r="50" fill="none" stroke="currentColor" stroke-width="12" class="text-gray-200 dark:text-white/10" />

                            @if ($averageScore !== null)
                                <circle
                                    cx="60" cy="60" r="50" fill="none" stroke="currentColor" stroke-width="12" stroke-linecap="round"
                                    pathLength="100" stroke-dasharray="100" stroke-dashoffset="{{ 100 - $averageScore }}"
                                    class="lp-ring-progress {{ $ringColors[$averageLevel->getColor()] }}"
                                />
                            @endif
                        </svg>

                        <div class="absolute inset-0 flex items-center justify-center">
                            <span class="text-3xl font-semibold tracking-tight text-gray-950 tabular-nums dark:text-white">
                                {{ $averageScore === null ? '–' : $averageScore }}<span class="text-base font-medium text-gray-600 dark:text-gray-400">{{ $averageScore === null ? '' : ' %' }}</span>
                            </span>
                        </div>
                    </div>

                    <figcaption class="grid min-w-44 flex-1 justify-items-start gap-1.5 md:max-w-56 md:flex-none md:justify-items-center">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Dein Verständnis</span>

                        @if ($averageLevel)
                            <x-filament::badge :color="$averageLevel->getColor()" :icon="$averageLevel->getIcon()" size="lg">
                                {{ $averageLevel->getLabel() }}
                            </x-filament::badge>

                            <span class="text-sm text-gray-600 dark:text-gray-400">
                                Durchschnitt aus {{ $evaluatedCount }} {{ $evaluatedCount === 1 ? 'bewerteten Hausaufgabe' : 'bewerteten Hausaufgaben' }}
                            </span>
                        @else
                            <span class="text-sm text-gray-600 dark:text-gray-400">
                                Gib deine erste Hausaufgabe ab, dann siehst du hier, wie gut du das Thema verstanden hast.
                            </span>
                        @endif
                    </figcaption>
                </figure>
            </div>
        </section>

        {{-- What's due next. --}}
        <section class="lp-card p-6 lg:col-span-2" aria-labelledby="lp-next-homework">
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <h3 id="lp-next-homework" class="text-base font-semibold text-gray-950 dark:text-white">Als Nächstes fällig</h3>

                <x-filament::link :href="$homeworkUrl" :icon="Heroicon::ArrowRight" icon-position="after" size="sm">
                    Alle Hausaufgaben
                </x-filament::link>
            </div>

            @if ($nextHomework->isEmpty())
                <div class="mt-4 flex items-center gap-4 rounded-xl bg-success-50 p-4 dark:bg-success-400/10">
                    <x-filament::icon :icon="Heroicon::OutlinedCheckBadge" class="size-8 shrink-0 text-success-600 dark:text-success-400" />

                    <p class="text-sm text-gray-800 dark:text-gray-200">
                        <span class="font-semibold">Alles erledigt.</span>
                        Du hast gerade keine offenen Hausaufgaben.
                    </p>
                </div>
            @else
                <ul class="mt-3 grid gap-1">
                    @foreach ($nextHomework as $item)
                        <li>
                            <a href="{{ $item['url'] }}" class="lp-focus -mx-3 flex items-center gap-4 rounded-xl px-3 py-3 transition hover:bg-gray-50 dark:hover:bg-white/5">
                                {{-- A leaf of the homework planner. --}}
                                <span class="flex size-12 shrink-0 flex-col items-center justify-center rounded-xl bg-primary-50 text-primary-800 ring-1 ring-primary-100 dark:bg-primary-400/10 dark:text-primary-200 dark:ring-primary-400/20" aria-hidden="true">
                                    @if ($item['due']['day'])
                                        <span class="text-lg leading-none font-semibold tabular-nums">{{ $item['due']['day'] }}</span>
                                        <span class="mt-0.5 text-xs leading-none">{{ $item['due']['month'] }}</span>
                                    @else
                                        <x-filament::icon :icon="Heroicon::OutlinedCalendar" class="size-5" />
                                    @endif
                                </span>

                                <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-4 gap-y-1.5">
                                    <span class="min-w-48 flex-1">
                                        <span class="block text-sm text-gray-600 dark:text-gray-400">{{ $item['lesson']->subject }}</span>
                                        <span class="block font-medium text-gray-950 dark:text-white">{{ $item['lesson']->topic }}</span>
                                    </span>

                                    <x-filament::badge :color="$item['due']['color']" :icon="$item['due']['icon']">
                                        {{ $item['due']['label'] }}
                                    </x-filament::badge>
                                </span>

                                @if ($item['due']['date'])
                                    <span class="sr-only">Fällig am {{ $item['due']['date'] }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Reverse learning. --}}
        <section class="relative flex flex-col overflow-hidden rounded-2xl bg-primary-700 p-6 text-white ring-1 ring-primary-800 lg:row-span-2 dark:ring-white/10" aria-labelledby="lp-explain-it">
            <x-filament::icon :icon="Heroicon::OutlinedChatBubbleLeftRight" class="pointer-events-none absolute -end-6 -bottom-8 size-44 text-white/10" />

            <x-filament::icon :icon="Heroicon::OutlinedChatBubbleLeftRight" class="size-8 text-primary-100" />

            <h3 id="lp-explain-it" class="mt-4 text-xl font-semibold tracking-tight">Erklär’s mir</h3>

            <p class="relative mt-2 text-pretty text-primary-50">
                Erklär ein Thema mit deinen eigenen Worten. Die KI fragt nach wie ein neugieriges Kind, und du merkst, was du schon richtig gut kannst.
            </p>

            <a
                href="{{ $explainItUrl }}"
                class="relative mt-6 inline-flex items-center gap-2 self-start rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-primary-800 transition hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white lg:mt-auto"
            >
                Thema erklären
                <x-filament::icon :icon="Heroicon::ArrowRight" class="size-4" />
            </a>
        </section>

        {{-- Latest feedback. --}}
        <section class="lp-card p-6 lg:col-span-2" aria-labelledby="lp-latest-feedback">
            <h3 id="lp-latest-feedback" class="text-base font-semibold text-gray-950 dark:text-white">Letzte Rückmeldung</h3>

            @if ($latestFeedback)
                <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2">
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ $latestFeedback->lesson->subject }}:
                        <span class="font-medium text-gray-950 dark:text-white">{{ $latestFeedback->lesson->topic }}</span>
                    </p>

                    <x-filament::badge :color="$latestFeedbackLevel->getColor()" :icon="$latestFeedbackLevel->getIcon()">
                        {{ $latestFeedbackLevel->getLabel() }} ({{ $latestFeedback->understanding_score }} %)
                    </x-filament::badge>
                </div>

                <blockquote class="mt-4 border-s-4 border-primary-200 ps-4 text-pretty text-gray-800 dark:border-primary-400/40 dark:text-gray-200">
                    {{ Str::limit($latestFeedback->feedback, 280) }}
                </blockquote>

                <x-filament::link :href="$homeworkUrl" :icon="Heroicon::ArrowRight" icon-position="after" size="sm" class="mt-4">
                    Zu deinen Hausaufgaben
                </x-filament::link>
            @else
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                    Sobald deine erste Hausaufgabe bewertet ist, steht hier, was schon gut klappt und woran du noch arbeiten kannst.
                </p>
            @endif
        </section>
    </div>
</x-filament-widgets::widget>
