@php
    use App\Filament\Pages\ExplainIt;
    use App\Filament\Pages\MyHomework;
    use App\Filament\Pages\WorkOnHomework;
    use Filament\Support\Icons\Heroicon;
    use Illuminate\Support\Str;

    /** Encouraging wording: a dip is "a bit lower lately", never a warning in red. */
    $trends = [
        'up' => ['label' => 'Es geht aufwärts', 'icon' => Heroicon::OutlinedArrowTrendingUp, 'color' => 'success'],
        'steady' => ['label' => 'Ziemlich gleich geblieben', 'icon' => Heroicon::OutlinedArrowRight, 'color' => 'gray'],
        'down' => ['label' => 'Zuletzt etwas niedriger', 'icon' => Heroicon::OutlinedArrowTrendingDown, 'color' => 'warning'],
    ];
    $hasData = $strengths->isNotEmpty() || $almost->isNotEmpty() || $practice->isNotEmpty();
@endphp

<x-filament-panels::page>
    <section class="lp-card p-6" aria-labelledby="lp-progress-intro">
        <h2 id="lp-progress-intro" class="text-base font-semibold text-gray-950 dark:text-white">Wo stehst du gerade?</h2>

        <p class="mt-2 max-w-2xl text-pretty text-gray-700 dark:text-gray-300">
            Das sind <strong class="font-semibold text-gray-950 dark:text-white">Hinweise aus deinen Hausaufgaben, keine Noten</strong>.
            Sie zeigen dir, was schon gut klappt und wo sich Üben lohnt. Du siehst hier nur deine eigenen Ergebnisse und wirst mit niemandem verglichen.
        </p>

        @if ($averageScore !== null)
            <dl class="mt-5 flex flex-wrap gap-x-10 gap-y-4">
                <div class="grid gap-1">
                    <dt class="text-sm text-gray-600 dark:text-gray-400">Dein Verständnis im Durchschnitt</dt>
                    <dd>@include('filament.pages.insights.score', ['score' => $averageScore, 'withLabel' => true])</dd>
                </div>

                @if ($trend)
                    <div class="grid gap-1">
                        <dt class="text-sm text-gray-600 dark:text-gray-400">Entwicklung</dt>
                        <dd>
                            <x-filament::badge :color="$trends[$trend]['color']" :icon="$trends[$trend]['icon']" class="w-fit">
                                {{ $trends[$trend]['label'] }}
                            </x-filament::badge>
                        </dd>
                    </div>
                @endif

                <div class="grid gap-1">
                    <dt class="text-sm text-gray-600 dark:text-gray-400">Das beruht auf</dt>
                    <dd class="font-medium text-gray-950 dark:text-white">
                        {{ $evaluatedCount }} {{ $evaluatedCount === 1 ? 'bewerteter Hausaufgabe' : 'bewerteten Hausaufgaben' }}
                    </dd>
                </div>
            </dl>
        @endif
    </section>

    @if (! $hasData)
        <x-filament::empty-state
            heading="Hier gibt es noch nichts zu sehen"
            description="Sobald deine erste Hausaufgabe bewertet ist, zeigt dir diese Seite, was du schon gut kannst und wo sich Üben lohnt."
            :icon="Heroicon::OutlinedSparkles"
        >
            <x-slot name="footer">
                <x-filament::button tag="a" :href="MyHomework::getUrl()" :icon="Heroicon::OutlinedClipboardDocumentList">
                    Zu meinen Hausaufgaben
                </x-filament::button>
            </x-slot>
        </x-filament::empty-state>
    @else
        @if ($strengths->isNotEmpty())
            <x-filament::section heading="Das kannst du schon gut" description="Hier hast du in deinen Hausaufgaben richtig gut abgeschnitten." :icon="Heroicon::OutlinedCheckCircle" icon-color="success">
                <ul class="grid gap-4 sm:grid-cols-2">
                    @foreach ($strengths as $strength)
                        <li wire:key="strength-{{ $strength['competency']->id }}" class="grid content-start gap-1 rounded-xl p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
                            <span class="text-xs text-gray-600 dark:text-gray-400">{{ $strength['competency']->subject ?? 'Fachübergreifend' }}</span>
                            <span class="font-medium text-gray-950 dark:text-white">{{ $strength['competency']->name }}</span>
                            <span class="text-sm text-gray-600 dark:text-gray-400">{{ Str::limit($strength['competency']->description, 110) }}</span>
                            <span class="mt-2">@include('filament.pages.insights.score', ['score' => $strength['average'], 'withLabel' => true])</span>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        <x-filament::section heading="Hier lohnt sich Üben" description="Bei diesen Themen ist noch Luft nach oben. Mit ein bisschen Übung wird das schnell besser." :icon="Heroicon::OutlinedLightBulb" icon-color="warning">
            @if ($practice->isEmpty())
                <div class="flex items-center gap-4 rounded-xl bg-success-50 p-4 dark:bg-success-400/10">
                    <x-filament::icon :icon="Heroicon::OutlinedCheckBadge" class="size-8 shrink-0 text-success-600 dark:text-success-400" />

                    <p class="text-sm text-gray-800 dark:text-gray-200">
                        <span class="font-semibold">Gut gemacht!</span>
                        Im Moment musst du nichts dringend üben.
                    </p>
                </div>
            @else
                <ul class="grid gap-4">
                    @foreach ($practice as $need)
                        <li wire:key="practice-{{ $need['competency']->id }}">
                            <article class="rounded-xl p-5 ring-1 ring-gray-950/5 dark:ring-white/10" aria-labelledby="practice-title-{{ $need['competency']->id }}">
                                <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                                    <div class="grid min-w-48 flex-1 gap-1">
                                        <span class="text-xs text-gray-600 dark:text-gray-400">{{ $need['competency']->subject ?? 'Fachübergreifend' }}</span>
                                        <h3 id="practice-title-{{ $need['competency']->id }}" class="font-medium text-gray-950 dark:text-white">{{ $need['competency']->name }}</h3>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ Str::limit($need['competency']->description, 110) }}</p>
                                    </div>

                                    @include('filament.pages.insights.score', ['score' => $need['average'], 'withLabel' => true])
                                </div>

                                @if ($need['lessons']->isNotEmpty())
                                    <h4 class="mt-5 text-sm font-semibold text-gray-950 dark:text-white">Hier ist es dir aufgefallen</h4>

                                    <ul class="mt-2 grid gap-3">
                                        @foreach ($need['lessons'] as $lesson)
                                            <li wire:key="practice-{{ $need['competency']->id }}-lesson-{{ $lesson->id }}" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                                                <span class="text-sm">
                                                    <span class="text-gray-600 dark:text-gray-400">{{ $lesson->subject }}:</span>
                                                    <span class="font-medium text-gray-950 dark:text-white">{{ $lesson->topic }}</span>
                                                    <span class="text-gray-600 dark:text-gray-400">· {{ $lesson->held_on->format('d.m.Y') }}</span>
                                                </span>

                                                <span class="flex flex-wrap gap-2">
                                                    <x-filament::button tag="a" :href="WorkOnHomework::getUrl(['lesson' => $lesson])" color="gray" size="sm" :icon="Heroicon::OutlinedEye">
                                                        Ergebnis ansehen
                                                    </x-filament::button>

                                                    <x-filament::button tag="a" :href="ExplainIt::getUrl(['lesson' => $lesson->getKey()])" size="sm" :icon="Heroicon::OutlinedChatBubbleLeftRight">
                                                        Mit {{ ExplainIt::TUTOR_NAME }} üben
                                                    </x-filament::button>
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                @if ($need['gaps'])
                                    <h4 class="mt-5 text-sm font-semibold text-gray-950 dark:text-white">Darauf solltest du noch einmal schauen</h4>

                                    <ul class="mt-2 list-disc ps-5 text-sm text-gray-700 dark:text-gray-300">
                                        @foreach ($need['gaps'] as $gap)
                                            <li>{{ $gap }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </article>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        @if ($almost->isNotEmpty())
            <x-filament::section heading="Fast geschafft" description="Das klappt schon ziemlich gut. Noch ein kleiner Schritt und du hast es." :icon="Heroicon::OutlinedAdjustmentsHorizontal" icon-color="warning">
                <ul class="grid gap-4 sm:grid-cols-2">
                    @foreach ($almost as $near)
                        <li wire:key="almost-{{ $near['competency']->id }}" class="grid content-start gap-1 rounded-xl p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
                            <span class="text-xs text-gray-600 dark:text-gray-400">{{ $near['competency']->subject ?? 'Fachübergreifend' }}</span>
                            <span class="font-medium text-gray-950 dark:text-white">{{ $near['competency']->name }}</span>
                            <span class="text-sm text-gray-600 dark:text-gray-400">{{ Str::limit($near['competency']->description, 110) }}</span>
                            <span class="mt-2">@include('filament.pages.insights.score', ['score' => $near['average'], 'withLabel' => true])</span>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
