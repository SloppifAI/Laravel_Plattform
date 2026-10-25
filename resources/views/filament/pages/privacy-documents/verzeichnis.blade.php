@php
    use App\Models\AppAuthorizationCode;

    $appCodeMinutes = AppAuthorizationCode::LIFETIME_MINUTES;
    $appAccessDays = config('sanctum.expiration') ? (int) ceil((int) config('sanctum.expiration') / 1440) : null;
    $appAccessPhrase = $appAccessDays ? "{$appAccessDays} Tage ab der Verbindung" : 'ohne feste Frist';

    $thirdCountries = match (true) {
        $aiProviderIsLocal => "Nein. Der KI-Anbieter ist ein vom Betreiber selbst betriebenes Sprachmodell ({$aiProvider}); Lage der Server laut Auftragsverarbeitungsvertrag.",
        $ai['enabled'] => "Möglich. Die KI-Funktionen sind eingeschaltet, Anbieter ist {$aiProvider}. Der Anbieter kann Daten auch außerhalb der EU verarbeiten, insbesondere in den USA. Garantien nach Art. 44 ff. DSGVO (Angemessenheitsbeschluss oder Standardvertragsklauseln) und der Vertrag nach Art. 28 DSGVO sind zu dokumentieren, hier eintragen: ______",
        default => "Nein, solange die KI-Funktionen ausgeschaltet sind. Werden sie eingeschaltet, gehen Daten an {$aiProvider}, auch eine Verarbeitung außerhalb der EU ist möglich; dann sind Garantien nach Art. 44 ff. DSGVO und ein Vertrag nach Art. 28 DSGVO zu dokumentieren.",
    };

    $measures = "Siehe Anlage „Technische und organisatorische Maßnahmen“ zum Auftragsverarbeitungsvertrag. Kurz: Trennung der Schulen, Rollen- und Rechtekonzept mit serverseitiger Prüfung, Zugriff von Lehrkräften nur in ihren Klassen, Passwörter nur als Hash, Sitzungsende nach {$sessionMinutes} Minuten ohne Aktivität, Schutz gegen gefälschte Anfragen, automatische Löschfristen, kein Tracking, Schriftarten vom eigenen Server.";

    $entries = [
        [
            'title' => 'A: Nutzung von Tuto im Unterricht',
            'rows' => [
                'Zwecke' => 'Unterstützung von Unterricht und Lernen: Unterrichtsnotizen und Arbeitsblätter, Hausaufgaben in Stufen und in Einfacher Sprache, Abgaben, „Erklär’s mir“-Gespräche, Rückfragen an die Lehrkraft, Auswertung des Verständnisses, Übersicht über Förderbedarf, Klassenbuch mit Fehlzeiten, Vertretung, Benachrichtigungen, Verwaltung von Konten und Klassen.',
                'Betroffene Personen' => 'Schüler:innen, Lehrkräfte, Schuladministration der Schule.',
                'Datenkategorien' => 'Name, Benutzername, E-Mail-Adresse (optional), Rolle, Klasse, gewählte Sprache, Passwort-Hash; Klassenleitung, Zuordnungen und Vertretungen; Stundenplan (Fach, Raum und Lehrkraft je Klasse und Stunde); Notizen und Arbeitsblätter der Lehrkräfte; Abgaben, Antworten mit gewählter Stufe, Gesprächsverläufe in „Erklär’s mir“, Rückfragen, Verständniswerte und Rückmeldungen; Klassenbuch mit Fehlzeiten; Benachrichtigungen; Sitzungs- und Protokolldaten.',
                'Empfänger' => 'Intern: Lehrkräfte (zu ihren Stunden und den Klassen, die sie leiten, unterrichten oder vertreten), Schuladministration (eigene Schule). Eine Vertretung darf für die Dauer der Vertretung: '.$substitutionGrants.'. Extern: Betreiber als Auftragsverarbeiter, dessen Unterauftragsverarbeiter (Hosting) nach Auftragsverarbeitungsvertrag. Die Plattform-Administration sieht keine Unterrichts- und Lerndaten.',
                'Übermittlung in Drittländer' => 'Nein, soweit die Unterauftragsverarbeiter des Betreibers in der EU verarbeiten (siehe Auftragsverarbeitungsvertrag). Für die KI-Funktionen siehe Eintrag B.',
                'Löschfristen' => 'Unterrichtsstunden mit Abgaben, Antworten, Rückfragen und Arbeitsblättern, „Erklär’s mir“-Gespräche und Klassenbuch-Einträge: automatisch '.$retention.". Benachrichtigungen: {$notificationReadDays} Tage nach dem Lesen, spätestens nach {$notificationMaxDays} Tagen. Konten: Löschung durch die Schuladministration, etwa beim Abgang von der Schule. Sitzungen: {$sessionMinutes} Minuten ohne Aktivität. Server-Logfiles und Fehlerprotokoll: Frist des Betreibers laut Anlage 1 des Auftragsverarbeitungsvertrags, hier eintragen: ______ Tage.",
                'Technische und organisatorische Maßnahmen' => $measures,
                'Rechtsgrundlage' => 'Art. 6 Abs. 1 lit. e DSGVO in Verbindung mit dem Schulgesetz und den Datenschutzvorschriften des Landes, für das Klassenbuch auch die Vorschriften zur Schulverwaltung. Die Schule ergänzt die genaue Norm.',
                'Datenschutz-Folgenabschätzung' => 'Schwellwertprüfung nötig, mit der Vorlage „Folgenabschätzung“.',
            ],
        ],
        [
            'title' => 'B: KI-Funktionen (optional)',
            'rows' => [
                'Zwecke' => 'Entwürfe für Hausaufgaben, Förderaufgaben und Stufen, Fassungen in Einfacher Sprache, Einschätzung von Abgaben, Gespräch in „Erklär’s mir“, beim Tuto-Mikro Spracherkennung und Zusammenfassung. Ohne Einschalten durch die Schuladministration arbeitet eine Demo-Logik auf der Plattform, und nichts geht an einen KI-Anbieter.',
                'Betroffene Personen' => 'Schüler:innen und Lehrkräfte, deren Texte verarbeitet werden; beim Tuto-Mikro alle, die im Raum sprechen.',
                'Datenkategorien' => ucfirst($aiData).'; beim Tuto-Mikro Ton-Abschnitte (nicht pseudonymisiert) und pseudonymisierte Notizen aus der Stunde. Nie Benutzernamen, E-Mail-Adressen, Konto-Kennungen, Namen von Klasse und Schule. Gespeichert werden die Ergebnisse (Entwürfe, Einschätzungen, Fassungen in Einfacher Sprache) sowie die Bestätigungen der Schuladministration, Zeitpunkt und Person beim Einschalten.',
                'Empfänger' => 'KI-Anbieter '.$aiProvider.' als weiterer Auftragsverarbeiter, nur bei eingeschalteten KI-Funktionen der Schule (derzeit '.($ai['enabled'] ? 'eingeschaltet' : 'ausgeschaltet').'). Die Schuladministration bestätigt beim Einschalten Auftragsverarbeitung und bezahlten Tarif.',
                'Übermittlung in Drittländer' => $thirdCountries,
                'Löschfristen' => 'Ergebnisse folgen den Fristen der Stunde, Abgabe oder des Gesprächs (Eintrag A). Tuto bittet den Anbieter bei jeder Anfrage, nichts zu speichern („store: false“); was der Anbieter dennoch vorhält, regelt sein Vertrag.',
                'Technische und organisatorische Maßnahmen' => 'Zwei Schalter (Plattform und Schule) mit Checkliste; Pseudonymisierung aller Texte vor jeder Anfrage; Stufen und Einfache Sprache nur mit Texten der Lehrkraft; keine Konto-Kennungen; Anweisung an die KI, niemanden zu identifizieren; Fehler werden angezeigt, nicht still ersetzt.',
                'Rechtsgrundlage' => 'Art. 6 Abs. 1 lit. e DSGVO in Verbindung mit dem Schulgesetz; beim Tuto-Mikro die Einwilligung (Eintrag C). Die Schule ergänzt die genaue Norm.',
                'Datenschutz-Folgenabschätzung' => 'Ja, vor dem Einschalten (Vorlage „Folgenabschätzung“).',
            ],
        ],
        [
            'title' => 'C: Tuto-Mikro im Unterricht (optional)',
            'rows' => [
                'Zwecke' => 'Das Mikrofon der Lehrkraft macht aus dem Ton einer Stunde Notizen, aus denen die Lehrkraft Zusammenfassung und Tafelbild-Vorschlag für ihre Unterrichtsnotizen gewinnt (Vor- und Nachbereitung). Die Anwesenheitsliste dient als Nachweis der Einwilligungsprüfung und als Vorschlag für die Fehlzeiten im Klassenbuch. Keine Kontrolle von Schüler:innen oder Lehrkräften.',
                'Betroffene Personen' => 'Alle, die im Raum sprechen: Schüler:innen (überwiegend minderjährig), die Lehrkraft, gegebenenfalls Vertretungen, Gäste, Praktikant:innen, Schulbegleitungen. Für die Einwilligungen zusätzlich die Erziehungsberechtigten.',
                'Datenkategorien' => "Ton (wird nicht gespeichert, nur für die Dauer der Anfrage im Arbeitsspeicher verarbeitet, vom Webserver gegebenenfalls kurz zwischengepuffert); Notizen aus der Stunde (nur im Browser der Lehrkraft); Nachweis je Sitzung: Lehrkraft, Beginn, Ende, Dauer, angehakte Schüler:innen, Zeitpunkt der Bestätigung, Fassung der Einwilligung, Zwecke, ob Ton-Abschnitte an den KI-Anbieter gingen. Einwilligungen je Schüler:in: Name der erklärenden Person, Zwecke, Fassung des Textes, Zeitpunkt, Widerruf. Gesprochenes kann zufällig besondere Kategorien nach Art. 9 DSGVO berühren.",
                'Empfänger' => 'Notizen und Nachweis: nur die Lehrkraft der Stunde, nicht die Schuladministration; Schüler:innen die eigene Anwesenheit in ihrem Datenexport. Einwilligungen: Schuladministration und Lehrkräfte der Klasse. Betreiber als Auftragsverarbeiter (technisch nur Betrieb). Nur bei eingeschalteten KI-Funktionen: '.$aiProvider.' für Spracherkennung (Ton-Abschnitte) und Zusammenfassung (pseudonymisierter Text).',
                'Übermittlung in Drittländer' => $thirdCountries,
                'Löschfristen' => "Ton: keine Speicherung. Notizen aus der Stunde: mit dem Schließen der Seite. Nachweis: automatisch {$sessionKeepDays} Tage nach Beginn, früher mit der Stunde oder dem Konto der Lehrkraft. In die Stundennotizen übernommener Text (pseudonymisiert) folgt den Fristen der Notizen (Eintrag A). Einwilligungen: mit dem Konto der Schüler:in.",
                'Technische und organisatorische Maßnahmen' => $measures.' Zusätzlich: Schalter der Schule mit Checkliste und veröffentlichter Einwilligungserklärung; gesperrte Fächer (derzeit: '.(implode(', ', $blockedSubjects) ?: 'keine').'); Start nur mit gültiger Einwilligung aller angehakten Schüler:innen und erneute Prüfung während der Sitzung; feste Leiste „Mikro an“ / „Mikro aus“; auf Wunsch der Lehrkraft eine Anzeige am Beamer, die nur „an“ oder „aus“ zeigt; Ton wird nie gespeichert (höchstens kurz vom Webserver zwischengepuffert, bis die Anfrage endet); Pseudonymisierung vor der Zusammenfassung.',
                'Rechtsgrundlage' => 'Einwilligung nach Art. 6 Abs. 1 lit. a, Art. 7 DSGVO aller Personen im Raum, bei Minderjährigen der Sorgeberechtigten und je nach Land und Alter der Schüler:innen; bei Beschäftigten Landesrecht beziehungsweise § 26 BDSG beachten. Zusätzlich § 201 StGB. Für zufällig mitgesprochene besondere Kategorien (Gesundheit, Herkunft, Religion, Familie) ausdrückliche Einwilligung nach Art. 9 Abs. 2 lit. a DSGVO.',
                'Datenschutz-Folgenabschätzung' => 'Ja, vor dem ersten Einsatz (Vorlage „Folgenabschätzung“). Personalrat beteiligen, soweit nach Landesrecht vorgesehen.',
            ],
        ],
        [
            'title' => 'D: Verbundene Apps (optional)',
            'rows' => [
                'Zwecke' => 'Lehrkräfte und Schuladministrationen können eine App (derzeit Tuto Mic) mit ihrem Konto verbinden, um ihre Stunden, Klassen und ihren Stundenplan zu sehen, neue Stunden anzulegen und die Zusammenfassung einer Stunde zu setzen (Vor- und Nachbereitung des Unterrichts). Damit die App Namen in Texten auf dem Gerät durch Platzhalter ersetzen kann, bevor ein Text das Gerät verlässt (lokale Pseudonymisierung), bekommt sie die Namen der Schüler:innen dieser Klassen. Die Verbindung ist freiwillig. Keine Kontrolle von Lehrkräften: Wann ein Zugang zuletzt genutzt wurde, sieht nur die Person selbst.',
                'Betroffene Personen' => 'Lehrkräfte und Mitglieder der Schuladministration, die eine App verbinden. Schüler:innen ihrer Klassen (Schuladministration: der Schule) nur mit ihrem Namen, für die lokale Pseudonymisierung; andere Angaben zu ihnen können nur dann in eine Zusammenfassung gelangen, wenn die Lehrkraft sie selbst hineinschreibt.',
                'Datenkategorien' => "Je Zugang: Konto, Name des Zugangs (so, wie die App das Gerät nennt, zum Beispiel „iPhone von Frau Schulze“), Hash des Zugangsschlüssels (nie der Schlüssel selbst), Berechtigungen (Stunden und Klassen ansehen, Stunden anlegen, Zusammenfassung setzen, Namen der Schüler:innen abrufen), Zeitpunkt der Verbindung, der letzten Nutzung und des Ablaufs. Je Einmal-Code: Hash des Codes (nie der Code selbst), Konto, App, Rückkehradresse, Prüfwert der App (PKCE), Ablaufzeitpunkt, Zeitpunkt der Einlösung. Keine IP-Adresse, keine Gerätekennung, keine E-Mail-Adresse zum Zugang. Über die App abrufbar: Stunden der Person ohne Förderaufgaben (Fach, Thema, Datum, Klasse, Zusammenfassung, Freigabestand), Klassen der Person (Nummer, Name), Fächer der Stunden an der Schule, eigene Einträge im Stundenplan für einen Tag (Uhrzeit, Klasse, Fach, Raum, zugehörige Stunde), beim Verbinden Name und Rolle, die Anzeigenamen der Schüler:innen in den Klassen der Person (Schuladministration: der Schule), nach Klasse gruppiert, ohne Benutzernamen, Nummern oder andere Angaben; die App darf sie nur zur lokalen Pseudonymisierung nutzen und nicht speichern (Tuto Mic arbeitet nach Angaben ihres Herstellers so). Über die App änderbar: neue Stunden (Klasse, Fach, Thema, Datum, Notizen) und die Zusammenfassung einer Stunde; sie werden wie Eingaben der Lehrkraft gespeichert (Eintrag A). Nie abrufbar: andere Daten von Schüler:innen als ihre Namen, Notizen, Ergebnisse, Abgaben, Gespräche, E-Mail-Adresse, Passwort.",
                'Empfänger' => 'Die verbundene App auf dem Gerät der Person, nur mit den oben genannten Daten der Person selbst (Schuladministration: der eigenen Schule) und den Namen der Schüler:innen ihrer Klassen. Die Zugänge sieht nur die Person selbst (Profil, Datenexport), nicht die Schuladministration und nicht die Plattform-Administration. Stunden und Zusammenfassungen: wie Eintrag A. Betreiber als Auftragsverarbeiter (technisch nur Betrieb). Betreiber der App, falls nicht der Betreiber der Plattform, hier eintragen: ______',
                'Übermittlung in Drittländer' => 'Nein, soweit die Unterauftragsverarbeiter des Betreibers in der EU verarbeiten (Eintrag A). Die Plattform sendet die Daten nur an die App auf dem Gerät der Person. Sendet die App selbst Daten an Dritte, ist das beim Betreiber der App zu klären, hier eintragen: ______',
                'Löschfristen' => "Einmal-Code: {$appCodeMinutes} Minuten gültig und nur einmal einlösbar; benutzte und abgelaufene Codes werden täglich automatisch gelöscht. Zugang: {$appAccessPhrase}; abgelaufene Zugänge werden täglich automatisch gelöscht, sobald sie seit mehr als einem Tag abgelaufen sind. Sofort: wenn die Person den Zugang im Profil entzieht. Mit dem Konto und mit jedem neuen Passwort des Kontos werden Zugänge und offene Codes gelöscht. Von einer App angelegte Stunden und gesetzte Zusammenfassungen: wie Eintrag A.",
                'Technische und organisatorische Maßnahmen' => $measures.' Zusätzlich: Anmeldung nur auf der Anmeldeseite der Plattform, die App erfährt das Passwort nie; Einmal-Code mit Prüfwert (PKCE nach RFC 7636), nur als Hash gespeichert, '.$appCodeMinutes.' Minuten gültig und nur einmal einlösbar; Zugangsschlüssel nur als Hash gespeichert; nur vier Berechtigungen (Stunden und Klassen ansehen, Stunden anlegen, Zusammenfassung setzen, Namen der Schüler:innen abrufen), befristet und von der Person jederzeit entziehbar; Prüfung von Rolle, Sperre der Schule und Auftragsverarbeitungsvertrag bei jeder Anfrage; Begrenzung der Anfragen zum Austausch des Codes je IP-Adresse; von Schüler:innen nur die Anzeigenamen in den Antworten, nur aus den Klassen der Person. Organisatorisch: Die Schule regelt, ob und auf welchen Geräten Lehrkräfte eine App verbinden dürfen, und weist darauf hin, dass Zusammenfassungen keine Daten von Schüler:innen enthalten sollen, weil Schüler:innen sie lesen.',
                'Rechtsgrundlage' => 'Art. 6 Abs. 1 lit. e DSGVO in Verbindung mit dem Schulgesetz und den Datenschutzvorschriften des Landes (wie Eintrag A). Das Verbinden ist freiwillig, die Nutzung von Tuto hängt nicht davon ab. Die Schule ergänzt die genaue Norm.',
                'Datenschutz-Folgenabschätzung' => 'Schwellwertprüfung nötig, mit der Vorlage „Folgenabschätzung“.',
            ],
        ],
    ];
