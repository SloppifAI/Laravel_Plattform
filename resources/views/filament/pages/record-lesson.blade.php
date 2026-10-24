@php
    use Filament\Support\Icons\Heroicon;

    $presentIds = array_values(array_unique(array_map(intval(...), $present)));
    $presentCount = count($presentIds);
    $infoBox = 'flex items-start gap-3 rounded-xl p-4 ring-1';
    $warningBox = $infoBox.' bg-warning-50 text-warning-900 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-200';
    $card = 'rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10';
@endphp

<x-filament-panels::page>
    {{-- One Alpine component for the whole page: the checkboxes below lock while the microphone is on. --}}
    <div x-data="tutoMikro(@js($micConfig))" class="grid gap-6">
        {{-- The microphone lives in the browser only: Livewire must never morph this part while it runs. --}}
        <div wire:ignore>
            @include('filament.pages.record-lesson-recorder')

            {{-- The indicator that cannot be missed: fixed to the top of the window, Lichtblau with a blue ring while Tuto listens, red while the microphone is off. --}}
            <template x-teleport="body">
                <div>
                    <div class="lp-mic-bar" role="status" aria-live="polite" aria-atomic="true" x-bind:data-state="listening ? 'on' : 'off'" data-state="off">
                        <span class="lp-mic-dot" aria-hidden="true" x-text="listening ? '●' : '■'">■</span>
                        <span class="lp-mic-text">
                            <span x-text="barLabel">Mikro aus</span>
                            <span class="lp-mic-note" x-cloak x-show="barNote" x-text="barNote"></span>
                        </span>
                    </div>

                    <div class="lp-mic-ring" x-cloak x-show="listening" aria-hidden="true"></div>
                </div>
            </template>

            {{-- Always on the page, filled a moment after a change: what the screen reader says about warnings and results. --}}
            <p class="lp-mic-sr" role="status" aria-live="polite" x-text="announcement"></p>
        </div>

        {{-- What Tuto-Mikro does and does not do, said before anyone presses anything. --}}
        <section class="lp-card p-5 sm:p-6" aria-labelledby="lp-mic-promise">
            <div class="flex items-start gap-4">
                <x-filament::icon :icon="Heroicon::OutlinedSignal" class="mt-1 size-8 shrink-0 text-primary-600 dark:text-primary-400" />

                <div class="min-w-0">
                    <h2 id="lp-mic-promise" class="text-xl font-semibold text-gray-950 dark:text-white">Tuto schreibt mit, was du sagst</h2>
                    <p class="mt-1 text-base text-gray-700 dark:text-gray-300">Danach macht Tuto daraus mit einem Klick eine Zusammenfassung, Kernbegriffe, einen Tafelbild-Vorschlag und die Fragen aus der Klasse.</p>

                    <ul class="mt-4 grid list-none gap-x-6 gap-y-3 p-0 text-base text-gray-700 sm:grid-cols-2 dark:text-gray-300">
                        <li class="flex items-start gap-2">
                            <x-filament::icon :icon="Heroicon::OutlinedInformationCircle" class="mt-0.5 size-5 shrink-0 text-primary-600 dark:text-primary-400" />
                            <span><strong>Nur dein Mikrofon.</strong> Trage ein Ansteckmikrofon oder ein Headset dicht am Mund. Der Browser kann Stimmen nicht trennen: Was in deiner Nähe laut gesagt wird, kann mitgeschrieben werden, auch Stimmen aus der Klasse.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <x-filament::icon :icon="Heroicon::OutlinedCheckCircle" class="mt-0.5 size-5 shrink-0 text-success-600 dark:text-success-400" />
                            <span><strong>Nur Ton, nie Bild. Der Ton wird nicht gespeichert.</strong>
                                @if ($aiEnabled)
                                    Er geht in Abschnitten von etwa {{ config('lernplattform.recordings.segment_seconds') }} Sekunden zur Spracherkennung an {{ $providerName }}. Bei uns läuft er nur durch den Arbeitsspeicher und wird nirgends abgelegt.
                                @else
                                    Die KI ist für deine Schule nicht eingeschaltet: Der Ton verlässt dein Gerät nicht. Tuto schreibt mit, wenn dein Browser Deutsch auf dem Gerät erkennen kann, sonst tippst du die Notizen selbst.
                                @endif
                            </span>
                        </li>
                        <li class="flex items-start gap-2">
                            <x-filament::icon :icon="Heroicon::OutlinedCheckCircle" class="mt-0.5 size-5 shrink-0 text-success-600 dark:text-success-400" />
                            <span><strong>Die Notizen bleiben in diesem Fenster.</strong> Gespeichert wird nur, was du in die Stundennotizen übernimmst. Verlässt du die Seite, sind die Notizen weg.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <x-filament::icon :icon="Heroicon::OutlinedCheckCircle" class="mt-0.5 size-5 shrink-0 text-success-600 dark:text-success-400" />
                            <span><strong>Nur mit Einwilligung.</strong> Tuto-Mikro startet nur, wenn für alle Anwesenden eine gültige Einwilligung vorliegt. Die Namen siehst nur du, nie die Klasse am Beamer.</span>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <div class="grid items-start gap-6 lg:grid-cols-2">
            {{-- Who is present, and whether the consents allow Tuto-Mikro. Names only on this screen. --}}
            <section class="lp-card p-5 sm:p-6" aria-labelledby="lp-mic-present">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="lp-mic-present" class="text-xl font-semibold text-gray-950 dark:text-white">Wer ist heute da?</h2>
                    <span class="text-sm text-gray-700 tabular-nums dark:text-gray-300">{{ $presentCount }} von {{ $students->count() }}</span>
                </div>

                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                    Tuto-Mikro startet nur, wenn für alle, die hier angehakt sind, eine gültige Einwilligung vorliegt. Wer fehlt, hakst du ab. Kommt jemand dazu, schalte das Mikro aus, hake die Person an und schalte es wieder an.
                </p>

                {{-- What the consents say right now; updated with every tick, so it is read aloud. --}}
                <div class="mt-4" role="status" aria-live="polite">
                    @if ($check->allowed)
                        <div class="{{ $infoBox }} bg-success-50 text-success-900 ring-success-600/20 dark:bg-success-500/10 dark:text-success-200">
                            <x-filament::icon :icon="Heroicon::OutlinedCheckCircle" class="mt-0.5 size-6 shrink-0" />
                            <p class="text-base">
                                Alle {{ $presentCount }} {{ $presentCount === 1 ? 'anwesende Schüler:in hat' : 'anwesenden Schüler:innen haben' }} eingewilligt{{ $consentFormVersion !== null ? " (Fassung {$consentFormVersion} der Einwilligungserklärung)" : '' }}. Du kannst das Mikro einschalten.
                            </p>
                        </div>
                    @else
                        <div class="{{ $warningBox }}">
                            <x-filament::icon :icon="Heroicon::OutlinedNoSymbol" class="mt-0.5 size-6 shrink-0" />
                            <div class="text-base">
                                <p class="font-medium">Tuto-Mikro kann noch nicht starten.</p>

                                <ul class="mt-1 list-disc space-y-1 ps-5">
                                    @foreach ($check->reasons as $reason)
                                        <li>{{ $reason }}</li>
                                    @endforeach
                                </ul>

                                @unless ($schoolEnabled)
                                    <p class="mt-2">Die Schuladministration entscheidet in den Einstellungen der Schule, ob Tuto-Mikro genutzt werden darf.</p>
                                @endunless
                            </div>
                        </div>
                    @endif
                </div>

                <fieldset class="mt-4 min-w-0 border-0 p-0" x-bind:disabled="active">
                    <legend class="lp-mic-sr">Anwesende Schüler:innen</legend>

                    <div class="flex flex-wrap items-center gap-2">
                        <x-filament::button type="button" color="gray" size="sm" wire:click="selectAll">Alle</x-filament::button>
                        <x-filament::button type="button" color="gray" size="sm" wire:click="selectNone">Niemand</x-filament::button>
                        <x-filament::button type="button" color="gray" size="sm" :icon="Heroicon::OutlinedShieldCheck" x-on:click="toggleNames()" x-bind:aria-pressed="hideNames">
                            <span x-text="hideNames ? 'Namen zeigen' : 'Namen verbergen'">Namen verbergen</span>
                        </x-filament::button>
                    </div>

                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300" x-cloak x-show="active">Solange das Mikro an ist, kannst du die Anwesenheit nicht ändern.</p>

                    <ul class="mt-4 grid list-none gap-2 p-0 sm:grid-cols-2">
                        @forelse ($students as $student)
                            @php
                                $isPresent = in_array($student->getKey(), $presentIds, true);
                                $reason = $isPresent ? ($check->studentReasons[$student->getKey()] ?? null) : null;
                            @endphp

                            <li wire:key="present-{{ $student->getKey() }}">
                                {{-- With the names hidden a row shows neither a name, nor a number from the class list, nor why somebody is marked: only the count above says something. --}}
                                <label class="flex h-full items-start gap-3 rounded-lg p-3 ring-1" x-bind:class="hideNames ? 'ring-gray-950/10 dark:ring-white/10' : '{{ $reason ? 'bg-warning-50 ring-warning-600/30 dark:bg-warning-500/10' : 'ring-gray-950/10 dark:ring-white/10' }}'">
                                    <input type="checkbox" wire:model.live="present" value="{{ $student->getKey() }}" class="mt-0.5 size-5 shrink-0 accent-primary-600" />

                                    <span class="min-w-0">
                                        <span class="block text-base font-medium break-words text-gray-950 dark:text-white">
                                            <span x-cloak x-show="! hideNames">{{ $student->name }}</span>
                                            <span x-cloak x-show="hideNames">Schüler:in</span>
                                        </span>

                                        @if ($reason)
                                            <span x-cloak x-show="! hideNames" class="mt-0.5 flex items-start gap-1 text-sm text-warning-800 dark:text-warning-200">
                                                <x-filament::icon :icon="Heroicon::OutlinedExclamationTriangle" class="mt-0.5 size-4 shrink-0" />
                                                {{ $reason }}
                                            </span>
                                        @endif
                                    </span>
                                </label>
                            </li>
                        @empty
                            <li class="text-base text-gray-700 sm:col-span-2 dark:text-gray-300">In dieser Klasse gibt es noch keine Schüler:innen.</li>
                        @endforelse
                    </ul>

                    {{-- The declaration the session record keeps: the list above is the people in the room. It is taken back whenever the list changes. --}}
                    <label class="mt-4 flex items-start gap-3 rounded-lg p-3 ring-1 ring-gray-950/10 dark:ring-white/10">
                        <input type="checkbox" wire:model.live="attendanceConfirmed" class="mt-0.5 size-5 shrink-0 accent-primary-600" />

                        <span class="min-w-0 text-base text-gray-950 dark:text-white">
                            <strong>Ich bestätige:</strong> Nur die Schüler:innen, die hier angehakt sind, sind im Raum.
                        </span>
                    </label>
                </fieldset>
            </section>

            {{-- The microphone. --}}
            <section class="lp-card p-5 sm:p-6" wire:ignore aria-labelledby="lp-mic-controls">
                <h2 id="lp-mic-controls" class="text-xl font-semibold text-gray-950 dark:text-white">Mikro</h2>

                <div class="mt-4 grid gap-4">
                    {{-- A second window for the projector: the class sees "Mikro an" or "Mikro aus" and the topic, nothing else. --}}
                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::button tag="a" :href="$boardUrl" target="_blank" rel="noopener" color="gray" :icon="Heroicon::OutlinedPresentationChartBar">
                            Beamer-Anzeige öffnen
                        </x-filament::button>

                        <p class="min-w-0 text-sm text-gray-700 dark:text-gray-300">Zeigt der Klasse am Beamer, ob das Mikro an oder aus ist, und das Thema der Stunde. Keine Namen, keine Texte.</p>
                    </div>

                    {{-- Without https the browser offers no microphone at all, so this is checked before "can't listen". --}}
                    <div x-cloak x-show="! secure" class="{{ $warningBox }}">
                        <x-filament::icon :icon="Heroicon::OutlinedLockClosed" class="mt-0.5 size-6 shrink-0" />
                        <p class="text-base">Tuto-Mikro funktioniert nur über eine gesicherte Verbindung (https). Öffne die Plattform über die https-Adresse.</p>
                    </div>

                    <div x-cloak x-show="secure && ! supported" class="{{ $warningBox }}">
                        <x-filament::icon :icon="Heroicon::OutlinedExclamationTriangle" class="mt-0.5 size-6 shrink-0" />
                        <p class="text-base">Dieser Browser kann den Ton nicht abgreifen. Bitte nutze eine aktuelle Version von Chrome, Edge, Firefox oder Safari.</p>
                    </div>

                    <div x-cloak x-show="error" x-ref="errorNotice" tabindex="-1" role="alert" class="lp-focus {{ $infoBox }} bg-danger-50 text-danger-800 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-200">
                        <x-filament::icon :icon="Heroicon::OutlinedExclamationTriangle" class="mt-0.5 size-6 shrink-0" />
                        <p class="text-base" x-text="error"></p>
                    </div>

                    <div x-cloak x-show="warning" class="{{ $warningBox }}">
                        <x-filament::icon :icon="Heroicon::OutlinedExclamationTriangle" class="mt-0.5 size-6 shrink-0" />
                        <p class="text-base" x-text="warning"></p>
                    </div>

                    {{-- Mikro aus: start --}}
                    <div x-cloak x-show="phase === 'off' || phase === 'requesting'" class="grid gap-4">
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
                                <li>Lade diese Seite neu und schalte das Mikro wieder an.</li>
                            </ol>
                            <p class="mt-3 font-medium">Safari auf iPad oder iPhone:</p>
                            <p class="mt-1">Tippe in der Adressleiste auf „aA“, dann auf „Website-Einstellungen“ und stelle „Mikrofon“ auf „Zulassen“. Lade die Seite danach neu.</p>
                            <p class="mt-3 text-sm">Klappt das nicht, prüfe in den Datenschutz-Einstellungen von Windows, macOS oder deinem Tablet, ob der Browser das Mikrofon benutzen darf.</p>
                        </div>

                        <div x-cloak x-show="phase === 'requesting'" class="flex items-start gap-3 rounded-xl bg-gray-50 p-4 text-base text-gray-800 ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10">
                            <x-filament::icon :icon="Heroicon::OutlinedSignal" class="mt-0.5 size-6 shrink-0 text-primary-600 dark:text-primary-400" />
                            <p>Dein Browser fragt jetzt nach dem Mikrofon. Erlaube den Zugriff im Fenster des Browsers, dann geht es los.</p>
                        </div>

                        <div>
                            <x-filament::button
                                size="xl"
                                :icon="Heroicon::OutlinedSignal"
                                class="w-full sm:w-auto"
                                x-ref="startButton"
                                x-on:click="start()"
                                x-bind:disabled="! startable"
                                x-bind:aria-disabled="! startable"
                                x-bind:class="{ 'fi-disabled': ! startable }"
                                aria-describedby="lp-mic-start-hint"
                                disabled
                            >
                                Mikro an
                            </x-filament::button>
                        </div>

                        <p id="lp-mic-start-hint" class="text-sm text-gray-700 dark:text-gray-300">
                            <span x-show="$wire.canStart && $wire.attendanceConfirmed">Beim Start fragt dein Browser, ob er das Mikrofon benutzen darf.</span>
                            <span x-cloak x-show="! $wire.canStart">Zuerst muss oben alles grün sein: Wer da ist, und die Einwilligungen.</span>
                            <span x-cloak x-show="$wire.canStart && ! $wire.attendanceConfirmed">Bestätige oben noch, dass nur die Angehakten im Raum sind.</span>
                        </p>
                    </div>

                    {{-- Mikro an, or just being switched off --}}
                    <div x-cloak x-show="active" class="grid gap-5">
                        <div>
                            <p x-ref="statusLine" tabindex="-1" class="lp-focus text-sm font-medium text-gray-700 dark:text-gray-300" x-text="barLabel"></p>
                            <p class="text-5xl font-semibold text-gray-950 tabular-nums sm:text-6xl dark:text-white" aria-hidden="true" x-text="clock"></p>

                            {{-- Whether sound arrives: a locked screen or a wrong input still "listens", but silence. --}}
                            <div x-cloak x-show="level !== null && listening" class="mt-2 flex items-center gap-3">
                                <div class="lp-mic-meter" aria-hidden="true"><span x-bind:style="'width: ' + Math.round(level * 100) + '%'"></span></div>

                                <p class="inline-flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">
                                    <x-filament::icon x-show="heard" :icon="Heroicon::OutlinedSignal" class="size-5 shrink-0 text-success-600 dark:text-success-400" />
                                    <x-filament::icon x-cloak x-show="! heard" :icon="Heroicon::OutlinedSignalSlash" class="size-5 shrink-0 text-warning-600 dark:text-warning-400" />
                                    <span x-show="heard">Ton kommt an</span>
                                    <span x-cloak x-show="! heard">Seit 10 Sekunden kein Ton hörbar</span>
                                </p>
                            </div>
                        </div>

                        <div>
                            <x-filament::button
                                x-ref="stopButton"
                                size="xl"
                                color="danger"
                                :icon="Heroicon::OutlinedStop"
                                class="w-full sm:w-auto"
                                x-on:click="stop()"
                                x-bind:disabled="phase !== 'on'"
                            >
                                Mikro aus
                            </x-filament::button>
                        </div>

                        <p class="text-sm text-gray-700 dark:text-gray-300">
                            <span x-show="phase === 'on'">Lass diese Seite geöffnet: Verlässt du sie, ist das Mikro aus und die Notizen sind weg.</span>
                            <span x-cloak x-show="phase === 'stopping'">Das Mikro ist aus. Der letzte Teil wird noch mitgeschrieben, bitte lass die Seite noch geöffnet.</span>
                        </p>
                    </div>
                </div>
            </section>
        </div>

        {{-- The notes: only in this browser. --}}
        <section class="lp-card p-5 sm:p-6" wire:ignore aria-labelledby="lp-notes-heading">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="lp-notes-heading" class="text-xl font-semibold text-gray-950 dark:text-white">Notizen aus der Stunde</h2>

                <x-filament::badge x-cloak x-show="listening && mode === 'server'" color="info" :icon="Heroicon::OutlinedSparkles">Tuto schreibt mit</x-filament::badge>
                <x-filament::badge x-cloak x-show="listening && mode === 'device'" color="info" :icon="Heroicon::OutlinedSparkles">Tuto schreibt mit, nur auf diesem Gerät</x-filament::badge>
            </div>

            {{-- Not available here: a prepared state, not an error. --}}
            @unless ($aiEnabled)
                <div x-cloak x-show="! sttAvailable" class="mt-4 flex items-start gap-4 rounded-xl border border-dashed border-gray-300 p-4 dark:border-white/20">
                    <x-filament::icon :icon="Heroicon::OutlinedSparkles" class="mt-0.5 size-8 shrink-0 text-primary-600 dark:text-primary-400" />

                    <div class="min-w-0">
                        <p class="text-base font-medium text-gray-950 dark:text-white">Tuto schreibt mit, sobald die KI deiner Schule eingeschaltet ist</p>
                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                            Bis dahin kannst du die Notizen selbst tippen oder einfügen, die Zusammenfassung klappt trotzdem. Auf Geräten, die Deutsch selbst erkennen können, schreibt Tuto auch jetzt schon mit, ohne dass der Ton das Gerät verlässt.
                        </p>

                        <p class="mt-3 text-sm font-medium text-gray-700 dark:text-gray-300">Danach macht Tuto daraus:</p>
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
            @endunless

            <label for="lp-notes" class="mt-4 block text-sm font-medium text-gray-950 dark:text-white">
                Lies den Text durch und korrigiere Erkennungsfehler, bevor Tuto etwas daraus macht.
            </label>

            <textarea
                id="lp-notes"
                x-ref="notesField"
                x-model="notes"
                x-bind:readonly="notesLocked"
                rows="10"
                maxlength="{{ $micConfig['maxTextChars'] }}"
                class="lp-focus mt-1.5 block w-full rounded-lg border-0 bg-white px-3 py-2 text-base text-gray-950 shadow-sm ring-1 ring-gray-950/15 placeholder:text-gray-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:placeholder:text-gray-400"
                placeholder="Hier erscheinen die Notizen aus der Stunde, sobald Tuto mitschreibt. Du kannst auch selbst tippen."
            ></textarea>

            <p class="mt-1 text-sm text-gray-600 italic dark:text-gray-400" x-cloak x-show="sttInterim" x-text="sttInterim"></p>

            <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                <x-filament::button
                    :icon="Heroicon::OutlinedSparkles"
                    class="w-full sm:w-auto"
                    x-on:click="summarise()"
                    x-bind:disabled="! canSummarise"
                    x-bind:aria-disabled="! canSummarise"
                    x-bind:class="{ 'fi-disabled': ! canSummarise }"
                    aria-describedby="lp-summary-hint"
                    disabled
                >
                    <span x-text="summarising ? 'Tuto arbeitet …' : 'Zusammenfassung erstellen'">Zusammenfassung erstellen</span>
                </x-filament::button>

                <x-filament::button color="gray" :icon="Heroicon::OutlinedXMark" class="w-full sm:w-auto" x-on:click="discard()" x-bind:disabled="active">
                    Notizen verwerfen
                </x-filament::button>
            </div>

            <div class="mt-3 grid gap-1 text-sm text-gray-700 dark:text-gray-300">
                <p id="lp-summary-hint">
                    @if ($aiEnabled)
                        Beim Erstellen geht nur der Text an {{ $providerName }}, nie der Ton, und die Namen deiner Klasse und dein eigener werden vorher durch Platzhalter ersetzt.
                    @else
                        Die KI ist für deine Schule nicht eingeschaltet: Die Zusammenfassung entsteht aus deinem Text auf der Plattform, es wird nichts an einen Dienst gesendet. Namen deiner Klasse werden durch Platzhalter ersetzt.
                    @endif
                </p>
                <p x-cloak x-show="! hasSession">Möglich, sobald Tuto-Mikro in dieser Stunde einmal an war.</p>
            </div>

            <div x-cloak x-show="resultError" role="alert" class="mt-4 {{ $infoBox }} bg-danger-50 text-danger-800 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-200">
                <x-filament::icon :icon="Heroicon::OutlinedExclamationTriangle" class="mt-0.5 size-6 shrink-0" />
                <p class="text-base" x-text="resultError"></p>
            </div>
        </section>

        {{-- What Tuto made of the notes. Nothing of it is stored until the teacher takes it over. --}}
        <section class="lp-card p-5 sm:p-6" wire:ignore x-cloak x-show="result" aria-labelledby="lp-result-heading">
            <h2 id="lp-result-heading" x-ref="resultHeading" tabindex="-1" class="lp-focus text-xl font-semibold text-gray-950 dark:text-white">Das hat Tuto aus der Stunde gemacht</h2>
            <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">Prüfe die Ergebnisse, bevor du sie übernimmst. Gespeichert wird nur, was du übernimmst. Namen deiner Klasse werden dabei durch Platzhalter ersetzt.</p>

            <template x-if="result">
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <section class="{{ $card }}" aria-labelledby="lp-res-summary">
                        <h3 id="lp-res-summary" class="text-base font-semibold text-gray-950 dark:text-white">Zusammenfassung</h3>
                        <p class="mt-2 text-base text-pretty text-gray-800 dark:text-gray-200" x-text="result.summary || 'Aus dem Text ließ sich keine Zusammenfassung ableiten.'"></p>
                    </section>

                    <section class="{{ $card }}" aria-labelledby="lp-res-terms">
                        <h3 id="lp-res-terms" class="text-base font-semibold text-gray-950 dark:text-white">Kernbegriffe</h3>
                        <ul class="mt-2 flex list-none flex-wrap gap-2 p-0" x-show="result.key_terms.length > 0">
                            <template x-for="term in result.key_terms" x-bind:key="term">
                                <li><span class="inline-flex items-center rounded-md bg-primary-50 px-2 py-1 text-sm font-medium text-primary-700 ring-1 ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-400" x-text="term"></span></li>
                            </template>
                        </ul>
                        <p class="mt-2 text-base text-gray-700 dark:text-gray-300" x-show="result.key_terms.length === 0">Keine Kernbegriffe erkannt.</p>
                    </section>

                    <section class="{{ $card }}" aria-labelledby="lp-res-board">
                        <h3 id="lp-res-board" class="text-base font-semibold text-gray-950 dark:text-white">Tafelbild-Vorschlag</h3>
                        <ul class="mt-2 list-disc space-y-1 ps-5 text-base text-gray-800 dark:text-gray-200" x-show="result.board_outline.length > 0">
                            <template x-for="line in result.board_outline" x-bind:key="line">
                                <li class="break-words" x-text="line"></li>
                            </template>
                        </ul>
                        <p class="mt-2 text-base text-gray-700 dark:text-gray-300" x-show="result.board_outline.length === 0">Kein Tafelbild abgeleitet.</p>
                    </section>

                    <section class="{{ $card }}" aria-labelledby="lp-res-questions">
                        <h3 id="lp-res-questions" class="text-base font-semibold text-gray-950 dark:text-white">Fragen aus der Klasse</h3>
                        <ul class="mt-2 list-disc space-y-1 ps-5 text-base text-gray-800 dark:text-gray-200" x-show="result.class_questions.length > 0">
                            <template x-for="question in result.class_questions" x-bind:key="question">
                                <li class="break-words" x-text="question"></li>
                            </template>
                        </ul>
                        <p class="mt-2 text-base text-gray-700 dark:text-gray-300" x-show="result.class_questions.length === 0">Keine Fragen erkannt.</p>
                    </section>
                </div>
            </template>

            <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                <x-filament::button color="gray" :icon="Heroicon::OutlinedDocumentPlus" class="w-full sm:w-auto" x-on:click="takeOver()" x-bind:disabled="! result || ! result.suggested_notes">
                    In Stundennotizen übernehmen
                </x-filament::button>

                <x-filament::button
                    color="gray"
                    :icon="Heroicon::OutlinedPencilSquare"
                    class="w-full sm:w-auto"
                    x-on:click="createHomework()"
                    x-bind:disabled="{{ $homeworkLocked ? 'true' : '! result || ! result.suggested_notes' }}"
                    aria-describedby="lp-homework-hint"
                    :disabled="$homeworkLocked"
                >
                    Hausaufgabe daraus erstellen
                </x-filament::button>
            </div>

            <p class="mt-3 text-sm text-gray-700 dark:text-gray-300" x-cloak x-show="taken" role="status">Übernommen: Du findest es in den Stundennotizen dieser Stunde.</p>

            <p id="lp-homework-hint" class="mt-3 text-sm text-gray-700 dark:text-gray-300">
                @if ($homeworkLocked)
                    Diese Stunde ist schon freigegeben, die Hausaufgabe lässt sich nur für eine Stunde im Entwurf neu erstellen.
                @else
                    Der Text kommt in die Stundennotizen, danach erstellst du dort die Hausaufgabe. Sie wartet auf Freigabe, bis du sie freigibst. Namen deiner Klasse werden dabei durch Platzhalter ersetzt.
                @endif
            </p>
        </section>
    </div>

    {{-- The sessions of this lesson: when Tuto-Mikro was on and who was marked as present. --}}
    <section class="grid gap-4" aria-labelledby="lp-mic-sessions">
        <h2 id="lp-mic-sessions" class="text-xl font-semibold text-gray-950 dark:text-white">Tuto-Mikro in dieser Stunde</h2>

        @forelse ($sessions as $session)
            @php($startedAt = $session->started_at->copy()->locale('de'))

            <article wire:key="session-{{ $session->getKey() }}" class="lp-card p-4 sm:p-5">
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                    {{ $startedAt->format('d.m.Y') }}, {{ $startedAt->format('H:i') }}@if ($session->finished_at) bis {{ $session->finished_at->format('H:i') }}@endif Uhr
                </h3>

                <p class="mt-1 text-sm text-gray-700 tabular-nums dark:text-gray-300">
                    @if ($session->durationLabel() !== null)
                        Dauer {{ $session->durationLabel() }} ·
                    @else
                        Ende unbekannt ·
                    @endif
                    {{ count($session->present_student_ids ?? []) }} anwesend
                    @if ($session->consent_form_version !== null)
                        · Einwilligungserklärung Fassung {{ $session->consent_form_version }}
                    @endif
                    · {{ $session->audio_sent_to_ai ? 'Ton zur Spracherkennung an '.$providerName.' gesendet' : 'Ton nicht gesendet' }}
                </p>
            </article>
        @empty
            <div class="lp-card flex items-start gap-4 p-5 sm:p-6">
                <x-filament::icon :icon="Heroicon::OutlinedClock" class="mt-0.5 size-8 shrink-0 text-primary-600 dark:text-primary-400" />

                <div class="min-w-0">
                    <h3 class="text-lg font-semibold text-gray-950 dark:text-white">Noch nicht eingeschaltet</h3>
                    <p class="mt-1 text-base text-pretty text-gray-700 dark:text-gray-300">Hier steht danach, wann Tuto-Mikro an war und wie viele Schüler:innen du als anwesend markiert hattest.</p>
                </div>
            </div>
        @endforelse

        <p class="text-sm text-gray-700 dark:text-gray-300">
            Es wird nur festgehalten, wann Tuto-Mikro an war und wer als anwesend markiert war, als Nachweis für Eltern und Schule. Es gibt keinen Ton und keinen Text. Die Einträge werden nach {{ $sessionKeepDays }} Tagen gelöscht.
        </p>
    </section>
</x-filament-panels::page>
