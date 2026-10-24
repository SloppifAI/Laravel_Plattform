@php
    use App\Filament\Pages\ExplainIt;
    use App\Filament\Pages\MyHomework;
    use App\Filament\Pages\WorkOnHomework;
    use Filament\Support\Icons\Heroicon;
    use Illuminate\Support\Str;

    /** Encouraging wording: a dip is "a bit lower lately", never a warning in red. */
    $trends = [
        'up' => ['label' => __('Es geht aufwärts'), 'icon' => Heroicon::OutlinedArrowTrendingUp, 'color' => 'success'],
        'steady' => ['label' => __('Ziemlich gleich geblieben'), 'icon' => Heroicon::OutlinedArrowRight, 'color' => 'gray'],
        'down' => ['label' => __('Zuletzt etwas niedriger'), 'icon' => Heroicon::OutlinedArrowTrendingDown, 'color' => 'warning'],
    ];
    $hasData = $strengths->isNotEmpty() || $almost->isNotEmpty() || $practice->isNotEmpty();
    $tutor = ExplainIt::TUTOR_NAME;
    $intro = '<strong class="font-semibold text-gray-950 dark:text-white">'.e(__('Hinweise aus deinen Hausaufgaben, keine Noten')).'</strong>';
@endphp

<x-filament-panels::page>
    <section class="lp-card p-6" aria-labelledby="lp-progress-intro">
        <h2 id="lp-progress-intro" class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Wo stehst du gerade?') }}</h2>

        <p class="mt-2 max-w-2xl text-pretty text-gray-700 dark:text-gray-300">
            {!! __('Das sind :hints. Sie zeigen dir, was schon gut klappt und wo sich Üben lohnt. Du siehst hier nur deine eigenen Ergebnisse und wirst mit niemandem verglichen.', ['hints' => $intro]) !!}
        </p>

        @if ($averageScore !== null)
            <dl class="mt-5 flex flex-wrap gap-x-10 gap-y-4">
                <div class="grid gap-1">
                    <dt class="text-sm text-gray-600 dark:text-gray-400">{{ __('Dein Verständnis im Durchschnitt') }}</dt>
                    <dd>@include('filament.pages.my-learning-progress.score', ['score' => $averageScore])</dd>
                </div>

                @if ($trend)
                    <div class="grid gap-1">
                        <dt class="text-sm text-gray-600 dark:text-gray-400">{{ __('Entwicklung') }}</dt>
                        <dd>
                            <x-filament::badge :color="$trends[$trend]['color']" :icon="$trends[$trend]['icon']" class="w-fit">
                                {{ $trends[$trend]['label'] }}
                            </x-filament::badge>
                        </dd>
                    </div>
                @endif

                <div class="grid gap-1">
                    <dt class="text-sm text-gray-600 dark:text-gray-400">{{ __('Bisher bewertet') }}</dt>
                    <dd class="font-medium text-gray-950 dark:text-white">
                        {{ trans_choice(':count Hausaufgabe|:count Hausaufgaben', $evaluatedCount) }}
                    </dd>
                </div>
            </dl>
        @endif
    </section>

    @if (! $hasData)
        <x-filament::empty-state
            :heading="__('Hier gibt es noch nichts zu sehen')"
            :description="__('Sobald deine erste Hausaufgabe bewertet ist, zeigt dir diese Seite, was du schon gut kannst und wo sich Üben lohnt.')"
            :icon="Heroicon::OutlinedSparkles"
        >
            <x-slot name="footer">
                <x-filament::button tag="a" :href="MyHomework::getUrl()" :icon="Heroicon::OutlinedClipboardDocumentList">
                    {{ __('Zu meinen Hausaufgaben') }}
                </x-filament::button>
            </x-slot>
        </x-filament::empty-state>
    @else
        @if ($strengths->isNotEmpty())
            <x-filament::section :heading="__('Das kannst du schon gut')" :description="__('Hier hast du in deinen Hausaufgaben richtig gut abgeschnitten.')" :icon="Heroicon::OutlinedCheckCircle" icon-color="success">
                <ul class="grid gap-4 sm:grid-cols-2">
                    @foreach ($strengths as $strength)
                        <li wire:key="strength-{{ $strength['competency']->id }}" class="grid content-start gap-1 rounded-xl p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
                            <span class="text-xs text-gray-600 dark:text-gray-400">{{ $strength['competency']->subject ?? __('Fachübergreifend') }}</span>
                            <span class="font-medium text-gray-950 dark:text-white">{{ $strength['competency']->name }}</span>
                            <span class="text-sm text-gray-600 dark:text-gray-400">{{ Str::limit($strength['competency']->description, 110, preserveWords: true) }}</span>
                            <span class="mt-2">@include('filament.pages.my-learning-progress.score', ['score' => $strength['average']])</span>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        <x-filament::section :heading="__('Hier lohnt sich Üben')" :description="__('Hier kannst du noch besser werden. Erklär das Thema :tutor, unserer KI, dann merkst du schnell, was schon sitzt.', ['tutor' => $tutor])" :icon="Heroicon::OutlinedLightBulb" icon-color="warning">
            @if ($practice->isEmpty())
                <div class="flex items-center gap-4 rounded-xl bg-success-50 p-4 dark:bg-success-400/10">
                    <x-filament::icon :icon="Heroicon::OutlinedCheckBadge" class="size-8 shrink-0 text-success-600 dark:text-success-400" />

                    <p class="text-sm text-gray-800 dark:text-gray-200">
                        <span class="font-semibold">{{ __('Gut gemacht!') }}</span>
                        {{ __('Im Moment läuft alles richtig gut.') }}
                    </p>
                </div>
            @else
                <ul class="grid gap-4">
                    @foreach ($practice as $need)
                        <li wire:key="practice-{{ $need['competency']->id }}">
                            <article class="rounded-xl p-5 ring-1 ring-gray-950/5 dark:ring-white/10" aria-labelledby="practice-title-{{ $need['competency']->id }}">
                                <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                                    <div class="grid min-w-48 flex-1 gap-1">
                                        <span class="text-xs text-gray-600 dark:text-gray-400">{{ $need['competency']->subject ?? __('Fachübergreifend') }}</span>
                                        <h3 id="practice-title-{{ $need['competency']->id }}" class="font-medium text-gray-950 dark:text-white">{{ $need['competency']->name }}</h3>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ Str::limit($need['competency']->description, 110, preserveWords: true) }}</p>
                                    </div>

                                    @include('filament.pages.my-learning-progress.score', ['score' => $need['average']])
                                </div>

                                @if ($need['practice_task'])
                                    {{-- The teacher handed out a Förderaufgabe for exactly this competency. --}}
                                    <div class="mt-5 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl bg-primary-50 p-4 dark:bg-primary-400/10">
                                        <p class="min-w-48 flex-1 text-sm text-gray-800 dark:text-gray-200">
                                            <span class="font-semibold">{{ __('Deine Lehrkraft hat dir eine Förderaufgabe gegeben.') }}</span>
                                            {{ __('Mit ein paar Fragen kannst du genau das üben.') }}
                                            @if ($need['practice_task']->due_on)
                                                {{ __('Bis :date.', ['date' => $need['practice_task']->due_on->format('d.m.Y')]) }}
                                            @endif
                                        </p>

                                        <x-filament::button tag="a" :href="WorkOnHomework::getUrl(['lesson' => $need['practice_task']])" size="sm" :icon="Heroicon::OutlinedPencilSquare">
                                            {{ __('Förderaufgabe bearbeiten') }}<span class="sr-only"> {{ __('zu :name', ['name' => $need['competency']->name]) }}</span>
                                        </x-filament::button>
                                    </div>
                                @endif

                                @if ($need['lessons']->isNotEmpty())
                                    <h4 class="mt-5 text-sm font-semibold text-gray-950 dark:text-white">{{ __('Bei diesen Hausaufgaben hat es noch gehakt') }}</h4>

                                    <ul class="mt-2 grid gap-3">
                                        @foreach ($need['lessons'] as $lesson)
                                            <li wire:key="practice-{{ $need['competency']->id }}-lesson-{{ $lesson->id }}" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                                                <span class="text-sm">
                                                    <span class="text-gray-600 dark:text-gray-400">{{ $lesson->subject }}:</span>
                                                    <span class="font-medium text-gray-950 dark:text-white">{{ $lesson->topic }}</span>
                                                    <span class="text-gray-600 dark:text-gray-400">· {{ $lesson->held_on->format('d.m.Y') }}</span>
                                                </span>

                                                <span class="flex flex-wrap gap-2">
                                                    <x-filament::button tag="a" :href="WorkOnHomework::getUrl(['lesson' => $lesson])" color="gray" size="sm" :icon="Heroicon::OutlinedDocumentText">
                                                        {{ __('Ergebnis ansehen') }}<span class="sr-only"> {{ __('zu :name', ['name' => $lesson->topic]) }}</span>
                                                    </x-filament::button>

                                                    <x-filament::button tag="a" :href="ExplainIt::getUrl(['lesson' => $lesson->getKey()])" size="sm" :icon="Heroicon::OutlinedChatBubbleLeftRight">
                                                        {{ __('Thema :tutor erklären', ['tutor' => $tutor]) }}<span class="sr-only"> {{ __('zu :name', ['name' => $lesson->topic]) }}</span>
                                                    </x-filament::button>
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    {{-- Every weak lesson was withdrawn from the student: still offer a way to start. --}}
                                    <div class="mt-5">
                                        <x-filament::button tag="a" :href="ExplainIt::getUrl()" size="sm" :icon="Heroicon::OutlinedChatBubbleLeftRight">
                                            {{ __('Thema :tutor erklären', ['tutor' => $tutor]) }}<span class="sr-only"> {{ __('zu :name', ['name' => $need['competency']->name]) }}</span>
                                        </x-filament::button>
                                    </div>
                                @endif

                                @if ($need['gaps'])
                                    <h4 class="mt-5 text-sm font-semibold text-gray-950 dark:text-white">{{ __('Das kannst du dir in diesen Hausaufgaben noch einmal anschauen') }}</h4>

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
            <x-filament::section :heading="__('Fast geschafft')" :description="__('Das klappt schon ziemlich gut. Noch ein kleiner Schritt und du hast es.')" :icon="Heroicon::OutlinedAdjustmentsHorizontal" icon-color="warning">
                <ul class="grid gap-4 sm:grid-cols-2">
                    @foreach ($almost as $near)
                        <li wire:key="almost-{{ $near['competency']->id }}" class="grid content-start gap-1 rounded-xl p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
                            <span class="text-xs text-gray-600 dark:text-gray-400">{{ $near['competency']->subject ?? __('Fachübergreifend') }}</span>
                            <span class="font-medium text-gray-950 dark:text-white">{{ $near['competency']->name }}</span>
                            <span class="text-sm text-gray-600 dark:text-gray-400">{{ Str::limit($near['competency']->description, 110, preserveWords: true) }}</span>
                            <span class="mt-2">@include('filament.pages.my-learning-progress.score', ['score' => $near['average']])</span>

                            <x-filament::link :href="ExplainIt::getUrl()" :icon="Heroicon::OutlinedChatBubbleLeftRight" size="sm" class="mt-2 w-fit">
                                {{ __('Thema :tutor erklären', ['tutor' => $tutor]) }}<span class="sr-only"> {{ __('zu :name', ['name' => $near['competency']->name]) }}</span>
                            </x-filament::link>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
