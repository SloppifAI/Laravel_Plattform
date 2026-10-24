@php
    use App\Filament\Pages\AiSettings;
    use App\Models\ClassSubstitution;
    use App\Models\DpaAcceptance;
    use App\Models\School;
    use Carbon\CarbonImmutable;
    use Illuminate\Support\HtmlString;

    /*
     * The contract text without the page around it, so it can be stored as it was shown when a school accepts it.
     * Everything that depends on the configuration comes from DpaAcceptance::terms(): that list is also the base of
     * the fingerprint of an acceptance, so a value used here that is not listed there would change the text silently.
     * Links are relative, because the stored text is shown on whichever host the platform runs on later.
     */
    $school = $school ?? null;
    $terms = DpaAcceptance::terms();

    $aiEnabled = $terms['aiEnabled'];
    $sessionMinutes = $terms['sessionMinutes'];
    $resetMinutes = $terms['resetMinutes'];
    $mikroSessionDays = $terms['mikroSessionDays'];
    $mikroMaxSessionHours = $terms['mikroMaxSessionHours'];
    $notificationReadDays = $terms['notificationReadDays'];
    $notificationDays = $terms['notificationDays'];
    $substitutionMaxDays = $terms['substitutionMaxDays'];

    $keptYears = $terms['keptYears'];
    $keptPhrase = match (true) {
        $keptYears <= 1 => 'das laufende Schuljahr',
        $keptYears === 2 => 'das laufende und das vorherige Schuljahr',
        default => 'das laufende und die '.($keptYears - 1).' vorherigen Schuljahre',
    };
    $schoolYearStarts = $terms['schoolYearStarts'];
    $schoolYearStart = preg_match('/^(\d{2})-(\d{2})$/', $schoolYearStarts, $startParts) && checkdate((int) $startParts[1], (int) $startParts[2], 2001)
        ? CarbonImmutable::create(2001, (int) $startParts[1], (int) $startParts[2])->locale('de')->translatedFormat('j. F')
        : $schoolYearStarts;
    // The agreements accepted so far promised two school years, so one year applies only from this date on (PruneLearningData).
    $oneYearRuleFrom = $keptYears === 1 && CarbonImmutable::hasFormat($terms['oneYearRuleFrom'], 'Y-m-d')
        ? CarbonImmutable::parse($terms['oneYearRuleFrom'])->format('d.m.Y')
        : null;

    /*
     * One list drives the table of contents and the numbering of the headings below, so they cannot drift apart.
     * Every clause of Art. 28 Abs. 3 DSGVO has its own section.
     */
    $sections = [
        'gegenstand' => 'Gegenstand und Dauer',
        'zweck' => 'Art und Zweck der Verarbeitung',
        'daten' => 'Daten und betroffene Personen',
        'auftraggeber' => 'Pflichten und Rechte des Auftraggebers',
        'weisungen' => 'Weisungen',
        'vertraulichkeit' => 'Vertraulichkeit',
        'sicherheit' => 'Sicherheit der Verarbeitung',
        'unterauftragsverarbeiter' => 'Unterauftragsverarbeiter',
        'betroffenenrechte' => 'Unterstützung bei Betroffenenrechten',
        'unterstuetzung' => 'Unterstützung bei Datenschutzverletzungen, Folgenabschätzung und Konsultation',
        'ort' => 'Ort der Verarbeitung und Drittländer',
        'loeschung' => 'Löschung und Rückgabe',
        'nachweise' => 'Nachweise und Kontrollen',
        'haftung' => 'Haftung',
        'laufzeit' => 'Laufzeit und Kündigung',
        'schluss' => 'Schlussbestimmungen',
    ];

    $annexes = [
        'anlage-1' => 'Anlage 1: Gegenstand, Daten, betroffene Personen, Zwecke und Fristen',
        'anlage-2' => 'Anlage 2: Technische und organisatorische Maßnahmen',
        'anlage-3' => 'Anlage 3: Unterauftragsverarbeiter',
    ];

    $heading = fn (string $id): string => (array_search($id, array_keys($sections), true) + 1).'. '.$sections[$id];

    /* A fact about the operator's own business that the code cannot know (names, addresses, contracts, the servers). */
    $todo = fn (string $what): HtmlString => new HtmlString('<mark class="avv-placeholder">[vom Auftragnehmer zu ergänzen: '.e($what).']</mark>');

    /*
     * The provider behind the KI-Funktionen, from `lernplattform.ai.provider`: Google Gemini by default, a local model
     * with Ollama (then nothing leaves the operator's servers), any other provider with the operator's details still open.
     */
    $aiProvider = match ($terms['aiProvider']) {
        'gemini' => [
            'local' => false,
            'name' => 'Google LLC, 1600 Amphitheatre Parkway, Mountain View, CA 94043, USA, beziehungsweise bei einem Vertrag über Google Cloud mit Kunden im EWR Google Cloud EMEA Limited, 70 Sir John Rogerson’s Quay, Dublin 2, Irland',
            'short' => 'Google (Gemini API)',
            'service' => 'Gemini API im kostenpflichtigen Tarif',
            'place' => new HtmlString('Google verarbeitet die Daten auch in den USA und in weiteren Ländern außerhalb der EU, in denen Google oder seine Unterauftragsverarbeiter Rechenzentren betreiben (Drittland).'),
            'safeguards' => new HtmlString('Datenverarbeitungsbedingungen von Google für Auftragsverarbeiter (Art. 28 Abs. 4 DSGVO) mit EU-Standardvertragsklauseln. Google LLC ist unter dem EU-US Data Privacy Framework zertifiziert (Angemessenheitsbeschluss der EU-Kommission vom 10. Juli 2023).'),
        ],
        'ollama' => [
            'local' => true,
            'short' => 'ein Sprachmodell auf Servern des Auftragnehmers (Ollama)',
        ],
        default => [
            'local' => false,
            'name' => new HtmlString(e(AiSettings::providerLabel($terms['aiProvider'])).': '.$todo('Name und Anschrift des Anbieters')),
            'short' => AiSettings::providerLabel($terms['aiProvider']),
            'service' => AiSettings::providerLabel($terms['aiProvider']),
            'place' => $todo('Ort der Verarbeitung'),
            'safeguards' => $todo('Vertrag nach Art. 28 Abs. 4 DSGVO und Garantien nach Art. 44 ff. DSGVO'),
        ],
    };
    $blockedSubjects = implode(', ', School::DEFAULT_MIKRO_BLOCKED_SUBJECTS);
