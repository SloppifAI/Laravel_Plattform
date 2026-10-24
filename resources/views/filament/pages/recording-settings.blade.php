@php
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-panels::page>
    @if ($recordingsEnabled)
        <x-filament::callout
            color="success"
            :icon="Heroicon::OutlinedSignal"
            :heading="__('Tuto-Mikro ist freigeschaltet')"
            :description="($enabledBy
                ? __('Seit :date, freigegeben von :name. Lehrkräfte dürfen das Mikro nur einschalten, wenn für alle anwesenden Schüler:innen eine gültige Einwilligung vorliegt.', ['date' => $school->recordings_enabled_at->format('d.m.Y'), 'name' => $enabledBy->name])
                : __('Seit :date. Lehrkräfte dürfen das Mikro nur einschalten, wenn für alle anwesenden Schüler:innen eine gültige Einwilligung vorliegt.', ['date' => $school->recordings_enabled_at->format('d.m.Y')]))"
        />
    @elseif ($switchedOn)
        <x-filament::callout
            color="warning"
            :icon="Heroicon::OutlinedExclamationTriangle"
            :heading="__('Tuto-Mikro ist vorübergehend gesperrt')"
            :description="__('Das Tuto-Mikro ist freigeschaltet, aber nicht alle Punkte der Checkliste sind bestätigt. Bestätige sie unten, dann geht es weiter.')"
        />
    @else
        <x-filament::callout
            color="gray"
            :icon="Heroicon::OutlinedNoSymbol"
            :heading="__('Tuto-Mikro ist ausgeschaltet')"
            :description="__('Solange das Tuto-Mikro aus ist, kann keine Lehrkraft es in einer Stunde einschalten. Schalte es erst frei, wenn die Voraussetzungen unten erfüllt sind.')"
        />
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
        <div class="space-y-6 lg:col-span-2">
            <x-filament::section
                :heading="__('1. Voraussetzungen')"
                :description="__('Das bestätigt die Schule selbst. Die Plattform kann es nicht prüfen. Ohne alle fünf Punkte lässt sich das Tuto-Mikro nicht freischalten.')"
                :icon="Heroicon::OutlinedClipboardDocumentCheck"
            >
                <form wire:submit="saveSettings" class="space-y-6">
                    {{ $this->settingsForm }}

                    <x-filament::button type="submit" wire:target="saveSettings">{{ __('Speichern') }}</x-filament::button>
                </form>
            </x-filament::section>

            <x-filament::section
                :heading="__('2. Einwilligungserklärung')"
                :description="__('Diesen Text bestätigen die Eltern. Er wird nie nachträglich geändert: Eine Änderung veröffentlicht eine neue Fassung.')"
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
                :heading="__('3. Tuto-Mikro freischalten')"
                :icon="Heroicon::OutlinedPower"
            >
                <div class="space-y-4">
                    @if (! $switchedOn && $missingSteps !== [])
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            <p class="font-medium text-gray-950 dark:text-white">{{ __('Zum Freischalten fehlt noch:') }}</p>
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
            <x-filament::section :heading="__('Stand der Einwilligungen')" :icon="Heroicon::OutlinedUsers">
                @if ($current === null)
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Es ist noch keine Fassung veröffentlicht.') }}</p>
                @else
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __('Fassung :version:', ['version' => $current->version]) }}
                        <span class="font-medium text-gray-950 dark:text-white">{{ __(':consented von :total', ['consented' => $consentedCount, 'total' => $studentCount]) }}</span>
                        {{ __('Schüler:innen haben eingewilligt.') }}
                        {{ __('Die Einwilligungen verwaltet die Lehrkraft unter „Einwilligungen“.') }}
                    </p>
                @endif

                <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Das Tuto-Mikro speichert keinen Ton. Der Nachweis je Sitzung wird nach :days Tagen automatisch gelöscht.', ['days' => $sessionDays]) }}
                </p>
            </x-filament::section>

            <x-filament::section :heading="__('Fassungen')" :icon="Heroicon::OutlinedClock">
                @if ($versions->isEmpty())
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Noch keine veröffentlichte Fassung.') }}</p>
                @else
                    <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                        @foreach ($versions as $version)
                            <li class="py-2 first:pt-0 last:pb-0">
                                <p class="font-medium text-gray-950 dark:text-white">
                                    {{ __('Fassung :version', ['version' => $version->version]) }}
                                    @if ($loop->first)
                                        <x-filament::badge color="success" :icon="Heroicon::OutlinedCheckCircle" class="ms-1 inline-flex">{{ __('Aktuell') }}</x-filament::badge>
                                    @endif
                                </p>
                                <p class="text-gray-600 dark:text-gray-400">
                                    @if ($version->creator)
                                        {{ __('Veröffentlicht am :date von :name.', ['date' => $version->published_at->format('d.m.Y'), 'name' => $version->creator->name]) }}
                                    @else
                                        {{ __('Veröffentlicht am :date.', ['date' => $version->published_at->format('d.m.Y')]) }}
                                    @endif
                                    {{ trans_choice(':count Einwilligung mit dieser Fassung.|:count Einwilligungen mit dieser Fassung.', $version->consents_count) }}
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
