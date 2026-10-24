@php
    $hasSchool = $school['name'] !== null;

    // One task: what to do, why, and optionally what the platform knows about it and a link to the place in the platform.
    $entry = fn (string $title, string $text, ?string $status = null, ?array $link = null): array => compact('title', 'text', 'status', 'link');

    $dpaStatus = match (true) {
        ! $hasSchool => null,
        $dpa['acceptedAt'] !== null => 'In der Plattform akzeptiert am '.$dpa['acceptedAt']->format('d.m.Y').'.',
        default => 'In der Plattform noch nicht akzeptiert.',
    };

    $aiStatus = match (true) {
        ! $hasSchool => null,
        $ai['enabled'] => 'KI-Funktionen sind für diese Schule eingeschaltet (Anbieter: '.$aiProvider.').',
        default => 'KI-Funktionen sind für diese Schule ausgeschaltet.',
    };

    $recordingStatus = match (true) {
        $recordings['enabled'] === null => null,
        $recordings['enabled'] => 'Tuto-Mikro ist für diese Schule eingeschaltet.',
        default => 'Tuto-Mikro ist für diese Schule ausgeschaltet.',
    };

    $general = [
        $entry('Entscheidung zum Einsatz von Tuto', 'Schulleitung beziehungsweise Schulträger entscheiden über den Einsatz und sind nach Landesrecht die verantwortliche Stelle. Zuständigkeiten, zum Beispiel Freigabe digitaler Lernmittel durch Schulträger oder Schulaufsicht, sind geklärt.'),
        $entry('Datenschutzbeauftragte(r) beteiligt', 'Die oder der schulische Datenschutzbeauftragte kennt Tuto und hat Stellung genommen (Art. 37 bis 39 DSGVO). Name und Kontakt stehen in der Datenschutzerklärung der Schule.'),
        $entry('Auftragsverarbeitungsvertrag abgeschlossen', 'Mit dem Betreiber besteht ein Vertrag nach Art. 28 DSGVO. Die Schuladministration akzeptiert ihn in der Plattform unter „Meine Schule“; bis dahin kann die Schule nur diese Seite nutzen. Alle Unterauftragsverarbeiter sind bekannt, und der Betreiber hat die Löschfrist für Server-Logfiles und Fehlerprotokoll genannt.', $dpaStatus, $dpa['url'] ? ['Vertrag ansehen', $dpa['url']] : null),
        $entry('Verzeichnis von Verarbeitungstätigkeiten ergänzt', 'Tuto steht im Verzeichnis der Schule (Art. 30 DSGVO), mit eigenen Einträgen für die KI-Funktionen und das Tuto-Mikro. Dafür gibt es die Vorlage „Verzeichnis“.'),
        $entry('Notwendigkeit einer Datenschutz-Folgenabschätzung geprüft', 'Für die Grundfunktionen ist die Prüfung dokumentiert, ob eine Folgenabschätzung nach Art. 35 DSGVO nötig ist. Für die KI-Funktionen und das Tuto-Mikro ist sie in aller Regel nötig, siehe Teil B und C.'),
        $entry('Eltern und Schüler:innen informiert', 'Die Elterninformation nach Art. 13 DSGVO ist angepasst und den Eltern zugegangen, die Datenschutzerklärung der Schule verweist auf Tuto. Dafür gibt es die Vorlage „Elterninformation“.'),
        $entry('Klassen und Lehrkräfte richtig zugeordnet', 'Lehrkräfte sehen Lernstände, Klassenbuch und Einwilligungen nur in Klassen, die sie leiten oder unterrichten. Die Schuladministration pflegt deshalb Klassenleitung und zugeordnete Lehrkräfte und nimmt Lehrkräfte aus einer Klasse, wenn sie sie nicht mehr unterrichten.'),
        $entry('Regeln für die Vertretung festgelegt', 'Eine Klassenleitung oder die Schuladministration kann eine Lehrkraft der Schule für höchstens '.$substitutionMaxDays.' Tage als Vertretung einer Klasse eintragen. Eine Vertretung darf in dieser Zeit: '.$substitutionGrants.'. Danach endet der Zugriff von selbst. Die Schule hat festgelegt, wer Vertretungen einträgt.'),
        $entry('Beschäftigte unterwiesen und auf Vertraulichkeit verpflichtet', 'Lehrkräfte und Schuladministration kennen die Regeln: keine privaten Angaben und möglichst keine Namen in Notizen und auf Arbeitsblättern, Passwörter nicht weitergeben, Klassencodes nur an die Klasse geben, Konten sorgfältig verwalten.'),
        $entry('Löschfristen festgelegt', 'Tuto löscht Unterrichtsstunden mit Abgaben, Antworten, Rückfragen und Arbeitsblättern, „Erklär’s mir“-Gespräche und Klassenbuch-Einträge automatisch '.$retention.'. Benachrichtigungen werden '.$notificationReadDays.' Tage nach dem Lesen gelöscht, spätestens nach '.$notificationMaxDays.' Tagen. Konten löscht die Schuladministration, etwa beim Abgang von der Schule. Für Server-Logfiles und Fehlerprotokoll gilt die Frist des Betreibers aus dem Auftragsverarbeitungsvertrag. Die Schule hat geprüft, ob diese Fristen zu ihrem Löschkonzept passen, auch für das Klassenbuch.'),
        $entry('Ansprechpartner und Meldeweg für Datenpannen benannt', 'Es ist klar, wer Anfragen betroffener Personen beantwortet und wer bei einer Datenpanne innerhalb von 72 Stunden die Aufsichtsbehörde informiert (Art. 33 DSGVO). Der Betreiber meldet Vorfälle der Schule unverzüglich.'),
        $entry('Betroffenenrechte vorbereitet', 'Auskunft: Jede Person kann ihre Daten im eigenen Profil unter „Meine Daten herunterladen“ abrufen. Berichtigung und Löschung laufen über die Schuladministration. Die Schule weiß, wie sie Anfragen beantwortet.'),
    ];

    $aiFunctions = [
        $entry('Entscheidung der Schule über die KI-Funktionen', 'Ohne Zustimmung der Schule geht nichts an einen KI-Anbieter: Die Schuladministration schaltet die KI-Funktionen unter „KI-Funktionen“ ein, erst nachdem sie vier Punkte bestätigt hat (Auftragsverarbeitung mit dem Anbieter, bezahlter Tarif ohne Nutzung der Eingaben zur Produktverbesserung, Folgenabschätzung, Eltern und Schüler:innen informiert). Die Plattform kann nicht prüfen, ob diese Bestätigungen stimmen. Nimmt die Schuladministration eine Bestätigung zurück, sind die KI-Funktionen wieder aus. Bis dahin arbeitet eine einfache Demo-Logik auf der Plattform.', $aiStatus, $ai['settingsUrl'] ? ['Zu „KI-Funktionen“', $ai['settingsUrl']] : null),
        $entry('Auftragsverarbeitung mit dem KI-Anbieter geklärt', 'Anbieter ist derzeit '.$aiProvider.'. Er ist im Auftragsverarbeitungsvertrag als Unterauftragsverarbeiter des Betreibers genannt oder hat mit der Schule einen eigenen Vertrag nach Art. 28 DSGVO. Für eine Verarbeitung außerhalb der EU liegen geeignete Garantien nach Art. 44 ff. DSGVO vor. Die Schule lässt sich bestätigen, dass ein bezahlter Tarif genutzt wird: Im kostenlosen Tarif von Gemini darf Google Eingaben zur Verbesserung seiner Produkte nutzen.'),
        $entry('Datenschutz-Folgenabschätzung für die KI-Funktionen', 'Die Vorlage „Folgenabschätzung“ ist von der Schule bewertet, mit der oder dem Datenschutzbeauftragten besprochen und von der Schulleitung freigegeben (Art. 35 DSGVO).'),
        $entry('Grenzen der Pseudonymisierung bekannt', 'Vor jeder Anfrage an die KI ersetzt Tuto die Namen der Schüler:innen der Klasse und der Lehrkräfte der Klasse durch Platzhalter. Namen anderer Personen, Spitznamen und falsch geschriebene Namen bleiben stehen, und auf Fotos und PDF-Arbeitsblättern kann Tuto nichts ersetzen. Die Lehrkräfte sind angewiesen, keine Arbeitsblätter mit Namen anzuhängen.'),
    ];

    $mikro = [
        $entry('Entscheidung der Schule über das Tuto-Mikro', 'Die Schule hat entschieden, ob und unter welchen Bedingungen Lehrkräfte das Tuto-Mikro nutzen dürfen, zum Beispiel nur zu bestimmten Zwecken und mit Ankündigung. Die Entscheidung ist schriftlich festgehalten, etwa in einer Dienstanweisung. Das Tuto-Mikro ist für jede Schule zunächst ausgeschaltet; die Schuladministration schaltet es unter „Tuto-Mikro & Einwilligungen“ ein, nachdem sie fünf Punkte bestätigt und eine Einwilligungserklärung veröffentlicht hat.', $recordingStatus, $recordings['settingsUrl'] ? ['Zu „Tuto-Mikro & Einwilligungen“', $recordings['settingsUrl']] : null),
        $entry('Gesperrte Fächer festgelegt', 'In Fächern, in denen Überzeugungen, Glauben oder das Intimleben der Kinder zur Sprache kommen, bleibt das Tuto-Mikro aus. Gesperrt sind derzeit: '.(implode(', ', $blockedSubjects) ?: 'keine Fächer').'. Die Schuladministration kann die Liste anpassen; eine leere Liste sperrt nichts. Für Förderaufgaben gibt es das Tuto-Mikro nie.'),
        $entry('Datenschutz-Folgenabschätzung durchgeführt', 'Die Vorlage „Folgenabschätzung“ ist auch für das Tuto-Mikro bewertet, mit der oder dem Datenschutzbeauftragten besprochen und von der Schulleitung freigegeben (Art. 35 DSGVO). Bleibt ein hohes Restrisiko, wurde die Aufsichtsbehörde konsultiert (Art. 36 DSGVO). Die Schuladministration bestätigt das in der Plattform; prüfen kann die Plattform es nicht.'),
        $entry('Personalrat beteiligt, Dienstvereinbarung geprüft', 'Das Tuto-Mikro erfasst auch die Stimmen der Lehrkräfte. Je nach Personalvertretungsrecht des Landes ist der Personalrat zu beteiligen, gegebenenfalls ist eine Dienstvereinbarung nötig. Der Zweck „keine Kontrolle von Lehrkräften“ ist dort festgeschrieben.'),
        $entry('Elterninformation zum Tuto-Mikro verteilt', 'Der Abschnitt zum Tuto-Mikro in der Elterninformation wurde nicht gestrichen und ist vor dem ersten Einsatz bei allen Eltern angekommen.'),
        $entry('Einwilligungstext angepasst und veröffentlicht', 'Die Vorlage „Einwilligung“ ist an die Schule und an das Landesrecht angepasst, auch zum Alter, ab dem Schüler:innen selbst unterschreiben. Für die digitale Einwilligung der Eltern wird der Text in der Plattform veröffentlicht; jede Änderung ist eine neue Fassung, und Einwilligungen zu einer älteren Fassung gelten dann als veraltet.'),
        $entry('Einwilligungen aller Personen im Raum liegen vor', 'Das Mikro der Lehrkraft erfasst alle, die im Raum sprechen: die Klasse, die Lehrkraft und eventuell Vertretungen, Gäste oder Begleitpersonen. Das nicht öffentlich gesprochene Wort ohne Einwilligung zu verarbeiten kann nach § 201 StGB strafbar sein. Die Plattform startet das Tuto-Mikro nur, wenn für alle Schüler:innen, die die Lehrkraft als anwesend anhakt, eine gültige Einwilligung der Eltern vorliegt. Einwilligungen von Lehrkräften und weiteren Personen im Raum kennt die Plattform nicht.'),
        $entry('Ablehnung und Widerruf geregelt', 'Wer nicht einwilligt oder widerruft, hat keine Nachteile. Weil sich einzelne Stimmen nicht aussparen lassen, bleibt das Tuto-Mikro dann aus, wenn die Person anwesend ist. Eltern können über den Link aus dem Elternbrief widerrufen. Die Schule hat festgelegt, wie andere Widerrufe entgegengenommen, in der Plattform erfasst und an die Lehrkräfte weitergegeben werden.'),
        $entry('Einwilligungen dokumentiert und aufbewahrt', 'Die Plattform speichert die Einwilligungen der Eltern je Schüler:in mit Fassung, Zwecken, Zeitpunkt, Name der erklärenden Person und Widerruf, digital über den Link im Elternbrief oder von der Schule auf Papier erfasst. Papier-Originale und Einwilligungen von Lehrkräften und weiteren Personen verwaltet die Schule (Nachweis nach Art. 7 Abs. 1 DSGVO).'),
        $entry('Lehrkräfte unterwiesen', 'Die Lehrkräfte kennen die Regeln: vor jedem Einschalten die Klasse hörbar informieren, die Beamer-Anzeige öffnen oder der Klasse ansagen, dass das Mikro läuft (die Plattform zeigt sie nicht von selbst), nur die Schüler:innen anhaken, die wirklich im Raum sind (die Liste ist ihre Erklärung, und sie verantworten sie), bei Gästen oder später Hinzukommenden das Mikro ausschalten und neu starten, ein Ansteck- oder Headset-Mikrofon nutzen, die Notizen vor der Zusammenfassung lesen und nur Nötiges in die Stundennotizen übernehmen. Die Plattform erzwingt das Lesen und die Richtigkeit der Liste nicht, das hängt an der Unterweisung.'),
        $entry('Zugriff und Fristen geprüft', 'Die Schule weiß, dass Tuto keinen Ton speichert, dass die Notizen aus der Stunde nur im Browser der Lehrkraft liegen, bis sie etwas in die Stundennotizen übernimmt, und dass der Nachweis jeder Sitzung (wer anwesend war, welche Fassung der Einwilligung galt) nach '.$sessionKeepDays.' Tagen gelöscht wird. Den Nachweis sieht nur die Lehrkraft der Stunde, nicht die Schuladministration; Schüler:innen finden die eigene Anwesenheit in ihrem Datenexport. Die Schuladministration bestätigt die Aufbewahrungsfrist in der Plattform.'),
        $entry('Überprüfung terminiert', 'Folgenabschätzung, Einwilligungstext und Regeln werden regelmäßig überprüft, mindestens einmal im Jahr und bei jeder Änderung der Plattform (Art. 35 Abs. 11 DSGVO). Der nächste Termin steht im Kalender.'),
    ];
