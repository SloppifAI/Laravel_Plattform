@php
    use Filament\Support\Icons\Heroicon;
@endphp

<x-filament-panels::page>
    @if ($aiActive)
        <x-filament::callout
            color="success"
            :icon="Heroicon::OutlinedCheckCircle"
            heading="KI-Funktionen sind eingeschaltet"
            :description="'Seit '.$school->ai_enabled_at->format('d.m.Y').($enabledBy ? ', eingeschaltet von '.$enabledBy->name : '').'. Texte aus dem Unterricht deiner Schule gehen pseudonymisiert an '.$providerLabel.'.'"
        />
    @elseif ($switchedOn && ! $platformOn)
        <x-filament::callout
            color="warning"
            :icon="Heroicon::OutlinedExclamationTriangle"
            heading="Eingeschaltet, aber die Plattform hat die KI-Funktionen ausgeschaltet"
            description="Solange der Schalter der Plattform aus ist, antwortet die Demo-Logik und es geht nichts an einen KI-Anbieter. Das ändert sich, sobald die Betreuung der Plattform ihn einschaltet."
        />
    @elseif ($switchedOn)
        <x-filament::callout
            color="warning"
            :icon="Heroicon::OutlinedExclamationTriangle"
            heading="KI-Funktionen sind vorübergehend gesperrt"
            description="Sie sind eingeschaltet, aber nicht alle Punkte der Checkliste sind bestätigt. Bestätige sie unten, dann geht es weiter. Bis dahin antwortet die Demo-Logik."
        />
    @else
        <x-filament::callout
            color="gray"
            :icon="Heroicon::OutlinedNoSymbol"
            heading="KI-Funktionen sind ausgeschaltet"
            description="Solange sie aus sind, antwortet die Demo-Logik. Sie arbeitet auf der Plattform selbst, es geht nichts an einen KI-Anbieter. Schalte die KI-Funktionen erst ein, wenn die Voraussetzungen unten erfüllt sind."
        />
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
        <div class="space-y-6 lg:col-span-2">
            <x-filament::section
                heading="1. Voraussetzungen"
                description="Das bestätigt die Schule selbst. Die Plattform kann es nicht prüfen. Ohne alle vier Punkte lassen sich die KI-Funktionen nicht einschalten."
                :icon="Heroicon::OutlinedClipboardDocumentCheck"
            >
                <form wire:submit="saveSettings" class="space-y-6">
                    {{ $this->settingsForm }}

                    <x-filament::button type="submit" wire:target="saveSettings">Speichern</x-filament::button>
                </form>
            </x-filament::section>

            <x-filament::section
                heading="2. KI-Funktionen einschalten"
                :icon="Heroicon::OutlinedPower"
            >
                <div class="space-y-4">
                    @if (! $switchedOn && $missingSteps !== [])
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            <p class="font-medium text-gray-950 dark:text-white">Zum Einschalten fehlt noch:</p>
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
                            {{ $this->disableAiAction }}
                        @else
                            {{ $this->enableAiAction }}
                        @endif
                    </div>
                </div>
            </x-filament::section>
        </div>

        <div class="space-y-6">
            <x-filament::section heading="Was an die KI geht" :icon="Heroicon::OutlinedDocumentText">
                <div class="space-y-3 text-sm text-gray-600 dark:text-gray-400">
                    <p>
                        Die KI schreibt Entwürfe für Hausaufgaben, Förderaufgaben und Stufen, schreibt Texte der Lehrkraft in Einfacher Sprache, schlägt eine Bewertung der Abgaben vor und spricht in „Erklär’s mir“ als Tuto mit den Schüler:innen. Beim Tuto-Mikro erkennt sie die Sprache und fasst die Notizen aus der Stunde zusammen.
                        Hausaufgaben-Entwürfe prüfen Lehrkräfte, bevor sie sie freigeben. Förderaufgaben können sie direkt freigeben, dann gibt es keine getrennte Prüfung.
                    </p>
                    <ul class="list-disc space-y-1 ps-5">
                        <li>Fach, Thema, Datum, Notizen und Zusammenfassung der Lehrkraft sowie Fragen mit Erwartungshorizont.</li>
                        <li>Arbeitsblätter, die Lehrkräfte an eine Stunde anhängen (Fotos und PDF).</li>
                        <li>Texte der Schüler:innen: ihre Zusammenfassung, ihre Antworten und ihre Nachrichten in „Erklär’s mir“.</li>
                        <li>Beim Tuto-Mikro kurze Ton-Abschnitte zur Spracherkennung, nur wenn alle angehakten Schüler:innen dafür eine Einwilligung haben. Tuto speichert keinen Ton.</li>
                    </ul>
                    <p>
                        <span class="font-medium text-gray-950 dark:text-white">Nie mit dabei:</span>
                        Namen von Schüler:innen und Lehrkräften. Die Plattform ersetzt sie vorher durch neutrale Bezeichnungen wie „Schüler:in A“.
                        Benutzernamen, E-Mail-Adressen und Konto-Kennungen gehen ebenfalls nie an die KI.
                    </p>
                    <p>
                        <span class="font-medium text-gray-950 dark:text-white">Grenzen:</span>
                        Ersetzt werden nur Namen, die die Plattform kennt (die Klasse und die Lehrkräfte, die sie unterrichten). Namen anderer Personen und Spitznamen bleiben stehen, und auf Fotos und PDF-Arbeitsblättern und in den Ton-Abschnitten des Tuto-Mikro kann die Plattform keine Namen ersetzen.
                        Schreibt deshalb keine Namen in Notizen und hängt keine Arbeitsblätter mit Namen an. Alle KI-Funktionen im Einzelnen stehen unter „Datenschutz &amp; KI“.
                    </p>
                </div>
            </x-filament::section>

            <x-filament::section heading="Anbieter und Plattform" :icon="Heroicon::OutlinedServerStack">
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-gray-600 dark:text-gray-400">KI-Anbieter</dt>
                        <dd class="font-medium text-gray-950 dark:text-white">{{ $providerLabel }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-600 dark:text-gray-400">Modell</dt>
                        <dd class="font-medium text-gray-950 dark:text-white">{{ $model ?: 'Standardmodell des Anbieters' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-600 dark:text-gray-400">Schalter der Plattform</dt>
                        <dd class="mt-1">
                            @if ($platformOn)
                                <x-filament::badge color="success" :icon="Heroicon::OutlinedCheckCircle" class="inline-flex">Eingeschaltet</x-filament::badge>
                            @else
                                <x-filament::badge color="gray" :icon="Heroicon::OutlinedNoSymbol" class="inline-flex">Ausgeschaltet</x-filament::badge>
                            @endif
                        </dd>
                    </div>
                </dl>

                <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                    Die KI läuft nur, wenn der Schalter der Plattform an ist und deine Schule die KI-Funktionen eingeschaltet hat.
                    Sonst antwortet die Demo-Logik.
                </p>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
