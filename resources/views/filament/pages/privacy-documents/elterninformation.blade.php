@include('filament.pages.privacy-documents.partials.head', [
    'title' => 'Elterninformation zu Tuto (Art. 13 DSGVO)',
    'lead' => 'Information der Eltern und Erziehungsberechtigten über die Verarbeitung personenbezogener Daten ihrer Kinder bei Tuto, einschließlich der KI-Funktionen und des optionalen Tuto-Mikro. Der Text ist an Eltern gerichtet und verwendet deshalb die Anrede „Sie“.',
])

<p>
    Sehr geehrte Eltern und Erziehungsberechtigte,
</p>
<p>
    {{ $school['name'] ?? $blank }} setzt im Unterricht die Lernplattform Tuto ein. Lehrkräfte halten dort fest, was sie behandelt haben, und geben daraus Hausaufgaben frei. Ihr Kind sieht, was es schon verstanden hat und was es noch üben sollte, und kann mit „Erklär’s mir“ üben und der Lehrkraft Rückfragen stellen. Mit diesem Schreiben informieren wir Sie darüber, welche Daten dabei verarbeitet werden und welche Rechte Sie und Ihr Kind haben.
</p>

<h3>1. Wer ist verantwortlich?</h3>
@include('filament.pages.privacy-documents.partials.school')

<p>
    Tuto wird von der folgenden Firma technisch betrieben. Sie verarbeitet die Daten nur nach Weisung der Schule und auf Grundlage eines Vertrags nach Art. 28 DSGVO (Auftragsverarbeitung):
</p>
@include('filament.pages.privacy-documents.partials.operator')

<h3>2. Wozu verarbeiten wir Daten, und auf welcher Grundlage?</h3>
<p>
    Tuto unterstützt den Unterricht, das Lernen und die Schulverwaltung (Klassenbuch). Rechtsgrundlage ist Art. 6 Abs. 1 lit. e DSGVO in Verbindung mit dem Schulgesetz und den Datenschutzvorschriften des Landes{{ filled($school['state']) ? ' '.$school['state'] : '' }}, weil die Verarbeitung dem Bildungs- und Erziehungsauftrag der Schule dient. Genaue Vorschrift: {{ $blank }} Für das Tuto-Mikro ist Ihre Einwilligung die Grundlage (Abschnitt 7).
</p>

<h3>3. Welche Daten werden verarbeitet?</h3>
<ul>
    <li>Name, Benutzername, Klasse, die gewählte Sprache und auf Wunsch eine E-Mail-Adresse. Das Passwort wird nur verschlüsselt (als Hash) gespeichert.</li>
    <li>Was Ihr Kind abgibt: Zusammenfassungen, Antworten auf Hausaufgaben mit der gewählten Stufe (Einstieg, Standard oder Profi), Gespräche mit Tuto in „Erklär’s mir“ und Rückfragen an die Lehrkraft.</li>
    <li>Das Ergebnis der Auswertung: ein Verständniswert von 0 bis 100 mit Rückmeldung. Er ist ein Hinweis, wo Unterstützung nötig sein könnte, und keine Note.</li>
    <li>Im Klassenbuch: was unterrichtet wurde und ob Ihr Kind gefehlt hat.</li>
    <li>Hinweise in der Glocke, etwa zu einer neuen Hausaufgabe, mit Thema, Fach und Klasse.</li>
    <li>Technische Daten, die beim Anmelden anfallen, etwa die Sitzungskennung und die IP-Adresse.</li>
</ul>
<p>
    Die E-Mail-Adresse ist freiwillig. Ohne Name und Benutzername kann Ihr Kind Tuto nicht nutzen. Ob die Nutzung im Unterricht verbindlich ist, entscheidet die Schule. Die Einstellungen „Einfache Sprache“ und „Namen verbergen“ bleiben im Browser und werden nicht an den Server übertragen.
</p>