@endphp
    <nav class="legal-toc" aria-labelledby="inhaltsverzeichnis">
        <p class="legal-note-title" id="inhaltsverzeichnis">Inhalt</p>
        <ul>
            <li><a href="#parteien">Vertragsparteien</a></li>
        </ul>
        <ol>
            @foreach ($sections as $id => $sectionTitle)
                <li><a href="#{{ $id }}">{{ $sectionTitle }}</a></li>
            @endforeach
        </ol>
        <p class="legal-note-title">Anlagen und Annahme</p>
        <ul>
            @foreach ($annexes as $id => $annexTitle)
                <li><a href="#{{ $id }}">{{ $annexTitle }}</a></li>
            @endforeach
            <li><a href="#annahme">Annahme</a></li>
        </ul>
    </nav>

    <h2 id="parteien">Vertragsparteien</h2>
    <h3>Auftraggeber (Verantwortlicher)</h3>
    @if ($school)
        <address>
            <strong>{{ $school->name }}</strong><br>
            @if ($school->school_type) {{ $school->school_type }}<br> @endif
            {{ $school->city }}, {{ $school->state }}<br>
            @if ($school->school_number) Amtliche Schulnummer: {{ $school->school_number }}<br> @endif
            @if ($school->contact_email) Kontakt: {{ $school->contact_email }} @endif
        </address>
    @else
        <p>
            Die Schule, die diesen Vertrag annimmt, beziehungsweise der Schulträger, soweit er nach dem jeweiligen
            Landesrecht für die Verarbeitung verantwortlich ist. Name, Ort und Kontakt der Schule ergeben sich aus ihrer
            Registrierung auf der Plattform. Angemeldete Schuladministrationen finden unter „Meine Schule“ eine mit den
            Daten ihrer Schule ausgefüllte Fassung.
        </p>
    @endif
    <p>im Folgenden <strong>Auftraggeber</strong></p>

    <h3>Auftragnehmer (Auftragsverarbeiter)</h3>
    <address>
        <strong>Softwareentwicklung Sebastian Bleuel</strong><br>
        vertreten durch Sebastian Bleuel<br>
        Ortesweg 10<br>
        36145 Hofbieber<br>
        Deutschland<br>
        Telefon: <a href="tel:+4915732599053">0157 32599053</a><br>
        E-Mail: <a href="mailto:info@sebastian-bleuel.de">info@sebastian-bleuel.de</a>
    </address>
    <p>im Folgenden <strong>Auftragnehmer</strong></p>


    <h2 id="gegenstand">{{ $heading('gegenstand') }}</h2>
    <p class="avv-clause">
        <span class="avv-no">1.1</span>
        Der Auftragnehmer stellt dem Auftraggeber die Lernplattform Tuto bereit, eine Software, die im Browser genutzt
        wird. Dabei verarbeitet er personenbezogene Daten im Auftrag des Auftraggebers. Dieser Vertrag legt die Pflichten
        beider Parteien zum Datenschutz fest. Er erfüllt die Anforderungen des Art. 28 Abs. 3 DSGVO.
    </p>
    <p class="avv-clause">
        <span class="avv-no">1.2</span>
        Gegenstand sind alle Verarbeitungen personenbezogener Daten, die der Auftragnehmer für den Auftraggeber im Rahmen
        der Plattform vornimmt: Konten, Rollen und Klassenzuordnungen, Unterrichts- und Lerndaten, Klassenbuch,
        Benachrichtigungen sowie technische Begleitdaten. Die optionalen Module nach Nummer 3.4 gehören nur dazu, soweit
        sie genutzt oder eingeschaltet sind.
    </p>
    <p class="avv-clause">
        <span class="avv-no">1.3</span>
        Nicht Gegenstand sind Verarbeitungen, für die der Auftragnehmer selbst Verantwortlicher ist. Das sind die
        Stammdaten der Schule und der Nachweis über die Annahme dieses Vertrags (Version, Zeitpunkt, Vertragstext, Name
        und Benutzername der annehmenden Person), die er verarbeitet, um die Nutzung der Plattform abzuwickeln, die
        Schulen voneinander zu trennen und die Plattform sicher zu betreiben. Dafür gilt die
        <a href="{{ route('legal.privacy', absolute: false) }}">Datenschutzerklärung</a>. Das Konto der annehmenden
        Person, auch das erste Konto der Schuladministration, ist dagegen ein Konto der Schule und gehört zu den Daten
        nach Anlage 1.
    </p>
    <p class="avv-clause">
        <span class="avv-no">1.4</span>
        Die Dauer der Verarbeitung richtet sich nach der Laufzeit der Nutzung der Plattform durch den Auftraggeber
        (Nummer 15). Die Anlagen 1 bis 3 sind Bestandteil des Vertrags.
    </p>

    <h2 id="zweck">{{ $heading('zweck') }}</h2>
    <p class="avv-clause">
        <span class="avv-no">2.1</span>
        <strong>Art der Verarbeitung:</strong> Der Auftragnehmer erhebt, erfasst, speichert, liest aus, fragt ab,
        verwendet und löscht Daten und macht sie berechtigten Personen innerhalb der Schule des Auftraggebers sichtbar.
        Auswertungen laufen regelbasiert auf dem Server des Auftragnehmers. Eine Verarbeitung durch einen KI-Anbieter
        gehört nur dazu, wenn die Schuladministration die KI-Funktionen nach Nummer 3.4 für ihre Schule eingeschaltet hat.
    </p>
    <p class="avv-clause">
        <span class="avv-no">2.2</span>
        <strong>Zweck:</strong> Der Auftraggeber nutzt die Plattform, damit Lehrkräfte Unterricht nachbereiten,
        Hausaufgaben in Stufen und in Einfacher Sprache bereitstellen und das Klassenbuch führen, Schüler:innen
        Hausaufgaben bearbeiten, mit „Erklär’s mir“ üben und ihrer Lehrkraft Rückfragen stellen, Lehrkräfte den Lernstand
        ihrer Klassen einschätzen, Elterngespräche vorbereiten und Vertretungen übergeben und die Schuladministration
        Konten, Klassen und die Einstellungen der Schule verwaltet. Die Plattform benachrichtigt die Beteiligten in der
        App über neue Hausaufgaben, Abgaben und Rückfragen. Der Auftragnehmer verarbeitet die Daten ausschließlich dafür.
    </p>
    <p class="avv-clause">
        <span class="avv-no">2.3</span>
        Der Auftragnehmer verwendet die Daten nicht für Werbung, nicht für eigene Profilbildung, nicht zum Training
        eigener oder fremder KI-Modelle und nicht für andere eigene Zwecke. Die Lernstandsauswertung, die der Auftraggeber
        mit der Plattform beauftragt (Anlage 1), bleibt davon unberührt.
    </p>
    <p class="avv-clause">
        <span class="avv-no">2.4</span>
        Die Plattform trifft keine ausschließlich automatisierten Entscheidungen im Sinne des Art. 22 DSGVO.
        Verständniswerte und Rückmeldungen sind Hinweise und keine Noten. Über Förderung, Noten und Versetzung entscheidet
        die Schule.
    </p>

    <h2 id="daten">{{ $heading('daten') }}</h2>
    <p class="avv-clause">
        <span class="avv-no">3.1</span>
        Welche Daten zu welchem Zweck und wie lange verarbeitet werden, steht in Anlage 1.
    </p>
    <p class="avv-clause">
        <span class="avv-no">3.2</span>
        <strong>Betroffene Personen</strong> sind Schüler:innen (auch Kinder und Jugendliche), Lehrkräfte und
        Mitarbeitende der Schuladministration des Auftraggebers sowie, nur bei den optionalen Modulen,
        Erziehungsberechtigte und weitere Personen, deren Stimme beim Tuto-Mikro zu hören ist oder die in Notizen aus der
        Stunde vorkommen.
    </p>
    <p class="avv-clause">
        <span class="avv-no">3.3</span>
        Die Plattform ist nicht dafür gedacht, besondere Kategorien personenbezogener Daten nach Art. 9 DSGVO zu
        verarbeiten. Freitexte und der Ton beim Tuto-Mikro können solche Angaben enthalten, wenn jemand sie hineinschreibt
        oder ausspricht. Der Auftraggeber weist Lehrkräfte und Schüler:innen an, das zu unterlassen. Die Fehlzeiten im
        Klassenbuch enthalten keine Gründe.
    </p>
    <p class="avv-clause">
        <span class="avv-no">3.4</span>
        <span class="avv-optional">Optionale Module</span>
        Die folgenden Module sind nur Gegenstand dieses Vertrags, soweit sie genutzt oder eingeschaltet sind. Die
        zugehörigen Teile der Anlagen 1 und 3 gelten erst dann. Das Tuto-Mikro und die KI-Funktionen schaltet die
        Schuladministration jeweils für ihre Schule ein und jederzeit wieder aus. Das Einschalten ist eine Weisung nach
        Nummer 5.2.
    </p>
    <ul class="avv-list">
        <li>
            <strong>Einwilligungsnachweise:</strong> Erfassung der Einwilligungen von Erziehungsberechtigten zum
            Tuto-Mikro mit Name der erziehungsberechtigten Person, den erteilten und widerrufenen Einwilligungen und den
            Zeitpunkten (Anlage 1). Sie werden verarbeitet, sobald die Schule das Einwilligungsverfahren beginnt, also
            schon vor dem Freischalten des Tuto-Mikros.
        </li>
        <li>
            <strong>Tuto-Mikro:</strong> Die Lehrkraft schaltet in einer Stunde das Mikrofon ihres eigenen Geräts ein,
            aus dem Ton entstehen Notizen aus der Stunde. Es gibt das Tuto-Mikro erst, wenn die Schuladministration es mit
            vollständiger Checkliste und veröffentlichtem Einwilligungstext für ihre Schule freischaltet, und nie in den
            Fächern, die die Schule ausschließt (ohne eigene Liste: {{ $blockedSubjects }}). Es setzt die Einwilligung für
            alle anwesenden Schüler:innen voraus. Die Plattform speichert keinen Ton, sondern nur einen Nachweis über die
            Sitzung (Anlage 1).
        </li>
        <li>
            <strong>KI-Funktionen:</strong> Entwürfe für Zusammenfassungen, Hausaufgaben, Förderaufgaben, Stufen und
            Fassungen in Einfacher Sprache, die Auswertung von Abgaben und „Erklär’s mir“ und beim Tuto-Mikro die
            Spracherkennung und Zusammenfassung durch einen KI-Anbieter (Anlage 3). Sie gelten nur für einen
            Auftraggeber, dessen Schuladministration sie eingeschaltet und dabei bestätigt hat, dass mit dem Anbieter ein
            Vertrag zur Auftragsverarbeitung besteht, ein bezahlter Tarif ohne Nutzung der Eingaben zur
            Produktverbesserung genutzt wird, eine Datenschutz-Folgenabschätzung vorliegt und Eltern und Schüler:innen
            informiert sind. Einschalten kann sie nur, solange der Auftragnehmer die KI-Anbindung für die Plattform
            bereitstellt.
            @unless ($aiEnabled)
                Derzeit ist die KI-Anbindung der Plattform abgeschaltet.
            @endunless
            Ohne Einschalten beantwortet eine regelbasierte Logik auf dem Server des Auftragnehmers alle Anfragen, und
            keine Daten verlassen die Plattform.
        </li>
    </ul>

    <h2 id="auftraggeber">{{ $heading('auftraggeber') }}</h2>
    <p class="avv-clause">
        <span class="avv-no">4.1</span>
        Der Auftraggeber ist Verantwortlicher im Sinne des Art. 4 Nr. 7 DSGVO. Er ist dafür verantwortlich, dass die
        Verarbeitung rechtmäßig ist. Im Schulbetrieb ist die Grundlage in der Regel Art. 6 Abs. 1 lit. e DSGVO in
        Verbindung mit dem Landesschulgesetz und den Datenschutzvorschriften des Landes. Er beurteilt auch, ob die
        Betroffenenrechte gewahrt sind.
    </p>
    <p class="avv-clause">
        <span class="avv-no">4.2</span>
        Der Auftraggeber informiert die Betroffenen nach Art. 13 und 14 DSGVO, holt erforderliche Einwilligungen ein,
        zum Beispiel von Erziehungsberechtigten für das Tuto-Mikro, führt das Verzeichnis seiner Verarbeitungstätigkeiten
        nach Art. 30 Abs. 1 DSGVO, prüft, ob eine Datenschutz-Folgenabschätzung nach Art. 35 DSGVO nötig ist, und
        entscheidet, ob er das Tuto-Mikro und die KI-Funktionen für seine Schule einschaltet. Vor dem Einschalten bestätigt
        seine Schuladministration die Punkte der jeweiligen Checkliste in der Plattform. Zu einer Mitteilung nach
        Nummer 8.2 äußert er sich innerhalb der Frist.
    </p>
    <p class="avv-clause">
        <span class="avv-no">4.3</span>
        Der Auftraggeber verwaltet die Konten und Klassen seiner Schule. Er sorgt dafür, dass nur berechtigte Personen ein
        Konto bekommen, dass Zugangsdaten vertraulich weitergegeben werden und dass die Rollen zur Aufgabe passen. Er legt
        fest, welche Lehrkräfte welche Klassen leiten oder unterrichten und wer vertritt. Danach richten sich die Rechte
        der Lehrkräfte (Anlage 2).
    </p>
    <p class="avv-clause">
        <span class="avv-no">4.4</span>
        Der Auftraggeber informiert den Auftragnehmer unverzüglich, wenn er Fehler oder Unregelmäßigkeiten bei der
        Verarbeitung feststellt. Ansprechpartner des Auftragnehmers sind die Kontaktdaten oben, Ansprechpartner des
        Auftraggebers ist die Kontakt-E-Mail-Adresse der Schule, die in den Stammdaten steht.
    </p>
    <p class="avv-clause">
        <span class="avv-no">4.5</span>
        Der Auftraggeber hat das Recht, sich jederzeit von der Einhaltung dieses Vertrags zu überzeugen (Nummer 13).
    </p>

    <h2 id="weisungen">{{ $heading('weisungen') }}</h2>
    <p class="avv-clause">
        <span class="avv-no">5.1</span>
        <strong>Art. 28 Abs. 3 lit. a DSGVO:</strong> Der Auftragnehmer verarbeitet personenbezogene Daten nur auf
        dokumentierte Weisung des Auftraggebers. Das gilt auch für eine Übermittlung in ein Drittland oder an eine
        internationale Organisation. Ausgenommen ist eine Verarbeitung, zu der er nach dem Recht der Union oder eines
        Mitgliedstaats verpflichtet ist. Dann teilt er dem Auftraggeber diese rechtliche Anforderung vor der Verarbeitung
        mit, sofern das Recht eine solche Mitteilung nicht wegen eines wichtigen öffentlichen Interesses verbietet.
    </p>
    <p class="avv-clause">
        <span class="avv-no">5.2</span>
        Dieser Vertrag und die Einstellungen, die der Auftraggeber in der Plattform vornimmt, sind die Weisungen bei
        Vertragsschluss. Dazu gehören das Anlegen, Ändern und Löschen von Konten und Klassen, der Import von
        Schülerlisten, die Zuordnung von Lehrkräften zu Klassen, Vertretungen, das Ein- und Ausschalten des Tuto-Mikros
        und der KI-Funktionen und die Liste der Fächer ohne Tuto-Mikro. Weitere Weisungen erteilt die Schuladministration
        des Auftraggebers in Textform, eine E-Mail genügt. Mündliche Weisungen bestätigt der Auftraggeber unverzüglich in
        Textform.
    </p>
    <p class="avv-clause">
        <span class="avv-no">5.3</span>
        <strong>Hinweispflicht (Art. 28 Abs. 3 Unterabs. 2 DSGVO):</strong> Hält der Auftragnehmer eine Weisung für
        einen Verstoß gegen die DSGVO oder gegen andere Datenschutzvorschriften der Union oder der Mitgliedstaaten,
        informiert er den Auftraggeber unverzüglich. Er darf die Ausführung der Weisung aussetzen, bis der Auftraggeber
        sie bestätigt oder ändert.
    </p>

    <h2 id="vertraulichkeit">{{ $heading('vertraulichkeit') }}</h2>
    <p class="avv-clause">
        <span class="avv-no">6.1</span>
        <strong>Art. 28 Abs. 3 lit. b DSGVO:</strong> Der Auftragnehmer setzt für die Verarbeitung nur Personen ein, die
        sich zur Vertraulichkeit verpflichtet haben oder einer gesetzlichen Verschwiegenheitspflicht unterliegen. Diese
        Personen verarbeiten die Daten nach Art. 29 und Art. 32 Abs. 4 DSGVO nur auf Weisung des Auftraggebers. Unterliegen
        Daten besonderen Geheimhaltungspflichten des Auftraggebers, etwa nach Landesrecht oder nach § 203 StGB, verpflichtet
        sich der Auftragnehmer auf Verlangen des Auftraggebers auch darauf.
    </p>
    <p class="avv-clause">
        <span class="avv-no">6.2</span>
        Der Auftragnehmer greift auf die Daten nur zu, soweit das für Betrieb, Wartung, Fehlersuche und die Unterstützung
        des Auftraggebers nötig ist. Er wertet Unterrichts- und Lerndaten nicht aus.
    </p>
    <p class="avv-clause">
        <span class="avv-no">6.3</span>
        Die Pflicht zur Vertraulichkeit besteht nach Ende dieses Vertrags fort.
    </p>
    <p class="avv-clause">
        <span class="avv-no">6.4</span>
        Der Auftraggeber verpflichtet seine Beschäftigten, die die Plattform nutzen, nach den für ihn geltenden
        Vorschriften zur Verschwiegenheit.
    </p>

    <h2 id="sicherheit">{{ $heading('sicherheit') }}</h2>
    <p class="avv-clause">
        <span class="avv-no">7.1</span>
        <strong>Art. 28 Abs. 3 lit. c und Art. 32 DSGVO:</strong> Der Auftragnehmer trifft geeignete technische und
        organisatorische Maßnahmen, um ein dem Risiko angemessenes Schutzniveau zu gewährleisten. Er berücksichtigt den
        Stand der Technik, die Kosten, Art, Umfang, Umstände und Zwecke der Verarbeitung und die Risiken für die
        Betroffenen. Das Risiko ist erhöht, weil es um Daten von Kindern und Jugendlichen geht.
    </p>
    <p class="avv-clause">
        <span class="avv-no">7.2</span>
        Die bei Vertragsschluss vereinbarten Maßnahmen beschreibt Anlage 2.
    </p>
    <p class="avv-clause">
        <span class="avv-no">7.3</span>
        Der Auftragnehmer darf die Maßnahmen weiterentwickeln, solange er das vereinbarte Schutzniveau nicht
        unterschreitet. Wesentliche Änderungen teilt er dem Auftraggeber mit und aktualisiert Anlage 2. Sie gelten auch ohne
        erneute Annahme (Nummer 15.4).
    </p>
    <p class="avv-clause">
        <span class="avv-no">7.4</span>
        Der Auftragnehmer überprüft, bewertet und evaluiert die Wirksamkeit der Maßnahmen regelmäßig, mindestens einmal
        im Jahr und bei wesentlichen Änderungen der Plattform (Art. 32 Abs. 1 lit. d DSGVO).
    </p>
    <p class="avv-clause">
        <span class="avv-no">7.5</span>
        Der Auftraggeber ist für die Sicherheit auf seiner Seite verantwortlich, zum Beispiel für Endgeräte,
        Netzwerk, Weitergabe von Zugangsdaten und Berechtigungen.
    </p>

    <h2 id="unterauftragsverarbeiter">{{ $heading('unterauftragsverarbeiter') }}</h2>
    <p class="avv-clause">
        <span class="avv-no">8.1</span>
        <strong>Art. 28 Abs. 2 und 4 DSGVO:</strong> Der Auftraggeber erteilt dem Auftragnehmer die allgemeine
        schriftliche Genehmigung, weitere Auftragsverarbeiter einzusetzen. Bei Vertragsschluss sind es die in Anlage 3
        genannten.
    </p>
    <p class="avv-clause">
        <span class="avv-no">8.2</span>
        Der Auftragnehmer informiert den Auftraggeber in Textform, an dessen Kontakt-E-Mail-Adresse, mindestens
        30 Tage vorher über jede beabsichtigte Hinzuziehung oder Ersetzung eines Unterauftragsverarbeiters. Der
        Auftraggeber kann innerhalb dieser Frist aus einem wichtigen datenschutzrechtlichen Grund Einspruch erheben.
        Ohne Einspruch gilt die Änderung nach Ablauf der Frist als genehmigt und wird Teil dieses Vertrags (Nummer
        15.4). Erhebt der Auftraggeber Einspruch, suchen die Parteien eine Lösung. Solange der Einspruch offen ist,
        setzt der Auftragnehmer den neuen Unterauftragsverarbeiter für die Daten des Auftraggebers nicht ein. Gelingt
        keine Lösung, verzichtet der Auftragnehmer darauf, oder der Auftraggeber kann nach Nummer 15.3 kündigen. Der
        Auftragnehmer dokumentiert Mitteilung, Frist und Antwort des Auftraggebers.
    </p>
    <p class="avv-clause">
        <span class="avv-no">8.3</span>
        <span class="avv-optional">Optionales Modul</span>
        @if ($aiProvider['local'])
            Die KI-Funktionen laufen mit {{ $aiProvider['short'] }}. Dafür setzt der Auftragnehmer keinen
            Unterauftragsverarbeiter ein. Einen externen KI-Anbieter setzt er erst nach einer Mitteilung nach Nummer 8.2
            ein.
        @else
            Der Anbieter der KI-Funktionen ist in Anlage 3 genannt und mit diesem Vertrag genehmigt. Der Auftragnehmer
            setzt ihn nur für Auftraggeber ein, deren Schuladministration die KI-Funktionen eingeschaltet hat (Nummer
            3.4), und nur für die Daten nach Anlage 1. Er nutzt ihn nur in einem kostenpflichtigen Tarif, in dem der
            Anbieter Eingaben und Ergebnisse nicht zur Verbesserung seiner Produkte oder zum Training nutzt, und nur auf
            Grundlage eines Vertrags nach Art. 28 Abs. 4 DSGVO. Schaltet die Schuladministration die KI-Funktionen aus,
            gehen keine Daten des Auftraggebers mehr an den Anbieter. Einen anderen KI-Anbieter setzt der Auftragnehmer
            erst nach einer Mitteilung nach Nummer 8.2 ein.
        @endif
    </p>
    <p class="avv-clause">
        <span class="avv-no">8.4</span>
        Der Auftragnehmer legt jedem Unterauftragsverarbeiter dieselben Datenschutzpflichten auf, die in diesem Vertrag
        stehen, insbesondere hinreichende Garantien für geeignete technische und organisatorische Maßnahmen (Art. 28
        Abs. 4 DSGVO). Er haftet gegenüber dem Auftraggeber dafür, dass der Unterauftragsverarbeiter seine Pflichten
        erfüllt. Auf Verlangen gibt er dem Auftraggeber Auskunft über den datenschutzrelevanten Inhalt der Verträge.
    </p>
    <p class="avv-clause">
        <span class="avv-no">8.5</span>
        Nebenleistungen ohne Zugriff auf personenbezogene Daten, etwa Telekommunikation oder Post, sind keine
        Unterauftragsverhältnisse.
    </p>

    <h2 id="betroffenenrechte">{{ $heading('betroffenenrechte') }}</h2>
    <p class="avv-clause">
        <span class="avv-no">9.1</span>
        <strong>Art. 28 Abs. 3 lit. e DSGVO:</strong> Der Auftragnehmer unterstützt den Auftraggeber mit geeigneten
        technischen und organisatorischen Maßnahmen dabei, Anträge der Betroffenen nach Art. 12 bis 23 DSGVO zu
        beantworten (Auskunft, Berichtigung, Löschung, Einschränkung, Datenübertragbarkeit, Widerspruch).
    </p>
    <p class="avv-clause">
        <span class="avv-no">9.2</span>
        Wendet sich eine betroffene Person an den Auftragnehmer und ist erkennbar der Auftraggeber gemeint, leitet der
        Auftragnehmer die Anfrage unverzüglich weiter. Er beantwortet sie nicht selbst, außer der Auftraggeber weist ihn
        dazu an.
    </p>
    <p class="avv-clause">
        <span class="avv-no">9.3</span>
        Die Plattform bietet dafür diese Funktionen. Jede Person kann unter „Mein Profil“ mit „Meine Daten herunterladen“
        die Daten zu ihrem Konto als Datei (JSON) laden, ohne Daten anderer Personen. Das dient der Auskunft und der
        Datenübertragbarkeit. Die Datei enthält das Konto mit der gewählten Sprache, die Unterrichts- und Lerndaten mit
        den gewählten Stufen, „Erklär’s mir“, Rückfragen, Klassenbuch, Vertretungen, Benachrichtigungen, den
        Einwilligungsnachweis und die Sitzungen des Tuto-Mikros. Die Schuladministration kann die Datei für jedes Konto
        ihrer Schule laden, dann enthält sie nur, was die Schuladministration ohnehin sehen darf. Sie kann Konten ihrer
        Schule ändern und löschen, dabei verschwinden die zugehörigen Abgaben, Gespräche und Rückfragen. Lehrkräfte können
        Abgaben löschen.
    </p>
    <p class="avv-clause">
        <span class="avv-no">9.4</span>
        Darüber hinaus, zum Beispiel beim Löschen einzelner Gespräche oder hochgeladener Arbeitsblätter auf dem Server,
        bei einer Einschränkung der Verarbeitung oder bei einer Auskunft über mehrere Konten, unterstützt der
        Auftragnehmer auf Weisung des Auftraggebers so rechtzeitig, dass dieser die Fristen des Art. 12 Abs. 3 DSGVO
        einhalten kann.
    </p>

    <h2 id="unterstuetzung">{{ $heading('unterstuetzung') }}</h2>
    <p class="avv-clause">
        <span class="avv-no">10.1</span>
        <strong>Art. 28 Abs. 3 lit. f DSGVO:</strong> Der Auftragnehmer unterstützt den Auftraggeber bei der Einhaltung
        der Pflichten aus Art. 32 bis 36 DSGVO, also bei der Sicherheit der Verarbeitung, bei der Meldung von
        Datenschutzverletzungen an die Aufsichtsbehörde und an Betroffene, bei der Datenschutz-Folgenabschätzung und bei
        der vorherigen Konsultation der Aufsichtsbehörde. Er berücksichtigt dabei die Art der Verarbeitung und die
        Informationen, die ihm zur Verfügung stehen.
    </p>
    <p class="avv-clause">
        <span class="avv-no">10.2</span>
        <strong>Datenschutzverletzungen:</strong> Wird dem Auftragnehmer eine Verletzung des Schutzes personenbezogener
        Daten bekannt, die Daten des Auftraggebers betrifft, meldet er sie dem Auftraggeber unverzüglich
        (Art. 33 Abs. 2 DSGVO), spätestens innerhalb von 24 Stunden nach Kenntnis, an dessen Kontakt-E-Mail-Adresse. Der
        Auftraggeber muss die Verletzung selbst binnen 72 Stunden melden (Art. 33 Abs. 1 DSGVO). Die Meldung beschreibt,
        soweit bekannt, die Art der Verletzung mit den Kategorien und der ungefähren Zahl der betroffenen Personen und
        Datensätze, die wahrscheinlichen Folgen und die ergriffenen oder vorgeschlagenen Maßnahmen und nennt einen
        Ansprechpartner. Informationen, die noch fehlen, reicht der Auftragnehmer schrittweise nach (Art. 33 Abs. 4 DSGVO).
    </p>
    <p class="avv-clause">
        <span class="avv-no">10.3</span>
        Der Auftragnehmer ergreift unverzüglich Maßnahmen, um die Verletzung einzudämmen und ihre Folgen zu mindern, und
        dokumentiert sie. Die Meldung an die Aufsichtsbehörde (Art. 33 Abs. 1 DSGVO) und die Benachrichtigung der
        Betroffenen (Art. 34 DSGVO) übernimmt der Auftraggeber. Der Auftragnehmer meldet Vorfälle nicht ohne Weisung an
        Dritte, außer eine Rechtsvorschrift verlangt es.
    </p>
    <p class="avv-clause">
        <span class="avv-no">10.4</span>
        Bei den optionalen Modulen (Tuto-Mikro, KI-Funktionen) prüft der Auftraggeber vor dem Einschalten, ob eine
        Datenschutz-Folgenabschätzung nötig ist, weil Daten von Kindern verarbeitet werden. Die Plattform lässt das
        Einschalten erst zu, wenn die Schuladministration bestätigt hat, dass sie durchgeführt ist. Der Auftragnehmer
        liefert dafür die Beschreibung der Verarbeitung, die Maßnahmen und die Unterauftragsverarbeiter.
    </p>

    <h2 id="ort">{{ $heading('ort') }}</h2>
    <p class="avv-clause">
        <span class="avv-no">11.1</span>
        Der Auftragnehmer verarbeitet die Daten auf Servern in Deutschland. Das Hosting beschreibt Anlage 3.
    </p>
    <p class="avv-clause">
        <span class="avv-no">11.2</span>
        Eine Verarbeitung oder ein Zugriff aus einem Land außerhalb der EU und des EWR (Drittland) ist nur zulässig, wenn
        der Auftraggeber vorher zugestimmt hat und die Voraussetzungen der Art. 44 ff. DSGVO erfüllt sind, also ein
        Angemessenheitsbeschluss der EU-Kommission oder geeignete Garantien wie EU-Standardvertragsklauseln bestehen,
        gegebenenfalls mit zusätzlichen Maßnahmen.
    </p>
    <p class="avv-clause">
        <span class="avv-no">11.3</span>
        <span class="avv-optional">Optionales Modul</span>
        @if ($aiProvider['local'])
            Die KI-Funktionen laufen auf Servern des Auftragnehmers. Eine Übermittlung in ein Drittland findet dafür
            nicht statt.
        @else
            Der Anbieter der KI-Funktionen kann Daten in einem Drittland verarbeiten (Anlage 3). Der Auftraggeber weist
            die Übermittlung an und stimmt ihr nach Nummer 11.2 zu, indem seine Schuladministration die KI-Funktionen
            einschaltet. Sie geschieht nur auf Grundlage der in Anlage 3 genannten Garantien. Solange die KI-Funktionen
            ausgeschaltet sind, übermittelt der Auftragnehmer keine Daten des Auftraggebers in ein Drittland.
        @endif
    </p>

    <h2 id="loeschung">{{ $heading('loeschung') }}</h2>
    <p class="avv-clause">
        <span class="avv-no">12.1</span>
        <strong>Art. 28 Abs. 3 lit. g DSGVO:</strong> Nach Abschluss der Verarbeitung, also nach Vertragsende oder auf
        Weisung des Auftraggebers, löscht der Auftragnehmer nach Wahl des Auftraggebers alle personenbezogenen Daten und
        vorhandenen Kopien oder gibt sie zurück und löscht sie danach. Das gilt nicht, soweit das Recht der Union oder
        eines Mitgliedstaats eine Speicherung verlangt.
    </p>
    <p class="avv-clause">
        <span class="avv-no">12.2</span>
        Der Auftragnehmer gibt die Daten in einem gängigen, maschinenlesbaren Format zurück (zum Beispiel JSON oder CSV).
        Die Wahl zwischen Löschung und Rückgabe teilt der Auftraggeber bis zum Vertragsende in Textform mit. Ohne
        Mitteilung löscht der Auftragnehmer die Daten spätestens vier Wochen nach Vertragsende.
    </p>
    <p class="avv-clause">
        <span class="avv-no">12.3</span>
        Datensicherungen löscht der Auftragnehmer, sobald ihre Aufbewahrungsfrist abgelaufen ist (Anlage 2). Auf Verlangen
        bestätigt er die Löschung in Textform.
    </p>
    <p class="avv-clause">
        <span class="avv-no">12.4</span>
        Schon während der Laufzeit löscht die Plattform Daten nach festen Fristen selbst (Anlage 1, Abschnitt 4).
    </p>
    <p class="avv-clause">
        <span class="avv-no">12.5</span>
        Den Nachweis über die Annahme dieses Vertrags darf der Auftragnehmer als eigener Verantwortlicher aufbewahren,
        um seine Pflichten zu belegen.
    </p>

    <h2 id="nachweise">{{ $heading('nachweise') }}</h2>
    <p class="avv-clause">
        <span class="avv-no">13.1</span>
        <strong>Art. 28 Abs. 3 lit. h DSGVO:</strong> Der Auftragnehmer stellt dem Auftraggeber alle Informationen zur
        Verfügung, die nötig sind, um die Einhaltung der Pflichten aus diesem Vertrag und aus Art. 28 DSGVO
        nachzuweisen. Dazu gehört insbesondere die Beschreibung der Maßnahmen in Anlage 2 und die Auskunft auf Anfrage.
    </p>
    <p class="avv-clause">
        <span class="avv-no">13.2</span>
        Der Auftragnehmer ermöglicht Überprüfungen einschließlich Inspektionen durch den Auftraggeber oder einen von ihm
        beauftragten Prüfer und trägt dazu bei. Der Auftraggeber kündigt sie rechtzeitig an und führt sie in den üblichen
        Geschäftszeiten ohne Störung des Betriebs durch. Beauftragte Prüfer sind zur Verschwiegenheit verpflichtet und
        keine Wettbewerber des Auftragnehmers.
    </p>
    <p class="avv-clause">
        <span class="avv-no">13.3</span>
        Den Nachweis kann der Auftragnehmer auch durch genehmigte Verhaltensregeln (Art. 40 DSGVO), eine Zertifizierung
        (Art. 42 DSGVO) oder aktuelle Prüfberichte führen, soweit sie den Prüfgegenstand abdecken.
    </p>
    <p class="avv-clause">
        <span class="avv-no">13.4</span>
        Der Auftragnehmer führt das Verzeichnis der Verarbeitungstätigkeiten für Auftragsverarbeiter (Art. 30 Abs. 2
        DSGVO), arbeitet mit der Aufsichtsbehörde zusammen (Art. 31 DSGVO) und informiert den Auftraggeber über
        Kontrollen der Aufsichtsbehörde, soweit sie diese Verarbeitung betreffen.
    </p>

    <h2 id="haftung">{{ $heading('haftung') }}</h2>
    <p class="avv-clause">
        <span class="avv-no">14.1</span>
        Gegenüber betroffenen Personen gilt Art. 82 Abs. 1, 2 und 4 DSGVO. Im Verhältnis der Parteien haftet jede Partei
        für die Verletzung ihrer Pflichten aus diesem Vertrag und aus der DSGVO. Der Ausgleich zwischen den Parteien
        richtet sich nach Art. 82 Abs. 5 DSGVO.
    </p>
    <p class="avv-clause">
        <span class="avv-no">14.2</span>
        Bußgelder nach Art. 83 DSGVO trägt jede Partei selbst, soweit sie gegen sie verhängt werden.
    </p>
    <p class="avv-clause">
        <span class="avv-no">14.3</span>
        Im Übrigen richtet sich die Haftung nach den gesetzlichen Vorschriften und den Vereinbarungen der Parteien zur
        Nutzung der Plattform.
    </p>

    <h2 id="laufzeit">{{ $heading('laufzeit') }}</h2>
    <p class="avv-clause">
        <span class="avv-no">15.1</span>
        Der Vertrag beginnt mit der Annahme durch den Auftraggeber (Nummer 16.1) und gilt, solange der Auftragnehmer
        personenbezogene Daten des Auftraggebers verarbeitet.
    </p>
    <p class="avv-clause">
        <span class="avv-no">15.2</span>
        Er endet, wenn der Auftraggeber die Plattform nicht mehr nutzt und der Auftragnehmer die Daten nach Nummer 12
        gelöscht oder zurückgegeben hat.
    </p>
    <p class="avv-clause">
        <span class="avv-no">15.3</span>
        Jede Partei kann den Vertrag aus wichtigem Grund in Textform kündigen, insbesondere bei einem schwerwiegenden
        Verstoß gegen diesen Vertrag oder gegen Datenschutzvorschriften. Der Auftraggeber kann außerdem kündigen, wenn
        ein Einspruch nach Nummer 8.2 nicht zu einer Lösung führt.
    </p>
    <p class="avv-clause">
        <span class="avv-no">15.4</span>
        Ändert der Auftragnehmer den Vertrag inhaltlich, veröffentlicht er eine neue Fassung und bittet die
        Schuladministration, sie unter „Meine Schule“ anzunehmen. Das gilt auch, wenn sich eine Angabe ändert, die die
        Plattform aus ihrer Einstellung in den Text übernimmt, etwa eine Frist oder der KI-Anbieter. Bis zur Annahme gilt
        die bisher angenommene Fassung weiter, die die Plattform mit ihrem Text aufbewahrt und unter „Meine Schule“
        anzeigt. Ausgenommen sind Änderungen der Maßnahmen nach Nummer 7.3 und der Unterauftragsverarbeiter nach
        Nummer 8.2. Sie werden mit der Mitteilung beziehungsweise nach Ablauf der Einspruchsfrist Teil des Vertrags, auch
        ohne erneute Annahme. Die Plattform bittet trotzdem um Annahme der neuen Fassung, damit der Nachweis aktuell
        bleibt.
    </p>

    <h2 id="schluss">{{ $heading('schluss') }}</h2>
    <p class="avv-clause">
        <span class="avv-no">16.1</span>
        <strong>Form (Art. 28 Abs. 9 DSGVO):</strong> Der Vertrag wird elektronisch geschlossen. Der Auftragnehmer macht
        mit diesem Text das Angebot. Der Auftraggeber nimmt es an, indem seine Schuladministration bei der Registrierung
        der Schule oder später unter „Meine Schule“ die Annahme bestätigt. Die Plattform speichert Version, Zeitpunkt, den
        Vertragstext, wie ihn die Schule gesehen hat, sowie Name und Benutzername der annehmenden Person. Dieser Nachweis
        bleibt auch bestehen, wenn das Konto später gelöscht wird. Wer annimmt, versichert, die Schule vertreten zu dürfen.
    </p>
    <p class="avv-clause">
        <span class="avv-no">16.2</span>
        Änderungen und Ergänzungen bedürfen der Textform, soweit nicht Nummer 15.4 gilt.
    </p>
    <p class="avv-clause">
        <span class="avv-no">16.3</span>
        Bei Widersprüchen zu anderen Vereinbarungen der Parteien geht dieser Vertrag in Fragen des Datenschutzes vor.
    </p>
    <p class="avv-clause">
        <span class="avv-no">16.4</span>
        Ist eine Bestimmung unwirksam, bleiben die übrigen wirksam. Die Parteien ersetzen die unwirksame Bestimmung durch
        eine wirksame, die ihrem Zweck am nächsten kommt.
    </p>
    <p class="avv-clause">
        <span class="avv-no">16.5</span>
        Es gilt deutsches Recht. Gerichtsstand ist, soweit zulässig, der Sitz des Auftraggebers.
    </p>

    <section class="avv-annex" aria-labelledby="anlage-1">
        <h2 id="anlage-1">{{ $annexes['anlage-1'] }}</h2>

        <h3>1. Gegenstand</h3>
        <p>
            Bereitstellung der Lernplattform Tuto für eine Schule und Verarbeitung der Daten, die ihre
            Schuladministration, Lehrkräfte und Schüler:innen dort eingeben oder die bei der Nutzung entstehen. Dauer:
            solange die Schule die Plattform nutzt (Nummer 15).
        </p>

        <h3>2. Betroffene Personen</h3>
        <ul>
            <li>Schüler:innen, auch Kinder und Jugendliche ab etwa 10 Jahren</li>
            <li>Lehrkräfte</li>
            <li>Mitarbeitende der Schuladministration</li>
            <li>
                <span class="avv-optional">Optional</span>
                Erziehungsberechtigte, bei den Einwilligungsnachweisen
            </li>
            <li>
                <span class="avv-optional">Optional</span>
                Weitere Personen, deren Stimme beim Tuto-Mikro zu hören ist oder die in Notizen aus der Stunde vorkommen
            </li>
        </ul>

        <h3>3. Datenkategorien, Zwecke und Speicherdauer</h3>
        <div class="legal-table-wrap" role="region" aria-label="Datenkategorien, Zwecke und Speicherdauer" tabindex="0">
            <table class="legal-table">
                <caption>Datenkategorien, Zwecke und Speicherdauer</caption>
                <thead>
                    <tr>
                        <th scope="col">Daten</th>
                        <th scope="col">Betroffene</th>
                        <th scope="col">Zweck</th>
                        <th scope="col">Speicherdauer</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th scope="row">Konten: Name, Benutzername, Passwort (nur als Hash), Rolle, Schule und Klasse, gewählte Sprache, freiwillig eine E-Mail-Adresse</th>
                        <td><span class="legal-cell-label">Betroffene</span> Schüler:innen, Lehrkräfte, Schuladministration</td>
                        <td><span class="legal-cell-label">Zweck</span> Anmeldung, Zuordnung von Inhalten, Rechte nach Rolle, Anzeige in der gewählten Sprache, Zurücksetzen des Passworts</td>
                        <td><span class="legal-cell-label">Speicherdauer</span> bis die Schuladministration das Konto löscht, spätestens bei Vertragsende (Nummer 12)</td>
                    </tr>
                    <tr>
                        <th scope="row">Klassenzuordnung und Vertretungen: Klassenleitung, zugeordnete Lehrkräfte, Vertretungen mit Zeitraum, Hinweis und einrichtender Person</th>
                        <td><span class="legal-cell-label">Betroffene</span> Lehrkräfte, Schuladministration</td>
                        <td><span class="legal-cell-label">Zweck</span> Rechte der Lehrkräfte für ihre Klassen, Übergabe einer Klasse an eine Vertretung</td>
                        <td><span class="legal-cell-label">Speicherdauer</span> bis die Zuordnung aufgehoben oder die Klasse oder das Konto gelöscht wird. Eine abgelaufene Vertretung gewährt nichts mehr und bleibt als Nachweis bis dahin bestehen.</td>
                    </tr>
                    <tr>
                        <th scope="row">Unterrichtsstunden: Fach, Thema, Datum, Notizen (auch übernommene Notizen aus der Stunde), Zusammenfassung, hochgeladene Arbeitsblätter (Foto, Scan, PDF), Fassungen in Einfacher Sprache</th>
                        <td><span class="legal-cell-label">Betroffene</span> Lehrkräfte, Schüler:innen nur, soweit jemand sie in Texte schreibt</td>
                        <td><span class="legal-cell-label">Zweck</span> Nachbereitung des Unterrichts, Entwurf von Hausaufgaben</td>
                        <td><span class="legal-cell-label">Speicherdauer</span> {{ $keptPhrase }}, danach automatische Löschung (Abschnitt 4)</td>
                    </tr>
                    <tr>
                        <th scope="row">Hausaufgaben und Abgaben: Fragen in Stufen, Zusammenfassungen, Antworten mit der gewählten Stufe, Verständniswert von 0 bis 100, Rückmeldung, Hinweise auf Lücken</th>
                        <td><span class="legal-cell-label">Betroffene</span> Schüler:innen, Lehrkräfte</td>
                        <td><span class="legal-cell-label">Zweck</span> Hausaufgaben bearbeiten und auswerten, Lernstand erkennen</td>
                        <td><span class="legal-cell-label">Speicherdauer</span> wie die zugehörige Unterrichtsstunde</td>
                    </tr>
                    <tr>
                        <th scope="row">„Erklär’s mir“: Thema, Gesprächsverlauf mit der Computerfigur Tuto, Verständniswert, Rückmeldung</th>
                        <td><span class="legal-cell-label">Betroffene</span> Schüler:innen</td>
                        <td><span class="legal-cell-label">Zweck</span> Üben und Lernstand erkennen</td>
                        <td><span class="legal-cell-label">Speicherdauer</span> {{ $keptPhrase }}, danach automatische Löschung</td>
                    </tr>
                    <tr>
                        <th scope="row">Rückfragen: Frage der Schüler:in zu einer Stunde, Antwort und antwortende Person, Zeitpunkte von Frage, Antwort und Lesen</th>
                        <td><span class="legal-cell-label">Betroffene</span> Schüler:innen, Lehrkräfte</td>
                        <td><span class="legal-cell-label">Zweck</span> Fragen zum Unterricht klären</td>
                        <td><span class="legal-cell-label">Speicherdauer</span> wie die zugehörige Unterrichtsstunde</td>
                    </tr>
                    <tr>
                        <th scope="row">Klassenbuch: Datum, Stunde, Fach, Thema, Unterrichtsinhalt, Hausaufgabe, Bemerkungen, fehlende Schüler:innen (ohne Gründe), eintragende Lehrkraft, Bestätigung</th>
                        <td><span class="legal-cell-label">Betroffene</span> Schüler:innen, Lehrkräfte</td>
                        <td><span class="legal-cell-label">Zweck</span> Nachweis des Unterrichts und der Anwesenheit</td>
                        <td><span class="legal-cell-label">Speicherdauer</span> {{ $keptPhrase }} nach dem Datum des Eintrags, danach automatische Löschung</td>
                    </tr>
                    <tr>
                        <th scope="row">Benachrichtigungen in der App: Titel und Text mit Thema, Fach und Klasse einer Stunde oder einer Anzahl, Link, Zeitpunkt, gelesen am</th>
                        <td><span class="legal-cell-label">Betroffene</span> Schüler:innen, Lehrkräfte</td>
                        <td><span class="legal-cell-label">Zweck</span> Hinweis auf neue Hausaufgaben, Abgaben, Rückfragen und Antworten</td>
                        <td><span class="legal-cell-label">Speicherdauer</span> {{ $notificationReadDays }} Tage nach dem Lesen, höchstens {{ $notificationDays }} Tage nach dem Eingang</td>
                    </tr>
                    <tr>
                        <th scope="row">Lernstand-Übersicht und Elterngesprächs-Bericht</th>
                        <td><span class="legal-cell-label">Betroffene</span> Schüler:innen</td>
                        <td><span class="legal-cell-label">Zweck</span> Förderbedarf einschätzen, Elterngespräche vorbereiten</td>
                        <td><span class="legal-cell-label">Speicherdauer</span> werden aus den gespeicherten Ergebnissen berechnet und enthalten nur, was dort steht</td>
                    </tr>
                    <tr>
                        <th scope="row">Einstellungen der Schule: Einschalten von Tuto-Mikro und KI-Funktionen mit Zeitpunkt und einschaltender Person, Bestätigungen der Checklisten, Fächer ohne Tuto-Mikro, Einwilligungstexte mit erstellender Person</th>
                        <td><span class="legal-cell-label">Betroffene</span> Schuladministration</td>
                        <td><span class="legal-cell-label">Zweck</span> Nachweis, wer was wann für die Schule eingeschaltet hat</td>
                        <td><span class="legal-cell-label">Speicherdauer</span> bis zum Ausschalten oder zur nächsten Änderung, Einwilligungstexte bis zur Löschung der Schule</td>
                    </tr>
                    <tr>
                        <th scope="row">Technische Daten: Sitzungskennung, Benutzerkennung, IP-Adresse, Browserangabe, Zeitpunkt der letzten Aktivität, auf der Anmeldeseite gewählte Sprache</th>
                        <td><span class="legal-cell-label">Betroffene</span> alle, die sich anmelden</td>
                        <td><span class="legal-cell-label">Zweck</span> Anmeldung, Sicherheit und Betrieb</td>
                        <td><span class="legal-cell-label">Speicherdauer</span> Sitzungen laufen nach {{ $sessionMinutes }} Minuten ohne Aktivität ab und werden regelmäßig entfernt</td>
                    </tr>
                    <tr>
                        <th scope="row">Einträge zum Zurücksetzen des Passworts: E-Mail-Adresse, verschlüsselter Code, Zeitpunkt</th>
                        <td><span class="legal-cell-label">Betroffene</span> Personen mit hinterlegter E-Mail-Adresse</td>
                        <td><span class="legal-cell-label">Zweck</span> Passwort zurücksetzen</td>
                        <td><span class="legal-cell-label">Speicherdauer</span> Link gilt {{ $resetMinutes }} Minuten, Eintrag wird beim Zurücksetzen gelöscht, unbenutzte täglich</td>
                    </tr>
                    <tr>
                        <th scope="row">Server-Logfiles und Fehlerprotokoll: IP-Adresse, Zeit, aufgerufene Adresse (auch die Adresse der Einwilligungsseite mit ihrem Code), Statuscode, Browserangabe, Benutzerkennung bei Fehlern</th>
                        <td><span class="legal-cell-label">Betroffene</span> alle, die die Plattform aufrufen, auch Erziehungsberechtigte auf der Einwilligungsseite</td>
                        <td><span class="legal-cell-label">Zweck</span> Betrieb, Fehlersuche, Abwehr von Angriffen</td>
                        <td><span class="legal-cell-label">Speicherdauer</span> {{ $todo('Frist für Logfiles und Fehlerprotokoll auf den Servern') }}</td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <span class="avv-optional">Optional</span>
                            Tuto-Mikro, Ton und Notizen aus der Stunde (aus dem Ton erkannter Text)
                        </th>
                        <td><span class="legal-cell-label">Betroffene</span> Lehrkräfte und Schüler:innen (Stimme und alles, was gesagt wird), weitere anwesende Personen</td>
                        <td><span class="legal-cell-label">Zweck</span> Unterricht nachbereiten, Hausaufgaben entwerfen; sichtbar nur für die Lehrkraft der Stunde</td>
                        <td>
                            <span class="legal-cell-label">Speicherdauer</span>
                            Der Ton wird nicht gespeichert. Bei eingeschalteten KI-Funktionen geht er in kurzen Abschnitten
                            über eine verschlüsselte Verbindung an den Server, wird dort nur im Arbeitsspeicher an den
                            KI-Anbieter weitergereicht und mit dem Ende der Anfrage verworfen. Ohne KI-Funktionen verlässt er
                            das Gerät der Lehrkraft nicht. Der erkannte Text bleibt nur im Browser der Lehrkraft, bis sie
                            etwas in die Notizen der Stunde übernimmt. Übernommenes gilt als Unterrichtsdaten.
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <span class="avv-optional">Optional</span>
                            Tuto-Mikro, Sitzungsnachweis: Stunde, Lehrkraft, Beginn, Ende, Dauer, als anwesend markierte Schüler:innen, Zeitpunkt der Bestätigung der Anwesenheit, Fassung des Einwilligungstextes, geprüfte Zwecke, ob Ton an den KI-Anbieter ging
                        </th>
                        <td><span class="legal-cell-label">Betroffene</span> Lehrkräfte, Schüler:innen</td>
                        <td><span class="legal-cell-label">Zweck</span> Nachweis, dass für alle Anwesenden eine Einwilligung vorlag; sichtbar nur für die Lehrkraft der Stunde</td>
                        <td><span class="legal-cell-label">Speicherdauer</span> {{ $mikroSessionDays }} Tage nach Beginn, dann automatische Löschung, mit der Stunde oder dem Konto der Lehrkraft früher</td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <span class="avv-optional">Optional</span>
                            Einwilligungsnachweise: Name der erziehungsberechtigten Person, erteilte und widerrufene Einwilligungen mit Zeitpunkten, Fassung des Einwilligungstextes, Art der Erklärung (digital oder auf Papier), erfassende Person, Bestätigung durch die Schüler:in, Prüfwert des Links
                        </th>
                        <td><span class="legal-cell-label">Betroffene</span> Erziehungsberechtigte, Schüler:innen</td>
                        <td><span class="legal-cell-label">Zweck</span> Nachweis der Einwilligungen des Auftraggebers</td>
                        <td><span class="legal-cell-label">Speicherdauer</span> bis das Konto der Schüler:in gelöscht wird, mit dem Konto verschwindet der Nachweis</td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <span class="avv-optional">Optional</span>
                            KI-Funktionen: Fach, Thema und Datum von Stunden, Notizen und Zusammenfassung der Lehrkraft, Fragen mit Erwartungshorizont, Arbeitsblätter als Bild oder PDF, Kompetenzen, anonyme Hinweise auf Lücken, Zusammenfassungen und Antworten der Schüler:innen mit der gewählten Stufe, „Erklär’s mir“-Gespräche, beim Tuto-Mikro Ton-Abschnitte und der Text für die Zusammenfassung. Alle Texte pseudonymisiert, ohne Benutzernamen, E-Mail-Adressen, Benutzerkennungen, Klassen- und Schulnamen
                        </th>
                        <td><span class="legal-cell-label">Betroffene</span> Lehrkräfte, Schüler:innen und weitere Personen, soweit sie in Texten, auf Arbeitsblättern oder im Ton vorkommen (Stimmen im Ton, Namen außerhalb der Klasse und Text in Bildern und PDF sind nicht pseudonymisiert)</td>
                        <td><span class="legal-cell-label">Zweck</span> Entwürfe für Zusammenfassung, Hausaufgaben, Förderaufgaben, Stufen und Einfache Sprache, Auswertung von Abgaben, Rückfragen und Einschätzung im „Erklär’s mir“-Chat, Spracherkennung und Zusammenfassung beim Tuto-Mikro</td>
                        <td>
                            <span class="legal-cell-label">Speicherdauer</span>
                            @if ($aiProvider['local'])
                                Die Plattform speichert nur die Ergebnisse, wie die zugehörigen Daten. Das Modell läuft auf
                                Servern des Auftragnehmers und speichert keine Anfragen.
                            @else
                                Die Plattform speichert nur die Ergebnisse, wie die zugehörigen Daten. Sie verlangt mit jeder
                                Anfrage, dass der Anbieter sie nicht für spätere Abrufe speichert, und führt dort keinen
                                Gesprächsverlauf. Wie lange der Anbieter Anfragen etwa zur Missbrauchserkennung aufbewahrt,
                                regeln seine Bedingungen (Anlage 3).
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h3>4. Automatische Löschungen</h3>
        <ul>
            <li>
                Unterrichtsstunden, „Erklär’s mir“-Gespräche und Einträge im Klassenbuch, die älter als die
                Aufbewahrungsfrist sind, löscht die Plattform täglich. Aufbewahrt werden {{ $keptPhrase }},
                ein Schuljahr beginnt am {{ $schoolYearStart }}. Fragen, Abgaben, Antworten, Nachrichten, Rückfragen und
                Arbeitsblatt-Dateien gehen mit. Konten, Klassen und Schulen bleiben bestehen.
                @if ($oneYearRuleFrom)
                    Übergang: Bis zum {{ $oneYearRuleFrom }} bleiben noch zwei Schuljahre gespeichert, weil die bis dahin
                    angenommenen Fassungen dieses Vertrags das zusagen. Danach wird jedes Schuljahr zu seinem Ende gelöscht.
                @endif
            </li>
            <li>
                Benachrichtigungen löscht die Plattform täglich {{ $notificationReadDays }} Tage nach dem Lesen, ungelesene
                spätestens {{ $notificationDays }} Tage nach dem Eingang.
            </li>
            <li>
                <span class="avv-optional">Optional</span>
                Nachweise über Sitzungen des Tuto-Mikros löscht die Plattform täglich {{ $mikroSessionDays }} Tage nach
                Beginn. Eine nicht beendete Sitzung nimmt nach {{ $mikroMaxSessionHours }} Stunden nichts mehr an.
            </li>
            <li>Nicht eingelöste Einträge zum Zurücksetzen des Passworts löscht die Plattform täglich.</li>
            <li>Die Fristen sind in der Konfiguration der Plattform festgelegt. Eine ungültige Einstellung löscht nichts.</li>
        </ul>
    </section>

    <section class="avv-annex" aria-labelledby="anlage-2">
        <h2 id="anlage-2">{{ $annexes['anlage-2'] }}</h2>
        <p>
            Maßnahmen nach Art. 32 DSGVO. Beschrieben ist, was die Plattform tatsächlich umsetzt. Angaben zum Betrieb der
            Server, die der Code nicht belegt, ergänzt der Auftragnehmer an den gelb markierten Stellen.
        </p>

        <h3>1. Vertraulichkeit</h3>
        <ul>
            <li>
                <strong>Zutritt:</strong> {{ $todo('Schutz der Räume und Server: Rechenzentrum, Zutrittsregelung, Zertifizierung') }}
            </li>
            <li>
                <strong>Zugang:</strong> Anmeldung mit Benutzername und Passwort. Das Passwort wird nur als Hash
                (bcrypt) gespeichert und braucht mindestens 8 Zeichen. Anmeldeversuche, Registrierungen und Anfragen zum
                Zurücksetzen des Passworts werden je IP-Adresse begrenzt.
            </li>
            <li>
                <strong>Sitzungen:</strong> Sie liegen in der Datenbank und laufen nach {{ $sessionMinutes }} Minuten ohne
                Aktivität ab. Das Sitzungs-Cookie ist für Skripte nicht lesbar (HttpOnly) und wird nur bei Anfragen von
                der Plattform selbst mitgeschickt (SameSite Lax).
                @if ($terms['secureCookie'])
                    Es wird nur über HTTPS gesendet.
                @else
                    Das Merkmal „Secure“ ist nicht gesetzt, verschlüsselt übertragen wird es über die HTTPS-Verbindung
                    nach Abschnitt 2.
                @endif
                Ändert jemand sein Passwort, werden die anderen Sitzungen dieses Kontos beendet.
            </li>
            <li>
                <strong>Gesperrte Schulen:</strong> Sperrt die Plattform-Administration eine Schule, kann sich niemand der
                Schule mehr anmelden.
            </li>
            <li>
                <strong>Zugriff:</strong> Rollen (Plattform-Administration, Schuladministration, Lehrkraft, Schüler:in) mit
                Berechtigungsregeln für jede Art von Daten. Fehlt für eine Ressource eine Regel, wird der Zugriff nicht
                freigegeben, sondern die Anfrage schlägt fehl. Schüler:innen sehen nur ihre eigenen Daten. Lehrkräfte
                sehen die Ergebnisse ihrer eigenen Stunden und die Lernstände, das Klassenbuch und die Einwilligungen der
                Klassen, die sie leiten, denen sie zugeordnet sind oder in denen sie gerade vertreten. Die Ergebnisse der
                eigenen Stunden sieht eine Lehrkraft auch dann noch, wenn sie der Klasse nicht mehr zugeordnet ist, die
                Lernstände, das Klassenbuch und die Einwilligungen der Klasse dann nicht mehr. Wer vertritt, darf für den
                Zeitraum der Vertretung (höchstens {{ $substitutionMaxDays }} Tage) {{ ClassSubstitution::grants('de') }},
                danach endet der Zugriff, auch auf die Ergebnisse der Stunden aus der Vertretung.
                Die Schuladministration sieht die Daten der eigenen Schule, die Plattform-Administration keine Unterrichts-
                und Lerndaten. Den Nachweis über eine Sitzung des Tuto-Mikros sieht nur die Lehrkraft der Stunde, auch
                nicht die Schuladministration.
            </li>
            <li>
                <strong>Trennung der Schulen:</strong> Konten, Klassen und Unterrichtsstunden gehören zu genau einer Schule.
                Die Berechtigungsregeln verhindern jeden Zugriff über Schulgrenzen hinweg. Rollen und Schulzuordnung
                lassen sich nur aus einer serverseitig geprüften Auswahl setzen. Eine Schuladministration legt Konten
                nur in der eigenen Schule an, und wer sich mit einem Klassencode selbst registriert, wird immer
                Schüler:in dieser Schule. Automatisierte Tests prüfen das.
            </li>
            <li>
                <strong>Verschlüsselung gespeicherter Daten:</strong> Die Anwendung verschlüsselt die Datenbank und die
                Arbeitsblätter nicht zusätzlich.
                {{ $todo('Verschlüsselung der Datenträger, der Datenbank und des Speichers (at rest) auf den Servern') }}
            </li>
            <li>
                <strong>Interne Zugriffe des Auftragnehmers:</strong> nur für Betrieb, Wartung und Fehlersuche.
                {{ $todo('technische Absicherung des Server- und Datenbankzugangs: Schlüssel, Mehrfaktor, Protokollierung') }}
            </li>
        </ul>

        <h3>2. Integrität</h3>
        <ul>
            <li>
                <strong>Übertragung:</strong> {{ $todo('Verschlüsselung der Verbindung (TLS): Zertifikat, Umleitung auf HTTPS') }}
                @unless ($aiProvider['local'])
                    Verbindungen zum KI-Anbieter laufen über HTTPS.
                @endunless
            </li>
            <li>
                <strong>Dateien:</strong> Arbeitsblätter liegen nicht öffentlich erreichbar auf dem Server und sind nur über
                die Plattform nach Prüfung der Berechtigung abrufbar. Ton des Tuto-Mikros wird weder als Datei noch in einer
                Warteschlange oder der Datenbank gespeichert.
            </li>
            <li>
                <strong>Gefälschte Anfragen:</strong> Formulare und Aktionen werden nur angenommen, wenn die Plattform sie
                selbst ausgelöst hat (CSRF-Schutz).
            </li>
            <li>
                <strong>Eingabekontrolle:</strong> Inhalte sind dem Konto zugeordnet, das sie angelegt hat. Änderungen und
                Löschungen von Daten werden nicht protokolliert. Technische Fehler werden im Fehlerprotokoll
                festgehalten.
            </li>
        </ul>

        <h3>3. Datenminimierung und Pseudonymisierung</h3>
        <ul>
            <li>
                Kein Tracking, keine Reichweitenmessung, keine Werbung und keine Inhalte von fremden Servern. Die
                Schriftart Lexend und die Platzhalter-Bilder mit den Initialen kommen von der Plattform selbst.
            </li>
            <li>Die E-Mail-Adresse eines Kontos ist freiwillig. Fehlzeiten im Klassenbuch werden ohne Gründe erfasst.</li>
            <li>
                Übersichten wie „Heute“ und die Zahl der Abgaben zeigen nur Anzahlen, keine Namen oder Ergebnisse.
                Benachrichtigungen nennen nur Thema, Fach und Klasse einer Stunde oder eine Anzahl.
            </li>
            <li>
                Die Wahl der Einfachen Sprache und die Einstellung „Namen verbergen“ beim Tuto-Mikro speichert nur der
                Browser. Sie werden nicht an den Server gesendet.
            </li>
            <li>
                <span class="avv-optional">Optional</span>
                <strong>Tuto-Mikro:</strong> Es nutzt nur das Mikrofon des Geräts der Lehrkraft, nur Ton. Der Server lässt
                es nur an, wenn die Schule es freigeschaltet hat, das Fach nicht ausgeschlossen ist, die Lehrkraft die
                Klasse leitet, unterrichtet oder vertritt, sie die anwesenden Schüler:innen markiert und bestätigt hat
                und für jede von ihnen eine gültige Einwilligung vorliegt. Bei jedem Ton-Abschnitt und vor jeder
                Zusammenfassung prüft er Einwilligungen und Schalter erneut. Ein Widerruf oder das Ausschalten wirkt
                spätestens beim nächsten Abschnitt. Ein Balken zeigt „Mikro an“ oder „Mikro aus“. Die Anzeige für den
                Beamer zeigt nur „Mikro an“ oder „Mikro aus“ und das Thema der Stunde, keine Namen und keinen Text.
            </li>
            <li>
                <span class="avv-optional">Optional</span>
                <strong>Pseudonymisierung vor jeder KI-Anfrage:</strong> Die Plattform schickt keine Namen, Benutzernamen,
                E-Mail-Adressen, Benutzerkennungen, Klassen- oder Schulnamen mit. In allen Texten ersetzt sie die Namen der
                Schüler:innen der Klasse durch „Schüler:in A“, „Schüler:in B“ und so weiter, die Namen der Lehrkräfte der
                Klasse durch „Lehrkraft“ und Namensteile, die mehrere Personen tragen, durch „Person“. Namen anderer
                Personen, Spitznamen, falsch geschriebene Namen und Text in Fotos und PDF-Dateien erkennt sie nicht. Die
                Ton-Abschnitte des Tuto-Mikros enthalten echte Stimmen und gesprochene Namen und gehen deshalb nur mit der
                Einwilligung aller Anwesenden in die Verarbeitung durch den KI-Anbieter an diesen.
            </li>
        </ul>

        <h3>4. Verfügbarkeit und Belastbarkeit</h3>
        <ul>
            <li>
                <strong>Betrieb:</strong> Die Plattform läuft als Docker-Container, bereitgestellt mit Coolify, die Daten
                liegen in einer MariaDB-Datenbank.
            </li>
            <li>
                <strong>Datensicherung:</strong> {{ $todo('Zeitplan, Aufbewahrungsdauer, Speicherort und Verschlüsselung der Backups') }}
            </li>
            <li>
                <strong>Wiederherstellung und Ausfälle:</strong> {{ $todo('Wiederherstellungsverfahren, Überwachung, Reaktion auf Ausfälle') }}
            </li>
            <li>
                <strong>Ausfall des KI-Anbieters:</strong> Die Plattform zeigt dann einen Fehler an und schickt die Daten
                nicht an einen anderen Anbieter.
            </li>
        </ul>

        <h3>5. Löschung und Betroffenenrechte</h3>
        <ul>
            <li>Tägliche automatische Löschung nach Aufbewahrungsfristen (Anlage 1, Abschnitt 4).</li>
            <li>
                Jede Person kann die Daten zu ihrem Konto als Datei herunterladen, ohne Daten anderer Personen (Nummer
                9.3). Die Schuladministration kann Konten ihrer Schule löschen, die zugehörigen Abgaben, Gespräche und
                Rückfragen werden mitgelöscht.
            </li>
        </ul>

        <h3>6. Auftragskontrolle und Organisation</h3>
        <ul>
            <li>
                Weisungen des Auftraggebers in Textform, Unterauftragsverarbeiter nur nach Anlage 3 mit Vertrag nach
                Art. 28 DSGVO.
            </li>
            <li>
                <strong>Verpflichtung und Schulung der Beschäftigten:</strong> {{ $todo('Verpflichtung zur Vertraulichkeit, Datenschutzschulung, Zahl und Rolle der Zugriffsberechtigten') }}
            </li>
            <li>
                <strong>Umgang mit Datenschutzverletzungen:</strong> Der Auftragnehmer prüft jeden Hinweis auf einen
                Vorfall unverzüglich, dokumentiert ihn mit Bewertung und Maßnahmen und meldet ihn nach Nummer 10.2.
            </li>
            <li>
                <strong>Überprüfung der Wirksamkeit (Art. 32 Abs. 1 lit. d DSGVO):</strong> Automatisierte Tests prüfen bei
                jeder Änderung unter anderem Rechte, Trennung der Schulen, Löschfristen, Einwilligungsprüfung und den
                Datenexport. Sicherheitsupdates der eingesetzten Software spielt der Auftragnehmer zeitnah ein, die
                Maßnahmen überprüft er nach Nummer 7.4.
            </li>
        </ul>
    </section>

    <section class="avv-annex" aria-labelledby="anlage-3">
        <h2 id="anlage-3">{{ $annexes['anlage-3'] }}</h2>
        <p>
            Der Auftraggeber genehmigt die folgenden Unterauftragsverarbeiter (Nummer 8). Der Anbieter der KI-Funktionen
            erhält Daten eines Auftraggebers nur, solange dessen Schuladministration die KI-Funktionen eingeschaltet hat
            (Nummer 8.3).
        </p>

        <div class="legal-table-wrap" role="region" aria-label="Unterauftragsverarbeiter" tabindex="0">
            <table class="legal-table">
                <caption>Unterauftragsverarbeiter</caption>
                <thead>
                    <tr>
                        <th scope="col">Dienstleister</th>
                        <th scope="col">Leistung und Daten</th>
                        <th scope="col">Ort und Garantien</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th scope="row">Hosting: {{ $todo('Name und Anschrift des Hosting-Anbieters und des Rechenzentrums, oder Eigenbetrieb auf eigenen Servern und dann die Zeile streichen') }}</th>
                        <td><span class="legal-cell-label">Leistung und Daten</span> Bereitstellung der Server, Datenbank und Speicher, alle Daten der Plattform</td>
                        <td><span class="legal-cell-label">Ort und Garantien</span> Deutschland, {{ $todo('Vertrag nach Art. 28 DSGVO mit dem Anbieter, Datum und Fundstelle') }}</td>
                        <td><span class="legal-cell-label">Status</span> {{ $todo('im Einsatz oder entfällt') }}</td>
                    </tr>
                    <tr>
                        <th scope="row">E-Mail-Versand: {{ $todo('Name des E-Mail-Dienstes, falls ein externer Dienst genutzt wird, sonst Zeile streichen') }}</th>
                        <td><span class="legal-cell-label">Leistung und Daten</span> Zustellung der E-Mails zum Zurücksetzen des Passworts: Empfängeradresse und Link</td>
                        <td><span class="legal-cell-label">Ort und Garantien</span> {{ $todo('Standort und Vertrag') }}</td>
                        <td><span class="legal-cell-label">Status</span> {{ $todo('im Einsatz oder entfällt') }}</td>
                    </tr>
                    @unless ($aiProvider['local'])
                        <tr>
                            <th scope="row">
                                <span class="avv-optional">Optional</span>
                                {{ $aiProvider['name'] }} (KI-Funktionen)
                            </th>
                            <td>
                                <span class="legal-cell-label">Leistung und Daten</span>
                                {{ $aiProvider['service'] }}: Erzeugen von Entwürfen, Auswertungen und Antworten aus
                                pseudonymisierten Texten und Arbeitsblättern als Bild oder PDF, Spracherkennung der
                                Ton-Abschnitte des Tuto-Mikros (Sprache Deutsch, ohne Trennung der Sprecher), Daten nach
                                Anlage 1. Keine Nutzung der Eingaben zur Verbesserung der Produkte des Anbieters oder zum
                                Training.
                            </td>
                            <td>
                                <span class="legal-cell-label">Ort und Garantien</span>
                                {{ $aiProvider['place'] }} {{ $aiProvider['safeguards'] }}
                            </td>
                            <td>
                                <span class="legal-cell-label">Status</span>
                                @if ($aiEnabled)
                                    im Einsatz nur für Auftraggeber, deren Schuladministration die KI-Funktionen eingeschaltet hat
                                @else
                                    nicht im Einsatz: Die KI-Anbindung der Plattform ist abgeschaltet, keine Schule kann die KI-Funktionen einschalten
                                @endif
                            </td>
                        </tr>
                    @endunless
                </tbody>
            </table>
        </div>
    </section>
