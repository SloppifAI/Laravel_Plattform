@php
    use Filament\Support\Icons\Heroicon;

    $importable = $counts['created'] + $counts['updated'];
@endphp

<x-filament-panels::page>
    @if ($outcome === null)
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
            <x-filament::section
                class="lg:col-span-2"
                :heading="__('CSV-Datei auswählen')"
                :description="__('Du siehst zuerst eine Vorschau. Gespeichert wird erst, wenn du den Import ausführst.')"
                :icon="Heroicon::OutlinedArrowUpTray"
            >
                <form wire:submit="createPreview" class="space-y-6">
                    {{ $this->form }}

                    <x-filament::button type="submit" :icon="Heroicon::OutlinedDocumentText" wire:target="createPreview">
                        {{ __('Vorschau erstellen') }}
                    </x-filament::button>
                </form>
            </x-filament::section>

            <x-filament::section :heading="__('So muss die Datei aufgebaut sein')" :icon="Heroicon::OutlinedInformationCircle">
                <div class="space-y-4 text-sm text-gray-600 dark:text-gray-400">
                    <p>
                        {{ __('Exportiere die Schülerliste als CSV aus deiner Schulverwaltung. Die erste Zeile enthält die Spaltennamen:') }}
                        <span class="font-medium text-gray-950 dark:text-white">{{ __('Nachname, Vorname, Klasse') }}</span>.
                        {{ __('Optional sind Benutzername und E-Mail.') }}
                    </p>

                    <div class="rounded-lg bg-gray-50 p-3 ring-1 ring-gray-950/5 md:overflow-x-auto dark:bg-white/5 dark:ring-white/10" tabindex="0" role="region" aria-label="{{ __('Beispiel einer CSV-Datei') }}">
                        <pre class="font-mono text-xs break-words whitespace-pre-wrap text-gray-950 md:whitespace-pre dark:text-white">{{ __('Nachname') }};{{ __('Vorname') }};{{ __('Klasse') }}