@endphp

@include('filament.pages.privacy-documents.partials.head', [
    'title' => 'Eintrag für das Verzeichnis von Verarbeitungstätigkeiten (Art. 30 DSGVO)',
    'lead' => 'Vorlage für vier Einträge im Verzeichnis der Schule: Tuto insgesamt, die optionalen KI-Funktionen, das optionale Tuto-Mikro und die optional verbundenen Apps, die jeweils eigene Empfänger, Rechtsgrundlagen und Fristen haben.',
])

<h3>Angaben zum Verantwortlichen (Art. 30 Abs. 1 lit. a)</h3>
@include('filament.pages.privacy-documents.partials.school')

<dl class="pd-facts">
    <dt>Gemeinsam Verantwortliche</dt>
    <dd>
        <ul class="pd-options">
            <li><span class="pd-check" aria-hidden="true"></span> keine</li>
            <li><span class="pd-check" aria-hidden="true"></span> ja, zum Beispiel der Schulträger: {{ $blank }}</li>
        </ul>
    </dd>

    <dt>Auftragsverarbeiter</dt>
    <dd>@include('filament.pages.privacy-documents.partials.operator')</dd>
</dl>

@foreach ($entries as $entry)
    <h3>Eintrag {{ $entry['title'] }}</h3>

    <dl class="pd-facts">
        @foreach ($entry['rows'] as $label => $value)
            <dt>{{ $label }}</dt>
            <dd>{{ $value }}</dd>
        @endforeach

        <dt>Eingeführt am</dt>
        <dd>{{ $blank }}</dd>

        <dt>Letzte Änderung am</dt>
        <dd>{{ $blank }}</dd>

        <dt>Eingetragen von</dt>
        <dd>{{ $blank }}</dd>
    </dl>
@endforeach

<p>
    Der Betreiber führt als Auftragsverarbeiter ein eigenes Verzeichnis nach Art. 30 Abs. 2 DSGVO. Dieser Eintrag ersetzt es nicht.
</p>