<h3>4. Wer sieht die Daten?</h3>
<ul>
    <li>Ihr Kind sieht die eigenen Daten, aber nicht das Klassenbuch. Gespräche in „Erklär’s mir“ zu einem frei gewählten Thema sieht nur Ihr Kind.</li>
    <li>Lehrkräfte sehen die Abgaben, Gespräche und Rückfragen zu ihren Stunden. Lernstand, Klassenbuch mit Fehlzeiten und Einwilligungen sehen nur die Lehrkräfte, die die Klasse leiten oder unterrichten.</li>
    <li>Vertritt eine Lehrkraft für eine begrenzte Zeit (höchstens {{ $substitutionMaxDays }} Tage), darf sie in dieser Zeit: {{ $substitutionGrants }}. Danach endet der Zugriff von selbst, auch auf Abgaben, Gespräche und Rückfragen zu den Stunden, die sie in der Klasse gehalten hat.</li>
    <li>Die Schuladministration der Schule kann die Daten der Schule einsehen, mit zwei Ausnahmen: Notizen und Nachweise des Tuto-Mikro (siehe Abschnitt 7) und Gespräche in „Erklär’s mir“ zu einem frei gewählten Thema.</li>
    <li>Andere Schüler:innen und andere Schulen sehen nie etwas. Die Plattform-Administration des Betreibers sieht keine Unterrichts- und Lerndaten.</li>
</ul>
<p>Der Betreiber hat technisch Zugriff auf die Server, soweit es für Betrieb, Wartung und Fehlersuche nötig ist. Er wertet Unterrichts- und Lerndaten nicht aus. Für den Serverbetrieb (Hosting) setzt er technische Dienstleister als Unterauftragsverarbeiter ein, die nur nach seiner Weisung arbeiten: {{ $blank }} Sind die KI-Funktionen eingeschaltet, ist der KI-Anbieter (Abschnitt 5) ein weiterer Empfänger.</p>

<h3>5. Künstliche Intelligenz</h3>
@if ($ai['enabled'])
    <p>
        Die Schule hat die KI-Funktionen eingeschaltet. Anbieter ist {{ $aiProvider }}. Die KI entwirft Hausaufgaben und Übungen für die Lehrkraft, schreibt Texte der Lehrkraft in Einfacher Sprache, schätzt Abgaben ein und führt das Gespräch in „Erklär’s mir“. Dafür werden {{ $aiData }} an den Anbieter übermittelt. Beim Tuto-Mikro kommen kurze Ton-Abschnitte zur Spracherkennung dazu (Abschnitt 7).
    </p>
    <p>
        Vor jeder Übermittlung ersetzt Tuto die Namen der Schüler:innen der Klasse und ihrer Lehrkräfte durch Platzhalter wie „Schüler:in A“. Benutzername, E-Mail-Adresse und Konto-Kennung werden nie mitgeschickt. Andere Namen, Spitznamen und Namen auf Fotos oder PDF-Arbeitsblättern werden aber nicht entfernt. Bitte nennen Sie und Ihr Kind deshalb keine Namen anderer Personen.
    </p>
    <p>
        Die Schule hat bestätigt, dass mit dem Anbieter ein Vertrag zur Auftragsverarbeitung besteht und ein bezahlter Tarif genutzt wird, bei dem Eingaben nicht zur Verbesserung seiner Produkte dienen. Tuto bittet den Anbieter bei jeder Anfrage, nichts zu speichern. Der Anbieter kann Daten auch außerhalb der EU verarbeiten, etwa in den USA. Grundlage der Übermittlung nach Art. 44 ff. DSGVO: {{ $blank }} Eine Kopie erhalten Sie auf Anfrage bei der Schule.
    </p>
@else
    <p>
        Derzeit sind die KI-Funktionen an unserer Schule ausgeschaltet. Entwürfe und Auswertungen entstehen mit einer einfachen Regel-Logik auf dem Server der Plattform. Es werden keine Daten an einen KI-Anbieter übermittelt. Schaltet die Schule die KI-Funktionen später ein (Anbieter wäre {{ $aiProvider }}), informiert sie Sie vorher.
    </p>
@endif
<p>
    Es gibt keine automatisierte Entscheidung im Sinne von Art. 22 DSGVO. Die KI kann sich irren. Die Einschätzung einer Abgabe sieht Ihr Kind sofort; sie ist keine Note. Lehrkräfte entscheiden, was aus den Ergebnissen folgt, und prüfen Hausaufgaben-Entwürfe, bevor sie sie freigeben.
</p>

