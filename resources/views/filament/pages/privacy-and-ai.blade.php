<x-filament-panels::page>
    {{-- The AI status callout from PrivacyAndAi::content() --}}
    {{ $this->content }}

    <x-filament::section icon="heroicon-o-eye-slash" icon-color="success">
        <x-slot name="heading">Keine Mitschnitte</x-slot>

        <div class="max-w-3xl space-y-3 text-sm text-gray-700 dark:text-gray-300">
            <p>
                Die Lernplattform nimmt keinen Unterricht auf, weder Ton noch Video. Eine Aufnahmefunktion gibt es nicht.
            </p>
            <p>
                Lehrkräfte tragen selbst ein, was sie festhalten möchten: Notizen, ein Tafelbild oder Arbeitsblätter als Foto oder PDF. Was nicht eingetragen wird, kennt die Plattform nicht.
            </p>
            <p>
                Die Ergebnisse sollen Schüler:innen gezielt unterstützen. Sie sind kein Mittel, um Lehrkräfte zu kontrollieren. Lehrkräfte sehen nur die Ergebnisse zu ihren eigenen Stunden, die Administration kann alle Stunden und Ergebnisse einsehen.
            </p>
        </div>
    </x-filament::section>

    <x-filament::section icon="heroicon-o-lock-closed" icon-color="primary">
        <x-slot name="heading">Welche Daten gespeichert werden und wer sie sieht</x-slot>
        <x-slot name="description">
            Alles liegt auf den Servern, auf denen die Plattform betrieben wird. Sehen können es nur die genannten Rollen.
        </x-slot>

        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($dataCategories as $category)
                <x-filament::section :icon="$category['icon']" icon-color="gray" heading-tag="h3" :heading="$category['title']" compact secondary>
                    <div class="space-y-3 text-sm text-gray-700 dark:text-gray-300">
                        <p>{{ $category['stored'] }}</p>

                        <div>
                            <p class="mb-1.5 font-medium text-gray-950 dark:text-white">Wer sieht das?</p>

                            <ul class="flex flex-wrap gap-2">
                                @foreach ($category['viewers'] as $viewer)
                                    <li>
                                        <x-filament::badge :color="$viewer['role']->getColor()" :icon="$viewer['icon']">
                                            {{ $viewer['label'] }}
                                        </x-filament::badge>
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                        @foreach ($category['notes'] as $note)
                            <p class="text-gray-600 dark:text-gray-400">{{ $note }}</p>
                        @endforeach
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section icon="heroicon-o-lifebuoy" icon-color="info">
        <x-slot name="heading">Die KI-Einschätzung ist keine Note</x-slot>

        <div class="max-w-3xl space-y-3 text-sm text-gray-700 dark:text-gray-300">
            <p>
                Der Verständniswert von 0 bis 100 ist eine KI-gestützte Einschätzung. Er hilft zu erkennen, wo jemand noch Unterstützung braucht. Er ist keine Note und ersetzt keine Beurteilung.
            </p>
            <p>
                Die Lehrkraft sieht das Ergebnis und entscheidet, was daraus folgt. Die KI kann sich irren.
            </p>

            <ul class="space-y-2 pt-1">
                @foreach ($levels as $item)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <x-filament::badge :color="$item['level']->getColor()" :icon="$item['level']->getIcon()">
                            {{ $item['level']->getLabel() }}
                        </x-filament::badge>
                        <span>{{ $item['range'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </x-filament::section>

    <x-filament::section icon="heroicon-o-cpu-chip" icon-color="gray">
        <x-slot name="heading">Was bei der Auswertung mit den Texten passiert</x-slot>

        <div class="max-w-3xl space-y-3 text-sm text-gray-700 dark:text-gray-300">
            @if ($aiEnabled)
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-[max-content_1fr]">
                    <dt class="font-medium text-gray-950 dark:text-white">Anbieter</dt>
                    <dd>Anthropic (Claude)</dd>

                    <dt class="font-medium text-gray-950 dark:text-white">Modell</dt>
                    <dd>{{ $model }}</dd>

                    <dt class="font-medium text-gray-950 dark:text-white">Gesendet wird</dt>
                    <dd>Nur Texte (Notizen der Lehrkraft, Zusammenfassungen, Antworten, „Erklär’s mir“-Gespräche) und Arbeitsblatt-Dateien (Fotos und PDFs).</dd>

                    <dt class="font-medium text-gray-950 dark:text-white">Nie gesendet wird</dt>
                    <dd>Namen, Benutzernamen oder Benutzer-IDs.</dd>
                </dl>
                <p>
                    Was in einem Text selbst steht, wird unverändert übertragen. Deshalb gehören keine Namen oder privaten Angaben in Antworten und Notizen. Lehnt das Modell eine Anfrage ab, kann sie automatisch von einem Ausweichmodell desselben Anbieters beantwortet werden.
                </p>
            @else
                <p>
                    Aktuell verlässt kein Text die Plattform. Eine einfache Demo-Logik zählt, wie viele wichtige Begriffe aus den Unterrichtsnotizen in einer Zusammenfassung oder Antwort vorkommen, und leitet daraus Wert und Rückmeldung ab. Sie versteht Inhalte nicht wirklich, die Werte sind deshalb nur grobe Anhaltspunkte.
                </p>
                <p>
                    Wird die KI-Anbindung später eingeschaltet, steht hier, welcher Anbieter und welches Modell genutzt werden und was übertragen wird.
                </p>
            @endif
        </div>
    </x-filament::section>

    @if ($isStudent)
        <x-filament::section icon="heroicon-o-light-bulb" icon-color="success">
            <x-slot name="heading">Tipps für dich</x-slot>

            <ul class="max-w-3xl list-disc space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                <li>Du darfst deine Lehrkraft jederzeit fragen, welche Daten über dich gespeichert sind.</li>
                <li>
                    Schreib keine privaten Angaben in deine Antworten und Erklärungen, zum Beispiel Adresse, Telefonnummer oder Namen anderer Personen.
                    Was du schreibst, wird so gespeichert, wie du es eingibst{{ $aiEnabled ? ' und zur Auswertung an die KI gesendet' : '' }}.
                </li>
                <li>Der Verständniswert ist keine Note. Er zeigt dir und deiner Lehrkraft, was du schon gut kannst und was du noch üben solltest.</li>
            </ul>
        </x-filament::section>
    @endif

    <x-filament::section icon="heroicon-o-question-mark-circle" icon-color="gray">
        <x-slot name="heading">Fragen zum Datenschutz?</x-slot>

        <p class="max-w-3xl text-sm text-gray-700 dark:text-gray-300">
            Ansprechpartner ist die Schulverwaltung (Administration). Dort bekommst du Auskunft darüber, welche Daten gespeichert sind, und kannst Fragen zu Berichtigung und Löschung stellen.
        </p>
    </x-filament::section>

    @if ($isAdmin)
        <x-filament::section icon="heroicon-o-cog-6-tooth" icon-color="danger">
            <x-slot name="heading">Für die Administration: KI-Anbindung einschalten</x-slot>
            <x-slot name="description">Dieser Abschnitt ist nur für die Administration sichtbar.</x-slot>

            <div class="max-w-3xl space-y-4 text-sm text-gray-700 dark:text-gray-300">
                <p>
                    @if ($aiEnabled)
                        Die Anbindung ist eingeschaltet. Sie wird über die Umgebungsvariablen (.env) des Servers gesteuert. Mit <code>ANTHROPIC_ENABLED=false</code> läuft die Plattform wieder komplett offline.
                    @else
                        Die Anbindung ist vorbereitet, aber ausgeschaltet. Eingeschaltet wird sie über die Umgebungsvariablen (.env) des Servers:
                    @endif
                </p>

                <pre class="overflow-x-auto rounded-lg bg-gray-100 p-3 text-xs text-gray-950 dark:bg-white/5 dark:text-white"><code>ANTHROPIC_ENABLED=true
ANTHROPIC_API_KEY=dein-api-schluessel
ANTHROPIC_MODEL=</code></pre>

                <p>
                    <code>ANTHROPIC_MODEL</code> ist optional. Bleibt es leer, gilt das Standardmodell der Plattform. Nach einer Änderung muss die Konfiguration der Anwendung neu geladen werden. Diese Seite zeigt dann automatisch Anbieter und Modell an.
                </p>

                <div>
                    <h3 class="mb-2 font-medium text-gray-950 dark:text-white">Checkliste vor dem Einschalten</h3>

                    <ul class="space-y-2">
                        @foreach ([
                            'Auftragsverarbeitungsvertrag mit dem Anbieter abgeschlossen',
                            'Datenschutz-Folgenabschätzung durchgeführt',
                            'Eltern und Schüler:innen informiert, zum Beispiel mit einem Hinweis auf diese Seite',
                            'Personalrat beteiligt',
                        ] as $step)
                            <li class="flex items-start gap-2">
                                <x-filament::icon icon="heroicon-o-stop" class="mt-0.5 size-5 shrink-0 text-gray-400 dark:text-gray-500" />
                                <span>{{ $step }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <p class="text-gray-600 dark:text-gray-400">
                    Die Liste ist eine Gedächtnisstütze und keine Rechtsberatung. Welche Schritte an eurer Schule nötig sind, klärt am besten eure Datenschutzbeauftragte oder euer Datenschutzbeauftragter.
                </p>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
