@php
    /** @var int $lessonCount */
    /** @var int $waitingCount */
    /** @var int $readyCount */
@endphp

<x-filament-panels::page>
    @if ($lessonCount > 0)
        {{-- A page of the exercise book: what is left to do today, in one sentence. --}}
        <section class="lp-card lp-paper" aria-labelledby="today-intro">
            <div class="py-6 ps-12 pe-6 sm:ps-20 md:pe-10">
                <h2 id="today-intro" class="text-xl font-semibold tracking-tight text-gray-950 sm:text-2xl dark:text-white">
                    {{ __('Freigabe in 30 Sekunden') }}
                </h2>

                <p class="mt-2 max-w-2xl text-base text-pretty text-gray-700 dark:text-gray-300">
                    @if ($waitingCount === 0)
                        {{ __('Alles freigegeben. Deine Klassen haben ihre Hausaufgaben.') }}
                    @else
                        <mark class="lp-marker">{{ trans_choice(':count Stunde wartet auf Freigabe|:count Stunden warten auf Freigabe', $waitingCount) }}</mark>,
                        {{ trans_choice(':count Hausaufgabe ist schon bereit.|:count Hausaufgaben sind schon bereit.', $readyCount) }}
                        {{ __('Lass die KI einen Entwurf machen, prüfe ihn kurz und gib ihn frei.') }}
                    @endif
                </p>
            </div>
        </section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