@endphp

@include('filament.pages.privacy-documents.partials.head', [
    'title' => 'Checkliste für die Einführung',
    'lead' => 'Was eine Schule klären sollte, bevor sie Tuto einsetzt, und zusätzlich, bevor sie die KI-Funktionen oder das Tuto-Mikro einschaltet. Erledigte Punkte werden abgehakt, mit Datum und Namen.',
])

<dl class="pd-facts">
    <dt>Schule</dt>
    <dd>{{ $school['name'] ?? $blank }}</dd>

    <dt>Stand der Checkliste</dt>
    <dd>{{ $printedOn }}</dd>
</dl>

<h3>Teil A: Einsatz von Tuto</h3>
<p>Diese Punkte gelten für Tuto insgesamt, auch ohne KI-Funktionen und ohne Tuto-Mikro.</p>

@include('filament.pages.privacy-documents.partials.tasks', ['tasks' => $general])

<h3>Teil B: Zusätzlich vor den KI-Funktionen</h3>
<p>
    Mit den KI-Funktionen gehen pseudonymisierte Texte und Arbeitsblätter an einen KI-Anbieter. Solange nicht alle Punkte erledigt sind, bleiben sie ausgeschaltet.
</p>

@include('filament.pages.privacy-documents.partials.tasks', ['tasks' => $aiFunctions])

<h3>Teil C: Zusätzlich vor dem Tuto-Mikro</h3>
<p>
    Das Tuto-Mikro nutzt das Mikrofon der Lehrkraft und macht aus dem Ton der Stunde Notizen. Tuto speichert keinen Ton. Es betrifft aber Kinder und das gesprochene Wort, deshalb gelten besondere Anforderungen. Solange nicht alle Punkte erledigt sind, sollte keine Lehrkraft das Tuto-Mikro einschalten.
</p>

@include('filament.pages.privacy-documents.partials.tasks', ['tasks' => $mikro])

<h3>Ansprechpartner</h3>
<dl class="pd-facts">
    <dt>Datenschutz an der Schule</dt>
    <dd>{{ $blank }}</dd>

    <dt>Plattform-Betrieb</dt>
    <dd>@include('filament.pages.privacy-documents.partials.operator')</dd>

    <dt>Aufsichtsbehörde</dt>
    <dd>Die oder der Landesbeauftragte für den Datenschutz des Landes{{ filled($school['state']) ? ' '.$school['state'] : '' }}: {{ $blank }}</dd>
</dl>