<h3>6. Wie lange werden die Daten gespeichert?</h3>
<ul>
    <li>Unterrichtsstunden mit Abgaben, Antworten und Rückfragen, Gespräche in „Erklär’s mir“ und Einträge im Klassenbuch löscht Tuto automatisch {{ $retention }}.</li>
    <li>Hinweise in der Glocke werden {{ $notificationReadDays }} Tage nach dem Lesen gelöscht, spätestens nach {{ $notificationMaxDays }} Tagen.</li>
    <li>Das Konto Ihres Kindes löscht die Schule, wenn es nicht mehr gebraucht wird, zum Beispiel beim Wechsel der Schule. Mit dem Konto werden auch die Einwilligungen gelöscht.</li>
    <li>Eine Sitzung endet nach {{ $sessionMinutes }} Minuten ohne Aktivität.</li>
    <li>Technische Protokolle des Servers (zum Beispiel mit der IP-Adresse, auch beim Aufruf des persönlichen Einwilligungslinks) löscht der Betreiber nach {{ $blank }} Tagen.</li>
</ul>

<h3>7. Tuto-Mikro: Ton im Unterricht</h3>
<div class="pd-notice" role="note">
    <p>[Nur verwenden, wenn die Schule das Tuto-Mikro einführt. Sonst diesen Abschnitt streichen. Das Tuto-Mikro setzt eine Einwilligung voraus, siehe Vorlage „Einwilligung“.]</p>
</div>

<p>
    Lehrkräfte können in einzelnen Stunden das Tuto-Mikro einschalten. Es nutzt nur das Mikrofon der Lehrkraft, nur Ton, kein Bild und kein Video. Daraus entstehen Notizen, eine Zusammenfassung und ein Tafelbild-Vorschlag für die Lehrkraft. Das Tuto-Mikro dient nicht der Kontrolle von Schüler:innen oder Lehrkräften und nicht der Benotung. @if ($blockedSubjects !== [])In den Fächern {{ implode(', ', $blockedSubjects) }} bleibt es aus, ebenso bei Förderaufgaben.@else Bei Förderaufgaben bleibt es aus.@endif
</p>

<h4>Freiwillig und nur mit Einwilligung</h4>
<ul>
    <li>Die Lehrkraft hakt an, wer im Raum ist. Tuto startet das Mikro nur, wenn für alle angehakten Schüler:innen eine gültige Einwilligung vorliegt, und prüft das während der Stunde laufend erneut. Fehlt eine Einwilligung, bleibt das Mikro aus. Auch die Lehrkraft und weitere Personen im Raum müssen einverstanden sein.</li>
    <li>Sie willigen über den persönlichen Link im Elternbrief oder auf Papier ein. Sie können jedem der drei Zwecke einzeln zustimmen oder nicht.</li>
    <li>Die Einwilligung ist freiwillig. Wer nicht einwilligt, hat keine Nachteile. Dann bleibt das Tuto-Mikro aus, wenn Ihr Kind im Raum ist.</li>
    <li>Sie können die Einwilligung jederzeit ohne Angabe von Gründen widerrufen, mit Wirkung für die Zukunft, über den persönlichen Link aus dem Elternbrief oder formlos bei {{ $blank }}. Ein Widerruf wirkt auch in einer laufenden Stunde: Tuto prüft die Einwilligungen bei bestehender Verbindung etwa alle {{ $segmentSeconds }} Sekunden erneut und schaltet das Mikro bei der nächsten Prüfung durch den Server ab. Fällt die Verbindung aus, kann die Prüfung ausbleiben. Der Widerruf berührt nicht die Rechtmäßigkeit der bisherigen Verarbeitung.</li>
    <li>Rechtsgrundlage ist Ihre Einwilligung nach Art. 6 Abs. 1 lit. a DSGVO. Im Ton können zufällig auch Angaben zu Gesundheit, Herkunft, Religion oder Familie vorkommen. Dafür holen wir Ihre ausdrückliche Einwilligung ein (Art. 9 Abs. 2 lit. a DSGVO). Je nach Alter und Einsichtsfähigkeit willigt Ihr Kind zusätzlich selbst ein.</li>
</ul>

