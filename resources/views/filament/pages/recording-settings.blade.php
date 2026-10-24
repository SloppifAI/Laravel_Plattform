@php
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-panels::page>
    @if ($recordingsEnabled)
        <x-filament::callout
            color="success"
            :icon="Heroicon::OutlinedMicrophone"
            heading="Aufnahmen sind freigeschaltet"
            :description="'Seit '.$school->recordings_enabled_at->format('d.m.Y').($enabledBy ? ', freigegeben von '.$enabledBy->name : '').'. Lehrkräfte dürfen eine Stunde nur aufnehmen, wenn für alle anwesenden Schüler:innen eine gültige Einwilligung vorliegt.'"
        />
    @elseif ($switchedOn)
        <x-filament::callout
            color="warning"
            :icon="Heroicon::OutlinedExclamationTriangle"
            heading="Aufnahmen sind vorübergehend gesperrt"
            description="Die Aufnahmen sind freigeschaltet, aber nicht alle Punkte der Checkliste sind bestätigt. Bestätige sie unten, dann geht es weiter."
        />
    @else
        <x-filament::callout
            color="gray"
            :icon="Heroicon::OutlinedNoSymbol"
            heading="Aufnahmen sind ausgeschaltet"
            description="Solange sie aus sind, kann keine Lehrkraft eine Stunde aufnehmen. Schalte sie erst frei, wenn die Voraussetzungen unten erfüllt sind."
        />
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
        <div class="space-y-6 lg:col-span-2">
            <x-filament::section
                heading="1. Voraussetzungen"
                description="Das bestätigt die Schule selbst. Die Plattform kann es nicht prüfen. Ohne alle fünf Punkte lassen sich die Aufnahmen nicht freischalten."
                :icon="Heroicon::OutlinedClipboardDocumentCheck"
            >
                <form wire:submit="saveSettings" class="space-y-6">
                    {{ $this->settingsForm }}

                    <x-filament::button type="submit" wire:target="saveSettings">Speichern</x-filament::button>
                </form>
            </x-filament::section>

            <x-filament::section
                heading="2. Einwilligungserklärung"
                description="Diesen Text bestätigen die Eltern. Er wird nie nachträglich geändert: Eine Änderung veröffentlicht eine neue Fassung."
                :icon="Heroicon::OutlinedDocumentText"
            >
                <div class="space-y-6">
                    {{ $this->consentFormForm }}

                    <div>
                        {{ $this->publishConsentFormAction }}
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section
                heading="3. Aufnahmen freischalten"
                :icon="Heroicon::OutlinedPower"
            >
                <div class="space-y-4">
                    @if (! $switchedOn && $missingSteps !== [])
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            <p class="font-medium text-gray-950 dark:text-white">Zum Freischalten fehlt noch:</p>
                            <ul class="mt-1 list-disc space-y-0.5 ps-5">
                                @foreach ($missingSteps as $step)
                                    <li>{{ $step }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div>
                        {{-- Rendered as plain HTML, which does not check visibility itself. --}}
                        @if ($switchedOn)
                            {{ $this->disableRecordingsAction }}
                        @else
                            {{ $this->enableRecordingsAction }}
                        @endif
                    </div>
                </div>
            </x-filament::section>
        </div>

        <div class="space-y-6">
            <x-filament::section heading="Stand der Einwilligungen" :icon="Heroicon::OutlinedUsers">
                @if ($current === null)
                    <p class="text-sm text-gray-600 dark:text-gray-400">Es ist noch keine Fassung veröffentlicht.</p>
                @else
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Fassung {{ $current->version }}:
                        <span class="font-medium text-gray-950 dark:text-white">{{ $consentedCount }} von {{ $studentCount }}</span>
                        Schüler:innen haben eingewilligt.
                        Die Einwilligungen verwaltet die Lehrkraft unter „Einwilligungen“.
                    </p>
                @endif

                <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                    Aufnahmen und Transkripte werden nach {{ $keepDays }} Tagen automatisch gelöscht.
                </p>
            </x-filament::section>

            <x-filament::section heading="Fassungen" :icon="Heroicon::OutlinedClock">
                @if ($versions->isEmpty())
                    <p class="text-sm text-gray-600 dark:text-gray-400">Noch keine veröffentlichte Fassung.</p>
                @else
                    <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                        @foreach ($versions as $version)
                            <li class="py-2 first:pt-0 last:pb-0">
                                <p class="font-medium text-gray-950 dark:text-white">
                                    Fassung {{ $version->version }}
                                    @if ($loop->first)
                                        <x-filament::badge color="success" :icon="Heroicon::OutlinedCheckCircle" class="ms-1 inline-flex">Aktuell</x-filament::badge>
                                    @endif
                                </p>
                                <p class="text-gray-600 dark:text-gray-400">
                                    Veröffentlicht am {{ $version->published_at->format('d.m.Y') }}@if ($version->creator) von {{ $version->creator->name }}@endif.
                                    {{ $version->consents_count }} {{ $version->consents_count === 1 ? 'Einwilligung' : 'Einwilligungen' }} mit dieser Fassung.
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
