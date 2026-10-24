@php
    use App\ConsentStatus;
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-panels::page>
    <x-filament::section :heading="__('Worum geht es?')" :icon="Heroicon::OutlinedSignal">
        <div class="space-y-3 text-sm text-gray-700 dark:text-gray-300">
            <p>{!! __('Deine Lehrkraft kann in einer Stunde das <strong>Tuto-Mikro</strong> einschalten. Es erfasst nur den Ton, es gibt kein Bild und kein Video. Daraus entstehen Notizen aus der Stunde.') !!}</p>
            <p>{!! __('Das geht nur, wenn deine Eltern eingewilligt haben und die ganze Klasse einverstanden ist. Deine Schule möchte, dass auch <strong>du selbst</strong> Ja sagst.') !!}</p>
            <p>{{ __('Du kannst jederzeit deine Meinung ändern. Dir passiert dadurch nichts.') }}</p>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('Dein Einverständnis')" :icon="Heroicon::OutlinedHandRaised">
        <div class="space-y-4">
            <p class="flex flex-wrap items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <span>{{ __('Einwilligung deiner Eltern:') }}</span>
                <x-filament::badge :color="$parentsConsented ? 'success' : $parentStatus->getColor()" :icon="$parentsConsented ? Heroicon::OutlinedCheckCircle : $parentStatus->getIcon()">
                    {{ $parentsConsented ? __('Liegt vor') : $parentStatus->getLabel() }}
                </x-filament::badge>
            </p>

            @if (! $parentsConsented)
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Sobald deine Eltern eingewilligt haben, kannst du hier zustimmen. Wenn du Fragen hast, sprich mit deiner Lehrkraft.') }}
                </p>
            @else
                <p class="flex flex-wrap items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <span>{{ __('Dein Einverständnis:') }}</span>
                    @if ($confirmedAt)
                        <x-filament::badge color="success" :icon="Heroicon::OutlinedCheckCircle">{{ __('Ja, seit :date', ['date' => $confirmedAt->format('d.m.Y')]) }}</x-filament::badge>
                    @else
                        <x-filament::badge color="gray" :icon="Heroicon::OutlinedQuestionMarkCircle">{{ __('Noch nicht gesagt') }}</x-filament::badge>
                    @endif
                </p>

                <div class="flex flex-wrap items-center gap-3">
                    @if (! $confirmedAt)
                        <x-filament::button wire:click="confirm" :icon="Heroicon::OutlinedCheckCircle" wire:target="confirm">
                            {{ __('Ich bin einverstanden') }}
                        </x-filament::button>
                    @else
                        <x-filament::button color="gray" wire:click="withdraw" :icon="Heroicon::OutlinedXCircle" wire:target="withdraw">
                            {{ __('Ich bin nicht mehr einverstanden') }}
                        </x-filament::button>
                    @endif
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-panels::page>