Müller;Anna;5a
Öztürk;Mehmet;5b</pre>
                    </div>

                    <ul class="list-disc space-y-1 ps-5">
                        <li>{{ __('Klassen und Konten entstehen nur in deiner Schule, und es werden nur Schüler:innen deiner Schule aktualisiert.') }}</li>
                        <li>{{ __('Klassen, die es noch nicht gibt, werden angelegt.') }}</li>
                        <li>{!! __('Neue Konten bekommen den Benutzernamen :username und ein zufälliges Startpasswort.', ['username' => '<span class="font-mono">'.e(__('vorname.nachname')).'</span>']) !!}</li>
                        <li>{{ __('Wer schon da ist (gleicher Benutzername, ohne Benutzernamen gleicher Name in derselben Klasse), wird aktualisiert statt doppelt angelegt.') }}</li>
                        <li>{{ __('Konten von Lehrkräften und der Administration bleiben unberührt. Ein Benutzername, der auf der Plattform schon vergeben ist, wird übersprungen.') }}</li>
                        <li>{{ __('Höchstens :max Schüler:innen pro Datei.', ['max' => \App\Support\StudentImport::MAX_ROWS]) }}</li>
                    </ul>
                </div>
            </x-filament::section>
        </div>
    @else
        @if ($imported)
            <div class="flex items-start gap-3 rounded-xl bg-success-50 p-4 ring-1 ring-success-600/20 dark:bg-success-400/10 dark:ring-success-400/30" role="status">
                <x-filament::icon :icon="Heroicon::OutlinedCheckCircle" class="size-5 shrink-0 text-success-600 dark:text-success-400" aria-hidden="true" />

                <p class="text-sm font-medium text-success-800 dark:text-success-300">{{ __('Der Import ist abgeschlossen.') }}</p>
            </div>

            @if ($counts['created'] > 0)
                <div class="flex items-start gap-3 rounded-xl bg-warning-50 p-4 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:ring-warning-400/30" role="alert">
                    <x-filament::icon :icon="Heroicon::OutlinedExclamationTriangle" class="size-5 shrink-0 text-warning-600 dark:text-warning-400" aria-hidden="true" />

                    <div class="space-y-1 text-sm text-warning-800 dark:text-warning-300">
                        <p class="font-medium">{{ __('Die Startpasswörter siehst du nur jetzt.') }}</p>
                        <p>
                            {{ __('Auf der Plattform liegen sie nur verschlüsselt und lassen sich später nicht mehr anzeigen, nur neu setzen.') }}
                            {{ __('Lade die Zugangsdaten herunter oder notiere sie, bevor du diese Seite verlässt.') }}
                        </p>
                    </div>
                </div>
            @endif
        @else
            <div class="flex items-start gap-3 rounded-xl bg-info-50 p-4 ring-1 ring-info-600/20 dark:bg-info-400/10 dark:ring-info-400/30" role="status">
                <x-filament::icon :icon="Heroicon::OutlinedInformationCircle" class="size-5 shrink-0 text-info-600 dark:text-info-400" aria-hidden="true" />

                <div class="space-y-1 text-sm text-info-800 dark:text-info-300">
                    <p class="font-medium">{{ __('Das ist nur eine Vorschau. Es wurde noch nichts gespeichert.') }}</p>
                    <p>{{ __('Prüf die Zeilen und führ dann den Import aus. Übersprungene Zeilen werden nicht importiert, alle anderen schon.') }}</p>
                </div>
            </div>
        @endif

        <ul class="flex flex-wrap items-center gap-2" aria-label="{{ __('Zusammenfassung') }}">
            <li>
                <x-filament::badge color="success" :icon="Heroicon::OutlinedUserPlus">
                    {{ $imported ? __(':count angelegt', ['count' => $counts['created']]) : __(':count werden angelegt', ['count' => $counts['created']]) }}
                </x-filament::badge>
            </li>
            <li>
                <x-filament::badge color="info" :icon="Heroicon::OutlinedArrowPath">
                    {{ $imported ? __(':count aktualisiert', ['count' => $counts['updated']]) : __(':count werden aktualisiert', ['count' => $counts['updated']]) }}
                </x-filament::badge>
            </li>
            <li>
                <x-filament::badge color="warning" :icon="Heroicon::OutlinedExclamationTriangle">
                    {{ __(':count übersprungen', ['count' => $counts['skipped']]) }}
                </x-filament::badge>
            </li>
        </ul>

        @if ($newClasses !== [])
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ $imported
                    ? trans_choice('Neu angelegte Klasse:|Neu angelegte Klassen:', count($newClasses))
                    : trans_choice('Neu angelegt wird die Klasse:|Neu angelegt werden die Klassen:', count($newClasses)) }}
                <span class="font-medium text-gray-950 dark:text-white">{{ implode(', ', $newClasses) }}</span>
            </p>
        @endif

        <div class="flex flex-wrap items-center gap-3">
            @if ($imported)
                @if ($counts['created'] > 0)
                    <x-filament::button wire:click="downloadCredentials" :icon="Heroicon::OutlinedArrowDownTray" wire:target="downloadCredentials">
                        {{ __('Zugangsdaten als CSV herunterladen') }}
                    </x-filament::button>
                @endif

                <x-filament::button
                    color="gray"
                    :icon="Heroicon::OutlinedArrowUpTray"
                    wire:click="startOver"
                    :wire:confirm="$counts['created'] > 0 ? __('Die Startpasswörter sind danach nicht mehr abrufbar. Hast du die Zugangsdaten gesichert?') : null"
                >
                    {{ __('Weitere Datei importieren') }}
                </x-filament::button>
            @else
                {{ $this->importAction }}

                <x-filament::button color="gray" :icon="Heroicon::OutlinedArrowUturnLeft" wire:click="startOver">
                    {{ __('Andere Datei wählen') }}
                </x-filament::button>

                @if ($importable === 0)
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Es gibt nichts zu importieren, alle Zeilen würden übersprungen.') }}</p>
                @endif
            @endif
        </div>

        {{ $this->table }}
    @endif
</x-filament-panels::page>
