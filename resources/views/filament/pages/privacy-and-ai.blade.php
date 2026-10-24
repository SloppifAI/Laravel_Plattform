<x-filament-panels::page>
    {{-- The AI status callout from PrivacyAndAi::content() --}}
    {{ $this->content }}

    <x-filament::section icon="heroicon-o-document-text" icon-color="success">
        <x-slot name="heading">Tuto-Mikro: Tuto speichert keinen Ton</x-slot>

        <div class="max-w-3xl space-y-3 text-sm text-gray-700 dark:text-gray-300">
            <p>
                Tuto speichert keinen Ton, kein Bild und kein Video (der Server kann einen Abschnitt für die Dauer der Anfrage kurz zwischenpuffern). Das Tuto-Mikro ist das Mikrofon der Lehrkraft: Daraus entstehen Notizen aus der Stunde, die nur die Lehrkraft sieht.
            </p>
            <p>
                Die Lehrkraft kann es nur einschalten, wenn die Schuladministration es freigegeben hat und für alle Schüler:innen, die sie als anwesend anhakt, eine Einwilligung der Eltern vorliegt. In Fächern, die die Schule sperrt, zum Beispiel Religion oder Ethik, bleibt es aus. Solange es an ist, steht „Mikro an“ auf dem Bildschirm der Lehrkraft. Die Lehrkraft kann dieselbe Anzeige am Beamer für die Klasse öffnen („an“ oder „aus“, ohne Namen); sie soll das tun oder der Klasse ansagen, dass das Mikro läuft.
            </p>
            <p>
                Mit eingeschalteten KI-Funktionen gehen kurze Ton-Abschnitte zur Spracherkennung an den KI-Anbieter und werden danach verworfen. Ohne KI-Funktionen verlässt der Ton das Gerät der Lehrkraft nicht. Was die Lehrkraft in die Stundennotizen übernimmt, wird mit ersetzten Namen gespeichert. Je Sitzung bleibt ein Nachweis, wer angehakt war; er wird nach {{ $sessionKeepDays }} Tagen gelöscht. Schüler:innen finden die eigene Anwesenheit in ihrem Datenexport.
            </p>
            <p>
                Die Ergebnisse sollen Schüler:innen gezielt unterstützen. Sie sind kein Mittel, um Lehrkräfte zu kontrollieren.
            </p>
        </div>
    </x-filament::section>

    <x-filament::section icon="heroicon-o-building-library" icon-color="primary">
        <x-slot name="heading">Jede Schule für sich</x-slot>

        <div class="max-w-3xl space-y-3 text-sm text-gray-700 dark:text-gray-300">
            <p>
                Alles, was zu einer Schule gehört, also Klassen, Konten, Unterrichtsstunden, Abgaben, Gespräche und das Klassenbuch, sehen nur Personen dieser Schule. Schulen sehen nichts voneinander.
            </p>
            <p>
                Lehrkräfte sehen die Ergebnisse zu ihren Stunden und den Lernstand der Klassen, die sie leiten, unterrichten oder gerade vertreten. Eine Vertretung gilt nur für eine begrenzte Zeit; danach endet der Zugriff von selbst, auch auf die Ergebnisse der Stunden, die sie gehalten hat. Die Schuladministration kann alle Stunden und Ergebnisse ihrer Schule einsehen, außer Gesprächen zu einem frei gewählten Thema und den Nachweisen des Tuto-Mikro.
            </p>

            <ul class="space-y-3 pt-1">
                @foreach ($roles as $role)
                    <li class="flex flex-col gap-1.5 sm:flex-row sm:items-start sm:gap-3">
                        <span class="sm:w-48 sm:shrink-0">
                            <x-filament::badge :color="$role->getColor()">{{ $role->getLabel() }}</x-filament::badge>
                        </span>
                        <span>{{ $role->getDescription() }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </x-filament::section>

    <x-filament::section icon="heroicon-o-lock-closed" icon-color="primary">
        <x-slot name="heading">Welche Daten gespeichert werden und wer sie sieht</x-slot>
        <x-slot name="description">
            Alles liegt auf den Servern, auf denen Tuto betrieben wird. Sehen können es nur die genannten Rollen.
        </x-slot>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            @foreach ($dataCategories as $category)
                <x-filament::section :icon="$category['icon']" icon-color="gray" heading-tag="h3" :heading="$category['title']" compact secondary>
                    <div class="space-y-3 text-sm text-gray-700 dark:text-gray-300">
                        <p>{{ $category['stored'] }}</p>

                        <div>
                            <p class="mb-1.5 font-medium text-gray-950 dark:text-white">Wer sieht das?</p>

                            <ul class="flex flex-wrap gap-2">
                                @foreach ($category['viewers'] as $viewer)
                                    <li>
                                        {{-- Long labels wrap instead of being cut off with an ellipsis. --}}
                                        <x-filament::badge
                                            :color="$viewer['role']->getColor()"
                                            :icon="$viewer['icon']"
                                            class="fi-wrapped [&_.fi-badge-label]:overflow-visible [&_.fi-badge-label]:whitespace-normal"
                                        >
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

        <div class="mt-4 max-w-3xl space-y-2 text-sm text-gray-700 dark:text-gray-300">
            <p>
                <span class="font-medium text-gray-950 dark:text-white">Wie lange?</span>
                Unterrichtsstunden mit Abgaben und Rückfragen, Gespräche in „Erklär’s mir“ und das Klassenbuch löscht Tuto automatisch {{ $retention }}.
                Hinweise in der Glocke werden {{ $notificationReadDays }} Tage nach dem Lesen gelöscht, spätestens nach {{ $notificationMaxDays }} Tagen.
            </p>
            <p>
                Jede Person kann ihre gespeicherten Daten im eigenen Profil herunterladen.
            </p>
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
        <x-slot name="heading">Was die KI bekommt</x-slot>

        <div class="space-y-4 text-sm text-gray-700 dark:text-gray-300">
            <div class="max-w-3xl space-y-3">
                @if ($aiEnabled)
                    <p>
                        {{ $isOperator ? 'Für Schulen, die die KI-Funktionen eingeschaltet haben,' : 'An deiner Schule sind die KI-Funktionen eingeschaltet. Dafür' }} gehen die unten genannten Daten an den KI-Anbieter.
                    </p>
                @else
                    <p>
                        Aktuell geht nichts an einen KI-Anbieter. Eine einfache Demo-Logik auf der Plattform zählt, wie viele wichtige Begriffe aus den Unterrichtsnotizen in einer Zusammenfassung oder Antwort vorkommen, und leitet daraus Wert und Rückmeldung ab. Sie versteht Inhalte nicht wirklich, die Werte sind deshalb nur grobe Anhaltspunkte.
                    </p>
                    <p>
                        Schaltet die Schuladministration die KI-Funktionen ein, gilt Folgendes.
                    </p>
                @endif

                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-[max-content_1fr]">
                    <dt class="font-medium text-gray-950 dark:text-white">Anbieter</dt>
                    <dd>{{ $providerLabel }}</dd>

                    <dt class="font-medium text-gray-950 dark:text-white">Modell</dt>
                    <dd>{{ $model }}</dd>

                    <dt class="font-medium text-gray-950 dark:text-white">Namen</dt>
                    <dd>Vor jeder Anfrage ersetzt Tuto die Namen der Schüler:innen der Klasse und ihrer Lehrkräfte durch Platzhalter wie „Schüler:in A“ oder „Lehrkraft“.</dd>

                    <dt class="font-medium text-gray-950 dark:text-white">Nie gesendet wird</dt>
                    <dd>Benutzernamen, E-Mail-Adressen, Konto-Kennungen und die Namen von Klasse und Schule.</dd>

                    <dt class="font-medium text-gray-950 dark:text-white">Grenzen</dt>
                    <dd>Namen anderer Personen, Spitznamen und falsch geschriebene Namen bleiben stehen. Auf Fotos und PDF-Arbeitsblättern und in den Ton-Abschnitten des Tuto-Mikro kann Tuto nichts ersetzen. Deshalb gehören keine Namen oder privaten Angaben in Notizen, Arbeitsblätter und Antworten.</dd>

                    <dt class="font-medium text-gray-950 dark:text-white">Beim Anbieter</dt>
                    <dd>Tuto bittet den Anbieter bei jeder Anfrage, nichts zu speichern. Die Schule bestätigt einen bezahlten Tarif, bei dem Eingaben nicht zur Verbesserung seiner Produkte dienen. Fällt der Anbieter aus, zeigt Tuto einen Fehler.</dd>
                </dl>
            </div>

            <ul class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach ($aiFunctions as $function)
                    <li class="lp-card space-y-2 p-4">
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $function['title'] }}</h3>
                        <dl class="space-y-2">
                            <div>
                                <dt class="font-medium text-gray-950 dark:text-white">Geht an die KI</dt>
                                <dd>{{ $function['sent'] }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium text-gray-950 dark:text-white">Bleibt hier</dt>
                                <dd>{{ $function['notSent'] }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium text-gray-950 dark:text-white">Ergebnis</dt>
                                <dd>{{ $function['result'] }}</dd>
                            </div>
                        </dl>
                    </li>
                @endforeach
            </ul>
        </div>
    </x-filament::section>

    @if ($isStudent)
        <x-filament::section icon="heroicon-o-light-bulb" icon-color="success">
            <x-slot name="heading">Tipps für dich</x-slot>

            <ul class="max-w-3xl list-disc space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                <li>Du darfst deine Lehrkraft jederzeit fragen, welche Daten über dich gespeichert sind. Du kannst sie auch selbst in deinem Profil herunterladen.</li>
                <li>
                    Schreib keine privaten Angaben in deine Antworten und Erklärungen, zum Beispiel Adresse, Telefonnummer oder Namen anderer Personen.
                    Was du schreibst, wird so gespeichert, wie du es eingibst{{ $aiEnabled ? ' und zur Auswertung an die KI gesendet, mit ersetzten Namen aus deiner Klasse' : '' }}.
                </li>
                <li>Ob du Einfache Sprache eingeschaltet hast, merkt sich nur dein Browser.</li>
                <li>Der Verständniswert ist keine Note. Er zeigt dir und deiner Lehrkraft, was du schon gut kannst und was du noch üben solltest.</li>
            </ul>
        </x-filament::section>
    @endif

    <x-filament::section icon="heroicon-o-question-mark-circle" icon-color="gray">
        <x-slot name="heading">Fragen zum Datenschutz?</x-slot>

        <p class="max-w-3xl text-sm text-gray-700 dark:text-gray-300">
            @if ($isOperator)
                Die Schulen und ihre Schulverwaltungen wenden sich bei Fragen zum Datenschutz an dich. Die Kontaktdaten des Betreibers stehen in der Datenschutzerklärung.
            @else
                Ansprechpartner ist die Schulverwaltung (Schuladministration) deiner Schule. Dort bekommst du Auskunft darüber, welche Daten gespeichert sind, und kannst Fragen zu Berichtigung und Löschung stellen.
            @endif
        </p>
    </x-filament::section>

    @if ($isAdmin || $isOperator)
        <x-filament::section icon="heroicon-o-cog-6-tooth" icon-color="gray">
            <x-slot name="heading">{{ $isOperator ? 'Für die Plattform-Administration: KI einrichten' : 'Für die Schuladministration: KI-Funktionen eurer Schule' }}</x-slot>
            <x-slot name="description">Dieser Abschnitt ist nur für die {{ $isOperator ? 'Plattform-Administration' : 'Schuladministration' }} sichtbar.</x-slot>

            <div class="max-w-3xl space-y-4 text-sm text-gray-700 dark:text-gray-300">
                @if ($isOperator)
                    <p>
                        Der Schalter der Plattform gilt für alle Schulen. An eine Schule geht die KI aber erst, wenn deren Schuladministration die KI-Funktionen unter „KI-Funktionen“ selbst einschaltet und dafür die Checkliste bestätigt.
                        Der Schalter der Plattform ist gerade {{ $platformAiEnabled ? 'eingeschaltet' : 'ausgeschaltet' }}. Gesteuert wird er über die Umgebungsvariablen (.env) des Servers:
                    </p>

                    <pre class="rounded-lg bg-gray-100 p-3 text-xs break-words whitespace-pre-wrap text-gray-950 md:overflow-x-auto md:whitespace-pre dark:bg-white/5 dark:text-white"><code>AI_ENABLED=true
AI_PROVIDER=gemini
GEMINI_API_KEY=dein-api-schluessel
AI_MODEL=
AI_TRANSCRIPTION_MODEL=
AI_TIMEOUT=120</code></pre>

                    <ul class="list-disc space-y-1.5 ps-5">
                        <li><code>AI_ENABLED</code>: der Schalter der Plattform. Ohne Angabe ist er an, sobald <code>GEMINI_API_KEY</code> gesetzt ist. Mit <code>AI_ENABLED=false</code> läuft die Plattform komplett offline.</li>
                        <li><code>AI_PROVIDER</code>: der Anbieter aus <code>config/ai.php</code>, Standard ist <code>gemini</code> (Google Gemini). Mit <code>ollama</code> nutzt die Plattform ein selbst betriebenes Modell (<code>OLLAMA_URL</code>).</li>
                        <li><code>AI_MODEL</code>: optional, das Modell für Entwürfe, Einschätzungen und „Erklär’s mir“. Leer gilt das Standardmodell des Anbieters.</li>
                        <li><code>AI_TRANSCRIPTION_MODEL</code>: optional, das Modell für die Spracherkennung des Tuto-Mikro.</li>
                        <li><code>AI_TIMEOUT</code>: wie viele Sekunden eine Anfrage dauern darf.</li>
                    </ul>

                    <p>
                        Nach einer Änderung muss die Konfiguration der Anwendung neu geladen werden. Diese Seite zeigt dann Anbieter und Modell an.
                    </p>
                @else
                    <p>
                        Ob Texte eurer Schule an den KI-Anbieter gehen, entscheidest du unter „KI-Funktionen“. Sie sind für eure Schule gerade {{ $aiEnabled ? 'eingeschaltet' : 'ausgeschaltet' }}.
                        Einschalten lassen sie sich erst, wenn du diese Punkte bestätigt hast:
                    </p>
                @endif

                <div>
                    <h3 class="mb-2 font-medium text-gray-950 dark:text-white">Checkliste vor dem Einschalten</h3>

                    <ul class="space-y-2">
                        @foreach ($aiChecklist as $step)
                            <li class="flex items-start gap-2">
                                <x-filament::icon icon="heroicon-o-stop" class="mt-0.5 size-5 shrink-0 text-gray-400 dark:text-gray-500" />
                                <span>{{ $step }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                @if ($aiSettingsUrl)
                    <x-filament::link :href="$aiSettingsUrl" icon="heroicon-m-arrow-right">Zu „KI-Funktionen“</x-filament::link>
                @endif

                <p class="text-gray-600 dark:text-gray-400">
                    Die Liste ist eine Gedächtnisstütze und keine Rechtsberatung. Die Vorlagen unter „Datenschutz-Unterlagen“ helfen bei Folgenabschätzung und Elterninformation. Welche Schritte an eurer Schule nötig sind, klärt am besten eure Datenschutzbeauftragte oder euer Datenschutzbeauftragter.
                </p>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