<h4>Was mit dem Ton geschieht</h4>
<ul>
    <li>Tuto speichert keinen Ton, weder als Datei noch in der Datenbank. Technisch kann der Server einen Abschnitt für die Dauer der Anfrage kurz zwischenpuffern; danach ist er weg.</li>
    <li>Sind die KI-Funktionen der Schule eingeschaltet, gehen kurze Ton-Abschnitte (etwa {{ $segmentSeconds }} Sekunden) über eine verschlüsselte Verbindung an den Server und von dort zur Spracherkennung an {{ $aiProvider }}. Darin sind echte Stimmen und gesprochene Namen zu hören; sie werden nicht pseudonymisiert. Danach werden sie verworfen. Das geschieht nur, wenn für alle angehakten Schüler:innen auch diesem Zweck zugestimmt wurde. Sind die KI-Funktionen ausgeschaltet, verlässt der Ton das Gerät der Lehrkraft nicht.</li>
    <li>Der erkannte Text (Notizen aus der Stunde) liegt nur im Browser der Lehrkraft und ist weg, wenn sie die Seite schließt. Für eine Zusammenfassung ersetzt Tuto vorher die Namen der Klasse durch Platzhalter. Die Zusammenfassung wird nicht gespeichert.</li>
    <li>Was die Lehrkraft in ihre Unterrichtsnotizen übernimmt, wird dort mit ersetzten Namen gespeichert und folgt den Fristen der Notizen (Abschnitt 6). Sind die KI-Funktionen eingeschaltet, kann daraus pseudonymisiert ein Hausaufgaben-Entwurf entstehen.</li>
    <li>Tuto speichert je Sitzung einen Nachweis: wann das Mikro an war, wer angehakt war und welche Fassung der Einwilligung galt. Ihn sieht nur die Lehrkraft der Stunde; Ihr Kind findet die eigene Anwesenheit in seinem Datenexport. Er wird nach {{ $sessionKeepDays }} Tagen gelöscht. Wer mit Einwilligung nicht angehakt war, kann der Lehrkraft im Klassenbuch als fehlend vorgeschlagen werden; sie prüft das.</li>
    <li>Solange das Mikro an ist, zeigt die Plattform „Mikro an“ deutlich auf dem Bildschirm der Lehrkraft an. Die Lehrkraft kann dieselbe Anzeige am Beamer für die Klasse öffnen; sie ist angewiesen, das zu tun oder der Klasse anzusagen, dass das Mikro läuft.</li>
</ul>

<h3>8. Ihre Rechte und die Rechte Ihres Kindes</h3>
<p>Sie und Ihr Kind haben nach der DSGVO folgende Rechte. Ihr Kind kann sie je nach Alter und Einsichtsfähigkeit selbst ausüben.</p>
<ul>
    <li>Auskunft über die gespeicherten Daten (Art. 15). Jede Person, auch Ihr Kind, kann die eigenen Daten im Profil von Tuto herunterladen: das Konto, die Abgaben, Gespräche und Rückfragen, die Fehlzeiten im Klassenbuch, die Einwilligung zum Tuto-Mikro, die eigene Anwesenheit in Sitzungen und die Hinweise in der Glocke.</li>
    <li>Berichtigung unrichtiger Daten (Art. 16).</li>
    <li>Löschung (Art. 17) und Einschränkung der Verarbeitung (Art. 18).</li>
    <li>Datenübertragbarkeit (Art. 20), soweit die Verarbeitung auf Einwilligung beruht.</li>
    <li>Widerspruch gegen die Verarbeitung aus Gründen, die sich aus Ihrer besonderen Situation ergeben (Art. 21), soweit sie auf Art. 6 Abs. 1 lit. e DSGVO beruht.</li>
    <li>Widerruf einer erteilten Einwilligung jederzeit (Art. 7 Abs. 3).</li>
    <li>Beschwerde bei einer Aufsichtsbehörde (Art. 77), in {{ filled($school['state']) ? $school['state'] : 'Ihrem Bundesland' }} bei der oder dem Landesbeauftragten für den Datenschutz: {{ $blank }}</li>
</ul>

<h3>9. Fragen</h3>
<p>
    Fragen beantworten die Schule und die oder der Datenschutzbeauftragte der Schule (Kontaktdaten oben).
</p>

@include('filament.pages.privacy-documents.partials.signatures', [
    'lines' => ['Ort, Datum', 'Schulleitung'],
])
