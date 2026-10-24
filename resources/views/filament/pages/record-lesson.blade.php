@php
    use App\Models\LessonRecording;
    use App\TranscriptStatus;
    use Filament\Support\Icons\Heroicon;

    $consentText = 'Ich habe die Klasse informiert, und Aufnahmen sind an meiner Schule erlaubt.';
    $sourceLabels = [
        LessonRecording::SOURCE_ON_DEVICE => 'live auf deinem Gerät erkannt',
        LessonRecording::SOURCE_SERVER => 'automatisch transkribiert',
        LessonRecording::SOURCE_MANUAL => 'von dir eingegeben oder korrigiert',
    ];
@endphp

<x-filament-panels::page>
    {{-- The recorder lives in the browser only: Livewire must never morph it while a recording runs. --}}
    <div wire:ignore x-data="lpRecorder(@js($recorderConfig))" class="grid gap-6">
        @include('filament.pages.record-lesson-recorder')

        {{-- The bar that cannot be missed: fixed to the top of the viewport while recording, paused or saving. --}}
        <template x-teleport="body">
            <div
                class="lp-rec-bar"
                role="status"
                aria-live="polite"
                aria-atomic="true"
                x-bind:data-state="phase"
                x-bind:class="{ 'lp-rec-bar--off': ! active }"
            >
                <span class="lp-rec-dot" aria-hidden="true" x-text="phase === 'paused' ? '❚❚' : '●'"></span>
                <span class="lp-rec-text">
                    <span><span x-text="barLabel"></span><span aria-hidden="true"> · </span><span class="lp-rec-clock" aria-hidden="true" x-text="clock"></span></span>
                    <span class="lp-rec-note" x-show="barNote" x-text="barNote"></span>
                </span>
            </div>
        </template>

        {{-- Always on the page, filled a moment after a change: what the screen reader says about warnings and results. --}}
        <p class="lp-rec-sr" role="status" aria-live="polite" x-text="announcement"></p>

        {{-- What happens to the recording, said before anyone presses anything. --}}
        <section class="lp-card p-5 sm:p-6" aria-labelledby="lp-rec-promise">
            <div class="flex items-start gap-4">
                <x-filament::icon :icon="Heroicon::OutlinedMicrophone" class="mt-1 size-8 shrink-0 text-primary-600 dark:text-primary-400" />

                <div class="min-w-0">
                    <h2 id="lp-rec-promise" class="text-xl font-semibold text-gray-950 dark:text-white">Nur Ton, nur für dich</h2>

                    <ul class="mt-3 grid list-none gap-x-6 gap-y-2 p-0 text-base text-gray-700 sm:grid-cols-2 dark:text-gray-300">
                        @foreach ([
                            'Aufgenommen wird nur Ton, kein Video und kein Bild.',
                            'Nur du kannst die Aufnahme hören. Auch die Schuladministration nicht.',
                            'Der Ton wird nie an eine KI gesendet.',
                            "Nach {$keepDays} Tagen wird die Aufnahme automatisch gelöscht.",
                        ] as $promise)
                            <li class="flex items-start gap-2">
                                <x-filament::icon :icon="Heroicon::OutlinedCheckCircle" class="mt-0.5 size-5 shrink-0 text-success-600 dark:text-success-400" />
                                <span>{{ $promise }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </section>

        <div class="grid items-start gap-6 lg:grid-cols-2">
            {{-- Controls --}}
            <section class="lp-card p-5 sm:p-6" aria-labelledby="lp-rec-controls">
                <h2 id="lp-rec-controls" class="text-xl font-semibold text-gray-950 dark:text-white">Aufnahme</h2>

                <div class="mt-4 grid gap-4">
                    @unless ($canRecord)
                        <div id="lp-rec-off" class="flex items-start gap-3 rounded-xl bg-warning-50 p-4 text-warning-900 ring-1 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-200">
                            <x-filament::icon :icon="Heroicon::OutlinedNoSymbol" class="mt-0.5 size-6 shrink-0" />
                            <div class="text-base">
                                <p class="font-medium">Aufnahmen sind für deine Schule nicht freigeschaltet.</p>
                                <p class="mt-1">Die Schuladministration entscheidet unter „Aufnahmen &amp; Einwilligungen“, ob Stunden aufgenommen werden dürfen. Aufnahmen, die es schon gibt, kannst du hier weiter anhören, auswerten und löschen.</p>
                            </div>
                        </div>
                    @endunless

                    {{-- Without https the browser offers no microphone at all, so this is checked before "can't record". --}}
                    <div x-cloak x-show="! secure" class="flex items-start gap-3 rounded-xl bg-warning-50 p-4 text-warning-900 ring-1 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-200">
                        <x-filament::icon :icon="Heroicon::OutlinedLockClosed" class="mt-0.5 size-6 shrink-0" />
                        <p class="text-base">Aufnahmen funktionieren nur über eine gesicherte Verbindung (https). Öffne die Plattform über die https-Adresse.</p>
                    </div>

                    <div x-cloak x-show="secure && ! supported" class="flex items-start gap-3 rounded-xl bg-warning-50 p-4 text-warning-900 ring-1 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-200">
                        <x-filament::icon :icon="Heroicon::OutlinedExclamationTriangle" class="mt-0.5 size-6 shrink-0" />
                        <p class="text-base">Dieser Browser kann keinen Ton aufnehmen. Bitte nutze eine aktuelle Version von Chrome, Edge, Firefox oder Safari.</p>
                    </div>

                    <div x-cloak x-show="error" x-ref="errorNotice" tabindex="-1" role="alert" class="lp-focus flex items-start gap-3 rounded-xl bg-danger-50 p-4 text-danger-800 ring-1 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-200">
                        <x-filament::icon :icon="Heroicon::OutlinedExclamationTriangle" class="mt-0.5 size-6 shrink-0" />
                        <div class="grid gap-3">
                            <p class="text-base" x-text="error"></p>

                            {{-- The parts that never reached the server stay in this browser until the page is left. --}}
                            <div x-show="unsentParts > 0" class="grid gap-2">
                                <p class="text-sm">Ein Teil der Aufnahme ist nur noch auf diesem Gerät. Lade ihn herunter, bevor du die Seite verlässt.</p>
                                <div>
                                    <x-filament::button color="danger" size="sm" :icon="Heroicon::OutlinedArrowDownTray" x-on:click="downloadUnsent()">
                                        Nicht gesicherten Teil herunterladen
                                    </x-filament::button>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Notices that stay until the next recording; a good upload never wipes them. --}}
                    <div x-cloak x-show="warning" class="flex items-start gap-3 rounded-xl bg-warning-50 p-4 text-warning-900 ring-1 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-200">
                        <x-filament::icon :icon="Heroicon::OutlinedExclamationTriangle" class="mt-0.5 size-6 shrink-0" />
                        <p class="text-base" x-text="warning"></p>
                    </div>

                    <div x-cloak x-show="uploadWarning" class="flex items-start gap-3 rounded-xl bg-warning-50 p-4 text-warning-900 ring-1 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-200">
                        <x-filament::icon :icon="Heroicon::OutlinedSignalSlash" class="mt-0.5 size-6 shrink-0" />
                        <p class="text-base"><span x-text="uploadWarning"></span> <span class="tabular-nums" x-show="waiting > 0">(<span x-text="waiting"></span> <span x-text="waiting === 1 ? 'Teil wartet' : 'Teile warten'"></span>)</span></p>
                    </div>

                    <div x-cloak x-show="transcriptUnsaved" class="flex items-start gap-3 rounded-xl bg-warning-50 p-4 text-warning-900 ring-1 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-200">
                        <x-filament::icon :icon="Heroicon::OutlinedExclamationTriangle" class="mt-0.5 size-6 shrink-0" />
                        <div class="grid gap-3">
                            <p class="text-base">Der Live-Text konnte nicht gespeichert werden. Er steht noch im Feld „Live-Transkript“ und geht verloren, sobald du eine neue Aufnahme startest oder die Seite verlässt.</p>
                            <div class="flex flex-wrap items-center gap-3">
                                <x-filament::button color="gray" size="sm" :icon="Heroicon::OutlinedClipboardDocument" x-on:click="copyLiveText()">
                                    Live-Text kopieren
                                </x-filament::button>
                                <span class="text-sm" x-cloak x-show="copyState === 'copied'">Kopiert. Füge ihn bei einer Aufnahme unter „Transkript“ ein.</span>
                                <span class="text-sm" x-cloak x-show="copyState === 'failed'">Das Kopieren hat nicht geklappt. Markiere den Text im Feld „Live-Transkript“ von Hand.</span>
                            </div>
                        </div>
                    </div>

                    <div x-cloak x-show="phase === 'done'" x-ref="doneNotice" tabindex="-1" class="lp-focus grid gap-3">
                        <div x-cloak x-show="doneKind === 'saved'" class="flex items-start gap-3 rounded-xl bg-success-50 p-4 text-success-900 ring-1 ring-success-600/20 dark:bg-success-500/10 dark:text-success-200">
                            <x-filament::icon :icon="Heroicon::OutlinedCheckCircle" class="mt-0.5 size-6 shrink-0" />
                            <p class="text-base">Die Aufnahme ist gesichert. Du findest sie weiter unten in der Liste, mit Transkript und Auswertung.</p>
                        </div>

                        <div x-cloak x-show="doneKind === 'early'" class="flex items-start gap-3 rounded-xl bg-warning-50 p-4 text-warning-900 ring-1 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-200">
                            <x-filament::icon :icon="Heroicon::OutlinedExclamationTriangle" class="mt-0.5 size-6 shrink-0" />
                            <p class="text-base"><span x-text="earlyReason"></span> Gesichert ist nur der Teil bis dahin. Du findest ihn weiter unten in der Liste.</p>
                        </div>

                        <div x-cloak x-show="doneKind === 'discarded'" class="flex items-start gap-3 rounded-xl bg-warning-50 p-4 text-warning-900 ring-1 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-200">
                            <x-filament::icon :icon="Heroicon::OutlinedExclamationTriangle" class="mt-0.5 size-6 shrink-0" />
                            <p class="text-base">Es kam kein Ton an, deshalb wurde nichts gespeichert. Prüfe das Mikrofon und starte die Aufnahme noch einmal.</p>
                        </div>
                    </div>

                    {{-- Not recording: start --}}
                    <div x-cloak x-show="phase === 'idle' || phase === 'done'" class="grid gap-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm text-gray-700 dark:text-gray-300">Mikrofon:</span>

                            <x-filament::badge x-cloak x-show="micState === 'granted'" color="success" :icon="Heroicon::OutlinedCheckCircle">erlaubt</x-filament::badge>
                            <x-filament::badge x-cloak x-show="micState === 'denied'" color="danger" :icon="Heroicon::OutlinedNoSymbol">blockiert</x-filament::badge>
                            <x-filament::badge x-cloak x-show="micState !== 'granted' && micState !== 'denied'" color="gray" :icon="Heroicon::OutlinedQuestionMarkCircle">dein Browser fragt beim Start</x-filament::badge>
                        </div>

                        <div x-cloak x-show="micState === 'denied' || accessDenied" class="rounded-xl bg-gray-50 p-4 text-base text-gray-800 ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10">
                            <p class="font-medium">Das Mikrofon ist für diese Seite blockiert.</p>
                            <ol class="mt-2 list-decimal space-y-1 ps-5">
                                <li>Klicke in der Adressleiste auf das Symbol links neben der Adresse (Schloss oder Regler).</li>
                                <li>Stelle „Mikrofon“ auf „Zulassen“.</li>
                                <li>Lade diese Seite neu und starte die Aufnahme noch einmal.</li>
                            </ol>
                            <p class="mt-3 font-medium">Safari auf iPad oder iPhone:</p>
                            <p class="mt-1">Tippe in der Adressleiste auf „aA“, dann auf „Website-Einstellungen“ und stelle „Mikrofon“ auf „Zulassen“. Lade die Seite danach neu.</p>
                            <p class="mt-3 text-sm">Klappt das nicht, prüfe in den Datenschutz-Einstellungen von Windows, macOS oder deinem Tablet, ob der Browser das Mikrofon benutzen darf.</p>
                        </div>

                        <div>
                            <x-filament::button
                                size="xl"
                                :icon="Heroicon::OutlinedMicrophone"
                                class="w-full sm:w-auto"
                                x-on:click="openConsent()"
                                x-bind:disabled="! supported || ! secure || ! canRecord"
                                :disabled="! $canRecord"
                            >
                                Aufnahme starten
                            </x-filament::button>
                        </div>
                    </div>

                    {{-- Waiting for the browser's own permission prompt --}}
                    <div x-cloak x-show="phase === 'requesting'" class="flex items-start gap-3 rounded-xl bg-gray-50 p-4 text-base text-gray-800 ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10">
                        <x-filament::icon :icon="Heroicon::OutlinedMicrophone" class="mt-0.5 size-6 shrink-0 text-primary-600 dark:text-primary-400" />
                        <p>Dein Browser fragt jetzt nach dem Mikrofon. Erlaube den Zugriff im Fenster des Browsers, dann startet die Aufnahme.</p>
                    </div>

                    {{-- Recording, paused or saving the last part --}}
                    <div x-cloak x-show="active" class="grid gap-5">
                        <div>
                            <p x-ref="statusLine" tabindex="-1" class="lp-focus text-sm font-medium text-gray-700 dark:text-gray-300" x-text="barLabel"></p>
                            <p class="text-5xl font-semibold text-gray-950 tabular-nums sm:text-6xl dark:text-white" aria-hidden="true" x-text="clock"></p>

                            {{-- Whether sound arrives: a locked screen or a wrong input still "records", but silence. --}}
                            <div x-cloak x-show="level !== null && phase === 'recording'" class="mt-2 flex items-center gap-3">
                                <div class="lp-rec-meter" aria-hidden="true"><span x-bind:style="'width: ' + Math.round(level * 100) + '%'"></span></div>

                                <p class="inline-flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">
                                    <x-filament::icon x-show="heard" :icon="Heroicon::OutlinedSpeakerWave" class="size-5 shrink-0 text-success-600 dark:text-success-400" />
                                    <x-filament::icon x-cloak x-show="! heard" :icon="Heroicon::OutlinedSpeakerXMark" class="size-5 shrink-0 text-warning-600 dark:text-warning-400" />
                                    <span x-show="heard">Ton kommt an</span>
                                    <span x-cloak x-show="! heard">Seit 10 Sekunden kein Ton hörbar</span>
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-col gap-3 sm:flex-row">
                            <x-filament::button
                                x-cloak
                                x-show="phase === 'recording'"
                                x-ref="pauseButton"
                                size="xl"
                                color="gray"
                                :icon="Heroicon::OutlinedPause"
                                class="w-full sm:w-auto"
                                x-on:click="pause()"
                            >
                                Pause
                            </x-filament::button>

                            <x-filament::button
                                x-cloak
                                x-show="phase === 'paused'"
                                x-ref="resumeButton"
                                size="xl"
                                :icon="Heroicon::OutlinedPlay"
                                class="w-full sm:w-auto"
                                x-on:click="resume()"
                            >
                                Fortsetzen
                            </x-filament::button>

                            <x-filament::button
                                size="xl"
                                color="danger"
                                :icon="Heroicon::OutlinedStop"
                                class="w-full sm:w-auto"
                                x-on:click="end()"
                                x-bind:disabled="phase === 'finishing'"
                            >
                                Aufnahme beenden
                            </x-filament::button>
                        </div>

                        <p class="text-sm text-gray-700 dark:text-gray-300">
                            <span x-show="phase !== 'finishing'">Lass diese Seite geöffnet: Verlässt du sie, endet die Aufnahme.</span>
                            <span x-cloak x-show="phase === 'finishing'">Die Aufnahme wird gesichert. Bitte lass die Seite noch geöffnet.</span>
                            <span class="tabular-nums" x-show="uploaded > 0">
                                Gesichert: <span x-text="uploaded"></span> <span x-text="uploaded === 1 ? 'Teil' : 'Teile'"></span><span x-show="waiting > 0">, <span x-text="waiting"></span> <span x-text="waiting === 1 ? 'Teil wartet' : 'Teile warten'"></span></span>.
                            </span>
                        </p>
                    </div>
                </div>
            </section>

            {{-- Live transcript --}}
            <section class="lp-card p-5 sm:p-6" aria-labelledby="lp-live-heading">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="lp-live-heading" class="text-xl font-semibold text-gray-950 dark:text-white">Live-Transkript</h2>

                    <x-filament::badge x-cloak x-show="sttAvailable && sttEnabled && active" color="info" :icon="Heroicon::OutlinedComputerDesktop">nur auf diesem Gerät</x-filament::badge>
                </div>

                {{-- The browser can recognise German on this device: offered, never forced. --}}
                <div x-cloak x-show="sttAvailable" class="mt-4 grid gap-4">
                    <label class="flex items-start gap-3">
                        <input type="checkbox" role="switch" x-model="sttEnabled" class="mt-1 size-5 shrink-0 accent-primary-600" />
                        <span>
                            <span class="block text-base font-medium text-gray-950 dark:text-white">Live-Transkript (nur auf diesem Gerät)</span>
                            <span class="mt-0.5 block text-sm text-gray-700 dark:text-gray-300">Die Spracherkennung läuft auf deinem Gerät, der Ton wird dafür nirgendwohin gesendet. Der erkannte Text wird bei der Aufnahme gespeichert.</span>
                        </span>
                    </label>

                    <div
                        role="log"
                        aria-live="off"
                        aria-label="Live-Transkript"
                        tabindex="0"
                        class="lp-focus max-h-64 min-h-32 overflow-y-auto rounded-xl bg-gray-50 p-4 text-base text-gray-950 ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/10"
                        x-effect="sttFinal; sttInterim; $nextTick(() => { $el.scrollTop = $el.scrollHeight })"
                    >
                        <p x-show="! sttFinal && ! sttInterim" class="text-gray-700 dark:text-gray-300">
                            <span x-show="! sttEnabled">Schalte das Live-Transkript ein, um mitzulesen.</span>
                            <span x-cloak x-show="sttEnabled">Sobald gesprochen wird, erscheint hier der Text.</span>
                        </p>
                        <p class="whitespace-pre-wrap"><span x-text="sttFinal"></span> <span class="text-gray-600 italic dark:text-gray-400" x-text="sttInterim"></span></p>
                    </div>
                </div>

                {{-- Not available here: a prepared state, not an error. --}}
                <div x-show="! sttAvailable" class="mt-4 flex items-start gap-4 rounded-xl border border-dashed border-gray-300 p-4 dark:border-white/20">
                    <x-filament::icon :icon="Heroicon::OutlinedLanguage" class="mt-0.5 size-8 shrink-0 text-primary-600 dark:text-primary-400" />

                    <div class="min-w-0">
                        <p class="text-base font-medium text-gray-950 dark:text-white">Live-Transkript vorbereitet – wird aktiv, sobald die Transkription freigeschaltet ist</p>
                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                            Dann erscheint hier während der Stunde der gesprochene Text. Auf Geräten, die Deutsch selbst erkennen können, geht das auch schon jetzt, ohne dass der Ton das Gerät verlässt.
                        </p>

                        <p class="mt-3 text-sm font-medium text-gray-700 dark:text-gray-300">Danach wertest du den Text aus:</p>
                        <ul class="mt-1.5 flex list-none flex-wrap gap-2 p-0">
                            @foreach (['Zusammenfassung', 'Kernbegriffe', 'Tafelbild-Vorschlag', 'Fragen aus der Klasse'] as $preview)
                                <li class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10">
                                    <x-filament::icon :icon="Heroicon::OutlinedSparkles" class="size-4 shrink-0" />
                                    {{ $preview }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </section>
        </div>

        {{-- Consent: asked in the page, before the browser is asked for the microphone. --}}
        <dialog
            x-ref="consentDialog"
            aria-labelledby="lp-consent-title"
            class="lp-rec-dialog lp-card m-auto max-h-[90dvh] w-[min(92vw,38rem)] overflow-y-auto p-0 text-gray-950 dark:text-white"
        >
            <div class="p-5 sm:p-7">
                <h2 id="lp-consent-title" class="text-2xl font-semibold">Bevor die Aufnahme startet</h2>

                <ul class="mt-4 grid list-none gap-3 p-0 text-base text-gray-800 dark:text-gray-200">
                    @foreach ([
                        'Aufgenommen wird nur Ton. Es gibt kein Video und kein Bild.',
                        'Die Aufnahme wird nur für dich gespeichert. Die Schuladministration, andere Lehrkräfte und Schüler:innen können sie nicht hören.',
                        'Der Ton wird nie an eine KI gesendet. Ein Transkript wird nur ausgewertet, wenn du es auslöst, und die Namen deiner Klasse werden vorher durch Platzhalter ersetzt.',
                        "Nach {$keepDays} Tagen wird die Aufnahme automatisch gelöscht. Du kannst sie jederzeit selbst löschen.",
                        'Informiere die Klasse vorher und halte dich an die Regeln deiner Schule.',
                    ] as $point)
                        <li class="flex items-start gap-3">
                            <x-filament::icon :icon="Heroicon::OutlinedCheckCircle" class="mt-0.5 size-6 shrink-0 text-primary-600 dark:text-primary-400" />
                            <span>{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>

                <label for="lp-consent-check" class="mt-6 flex items-start gap-3 rounded-xl bg-gray-50 p-4 text-base font-medium ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/10">
                    <input id="lp-consent-check" type="checkbox" x-model="consentChecked" aria-required="true" class="mt-0.5 size-6 shrink-0 accent-primary-600" />
                    <span>{{ $consentText }}</span>
                </label>

                <p class="mt-3 text-sm text-gray-700 dark:text-gray-300">Danach fragt dich dein Browser, ob er das Mikrofon benutzen darf.</p>

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <x-filament::button color="gray" size="lg" class="w-full sm:w-auto" x-on:click="closeConsent()">
                        Abbrechen
                    </x-filament::button>

                    <x-filament::button
                        size="lg"
                        :icon="Heroicon::OutlinedMicrophone"
                        class="w-full sm:w-auto"
                        x-on:click="confirmConsent()"
                        x-bind:disabled="! consentChecked"
                        x-bind:aria-disabled="! consentChecked"
                        x-bind:class="{ 'fi-disabled': ! consentChecked }"
                        aria-describedby="lp-consent-hint"
                        disabled
                    >
                        Aufnahme starten
                    </x-filament::button>
                </div>

                <p id="lp-consent-hint" class="mt-2 text-sm text-gray-700 sm:text-end dark:text-gray-300" x-show="! consentChecked">
                    Setze zuerst das Häkchen, dann kannst du starten.
                </p>
            </div>
        </dialog>
    </div>

    {{-- Saved recordings of this lesson --}}
    <section class="grid gap-4" aria-labelledby="lp-rec-list">
        <h2 id="lp-rec-list" class="text-xl font-semibold text-gray-950 dark:text-white">Aufnahmen dieser Stunde</h2>

        @forelse ($recordings as $recording)
            {{-- A recording that is still being written has no player, no text box and no delete button yet. --}}
            @if ($recording->isLive())
                <article wire:key="recording-{{ $recording->id }}" class="lp-card p-5 sm:p-6" aria-labelledby="recording-title-{{ $recording->id }}">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <h3 id="recording-title-{{ $recording->id }}" class="text-lg font-semibold text-gray-950 dark:text-white">
                            Aufnahme vom {{ $recording->started_at->format('d.m.Y') }}, {{ $recording->started_at->format('H:i') }} Uhr
                        </h3>

                        <x-filament::badge color="danger" :icon="Heroicon::OutlinedMicrophone">Läuft gerade</x-filament::badge>
                    </div>

                    <p class="mt-2 text-base text-gray-700 dark:text-gray-300">
                        Diese Aufnahme läuft noch, in diesem oder einem anderen Fenster. Sobald du sie beendet hast, findest du sie hier mit Player, Transkript und Auswertung. Löschen lässt sie sich erst danach.
                    </p>
                </article>

                @continue
            @endif

            @php
                $status = $recording->transcript_status;
                $hasTranscript = filled($recording->transcript);
                $analysis = $recording->analysis;
                $hasResult = is_array($analysis);
                $startedAt = $recording->started_at->copy()->locale('de');
                $duration = $recording->durationLabel();
                $hasAudio = $recording->hasAudio();
                // Alpine switches these buttons on and off while the teacher types, so each carries the expression too.
                $notesOff = $hasResult ? 'false' : '! hasText';
                $homeworkOff = $lesson->isPublished() ? 'true' : $notesOff;
            @endphp

            <article wire:key="recording-{{ $recording->id }}" class="lp-card p-5 sm:p-6" aria-labelledby="recording-title-{{ $recording->id }}">
                <header class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 id="recording-title-{{ $recording->id }}" class="text-lg font-semibold text-gray-950 dark:text-white">
                            Aufnahme vom {{ $startedAt->format('d.m.Y') }}, {{ $startedAt->format('H:i') }} Uhr
                        </h3>

                        <p class="mt-0.5 text-sm text-gray-700 tabular-nums dark:text-gray-300">
                            @if ($duration !== null)
                                Dauer {{ $duration }}
                            @else
                                Unterbrochen, die Dauer ist unbekannt
                            @endif
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($hasAudio)
                            <x-filament::button tag="a" :href="route('recordings.download', $recording)" color="gray" size="sm" :icon="Heroicon::OutlinedArrowDownTray">
                                Herunterladen
                            </x-filament::button>
                        @endif

                        {{ ($this->deleteRecordingAction)(['recording' => $recording->id]) }}
                    </div>
                </header>

                @if ($hasAudio)
                    <audio controls preload="none" class="mt-4 w-full" aria-label="Aufnahme vom {{ $startedAt->format('d.m.Y') }}, {{ $startedAt->format('H:i') }} Uhr abspielen" src="{{ route('recordings.stream', $recording) }}">
                        Dein Browser kann die Aufnahme nicht abspielen. Lade sie herunter.
                    </audio>
                @else
                    <p class="mt-4 text-base text-gray-700 dark:text-gray-300">Die Audiodatei ist nicht mehr vorhanden.</p>
                @endif

                <div
                    class="mt-6 border-t border-gray-950/5 pt-5 dark:border-white/10"
                    x-data="{ hasText: @js($hasTranscript) }"
                    x-effect="hasText = (($wire.transcripts[{{ $recording->id }}] ?? '').trim() !== '')"
                >
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <h4 class="text-base font-semibold text-gray-950 dark:text-white">Transkript &amp; Auswertung</h4>

                        <x-filament::badge :color="$status->getColor()" :icon="$status->getIcon()">{{ $status->getLabel() }}</x-filament::badge>

                        @if ($recording->transcript_source !== null)
                            <span class="text-sm text-gray-700 dark:text-gray-300">Quelle: {{ $sourceLabels[$recording->transcript_source] ?? $recording->transcript_source }}</span>
                        @endif
                    </div>

                    <label for="transcript-{{ $recording->id }}" class="mt-4 block text-sm font-medium text-gray-950 dark:text-white">
                        Transkript. Du kannst Text einfügen oder korrigieren.
                    </label>

                    <textarea
                        id="transcript-{{ $recording->id }}"
                        rows="8"
                        wire:model="transcripts.{{ $recording->id }}"
                        x-on:input="hasText = $event.target.value.trim() !== ''"
                        class="lp-focus mt-1.5 block w-full rounded-lg border-0 bg-white px-3 py-2 text-base text-gray-950 shadow-sm ring-1 ring-gray-950/15 placeholder:text-gray-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:placeholder:text-gray-400"
                        placeholder="Noch kein Transkript. Füge hier den Text der Stunde ein."
                    ></textarea>

                    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                        <x-filament::button color="gray" :icon="Heroicon::OutlinedCheck" class="w-full sm:w-auto" wire:click="saveTranscript({{ $recording->id }})">
                            Transkript speichern
                        </x-filament::button>

                        <x-filament::button
                            color="gray"
                            :icon="Heroicon::OutlinedLanguage"
                            class="w-full sm:w-auto"
                            wire:click="createTranscript({{ $recording->id }})"
                            :disabled="! $transcriptionEnabled"
                            aria-describedby="transcribe-hint-{{ $recording->id }}"
                        >
                            Transkript erstellen
                        </x-filament::button>

                        <x-filament::button
                            :icon="Heroicon::OutlinedSparkles"
                            class="w-full sm:w-auto"
                            wire:click="analyse({{ $recording->id }})"
                            :disabled="! $hasTranscript"
                            x-bind:disabled="! hasText"
                            x-bind:aria-disabled="! hasText"
                            x-bind:class="{ 'fi-disabled': ! hasText }"
                            aria-describedby="analyse-hint-{{ $recording->id }}"
                        >
                            Mit KI auswerten
                            @unless ($aiEnabled)
                                <span class="ms-1 rounded-sm bg-white/25 px-1.5 py-0.5 text-xs font-semibold">Demo-Modus</span>
                            @endunless
                        </x-filament::button>
                    </div>

                    <div class="mt-3 grid gap-1 text-sm text-gray-700 dark:text-gray-300">
                        <p id="transcribe-hint-{{ $recording->id }}">
                            @if ($transcriptionEnabled)
                                „Transkript erstellen“ schreibt die Aufnahme auf dem Server in Text um. Der Ton geht dafür an den angebundenen Spracherkennungsdienst.
                            @else
                                „Transkript erstellen“ ist noch nicht freigeschaltet. Bis dahin hilft das Live-Transkript auf deinem Gerät oder Text, den du selbst einfügst.
                            @endif
                        </p>

                        <p id="analyse-hint-{{ $recording->id }}">
                            @if ($aiEnabled)
                                Die KI bekommt nur den Text, nie den Ton. Die Namen deiner Klasse und dein eigener werden vorher durch Platzhalter ersetzt.
                            @else
                                Demo-Modus: Die Auswertung entsteht lokal aus deinem Text, es wird nichts an eine KI gesendet. Sie ist nur sinnvoll, wenn ein Transkript da ist.
                            @endif
                        </p>
                    </div>

                    @if ($hasResult)
                        <div class="mt-6 grid gap-4 md:grid-cols-2">
                            <section class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10" aria-labelledby="summary-{{ $recording->id }}">
                                <h5 id="summary-{{ $recording->id }}" class="text-base font-semibold text-gray-950 dark:text-white">Zusammenfassung</h5>
                                <p class="mt-2 text-base text-pretty text-gray-800 dark:text-gray-200">{{ $analysis['summary'] ?: 'Aus dem Text ließ sich keine Zusammenfassung ableiten.' }}</p>
                            </section>

                            <section class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10" aria-labelledby="terms-{{ $recording->id }}">
                                <h5 id="terms-{{ $recording->id }}" class="text-base font-semibold text-gray-950 dark:text-white">Kernbegriffe</h5>
                                @if (filled($analysis['key_terms']))
                                    <ul class="mt-2 flex list-none flex-wrap gap-2 p-0">
                                        @foreach ($analysis['key_terms'] as $term)
                                            <li><x-filament::badge color="primary">{{ $term }}</x-filament::badge></li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="mt-2 text-base text-gray-700 dark:text-gray-300">Keine Kernbegriffe erkannt.</p>
                                @endif
                            </section>

                            <section class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10" aria-labelledby="board-{{ $recording->id }}">
                                <h5 id="board-{{ $recording->id }}" class="text-base font-semibold text-gray-950 dark:text-white">Tafelbild-Vorschlag</h5>
                                @if (filled($analysis['board_outline']))
                                    <ul class="mt-2 list-disc space-y-1 ps-5 text-base text-gray-800 dark:text-gray-200">
                                        @foreach ($analysis['board_outline'] as $line)
                                            <li class="break-words">{{ $line }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="mt-2 text-base text-gray-700 dark:text-gray-300">Kein Tafelbild abgeleitet.</p>
                                @endif
                            </section>

                            <section class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10" aria-labelledby="questions-{{ $recording->id }}">
                                <h5 id="questions-{{ $recording->id }}" class="text-base font-semibold text-gray-950 dark:text-white">Fragen aus der Klasse</h5>
                                @if (filled($analysis['class_questions']))
                                    <ul class="mt-2 list-disc space-y-1 ps-5 text-base text-gray-800 dark:text-gray-200">
                                        @foreach ($analysis['class_questions'] as $question)
                                            <li class="break-words">{{ $question }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="mt-2 text-base text-gray-700 dark:text-gray-300">Keine Fragen erkannt.</p>
                                @endif
                            </section>
                        </div>

                        <p class="mt-3 text-sm text-gray-700 dark:text-gray-300">
                            Ausgewertet am {{ $recording->analysed_at?->copy()->locale('de')->isoFormat('D.M.YYYY, HH:mm') }} Uhr. Prüfe die Ergebnisse, bevor du sie übernimmst.
                        </p>
                    @endif

                    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                        <x-filament::button
                            color="gray"
                            :icon="Heroicon::OutlinedDocumentPlus"
                            class="w-full sm:w-auto"
                            wire:click="applyToNotes({{ $recording->id }})"
                            :disabled="! $hasTranscript && ! $hasResult"
                            x-bind:disabled="{{ $notesOff }}"
                            x-bind:aria-disabled="{{ $notesOff }}"
                            x-bind:class="{ 'fi-disabled': {{ $notesOff }} }"
                        >
                            In Stundennotizen übernehmen
                        </x-filament::button>

                        <x-filament::button
                            color="gray"
                            :icon="Heroicon::OutlinedPencilSquare"
                            class="w-full sm:w-auto"
                            wire:click="createHomework({{ $recording->id }})"
                            :disabled="$lesson->isPublished() || (! $hasTranscript && ! $hasResult)"
                            x-bind:disabled="{{ $homeworkOff }}"
                            x-bind:aria-disabled="{{ $homeworkOff }}"
                            x-bind:class="{ 'fi-disabled': {{ $homeworkOff }} }"
                            aria-describedby="homework-hint-{{ $recording->id }}"
                        >
                            Hausaufgabe daraus erstellen
                        </x-filament::button>
                    </div>

                    <p id="homework-hint-{{ $recording->id }}" class="mt-3 text-sm text-gray-700 dark:text-gray-300">
                        @if ($lesson->isPublished())
                            Diese Stunde ist schon verteilt, die Hausaufgabe lässt sich nur für eine Stunde im Entwurf neu erstellen.
                        @else
                            Der Text kommt in die Stundennotizen, danach wählst du in der Stunde „Hausaufgabe mit KI erstellen“. Namen deiner Klasse werden dabei durch Platzhalter ersetzt.
                        @endif
                    </p>
                </div>
            </article>
        @empty
            <div class="lp-card flex items-start gap-4 p-5 sm:p-6">
                <x-filament::icon :icon="Heroicon::OutlinedMicrophone" class="mt-0.5 size-8 shrink-0 text-primary-600 dark:text-primary-400" />

                <div class="min-w-0">
                    <h3 class="text-lg font-semibold text-gray-950 dark:text-white">Noch keine Aufnahme</h3>
                    <p class="mt-1 text-base text-pretty text-gray-700 dark:text-gray-300">
                        Nach der ersten Aufnahme findest du sie hier: zum Anhören, mit Transkript, Auswertung und Übernahme in die Stundennotizen.
                    </p>
                </div>
            </div>
        @endforelse
    </section>
</x-filament-panels::page>
