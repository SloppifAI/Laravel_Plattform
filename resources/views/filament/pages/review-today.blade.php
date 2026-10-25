@php
    use App\ReviewRating;
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-panels::page>
    @if ($item === null)
        <x-filament::empty-state
            :heading="$reviewedToday > 0 ? __('Für heute geschafft') : __('Heute ist nichts zu wiederholen')"
            :description="$reviewedToday > 0
                ? trans_choice('Du hast heute :count Frage wiederholt. Morgen geht es weiter, sobald wieder etwas fällig ist.|Du hast heute :count Fragen wiederholt. Morgen geht es weiter, sobald wieder etwas fällig ist.', $reviewedToday)
                : __('Sobald deine Hausaufgaben bewertet sind, kommen die Fragen nach und nach wieder, kurz bevor du sie vergessen würdest.')"
            :icon="$reviewedToday > 0 ? Heroicon::OutlinedCheckBadge : Heroicon::OutlinedRectangleStack"
        >
            <x-slot name="footer">
                <x-filament::button tag="a" :href="$dashboardUrl" :icon="Heroicon::OutlinedHome">
                    {{ __('Zurück zur Startseite') }}
                </x-filament::button>
            </x-slot>
        </x-filament::empty-state>
    @else
        {{-- A new key per question: the field starts empty again and the Alpine state of the last one is gone. --}}
        <section
            wire:key="review-item-{{ $item->getKey() }}"
            x-data="{ own: '' }"
            class="lp-card grid gap-5 p-6"
            aria-labelledby="lp-review-question"
        >
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ $item->question->lesson->subject }} · {{ $item->question->lesson->topic }}
                </p>

                <x-filament::badge color="gray">
                    {{ __('Frage :number von :total', ['number' => $number, 'total' => $total]) }}
                </x-filament::badge>
            </div>

            <h2 id="lp-review-question" class="text-xl font-semibold text-pretty text-gray-950 whitespace-pre-line dark:text-white">{{ $prompt }}</h2>

            {{-- Not bound to Livewire on purpose: what is typed here stays in the browser and is neither sent nor stored. --}}
            <div class="grid gap-2">
                <label for="lp-review-own" class="text-sm font-medium text-gray-950 dark:text-white">{{ __('Deine Antwort (nur für dich)') }}</label>

                <textarea
                    id="lp-review-own"
                    x-model="own"
                    rows="4"
                    maxlength="3000"
                    class="w-full rounded-lg border-0 bg-white px-3 py-2 text-base text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500"
                ></textarea>

                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Das Feld ist nur zum Nachdenken. Was du hier schreibst, wird nicht gespeichert.') }}
                </p>
            </div>

            @if (! $revealed)
                <div>
                    <x-filament::button wire:click="reveal({{ $item->getKey() }})" wire:target="reveal" :icon="Heroicon::OutlinedLightBulb">
                        {{ __('Lösung zeigen') }}
                    </x-filament::button>
                </div>
            @else
                <div class="grid gap-5" aria-live="polite">
                    <div class="grid gap-2 rounded-xl bg-primary-50 p-4 ring-1 ring-primary-100 dark:bg-primary-400/10 dark:ring-primary-400/20">
                        <h3 class="text-sm font-semibold text-primary-800 dark:text-primary-200">{{ __('So lautet die Lösung') }}</h3>

                        <p class="text-gray-950 whitespace-pre-line dark:text-white">{{ $item->question->expected_answer }}</p>
                    </div>

                    <template x-if="own.trim() !== ''">
                        <div class="grid gap-1">
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Deine Antwort') }}</h3>

                            <p class="whitespace-pre-line text-gray-700 dark:text-gray-300" x-text="own"></p>
                        </div>
                    </template>

                    <div class="grid gap-3">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Wie ist es dir gegangen?') }}</h3>

                        <div class="flex flex-wrap gap-3">
                            @foreach ($ratings as $rating)
                                <x-filament::button
                                    wire:key="review-rating-{{ $item->getKey() }}-{{ $rating->value }}"
                                    wire:click="rate({{ $item->getKey() }}, '{{ $rating->value }}')"
                                    wire:target="rate"
                                    :color="$rating->getColor()"
                                    :outlined="$rating !== ReviewRating::Knew"
                                >
                                    {{ $rating->getLabel() }}
                                </x-filament::button>
                            @endforeach
                        </div>

                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            {{ __('Das bleibt unter uns: Deine Einschätzung bestimmt nur, wann die Frage wiederkommt. Sie zählt nicht als Punkte, und deine Lehrkraft sieht sie nicht.') }}
                        </p>
                    </div>
                </div>
            @endif
        </section>
    @endif
</x-filament-panels::page>
