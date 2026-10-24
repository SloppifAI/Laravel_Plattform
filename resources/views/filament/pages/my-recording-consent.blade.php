@php
    use App\ConsentStatus;
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-panels::page>
    <x-filament::section heading="Worum geht es?" :icon="Heroicon::OutlinedMicrophone">
        <div class="space-y-3 text-sm text-gray-700 dark:text-gray-300">
            <p>Deine Lehrkraft kann eine Stunde als <strong>Tonaufnahme</strong> aufnehmen. Es gibt kein Bild und kein Video.</p>
            <p>Das geht nur, wenn deine Eltern eingewilligt haben und die ganze Klasse einverstanden ist. Deine Schule möchte, dass auch <strong>du selbst</strong> Ja sagst.</p>
            <p>Du kannst jederzeit deine Meinung ändern. Dir passiert dadurch nichts.</p>
        </div>
    </x-filament::section>

    <x-filament::section heading="Dein Einverständnis" :icon="Heroicon::OutlinedHandRaised">
        <div class="space-y-4">
            <p class="flex flex-wrap items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <span>Einwilligung deiner Eltern:</span>
                <x-filament::badge :color="$parentsConsented ? 'success' : $parentStatus->getColor()" :icon="$parentsConsented ? Heroicon::OutlinedCheckCircle : $parentStatus->getIcon()">
                    {{ $parentsConsented ? 'Liegt vor' : $parentStatus->getLabel() }}
                </x-filament::badge>
            </p>

            @if (! $parentsConsented)
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Sobald deine Eltern eingewilligt haben, kannst du hier zustimmen. Wenn du Fragen hast, sprich mit deiner Lehrkraft.
                </p>
            @else
                <p class="flex flex-wrap items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <span>Dein Einverständnis:</span>
                    @if ($confirmedAt)
                        <x-filament::badge color="success" :icon="Heroicon::OutlinedCheckCircle">Ja, seit {{ $confirmedAt->format('d.m.Y') }}</x-filament::badge>
                    @else
                        <x-filament::badge color="gray" :icon="Heroicon::OutlinedQuestionMarkCircle">Noch nicht gesagt</x-filament::badge>
                    @endif
                </p>

                <div class="flex flex-wrap items-center gap-3">
                    @if (! $confirmedAt)
                        <x-filament::button wire:click="confirm" :icon="Heroicon::OutlinedCheckCircle" wire:target="confirm">
                            Ich bin einverstanden
                        </x-filament::button>
                    @else
                        <x-filament::button color="gray" wire:click="withdraw" :icon="Heroicon::OutlinedXCircle" wire:target="withdraw">
                            Ich bin nicht mehr einverstanden
                        </x-filament::button>
                    @endif
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-panels::page>
