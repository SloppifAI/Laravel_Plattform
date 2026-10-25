@extends('filament.legal.layout')

@php
    use App\Filament\Pages\AiSettings;
    use App\Models\AppAuthorizationCode;
    use App\Models\ClassSubstitution;
    use App\Models\School;
    use App\Models\User;
    use Carbon\CarbonImmutable;
    use Filament\AvatarProviders\UiAvatarsProvider;
    use Filament\Facades\Filament;
    use Filament\Models\Contracts\HasAvatar;

    /*
     * One list drives the table of contents and the numbered headings, so they cannot drift apart; references to other
     * sections use $no(). tests/Feature/LegalPagesTest keeps both in sync.
     */
    $sections = [
        'verantwortlicher' => 'Verantwortlicher',
        'ueberblick' => 'Überblick',
        'hosting' => 'Hosting und Server-Logfiles',
        'cookies' => 'Cookies und lokaler Speicher',
        'benutzerkonten' => 'Schulen und Benutzerkonten',
        'lerndaten' => 'Unterrichts- und Lerndaten',
        'ki' => 'Einsatz von KI',
        'tuto-mikro' => 'Tuto-Mikro',
        'apps' => 'Verbundene Apps',
        'schriftarten' => 'Schriftarten und externe Inhalte',
        'kontakt' => 'Kontakt per E-Mail',
        'speicherdauer' => 'Speicherdauer',
        'kinder' => 'Kinder und Jugendliche',
        'rechte' => 'Rechte der betroffenen Personen',
        'beschwerde' => 'Beschwerderecht',
        'aenderungen' => 'Änderungen dieser Erklärung',
    ];
    $no = fn (string $id): int => array_search($id, array_keys($sections), true) + 1;
    $heading = fn (string $id): string => $no($id).'. '.$sections[$id];

    $sessionMinutes = (int) config('session.lifetime');
    $keptYears = (int) config('lernplattform.retention.school_years_kept');
    $keptPhrase = match (true) {
        $keptYears <= 1 => 'das laufende Schuljahr',
        $keptYears === 2 => 'das laufende und das vorherige Schuljahr',
        default => 'das laufende und die '.($keptYears - 1).' vorherigen Schuljahre',
    };
    $oneYearRuleFrom = (string) config('lernplattform.retention.one_year_rule_from');
    $transitionDate = $keptYears === 1 && CarbonImmutable::hasFormat($oneYearRuleFrom, 'Y-m-d')
        ? CarbonImmutable::parse($oneYearRuleFrom)->format('d.m.Y')
        : null;
    $mikroSessionDays = (int) config('lernplattform.recordings.session_keep_days');
    $mikroMaxSessionHours = (int) config('lernplattform.recordings.max_session_hours');
    $notificationReadDays = (int) config('lernplattform.notifications.keep_read_days');
    $notificationDays = (int) config('lernplattform.notifications.keep_days');
    $substitutionMaxDays = (int) config('lernplattform.substitutions.max_days');
    // Connected apps: the one-time code's lifetime and the access's lifetime (sanctum.expiration is in minutes, null for none).
    $appCodeMinutes = AppAuthorizationCode::LIFETIME_MINUTES;
    $appAccessDays = config('sanctum.expiration') ? (int) ceil((int) config('sanctum.expiration') / 1440) : null;

    // The platform switch; every school still decides for itself (School::aiEnabled()).
    $aiEnabled = (bool) config('lernplattform.ai.enabled');
    $aiProvider = (string) config('lernplattform.ai.provider');
    $aiIsLocal = $aiProvider === 'ollama';
    $aiIsGoogle = $aiProvider === 'gemini';
    $blockedSubjects = implode(', ', School::DEFAULT_MIKRO_BLOCKED_SUBJECTS);

    /*
     * Filament's default avatar provider builds an https://ui-avatars.com URL, so the browser asks that host for
     * the initials image. The text below follows the panel's real setting: it discloses the request while the panel
     * makes it and says nothing once the panel serves the avatar itself.
     */
    $externalAvatars = Filament::getPanel('lernplattform')->getDefaultAvatarProvider() === UiAvatarsProvider::class
        && ! is_subclass_of(User::class, HasAvatar::class);
@endphp

@section('title', 'Datenschutzerklärung')
@section('description', 'Wie Tuto personenbezogene Daten verarbeitet und welche Rechte Sie haben')

@section('content')
    <h1>Datenschutzerklärung</h1>
    <p><strong>Stand: Oktober 2026</strong></p>
    <p>
        Diese Erklärung informiert Sie nach Art. 13 und 14 DSGVO darüber, welche personenbezogenen Daten auf der
        Lernplattform Tuto verarbeitet werden, wozu das geschieht und welche Rechte Sie haben. Sie richtet sich an
        Schüler:innen, Eltern, Lehrkräfte und die Verwaltung.
    </p>

    <div class="legal-note">
        <p class="legal-note-title">Demo und Pilot</p>
        <p>
            Die Plattform läuft derzeit als Demo und Pilot und enthält ausschließlich erfundene Daten. Bitte geben Sie
            keine echten personenbezogenen Daten ein.
        </p>
    </div>

    <nav class="legal-toc" aria-labelledby="inhaltsverzeichnis">
        <p class="legal-note-title" id="inhaltsverzeichnis">Inhalt</p>
        <ol>
            @foreach ($sections as $id => $title)
                <li><a href="#{{ $id }}">{{ $title }}</a></li>
            @endforeach
        </ol>
    </nav>

    <h2 id="verantwortlicher">{{ $heading('verantwortlicher') }}</h2>
    <p>
        <strong>Demo- und Pilotbetrieb.</strong> Für die Verarbeitung personenbezogener Daten auf dieser Plattform ist
        derzeit verantwortlich im Sinne der DSGVO:
    </p>
    <address>
        Sebastian Bleuel<br>
        Softwareentwicklung Sebastian Bleuel<br>
        Ortesweg 10<br>
        36145 Hofbieber<br>
        Deutschland<br>
        Telefon: <a href="tel:+4915732599053">0157 32599053</a><br>
        E-Mail: <a href="mailto:info@sebastian-bleuel.de">info@sebastian-bleuel.de</a>
    </address>
    <p>Weitere Angaben zum Anbieter finden Sie im <a href="{{ route('legal.imprint') }}">Impressum</a>.</p>
    <p>
        <strong>Regulärer Schulbetrieb.</strong> Setzt eine Schule Tuto im Unterricht ein, ist die Schule beziehungsweise
        der Schulträger verantwortlich, weil sie über Zwecke und Mittel der Verarbeitung entscheiden. Wir verarbeiten die
        Daten dann als Auftragsverarbeiter nach Art. 28 DSGVO, auf Grundlage eines
        <a href="{{ route('legal.dpa') }}">Vertrags zur Auftragsverarbeitung</a> und nur nach Weisung der Schule. Erste
        Ansprechpartnerin für Fragen zum Datenschutz ist dann die Schule. Ihre Kontaktdaten und die ihrer
        Datenschutzbeauftragten stehen in den Datenschutzhinweisen der Schule.
    </p>
    <p>
        <strong>Registrierung einer Schule.</strong> Für die Angaben, die eine Schule bei ihrer Registrierung macht (die
        Stammdaten der Schule und der Nachweis, dass sie den Vertrag zur Auftragsverarbeitung angenommen hat), und
        für die Verwaltung der Schulen auf der Plattform (Abschnitt {{ $no('benutzerkonten') }}) sind wir selbst
        verantwortlich. Wir schließen damit die Nutzung der Plattform mit der Schule ab und halten die Plattform sicher.
        Das Konto der Schuladministration selbst, auch das erste, ist ein Konto der Schule: Dessen Daten verarbeiten wir
        im Auftrag der Schule.
    </p>

    <h2 id="ueberblick">{{ $heading('ueberblick') }}</h2>
    <p>
        Tuto unterstützt das Lernen. Lehrkräfte halten fest, was sie im Unterricht behandelt haben, und führen das
        Klassenbuch. Tuto entwirft daraus passende Hausaufgaben, auf Wunsch in Stufen und in Einfacher Sprache.
        Schüler:innen sehen, was sie schon verstanden haben und wo sie noch üben sollten, und können ihrer Lehrkraft
        Rückfragen stellen.
    </p>
    <ul>
        <li>
            Tuto zeichnet keinen Unterricht auf, und es gibt kein Bild und kein Video. Lehrkräfte tragen selbst Notizen
            oder ein Tafelbild ein und können Arbeitsblätter als Foto, Scan oder PDF hochladen. Nur wenn die Schule das
            Tuto-Mikro freischaltet und die Einwilligungen vorliegen, kann eine Lehrkraft das Mikrofon ihres Geräts
            einschalten. Daraus entstehen Notizen aus der Stunde, der Ton wird dabei nicht gespeichert (siehe Abschnitt
            {{ $no('tuto-mikro') }}).
        </li>
        <li>
            KI nutzt Tuto nur, wenn die Schule die KI-Funktionen selbst einschaltet. Namen werden vorher ersetzt (siehe
            Abschnitt {{ $no('ki') }}).
        </li>
        <li>
            Es gibt kein Tracking: keine Reichweitenmessung, keine Werbung und keine Schriftarten von fremden Servern.
            @if ($externalAvatars)
                Nur das Platzhalter-Bild neben dem Namen kommt von einem fremden Server (siehe Abschnitt {{ $no('schriftarten') }}).
            @else
                Es werden auch keine Inhalte von Dritten eingebettet.
            @endif
        </li>
        <li>
            Jede Schule registriert sich selbst auf der Plattform. Alles, was zu einer Schule gehört, also Klassen,
            Konten, Unterrichtsstunden, Abgaben und Gespräche, sehen nur Personen dieser Schule. Schulen sehen nichts
            voneinander (siehe Abschnitt {{ $no('benutzerkonten') }} und {{ $no('lerndaten') }}).
        </li>
        <li>
            Schüler:innen können sich mit dem Klassencode ihrer Lehrkraft selbst registrieren. Konten für Lehrkräfte und
            weitere Personen legt die Schuladministration der Schule an (siehe Abschnitt {{ $no('benutzerkonten') }}).
        </li>
        <li>
            Lehrkräfte und Schuladministrationen können eine App mit ihrem Konto verbinden. Die App sieht nur
            Stunden und Klassen der Person und von Schüler:innen nur die Namen, mit denen sie Texte auf dem Gerät
            pseudonymisiert. Die Person kann den Zugang jederzeit entziehen (siehe Abschnitt {{ $no('apps') }}).
        </li>
    </ul>

    <aside class="legal-note" aria-labelledby="kurz-fuer-schueler">
        <p class="legal-note-title" id="kurz-fuer-schueler">Kurz erklärt für Schüler:innen</p>
        <ul>
            <li>
                Tuto kennt deinen Namen, deinen Benutzernamen, deine Klasse, die Sprache, die du gewählt hast, und dein
                Passwort. Dein Passwort wird nur in verschlüsselter Form (als Hash) gespeichert, Tuto kann es nicht
                nachlesen. Hat die Schuladministration deiner Schule dein Konto angelegt, hat sie dein Startpasswort einmal
                gesehen. Ändere es dann in deinem Profil.
            </li>
            <li>
                Eine E-Mail-Adresse musst du nicht angeben. Wir brauchen sie nur, um dir einen Link zu schicken, wenn du
                dein Passwort vergessen hast.
            </li>
            <li>
                Alles, was du abgibst, wird gespeichert: deine Zusammenfassungen, deine Antworten mit der Stufe, die du
                gewählt hast, deine Gespräche mit Tuto und deine Rückfragen an die Lehrkraft. Deine Lehrkraft sieht deine
                Abgaben, Rückfragen und Gespräche, die zu ihren Stunden gehören. Lehrkräfte, die deine Klasse leiten,
                unterrichten oder gerade vertreten, sehen außerdem deine Punktwerte und die Rückmeldungen zu allen deinen
                Hausaufgaben und im Klassenbuch, an welchen Tagen du gefehlt hast. Auch die Schuladministration deiner
                Schule kann das sehen. Die Plattform-Administration, die die Plattform betreut, sieht deine Daten nicht.
                Andere Schüler:innen und andere Schulen sehen es nie.
            </li>
            <li>Tuto ist ein Computerprogramm und kein Mensch.</li>
            <li>
                Hat deine Schule die KI-Funktionen eingeschaltet, gehen deine Texte an einen KI-Anbieter. Vorher ersetzt
                Tuto die Namen aus deiner Klasse, zum Beispiel durch „Schüler:in A“.
            </li>
            <li>
                Ob du Einfache Sprache eingeschaltet hast, merkt sich nur dein Browser. Tuto speichert das nicht.
            </li>
            <li>
                Beim Tuto-Mikro ist nur das Mikrofon deiner Lehrkraft an. Der Ton wird nicht gespeichert. Es läuft nur,
                wenn deine Eltern eingewilligt haben, und ein Balken zeigt, ob es an ist.
            </li>
            <li>
                Deine Lehrkraft oder die Schuladministration kann eine App mit ihrem Konto verbinden. Die App sieht nur
                Stunden und Klassen dieser Person und von dir nur deinen Namen. Damit ersetzt sie deinen Namen auf dem
                Gerät dieser Person durch einen Platzhalter, bevor ein Text das Gerät verlässt. Die Zusammenfassung, die du
                vor den Fragen liest, kann von so einer App stammen.
            </li>
            <li>
                Der Verständniswert von 0 bis 100 ist keine Note. Er zeigt nur, wo du vielleicht noch Hilfe brauchen
                kannst. Deine Lehrkraft entscheidet, was daraus folgt.
            </li>
            <li>
                Schreib bitte keine privaten Dinge hinein, zum Beispiel deine Adresse, deine Telefonnummer oder Fotos von
                dir.
            </li>
            <li>
                Du und deine Eltern dürft jederzeit fragen, welche Daten über dich gespeichert sind, und verlangen, dass
                sie gelöscht werden. Frag dafür deine Lehrkraft oder deine Schule. In deinem Profil kannst du mit „Meine
                Daten herunterladen“ selbst nachsehen.
            </li>
        </ul>
    </aside>

    <h2 id="hosting">{{ $heading('hosting') }}</h2>
    <h3>Hosting</h3>
    <p>
        Die Plattform läuft auf eigenen beziehungsweise angemieteten Servern in Deutschland. Sie wird als Docker-Container
        betrieben und mit Coolify bereitgestellt, die Daten liegen in einer MariaDB-Datenbank. Beim Hosting werden keine
        Daten in Länder außerhalb der EU übermittelt. Soweit ein externer Betreiber die Server bereitstellt, verarbeitet er
        Daten nur nach unserer Weisung und auf Grundlage eines Vertrags nach Art. 28 DSGVO.
    </p>
    <h3>Server-Logfiles und Fehlerprotokoll</h3>
    <p>
        Bei jedem Aufruf der Plattform verarbeitet der Webserver technisch notwendige Daten: die IP-Adresse, Datum und
        Uhrzeit, die aufgerufene Adresse, den Statuscode, die übertragene Datenmenge sowie Angaben zu Browser und
        Betriebssystem. Wir brauchen sie, um die Seiten auszuliefern, den Betrieb stabil zu halten, Fehler zu finden und
        Angriffe abzuwehren. Ruft jemand die Einwilligungsseite für das Tuto-Mikro auf, steht deren Adresse mit dem darin
        enthaltenen Code ebenfalls in diesem Zugriffsprotokoll. Tuto selbst speichert dazu weder den Code im Klartext noch
        die IP-Adresse oder die Browserangabe.
    </p>
    <p>
        Auch die Plattform selbst schreibt ein Protokoll mit technischen Fehlermeldungen. Es dient der Fehlersuche. Tritt
        ein Fehler auf, können darin Angaben zur betroffenen Anfrage stehen, zum Beispiel die Benutzerkennung. Anfragen an
        den KI-Anbieter und seine Antworten schreibt Tuto nicht in dieses Protokoll.
    </p>
    <p>
        Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO. Unser berechtigtes Interesse ist ein sicherer und fehlerfreier
        Betrieb. Wir bewahren die Logdaten nur so lange auf, wie wir sie für Betrieb, Fehlersuche und Abwehr von Angriffen
        brauchen, und löschen sie dann. Bei einem Sicherheitsvorfall bewahren wir die betroffenen Einträge so lange auf,
        bis er geklärt ist.
    </p>

    <h2 id="cookies">{{ $heading('cookies') }}</h2>
    <p>
        Die Plattform verwendet nur technisch notwendige Cookies sowie Einträge im lokalen Speicher und im Sitzungsspeicher
        Ihres Browsers. Sie sind nötig, damit die Anmeldung funktioniert und Formulare sicher abgeschickt werden können,
        oder sie merken sich Einstellungen zur Darstellung. Cookies für Analyse, Reichweitenmessung oder Werbung gibt es
        nicht. Die Seiten Impressum und Datenschutzerklärung und die Einwilligungsseite für Eltern setzen selbst keine
        Cookies.
    </p>
    <p>
        Das Speichern und Auslesen solcher Informationen ist nach § 25 Abs. 2 Nr. 2 TDDDG ohne Einwilligung erlaubt, weil
        es unbedingt erforderlich ist, um den von Ihnen ausdrücklich gewünschten Dienst bereitzustellen. Da nichts
        Optionales eingesetzt wird, ist kein Cookie-Banner nötig. Die dabei anfallende Verarbeitung personenbezogener
        Daten stützt sich auf Art. 6 Abs. 1 lit. f DSGVO, im Schulbetrieb auf lit. e (siehe Abschnitt {{ $no('benutzerkonten') }}).
    </p>
    <p>
        Das Sitzungs-Cookie und das XSRF-Cookie setzt die Plattform schon, wenn Sie die Anmeldeseite, die Registrierung
        oder die Seite „Passwort vergessen“ öffnen, also auch ohne Konto. Zur Sitzung speichert der Server in der
        Datenbank die Sitzungskennung, die IP-Adresse, die Browserangabe und den Zeitpunkt der letzten Aktivität, bis die
        Sitzung nach {{ $sessionMinutes }} Minuten ohne Aktivität abläuft. Wählen Sie auf der Anmeldeseite eine Sprache,
        merkt sich der Server diese Wahl in der Sitzung, nach der Anmeldung in Ihrem Konto. Das dient der Sicherheit, dem
        Betrieb der Anmeldung und der Anzeige in Ihrer Sprache (Art. 6 Abs. 1 lit. f DSGVO).
    </p>

    <h3>Cookies</h3>
    <div class="legal-table-wrap" role="region" aria-label="Cookies der Plattform" tabindex="0">
        <table class="legal-table">
            <caption>Cookies der Plattform</caption>
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Zweck</th>
                    <th scope="col">Gültigkeit</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <th scope="row"><code>{{ config('session.cookie') }}</code></th>
                    <td>
                        <span class="legal-cell-label">Zweck</span>
                        Wird gesetzt, sobald Sie die Anmeldeseite, die Registrierung oder die Seite „Passwort vergessen“
                        öffnen, und hält nach der Anmeldung Ihre Anmeldung aufrecht. Das Cookie enthält nur eine zufällige
                        Sitzungskennung. Die Sitzung selbst liegt in der Datenbank auf dem Server.
                    </td>
                    <td><span class="legal-cell-label">Gültigkeit</span> {{ $sessionMinutes }} Minuten nach der letzten Aktivität, spätestens bis zur Abmeldung</td>
                </tr>
                <tr>
                    <th scope="row"><code>XSRF-TOKEN</code></th>
                    <td>
                        <span class="legal-cell-label">Zweck</span>
                        Schützt vor gefälschten Anfragen (Cross-Site-Request-Forgery). Formulare und Aktionen werden nur
                        angenommen, wenn sie von der Plattform selbst ausgelöst wurden.
                    </td>
                    <td><span class="legal-cell-label">Gültigkeit</span> wie das Sitzungs-Cookie</td>
                </tr>
                <tr>
                    <th scope="row"><code>remember_web_</code> mit angehängter Kennung</th>
                    <td>
                        <span class="legal-cell-label">Zweck</span>
                        Nur wenn Sie bei der Anmeldung „Angemeldet bleiben“ ankreuzen. Speichert eine verschlüsselte
                        Kennung, damit Sie ohne neue Anmeldung wiedererkannt werden.
                    </td>
                    <td><span class="legal-cell-label">Gültigkeit</span> bis zu 400 Tage, spätestens bis zur Abmeldung</td>
                </tr>
            </tbody>
        </table>
    </div>
    <p>
        Auf gemeinsam genutzten Geräten, zum Beispiel in der Schule, sollten Sie „Angemeldet bleiben“ nicht wählen.
    </p>

    <h3>Lokaler Speicher und Sitzungsspeicher des Browsers</h3>
    <div class="legal-table-wrap" role="region" aria-label="Einträge im Browser-Speicher" tabindex="0">
        <table class="legal-table">
            <caption>Einträge im lokalen Speicher und im Sitzungsspeicher des Browsers</caption>
            <thead>
                <tr>
                    <th scope="col">Schlüssel</th>
                    <th scope="col">Speicher</th>
                    <th scope="col">Zweck</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <th scope="row"><code>filament:theme:mode</code></th>
                    <td><span class="legal-cell-label">Speicher</span> lokaler Speicher</td>
                    <td><span class="legal-cell-label">Zweck</span> Ihre Wahl zwischen hellem, dunklem oder vom System vorgegebenem Erscheinungsbild</td>
                </tr>
                <tr>
                    <th scope="row"><code>filament:sidebar:open</code>, <code>filament:sidebar:desktop_open</code></th>
                    <td><span class="legal-cell-label">Speicher</span> lokaler Speicher</td>
                    <td><span class="legal-cell-label">Zweck</span> Ob die Seitenleiste geöffnet oder eingeklappt ist</td>
                </tr>
                <tr>
                    <th scope="row"><code>filament:sidebar:collapsed_groups</code></th>
                    <td><span class="legal-cell-label">Speicher</span> lokaler Speicher</td>
                    <td><span class="legal-cell-label">Zweck</span> Welche Gruppen der Seitenleiste eingeklappt sind</td>
                </tr>
                <tr>
                    <th scope="row"><code>tuto-einfache-sprache-</code> mit angehängter Kontokennung</th>
                    <td><span class="legal-cell-label">Speicher</span> lokaler Speicher</td>
                    <td><span class="legal-cell-label">Zweck</span> Nur bei Schüler:innen: ob bei Hausaufgaben die Einfache Sprache eingeschaltet ist (1 oder 0)</td>
                </tr>
                <tr>
                    <th scope="row"><code>tuto-mikro-hide-names</code></th>
                    <td><span class="legal-cell-label">Speicher</span> lokaler Speicher</td>
                    <td><span class="legal-cell-label">Zweck</span> Nur bei Lehrkräften: ob beim Tuto-Mikro die Namen verborgen sind (1 oder 0)</td>
                </tr>
                <tr>
                    <th scope="row"><code>filament.notifications.redirect</code></th>
                    <td><span class="legal-cell-label">Speicher</span> Sitzungsspeicher</td>
                    <td>
                        <span class="legal-cell-label">Zweck</span>
                        Merkt sich kurz eine Meldung über einen Seitenwechsel hinweg und wird danach wieder entfernt
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <p>
        Diese Einträge bleiben in Ihrem Browser und werden nicht an unseren Server gesendet. Die Einträge im lokalen
        Speicher bleiben bestehen, bis Sie die Website-Daten Ihres Browsers löschen. Der Eintrag im Sitzungsspeicher
        verschwindet spätestens, wenn Sie den Tab schließen. Impressum und Datenschutzerklärung lesen die Einstellung zum
        Erscheinungsbild nur aus, um hell oder dunkel passend anzuzeigen. Sie schreiben nichts in den Browser-Speicher.
    </p>
    <p>
        Sie können Cookies und Website-Daten jederzeit in Ihrem Browser löschen oder blockieren. Ohne das
        Sitzungs-Cookie ist keine Anmeldung möglich.
    </p>

    <h2 id="benutzerkonten">{{ $heading('benutzerkonten') }}</h2>
    <p>
        Wer die Plattform nutzen will, braucht ein Konto, und jedes Konto gehört zu einer Schule. Nur die
        Plattform-Administration gehört zu keiner Schule (siehe unten). Ein Konto entsteht auf einem von drei Wegen:
    </p>
    <ul>
        <li>
            <strong>Eine Schule registriert sich.</strong> Dabei entstehen die Schule auf der Plattform und das erste
            Konto ihrer Schuladministration (siehe „Registrierung einer Schule“).
        </li>
        <li>
            <strong>Die Schuladministration legt es an</strong>, einzeln oder durch den Import einer Schülerliste, die
            die Schulsoftware als CSV-Datei exportiert. Auf diesem Weg entstehen die Konten von Lehrkräften und weiteren
            Administrationen der Schule.
        </li>
        <li>
            <strong>Schüler:innen registrieren sich selbst.</strong> Auf der Anmeldeseite geben sie unter „Mit Klassencode
            registrieren“ ihren Namen, einen Benutzernamen, ein Passwort und den Klassencode ihrer Lehrkraft ein, auf
            Wunsch auch eine E-Mail-Adresse. Das Konto wird immer als Schüler:in angelegt und der Klasse und der Schule
            zugeordnet, zu denen der Code gehört. Den Code selbst speichern wir nicht im Konto.
        </li>
    </ul>
    <p>
        Bei der Selbstregistrierung prüft vorab niemand, ob die Person wirklich zur Klasse gehört. Wer den Klassencode
        kennt, kann ein Schülerkonto in dieser Klasse erstellen. Lehrkräfte geben den Code deshalb nur an ihre Klasse
        weiter. Die Schuladministration kann Konten, die nicht dazugehören, löschen.
    </p>
    <h3>Welche Daten verarbeitet werden</h3>
    <ul>
        <li>Name und Benutzername</li>
        <li>
            optional eine E-Mail-Adresse, ausschließlich für den Link zum Zurücksetzen des Passworts (siehe unten)
        </li>
        <li>das Passwort, das nur als Hash gespeichert wird und nicht lesbar ist</li>
        <li>die Rolle: Plattform-Administration, Schuladministration, Lehrkraft oder Schüler:in</li>
        <li>die Schule, zu der das Konto gehört, bei Schüler:innen die Klasse</li>
        <li>
            bei Lehrkräften die Klassen, die sie leiten oder denen sie zugeordnet sind, und Vertretungen mit Zeitraum,
            Hinweis und der Person, die sie eingerichtet hat
        </li>
        <li>
            der Stundenplan der Klassen: Fach, Raum und die eingetragene Lehrkraft je Wochentag und Stunde. Schüler:innen
            sehen den Plan ihrer Klasse mit den Namen der Lehrkräfte, Lehrkräfte ihre eigenen Stunden, die Klassenleitung
            den ganzen Plan ihrer Klasse und die Schuladministration alle Pläne der Schule
        </li>
        <li>die gewählte Sprache der Oberfläche (Deutsch oder Englisch), solange jemand eine gewählt hat</li>
        <li>
            bei Lehrkräften und Schuladministrationen, die eine App verbunden haben, die Zugänge dieser Apps (siehe
            Abschnitt {{ $no('apps') }})
        </li>
        <li>
            bei der Schuladministration, wer das Tuto-Mikro und die KI-Funktionen der Schule wann eingeschaltet hat
        </li>
        <li>
            technische Sitzungsdaten: Sitzungskennung, bei angemeldeten Personen die Benutzerkennung, dazu IP-Adresse,
            Browserangabe und Zeitpunkt der letzten Aktivität (siehe Abschnitt {{ $no('cookies') }})
        </li>
    </ul>
    <p>
        Ohne Name, Benutzername, Passwort und Rolle kann kein Konto angelegt werden. Die E-Mail-Adresse ist freiwillig.
    </p>
    <h3>Wozu</h3>
    <p>
        Die Daten dienen der Anmeldung, der Zuordnung von Inhalten zu Personen und Klassen, der Vergabe von Rechten nach
        Rolle und Klasse, der Anzeige in der gewählten Sprache, dem Zurücksetzen vergessener Passwörter und der Sicherheit
        der Plattform. Die Schuladministration sieht alle Konten ihrer Schule. Lehrkräfte sehen die Namen der
        Schüler:innen der Klassen, die sie leiten, unterrichten oder vertreten. Die Plattform-Administration sieht nur die
        Konten der Schuladministrationen. An Dritte geben wir diese Daten nicht
        weiter{{ $externalAvatars ? ', abgesehen vom Platzhalter-Bild (siehe Abschnitt '.$no('schriftarten').')' : '' }}.
    </p>

    <h3>Registrierung einer Schule</h3>
    <p>
        Eine Schule legt sich selbst auf der Plattform an. Dabei werden diese Angaben verarbeitet:
    </p>
    <ul>
        <li>
            die Daten der Schule: Name, Ort und Bundesland, auf Wunsch auch Schulnummer, Schulform und eine
            Kontakt-E-Mail-Adresse der Schule
        </li>
        <li>
            die Daten des ersten Kontos der Schuladministration: Name, Benutzername und Passwort (nur als Hash
            gespeichert), gegebenenfalls eine E-Mail-Adresse. Dieses Konto gehört danach zur Schule, wir verarbeiten es in
            deren Auftrag.
        </li>
        <li>
            die Annahme des <a href="{{ route('legal.dpa') }}">Vertrags zur Auftragsverarbeitung</a>: Version, Zeitpunkt,
            der Vertragstext, wie die Schule ihn gesehen hat, sowie Name und Benutzername der annehmenden Person. Dieser
            Nachweis bleibt auch bestehen, wenn das Konto später gelöscht wird.
        </li>
        <li>der Zeitpunkt der Registrierung und, falls die Schule gesperrt wird, der Zeitpunkt der Sperrung</li>
    </ul>
    <p>
        <strong>Wozu:</strong> Die Angaben dienen dazu, die Schule und ihre erste Schuladministration anzulegen, die
        Daten jeder Schule von denen anderer Schulen zu trennen, die Schule bei Fragen zum Betrieb zu erreichen und eine
        Schule sperren zu können, zum Beispiel bei Missbrauch oder auf Wunsch der Schule. Gesperrt kann sich niemand der
        Schule mehr anmelden. Die Daten bleiben dabei gespeichert, bis sie gelöscht werden oder die Sperre aufgehoben
        wird. Bis die Schuladministration den Vertrag zur Auftragsverarbeitung angenommen hat, kann die Schule nur die
        Seite „Meine Schule“ nutzen.
    </p>
    <p>
        <strong>Rechtsgrundlage:</strong> Art. 6 Abs. 1 lit. b DSGVO, soweit die Schule mit uns die Nutzung der
        Plattform vereinbart, und Art. 6 Abs. 1 lit. f DSGVO für den sicheren Betrieb der Plattform, die Trennung der
        Schulen voneinander und den Nachweis des Vertrags. Die Stammdaten der Schule sehen die Plattform-Administration (alle Schulen) und die
        Schuladministration der Schule (nur die eigene Schule).
    </p>

    <h3>Plattform-Administration</h3>
    <p>
        Die Plattform-Administration ist die Rolle, mit der wir als Betreiber die Plattform betreuen. Ihre Konten
        gehören zu keiner Schule.
    </p>
    <ul>
        <li>
            <strong>Das kann sie:</strong> registrierte Schulen und ihre Stammdaten ansehen und bearbeiten, Schulen
            sperren und wieder freigeben, Zugänge von Schuladministrationen anlegen (nur für Schulen, die noch keine haben)
            und löschen (nie den letzten einer Schule) und das gemeinsame Kompetenzraster pflegen. Passwort und
            E-Mail-Adresse einer Schuladministration kann sie nicht ändern, wer sich ausgesperrt hat, setzt das Passwort
            selbst zurück. Auf ihrer Startseite sieht sie Zahlen, nämlich wie viele
            Schulen, Konten je Rolle und Klassen es gibt, und die zuletzt registrierten Schulen, aber keine Personen.
        </li>
        <li>
            <strong>Das sieht sie in der Plattform nicht:</strong> Unterrichtsstunden, Notizen, Arbeitsblätter,
            Hausaufgaben, Abgaben, Verständniswerte, „Erklär’s mir“-Gespräche, Rückfragen, Klassenbuch, Einwilligungen,
            Klassen sowie die Konten von Lehrkräften und Schüler:innen.
        </li>
    </ul>
    <p>
        Technisch betreiben wir die Server und die Datenbank und haben dort Zugriff, soweit es für Betrieb, Wartung und
        Fehlersuche nötig ist (siehe Abschnitt {{ $no('hosting') }}). Diesen Zugriff nutzen wir nur dafür und nicht, um Unterrichts- oder
        Lerndaten auszuwerten.
    </p>

    <h3>Import einer Schülerliste</h3>
    <p>
        Beim Import legt die Plattform aus der hochgeladenen Liste Klassen und Konten an und vergibt für neue Konten
        zufällige Startpasswörter. Die Administration sieht diese Startpasswörter danach einmalig im Klartext, um sie
        weiterzugeben, und kann sie als CSV-Liste herunterladen. In der Datenbank liegt nur der Hash. Die hochgeladene
        Datei wird nur zum Einlesen gebraucht. Sie liegt bis zum Abschluss des Imports im temporären Ordner des Servers
        und wird nicht dauerhaft aufbewahrt.
    </p>

    <h3>Passwort zurücksetzen</h3>
    <p>
        Wer eine E-Mail-Adresse hinterlegt hat, kann auf der Anmeldeseite unter „Passwort vergessen?“ den Benutzernamen
        oder die Adresse eingeben. Gehört dazu ein Konto mit hinterlegter Adresse, schicken wir an diese Adresse eine
        E-Mail mit einem Link zum Zurücksetzen. Die Antwort auf der Seite ist immer gleich, damit niemand erfährt, ob es
        ein Konto gibt.
    </p>
    <p>
        Für den Link speichert die Plattform in der Datenbank (Tabelle <code>password_reset_tokens</code>) die
        E-Mail-Adresse, einen verschlüsselten Code und den Zeitpunkt der Anfrage. Der Link gilt 60 Minuten. Der Eintrag
        wird nach erfolgreichem Zurücksetzen gelöscht und bei einer neuen Anfrage ersetzt. Nicht eingelöste Einträge sind
        nach Ablauf unbrauchbar und werden bei der Datenbankpflege entfernt. Wer keine Adresse hinterlegt hat, wendet sich
        an die Administration, die das Passwort neu setzen kann.
    </p>
    <p>
        Den Versand übernimmt der E-Mail-Dienst, den der Betreiber für die Plattform eingerichtet hat. Soweit das ein
        externer Dienstleister ist, erhält er dafür die Empfängeradresse und den Inhalt der E-Mail. Er verarbeitet sie nur
        nach unserer Weisung auf Grundlage eines Vertrags nach Art. 28 DSGVO. Eine normale E-Mail ist auf dem Weg nicht
        immer verschlüsselt.
    </p>

    <h3>Rechtsgrundlage</h3>
    <p>
        Im Schulbetrieb ist es Art. 6 Abs. 1 lit. e DSGVO in Verbindung mit dem jeweiligen Landesschulgesetz und den
        Datenschutzvorschriften des Landes für Schulen, in Hessen etwa dem Hessischen Schulgesetz. Die Verarbeitung dient
        dem schulischen Bildungs- und Erziehungsauftrag.
    </p>
    <p>
        Im Demo- und Pilotbetrieb ist es Art. 6 Abs. 1 lit. f DSGVO. Unser berechtigtes Interesse ist es, die Plattform
        mit Demokonten vorzuführen und zu erproben. Die Demokonten gehören zu erfundenen Personen, ihre Zugangsdaten
        stehen auf der Anmeldeseite.
    </p>

    <h2 id="lerndaten">{{ $heading('lerndaten') }}</h2>
    <h3>Was gespeichert wird</h3>
    <ul>
        <li>
            <strong>Unterrichtsstunden (Lehrkräfte):</strong> Fach, Thema, Datum, Notizen oder Tafelbild, auch Notizen
            aus der Stunde, die die Lehrkraft vom Tuto-Mikro übernommen hat, eine Zusammenfassung und hochgeladene
            Arbeitsblätter als Foto, Scan oder PDF (bis 10 MB je Datei). Die Dateien liegen auf dem Server und sind nur
            über die Plattform abrufbar.
        </li>
        <li>
            <strong>Hausaufgaben:</strong> eine Zusammenfassung und Fragen, auf Wunsch in drei Stufen (Einstieg, Standard,
            Profi). Sie entstehen zunächst als Entwurf (siehe Abschnitt {{ $no('ki') }}), die Lehrkraft prüft und ändert
            sie und gibt sie dann für die Schüler:innen frei. Schüler:innen sehen die Zusammenfassung der Lehrkraft vor
            den Fragen. Schaltet eine Schüler:in Einfache Sprache ein, speichert Tuto die vereinfachte Fassung der
            Zusammenfassung und der Fragen bei der Stunde, damit sie nicht für jede Person neu entsteht. Sie enthält nur
            Texte der Lehrkraft.
        </li>
        <li>
            <strong>Abgaben (Schüler:innen):</strong> die eigene Zusammenfassung und die Antworten auf die Fragen mit der
            gewählten Stufe. Dazu das Ergebnis der Auswertung: ein Verständniswert von 0 bis 100 für die ganze Abgabe und
            für jede Antwort, ein Feedback-Text, Hinweise auf Lücken und der Zeitpunkt der Auswertung.
        </li>
        <li>
            <strong>„Erklär’s mir“:</strong> Schüler:innen erklären der Computerfigur Tuto ein Thema und bekommen
            Rückfragen. Gespeichert werden das Thema, der gesamte Gesprächsverlauf, der Verständniswert und das Feedback.
        </li>
        <li>
            <strong>Rückfragen:</strong> Schüler:innen können der Lehrkraft einer Stunde eine Frage stellen. Gespeichert
            werden die Frage, die Antwort, wer geantwortet hat, und wann gefragt, geantwortet und gelesen wurde.
        </li>
        <li>
            <strong>Klassenbuch:</strong> Datum, Stunde, Fach, Thema, Unterrichtsinhalt, Hausaufgabe, Bemerkungen, die
            fehlenden Schüler:innen (ohne Gründe), die eintragende Lehrkraft und ob der Eintrag bestätigt ist. Legt eine
            Lehrkraft den Entwurf eines Eintrags aus einer Stunde an, schlägt Tuto als fehlend die Schüler:innen der Klasse
            vor, die in der zuletzt bestätigten Anwesenheitsliste des Tuto-Mikros dieser Stunde nicht angehakt sind (nur
            solche mit gültiger Einwilligung). Die Lehrkraft prüft und ändert den Vorschlag.
        </li>
        <li>
            <strong>Benachrichtigungen:</strong> Hinweise in der App auf neue Hausaufgaben, Abgaben, Rückfragen und
            Antworten. Sie nennen Thema, Fach und Klasse einer Stunde oder eine Anzahl und werden nur in der App
            angezeigt, nicht per E-Mail verschickt.
        </li>
    </ul>
    <p>
        Aus den gespeicherten Ergebnissen berechnet die Plattform eine Übersicht zum Lernstand und möglichem
        Förderbedarf je Klasse sowie einen druckbaren Bericht für Elterngespräche. Beides enthält nur, was bereits
        gespeichert ist. Die Übersicht „Heute“ und die Zahl der Abgaben zeigen nur Anzahlen, keine Namen.
    </p>

    <div class="legal-note">
        <p class="legal-note-title">Hinweis für Lehrkräfte</p>
        <p>
            Bitte tragen Sie in Notizen und Arbeitsblätter keine personenbezogenen Daten von Schüler:innen ein, zum
            Beispiel Namen, Fotos oder Leistungsstände, und tragen Sie im Klassenbuch keine Gründe für Fehlzeiten ein. Das
            Tuto-Mikro können Sie nur nutzen, wenn Ihre Schule es freigeschaltet hat und die Einwilligungen vorliegen.
            Bitte laden Sie Arbeitsblätter nur hoch, wenn Sie dazu berechtigt sind.
        </p>
    </div>

    <h3>Lehrkräfte einer Klasse und Vertretung</h3>
    <p>
        Was eine Lehrkraft über eine Klasse sieht, richtet sich danach, ob sie die Klasse leitet (Klassenleitung), ihr von
        der Schuladministration zugeordnet ist oder sie gerade vertritt. Lernstände, Klassenbuch und Einwilligungen einer
        Klasse sieht eine Lehrkraft nur, solange das zutrifft. Die Ergebnisse einer Stunde (Abgaben, Gespräche und
        Rückfragen) sieht dagegen die Lehrkraft, die die Stunde angelegt hat, auch dann noch, wenn sie der Klasse nicht
        mehr zugeordnet ist. Wer nur vertreten hat, verliert die Ergebnisse der Stunden aus der Vertretung mit dem Ende
        der Vertretung. Eine Vertretung richtet die Klassenleitung oder die Schuladministration für einen Zeitraum
        ein, höchstens {{ $substitutionMaxDays }} Tage. Für diesen Zeitraum darf die vertretende Lehrkraft
        {{ ClassSubstitution::grants('de') }}. Mit dem letzten Tag endet dieser Zugriff, auch auf die Ergebnisse der Stunden,
        die sie in der Vertretung gehalten hat.
    </p>

    <h3>Wer sieht was</h3>
    <div class="legal-table-wrap" role="region" aria-label="Wer welche Daten sehen kann" tabindex="0">
        <table class="legal-table">
            <caption>Wer welche Daten sehen kann</caption>
            <thead>
                <tr>
                    <th scope="col">Daten</th>
                    <th scope="col">Schüler:in</th>
                    <th scope="col">Lehrkraft</th>
                    <th scope="col">Schuladministration (nur die eigene Schule)</th>
                    <th scope="col">Plattform-Administration</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <th scope="row">Abgaben, Verständniswerte und Feedback</th>
                    <td><span class="legal-cell-label">Schüler:in</span> nur die eigenen</td>
                    <td><span class="legal-cell-label">Lehrkraft</span> die Abgaben zu den eigenen Stunden, nach dem Ende einer Vertretung nicht mehr die zu Stunden aus der Vertretung, dazu im Elterngesprächs-Bericht Verständniswert und Feedback aller Hausaufgaben der Schüler:innen ihrer Klassen (siehe unten)</td>
                    <td><span class="legal-cell-label">Schuladministration (nur die eigene Schule)</span> alle</td>
                    <td><span class="legal-cell-label">Plattform-Administration</span> keine Einsicht</td>
                </tr>
                <tr>
                    <th scope="row">„Erklär’s mir“-Gespräche mit Verlauf</th>
                    <td><span class="legal-cell-label">Schüler:in</span> nur die eigenen</td>
                    <td><span class="legal-cell-label">Lehrkraft</span> nur Gespräche, die zu einer eigenen Stunde gehören, nach dem Ende einer Vertretung nicht mehr die zu Stunden aus der Vertretung</td>
                    <td><span class="legal-cell-label">Schuladministration (nur die eigene Schule)</span> Gespräche, die zu einer Stunde gehören, nicht die zu frei gewählten Themen</td>
                    <td><span class="legal-cell-label">Plattform-Administration</span> keine Einsicht</td>
                </tr>
                <tr>
                    <th scope="row">Rückfragen an die Lehrkraft</th>
                    <td><span class="legal-cell-label">Schüler:in</span> nur die eigenen mit den Antworten</td>
                    <td><span class="legal-cell-label">Lehrkraft</span> die Rückfragen zu den eigenen Stunden, nach dem Ende einer Vertretung nicht mehr die zu Stunden aus der Vertretung</td>
                    <td><span class="legal-cell-label">Schuladministration (nur die eigene Schule)</span> alle</td>
                    <td><span class="legal-cell-label">Plattform-Administration</span> keine Einsicht</td>
                </tr>
                <tr>
                    <th scope="row">Unterrichtsnotizen und Arbeitsblätter</th>
                    <td><span class="legal-cell-label">Schüler:in</span> keine Einsicht</td>
                    <td><span class="legal-cell-label">Lehrkraft</span> die eigenen Stunden</td>
                    <td><span class="legal-cell-label">Schuladministration (nur die eigene Schule)</span> alle</td>
                    <td><span class="legal-cell-label">Plattform-Administration</span> keine Einsicht</td>
                </tr>
                <tr>
                    <th scope="row">Hausaufgaben mit Fragen und Zusammenfassung</th>
                    <td><span class="legal-cell-label">Schüler:in</span> nur freigegebene und ihnen zugewiesene, mit der Zusammenfassung der Lehrkraft vor den Fragen</td>
                    <td><span class="legal-cell-label">Lehrkraft</span> die eigenen Stunden</td>
                    <td><span class="legal-cell-label">Schuladministration (nur die eigene Schule)</span> alle</td>
                    <td><span class="legal-cell-label">Plattform-Administration</span> keine Einsicht</td>
                </tr>
                <tr>
                    <th scope="row">Lernstand-Übersicht</th>
                    <td><span class="legal-cell-label">Schüler:in</span> keine Einsicht</td>
                    <td>
                        <span class="legal-cell-label">Lehrkraft</span>
                        für die Klassen, die sie leiten, denen sie zugeordnet sind oder die sie gerade vertreten:
                        Kompetenzwerte und Förderbedarf aller Schüler:innen der Klasse, berechnet aus allen ausgewerteten
                        Antworten, auch zu Stunden anderer Lehrkräfte
                    </td>
                    <td><span class="legal-cell-label">Schuladministration (nur die eigene Schule)</span> alle</td>
                    <td><span class="legal-cell-label">Plattform-Administration</span> keine Einsicht</td>
                </tr>
                <tr>
                    <th scope="row">Elterngesprächs-Bericht</th>
                    <td><span class="legal-cell-label">Schüler:in</span> keine Einsicht</td>
                    <td>
                        <span class="legal-cell-label">Lehrkraft</span>
                        für Schüler:innen dieser Klassen: Punktwert, Feedback und Hinweise auf Lücken aller freigegebenen
                        Hausaufgaben, auch zu Stunden anderer Lehrkräfte, sowie Thema, Punktwert und Feedback der
                        „Erklär’s mir“-Gespräche zu eigenen Stunden (ohne Gesprächsverlauf)
                    </td>
                    <td><span class="legal-cell-label">Schuladministration (nur die eigene Schule)</span> alle, bei „Erklär’s mir“ nur Gespräche, die zu einer Stunde gehören</td>
                    <td><span class="legal-cell-label">Plattform-Administration</span> keine Einsicht</td>
                </tr>
                <tr>
                    <th scope="row">Klassenbuch mit Fehlzeiten</th>
                    <td><span class="legal-cell-label">Schüler:in</span> keine Einsicht</td>
                    <td><span class="legal-cell-label">Lehrkraft</span> das ganze Klassenbuch der Klassen, die sie leiten, denen sie zugeordnet sind oder die sie gerade vertreten, mit Export</td>
                    <td><span class="legal-cell-label">Schuladministration (nur die eigene Schule)</span> alle</td>
                    <td><span class="legal-cell-label">Plattform-Administration</span> keine Einsicht</td>
                </tr>
                <tr>
                    <th scope="row">Einwilligungen zum Tuto-Mikro</th>
                    <td><span class="legal-cell-label">Schüler:in</span> nur den eigenen Stand, um selbst zuzustimmen</td>
                    <td><span class="legal-cell-label">Lehrkraft</span> für die Klassen, die sie leiten, denen sie zugeordnet sind oder die sie gerade vertreten</td>
                    <td><span class="legal-cell-label">Schuladministration (nur die eigene Schule)</span> alle</td>
                    <td><span class="legal-cell-label">Plattform-Administration</span> keine Einsicht</td>
                </tr>
                <tr>
                    <th scope="row">Nachweis über Sitzungen des Tuto-Mikros</th>
                    <td><span class="legal-cell-label">Schüler:in</span> keine Einsicht</td>
                    <td><span class="legal-cell-label">Lehrkraft</span> nur die Lehrkraft der Stunde</td>
                    <td><span class="legal-cell-label">Schuladministration (nur die eigene Schule)</span> keine Einsicht</td>
                    <td><span class="legal-cell-label">Plattform-Administration</span> keine Einsicht</td>
                </tr>
                <tr>
                    <th scope="row">Konten und Klassen</th>
                    <td><span class="legal-cell-label">Schüler:in</span> keine Einsicht</td>
                    <td><span class="legal-cell-label">Lehrkraft</span> nur die Namen der Schüler:innen der Klassen, die sie leiten, unterrichten oder vertreten</td>
                    <td><span class="legal-cell-label">Schuladministration (nur die eigene Schule)</span> alle Konten und Klassen der Schule</td>
                    <td><span class="legal-cell-label">Plattform-Administration</span> nur die Konten der Schuladministrationen, keine Klassen und keine Konten von Lehrkräften und Schüler:innen</td>
                </tr>
                <tr>
                    <th scope="row">Stammdaten der Schule und Zugänge der Schuladministration</th>
                    <td><span class="legal-cell-label">Schüler:in</span> keine Einsicht</td>
                    <td><span class="legal-cell-label">Lehrkraft</span> keine Einsicht</td>
                    <td><span class="legal-cell-label">Schuladministration (nur die eigene Schule)</span> die der eigenen Schule</td>
                    <td><span class="legal-cell-label">Plattform-Administration</span> die aller Schulen</td>
                </tr>
            </tbody>
        </table>
    </div>
    <p>Andere Schüler:innen sehen die Abgaben, Gespräche und Rückfragen einer Schülerin oder eines Schülers nie.</p>
    <p>
        Die Daten verschiedener Schulen sind voneinander getrennt. Lehrkräfte und Schuladministration einer Schule sehen
        nie Klassen, Konten, Unterrichtsstunden, Abgaben oder Gespräche einer anderen Schule.
    </p>

    <h3>Verständniswert und Förderbedarf</h3>
    <p>
        Der Verständniswert ist ein Hinweis darauf, wo Unterstützung nötig sein könnte. Er ist keine Note und keine
        Leistungsbewertung im schulrechtlichen Sinn. Alle Stufen werden am selben Erwartungshorizont gemessen. Lehrkräfte
        entscheiden selbst, ob und wie sie den Wert nutzen. Über Förderung, Noten oder Versetzung entscheidet die Schule,
        nie die Plattform (siehe Abschnitt {{ $no('ki') }}).
    </p>
    <h3>Rechtsgrundlage</h3>
    <p>
        Im Schulbetrieb Art. 6 Abs. 1 lit. e DSGVO in Verbindung mit dem jeweiligen Landesschulgesetz, im Demo- und
        Pilotbetrieb Art. 6 Abs. 1 lit. f DSGVO, wie in Abschnitt {{ $no('benutzerkonten') }} beschrieben.
    </p>

    <h2 id="ki">{{ $heading('ki') }}</h2>
    <p>Tuto kann für diese Aufgaben ein KI-Sprachmodell nutzen:</p>
    <ul>
        <li>
            <strong>Entwürfe für Lehrkräfte:</strong> aus den Notizen einer Stunde eine Zusammenfassung und fünf Fragen
            (die Fragen, die die Lehrkraft selbst geschrieben oder bearbeitet hat, gehen pseudonymisiert mit, damit Tuto sie
            nicht wiederholt), Förderaufgaben mit drei bis vier Fragen, Fragen in drei Stufen und Fassungen in Einfacher Sprache.
        </li>
        <li>
            <strong>Auswertung einer Abgabe:</strong> ein Verständniswert von 0 bis 100, eine Rückmeldung, bis zu drei
            Hinweise auf Lücken und ein Wert je Antwort.
        </li>
        <li>
            <strong>„Erklär’s mir“:</strong> Die Computerfigur Tuto stellt Rückfragen und schätzt am Ende ein, wie gut das
            Thema erklärt wurde. Tuto ist ein Computerprogramm und keine reale Person.
        </li>
        <li><strong>Tuto-Mikro:</strong> Spracherkennung und Zusammenfassung (siehe Abschnitt {{ $no('tuto-mikro') }}).</li>
    </ul>

    <h3>Jede Schule entscheidet selbst</h3>
    <p>
        Die KI-Funktionen sind ausgeschaltet, bis die Schuladministration einer Schule sie unter „KI-Funktionen“
        einschaltet. Vorher bestätigt sie, dass mit dem KI-Anbieter ein Vertrag zur Auftragsverarbeitung besteht, ein
        bezahlter Tarif genutzt wird, in dem Eingaben nicht zur Verbesserung der Produkte des Anbieters dienen, eine
        Datenschutz-Folgenabschätzung durchgeführt ist und Eltern und Schüler:innen informiert sind. Sie kann die
        KI-Funktionen jederzeit wieder ausschalten. Gespeichert wird, wann und von wem sie eingeschaltet wurden.
    </p>
    <p>
        Solange eine Schule die KI-Funktionen nicht eingeschaltet hat, beantwortet eine regelbasierte Offline-Logik auf
        unserem Server alle Anfragen ihrer Lehrkräfte und Schüler:innen. Sie arbeitet mit festen Textregeln, ermittelt die
        wichtigsten Begriffe aus den Unterrichtsnotizen und misst, wie viele davon ein Text der Schülerin oder des Schülers
        abdeckt. Sie ruft keinen externen Dienst auf, und es werden keine Daten an Dritte übermittelt.
    </p>

    @if ($aiEnabled)
        <div class="legal-note">
            <p class="legal-note-title">Aktueller Stand: Die KI-Anbindung der Plattform ist eingeschaltet.</p>
            <p>
                Schulen können die KI-Funktionen für sich einschalten.
                @if ($aiIsLocal)
                    Das Sprachmodell läuft auf unseren eigenen Servern.
                @elseif ($aiIsGoogle)
                    Anbieter ist Google mit der Gemini API.
                @else
                    Anbieter ist {{ AiSettings::providerLabel($aiProvider) }}.
                @endif
            </p>
        </div>
    @else
        <div class="legal-note">
            <p class="legal-note-title">Aktueller Stand: Die KI-Anbindung der Plattform ist abgeschaltet.</p>
            <p>
                Keine Schule kann die KI-Funktionen derzeit einschalten. Alle Auswertungen laufen auf unserem Server mit
                der Offline-Logik. Für die Auswertungen werden keine Daten an Dritte übermittelt.
            </p>
        </div>
    @endif

    <h3>Anbieter</h3>
    @if ($aiIsLocal)
        <p>
            Das Sprachmodell läuft auf unseren eigenen Servern (Ollama). Die Daten verlassen sie dafür nicht und gehen an
            keinen weiteren Anbieter.
        </p>
    @elseif ($aiIsGoogle)
        <p>
            Anbieter ist Google mit der Gemini API im kostenpflichtigen Tarif: Google LLC, 1600 Amphitheatre Parkway,
            Mountain View, CA 94043, USA, beziehungsweise bei einem Vertrag über Google Cloud mit Kunden im EWR Google Cloud
            EMEA Limited, 70 Sir John Rogerson’s Quay, Dublin 2, Irland. Google verarbeitet die Daten als unser
            Unterauftragsverarbeiter nach Art. 28 DSGVO. Im kostenpflichtigen Tarif nutzt Google Eingaben und Ergebnisse
            nicht, um seine Produkte zu verbessern oder Modelle zu trainieren. Im kostenlosen Tarif dürfte Google das,
            deshalb wird er nicht genutzt.
        </p>
    @else
        <p>
            Anbieter ist {{ AiSettings::providerLabel($aiProvider) }}. Er verarbeitet die Daten als unser
            Unterauftragsverarbeiter nach Art. 28 DSGVO, so wie es der
            <a href="{{ route('legal.dpa') }}">Vertrag zur Auftragsverarbeitung</a> in Anlage 3 beschreibt.
        </p>
    @endif

    <h3>Was übermittelt wird</h3>
    <ul>
        <li>
            aus den Stunden: Fach, Thema, Datum, Notizen und Zusammenfassung der Lehrkraft, Fragen mit dem
            Erwartungshorizont, bei Förderaufgaben die Kompetenz, anonyme Hinweise auf Lücken und Thema, Datum und
            Notizen der zugrunde liegenden Stunden
        </li>
        <li>
            hochgeladene Arbeitsblätter als Bild (JPEG, PNG, WebP, HEIC) oder PDF, nur für Entwürfe von Hausaufgaben und für
            die Auswertung von Abgaben
        </li>
        <li>
            Texte der Schüler:innen: ihre Zusammenfassung und Antworten mit der gewählten Stufe, Thema und Beiträge im
            „Erklär’s mir“-Chat
        </li>
        <li>
            für Stufen und Einfache Sprache nur Texte der Lehrkraft (Zusammenfassung und Fragen), nie Antworten von
            Schüler:innen
        </li>
        <li>beim Tuto-Mikro die Ton-Abschnitte und der Text für die Zusammenfassung (siehe Abschnitt {{ $no('tuto-mikro') }})</li>
        <li>technische Kennungen von Fragen und Kompetenzen, damit die Ergebnisse zugeordnet werden können</li>
    </ul>
    <h3>Pseudonymisierung</h3>
    <p>
        Benutzernamen, E-Mail-Adressen, Benutzerkennungen, Klassen- und Schulnamen werden nicht übermittelt. Vor jeder
        Anfrage ersetzt Tuto in allen Texten die Namen der Schüler:innen der Klasse durch „Schüler:in A“, „Schüler:in B“
        und so weiter, die Namen der Lehrkräfte der Klasse durch „Lehrkraft“ und Namensteile, die mehrere Personen
        tragen, durch „Person“. Namen anderer Personen, Spitznamen und falsch geschriebene Namen erkennt Tuto nicht, und
        Text in Fotos und PDF-Dateien kann es nicht ersetzen. Deshalb bitten wir alle, keine persönlichen Angaben in
        Texte und Arbeitsblätter zu schreiben.
    </p>
    @unless ($aiIsLocal)
        <h3>Speicherung beim Anbieter</h3>
        <p>
            Tuto speichert nur die Ergebnisse, zusammen mit den Daten, zu denen sie gehören. Mit jeder Anfrage verlangt Tuto
            vom Anbieter, die Anfrage nicht für spätere Abrufe zu speichern, und führt dort keinen Gesprächsverlauf. Wie
            lange der Anbieter Anfragen etwa zur Erkennung von Missbrauch aufbewahrt, regeln seine Bedingungen. Darauf haben
            wir keinen Einfluss. Auskunft dazu geben wir auf Anfrage (siehe Abschnitt {{ $no('rechte') }}).
        </p>
        <h3>Übermittlung in Drittländer</h3>
        <p>
            @if ($aiIsGoogle)
                Google verarbeitet die Daten auch in den USA und in weiteren Ländern außerhalb der EU. Grundlage sind
                die Datenverarbeitungsbedingungen von Google mit EU-Standardvertragsklauseln. Google LLC ist außerdem
                unter dem EU-US Data Privacy Framework zertifiziert, für das ein Angemessenheitsbeschluss der
                EU-Kommission besteht (Art. 45 DSGVO).
            @else
                Verarbeitet der Anbieter Daten außerhalb der EU, geschieht das nur mit geeigneten Garantien nach Art. 44 ff.
                DSGVO, also auf Grundlage von EU-Standardvertragsklauseln oder eines Angemessenheitsbeschlusses der
                EU-Kommission.
            @endif
            Eine Kopie der Garantien erhalten Sie auf Anfrage (siehe Abschnitt {{ $no('rechte') }}). Daten einer Schule
            gehen nur dorthin, solange sie die KI-Funktionen eingeschaltet hat.
        </p>
    @endunless
    <h3>Fehler</h3>
    <p>
        Antwortet der Anbieter nicht, zeigt Tuto einen Fehler an. Es greift dann nicht unbemerkt auf die Offline-Logik
        zurück und fragt keinen anderen Anbieter.
    </p>
    <h3>Rechtsgrundlage</h3>
    <p>
        Im Schulbetrieb Art. 6 Abs. 1 lit. e DSGVO in Verbindung mit dem Landesschulgesetz, auf Grundlage der
        Entscheidung der Schule, die KI-Funktionen einzuschalten. Beim Tuto-Mikro braucht die Verarbeitung durch den
        KI-Anbieter außerdem die Einwilligung (siehe Abschnitt {{ $no('tuto-mikro') }}).
    </p>

    <h3>Keine automatisierten Entscheidungen</h3>
    <p>
        Es gibt keine ausschließlich automatisierte Entscheidung im Sinne des Art. 22 DSGVO. Die Auswertungen, Verständniswerte
        und Rückmeldungen sind Hinweise. Menschen entscheiden, was daraus folgt. Auch die KI kann sich irren. Entwürfe von
        Hausaufgaben bleiben Entwürfe, bis die Lehrkraft sie freigibt. Eine Förderaufgabe, die die Lehrkraft mit „Direkt
        freigeben“ anlegt, geht ohne weitere Durchsicht an die ausgewählten Schüler:innen. Die Auswertung einer Abgabe
        und die Einschätzung bei „Erklär’s mir“ sehen die Schüler:innen direkt, ohne dass eine Lehrkraft sie vorher prüft.
    </p>

    <h2 id="tuto-mikro">{{ $heading('tuto-mikro') }}</h2>
    <p>
        Mit dem Tuto-Mikro kann eine Lehrkraft während einer Stunde das Mikrofon ihres eigenen Geräts einschalten. Aus dem
        Ton entstehen Notizen aus der Stunde, aus denen sie später ihre Unterrichtsnotizen und Hausaufgaben erstellen
        kann. Es gibt kein Bild und kein Video. Das Mikrofon nimmt den Ton im Raum auf, also auch Stimmen von
        Schüler:innen in der Nähe. Wir empfehlen deshalb ein Ansteck- oder Headset-Mikrofon.
    </p>
    <h3>Wann es an sein darf</h3>
    <p>Der Server lässt das Tuto-Mikro nur an, wenn alles davon zutrifft:</p>
    <ul>
        <li>
            Die Schuladministration hat es für die Schule freigeschaltet, nachdem sie ihre Checkliste bestätigt und einen
            Einwilligungstext veröffentlicht hat. Die Schule ist nicht gesperrt.
        </li>
        <li>
            Das Fach ist nicht ausgeschlossen. Ohne eigene Liste der Schule sind das {{ $blockedSubjects }}, weil dort
            Überzeugungen und Privates zur Sprache kommen. Die Schuladministration kann die Liste ändern.
        </li>
        <li>Die Lehrkraft leitet die Klasse, ist ihr zugeordnet oder vertritt sie gerade.</li>
        <li>
            Die Lehrkraft hat die anwesenden Schüler:innen angehakt und bestätigt, dass nur sie im Raum sind. Für diese
            Angabe ist die Lehrkraft verantwortlich. Wer später dazukommt, ist erst erfasst, wenn sie das Tuto-Mikro neu
            startet.
        </li>
        <li>
            Für jede angehakte Schüler:in liegt eine gültige Einwilligung der Erziehungsberechtigten für den Ton und die
            Notizen aus der Stunde vor, bei eingeschalteten KI-Funktionen der Schule auch für die Spracherkennung und
            Zusammenfassung durch den KI-Anbieter.
        </li>
    </ul>
    <p>
        Bei jedem Ton-Abschnitt und vor jeder Zusammenfassung prüft der Server das erneut. Wird eine Einwilligung
        widerrufen oder das Tuto-Mikro oder die KI ausgeschaltet, endet es spätestens beim nächsten Abschnitt. Eine
        Sitzung, die nach {{ $mikroMaxSessionHours }} Stunden nicht beendet ist, nimmt nichts mehr an. Solange das
        Mikrofon an ist, zeigt ein Balken „Mikro an“, sonst „Mikro aus“. Die Anzeige für den Beamer zeigt nur „Mikro an“
        oder „Mikro aus“ und das Thema der Stunde, keine Namen und keinen Text.
    </p>
    <h3>Was mit dem Ton geschieht</h3>
    <ul>
        <li>
            Der Ton wird nie gespeichert: nicht als Datei, nicht in einer Warteschlange und nicht in der Datenbank.
        </li>
        <li>
            Hat die Schule die KI-Funktionen eingeschaltet, schickt der Browser kurze Ton-Abschnitte über eine
            verschlüsselte Verbindung an den Server. Der reicht sie nur im Arbeitsspeicher an den KI-Anbieter weiter
            (Abschnitt {{ $no('ki') }}) und verwirft sie, sobald die Anfrage beendet ist. Diese Abschnitte sind nicht
            pseudonymisiert: Darin sind echte Stimmen und gesprochene Namen zu hören.
        </li>
        <li>
            Ohne KI-Funktionen verlässt der Ton das Gerät der Lehrkraft nicht. Erkennt der Browser selbst deutsche
            Sprache auf dem Gerät, macht er daraus Text, sonst schreibt die Lehrkraft selbst mit.
        </li>
    </ul>
    <h3>Notizen aus der Stunde und Zusammenfassung</h3>
    <p>
        Der erkannte Text bleibt nur im Browser der Lehrkraft und ist weg, wenn sie die Seite schließt. Die Lehrkraft
        prüft und ändert ihn. Gespeichert wird nur, was sie in die Notizen der Stunde übernimmt. Dabei ersetzt Tuto die
        Namen der Klasse noch einmal. Übernommene Notizen gelten als Unterrichtsdaten (Abschnitt {{ $no('lerndaten') }}).
    </p>
    <p>
        Eine Zusammenfassung erstellt Tuto nur auf Wunsch der Lehrkraft und nur aus dem Text, nie aus dem Ton. Vorher
        ersetzt es die Namen wie in Abschnitt {{ $no('ki') }} beschrieben, dazu Fach und Thema der Stunde. Den KI-Anbieter
        nutzt es dafür nur bei eingeschalteten KI-Funktionen und wenn für alle Anwesenden die Einwilligung dazu vorliegt,
        sonst die Offline-Logik. Die Zusammenfassung selbst wird nicht gespeichert.
    </p>
    <h3>Was gespeichert wird</h3>
    <p>
        Je Sitzung ein Nachweis: Stunde, Lehrkraft, Beginn, Ende, Dauer, die als anwesend angehakten Schüler:innen, wann
        die Lehrkraft die Anwesenheit bestätigt hat, die Fassung des Einwilligungstextes, welche Zwecke geprüft wurden und
        ob Ton an den KI-Anbieter ging. Er enthält keinen Ton und keinen Text. Nur die Lehrkraft der Stunde sieht ihn,
        auch nicht die Schuladministration. Die Anwesenheitsliste der zuletzt bestätigten Sitzung einer Stunde dient
        außerdem als Vorschlag für die Fehlzeiten im Klassenbuch-Entwurf dieser Stunde (Abschnitt {{ $no('lerndaten') }}).
        Was die Lehrkraft davon als Fehlzeit im Klassenbuch einträgt, sehen alle, die das Klassenbuch der Klasse sehen.
        Der Nachweis wird {{ $mikroSessionDays }} Tage nach Beginn automatisch gelöscht, mit der
        Stunde oder dem Konto der Lehrkraft früher. Namen sieht nur die Lehrkraft auf ihrem Bildschirm. Mit „Namen
        verbergen“ blendet sie sie aus, diese Wahl merkt sich nur ihr Browser.
    </p>
    <h3>Einwilligungen</h3>
    <p>
        Die Schule holt die Einwilligungen der Erziehungsberechtigten digital über einen persönlichen Link oder auf Papier
        ein, auf Wunsch der Schule bestätigen die Schüler:innen sie selbst. Gespeichert werden der Name der
        erziehungsberechtigten Person, die erteilten und widerrufenen Zwecke mit Zeitpunkten, die Fassung des
        Einwilligungstextes, ob die Erklärung digital oder auf Papier kam, wer eine Papiererklärung erfasst hat, die
        Bestätigung der Schüler:in und ein Prüfwert des Links. Den Link selbst speichert Tuto nicht. Eine Papiererklärung,
        die älter ist als die letzte gespeicherte, nimmt Tuto nicht an. Die öffentliche Einwilligungsseite setzt kein
        Cookie und legt keine Sitzung an.
    </p>
    <h3>Rechtsgrundlage</h3>
    <p>
        Für Ton und Notizen der Schüler:innen die Einwilligung der Erziehungsberechtigten (Art. 6 Abs. 1 lit. a DSGVO),
        die die Schule einholt und die jederzeit für die Zukunft widerrufen werden kann. Für die Lehrkraft die Regelungen
        der Schule. Für den Nachweis über die Sitzungen und die Einwilligungen die Nachweispflicht der Schule (Art. 7
        Abs. 1 DSGVO).
    </p>

    <h2 id="apps">{{ $heading('apps') }}</h2>
    <p>
        Lehrkräfte und Schuladministrationen können eine App, derzeit Tuto Mic, mit ihrem Konto verbinden.
        Die App zeigt ihnen ihre Stunden und Klassen, legt neue Stunden an und setzt die Zusammenfassung einer Stunde.
        Damit sie Namen in Texten auf dem Gerät durch Platzhalter ersetzen kann, bekommt sie die Namen der Schüler:innen
        dieser Klassen. Das geschieht nur, wenn die Person die App selbst verbindet. Wer keine App verbindet, ist davon
        nicht betroffen, Schüler:innen nur mit ihrem Namen, wenn eine ihrer Lehrkräfte oder die Schuladministration
        eine App verbindet.
    </p>
    <h3>Wer eine App verbinden kann</h3>
    <p>
        Nur Lehrkräfte und Schuladministrationen einer Schule, die nicht gesperrt ist und den
        <a href="{{ route('legal.dpa') }}">Vertrag zur Auftragsverarbeitung</a> angenommen hat. Schüler:innen und die
        Plattform-Administration können keine App verbinden. Der Server prüft das auf der Seite zum Verbinden, beim
        Ausstellen des Zugangs und bei jeder Anfrage der App. Wird die Schule gesperrt oder verliert das Konto seine
        Rolle, bekommt die App danach keine Daten mehr.
    </p>
    <h3>So wird eine App verbunden</h3>
    <p>
        Die App öffnet im Browser die Seite „App verbinden“ der Plattform. Dort meldet sich die Person mit Benutzername
        und Passwort an, wie sonst auch, das Passwort erfährt die App nie. Die Seite zeigt, was die App darf und was
        nicht. Mit „Erlauben“ erzeugt die Plattform einen Einmal-Code und schickt den Browser mit dem Code zurück in die
        App. Die App tauscht den Code innerhalb von {{ $appCodeMinutes }} Minuten gegen einen Zugang, und nur mit einem
        Prüfwert, den allein die App kennt (PKCE nach RFC 7636). Mit „Abbrechen“ bekommt die App nichts.
    </p>
    <h3>Was die App sieht und ändern kann</h3>
    <ul>
        <li>
            <strong>Sehen:</strong> die Stunden, die die Person selbst bearbeiten darf, bei Lehrkräften ihre eigenen, bei
            der Schuladministration alle der Schule, jeweils ohne Förderaufgaben. Das sind Fach, Thema, Datum, die Klasse
            (Nummer und Name), die Zusammenfassung, ob sie aus einer App stammt und ob die Hausaufgabe freigegeben ist.
            Dazu die Klassen, in denen die Person Stunden anlegen darf (Nummer und Name), die Fächer, die an der Schule
            in Stunden vorkommen, und beim Verbinden der Name und die Rolle der Person.
        </li>
        <li>
            <strong>Namen von Schüler:innen:</strong> die Anzeigenamen der Schüler:innen in den Klassen, die die Person
            leitet, denen sie zugeordnet ist oder die sie an diesem Tag vertritt, bei der Schuladministration in allen
            Klassen der Schule, jeweils mit Nummer und Name der Klasse. Nur der Name, keine Benutzernamen, Nummern,
            E-Mail-Adressen oder anderen Angaben. Die App darf die Namen ausschließlich nutzen, um sie in Texten auf dem
            Gerät durch Platzhalter zu ersetzen, bevor ein Text das Gerät verlässt (lokale Pseudonymisierung), und darf
            sie nicht speichern. Tuto Mic arbeitet nach Angaben ihres Herstellers so.
        </li>
        <li>
            <strong>Ändern:</strong> in diesen Klassen neue Stunden anlegen (Klasse, Fach, Thema, Datum und auf Wunsch
            Notizen, die Person ist dann die Lehrkraft der Stunde) und die Zusammenfassung einer Stunde setzen.
        </li>
        <li>
            <strong>Nie:</strong> andere Daten von Schüler:innen als ihre Namen, die Notizen einer Stunde, Ergebnisse,
            Abgaben, Gespräche, die E-Mail-Adresse oder das Passwort.
        </li>
    </ul>
    <p>
        Eine Stunde, die die App anlegt, und eine Zusammenfassung, die sie setzt, speichert Tuto wie Eingaben der
        Lehrkraft (Abschnitt {{ $no('lerndaten') }}). Eine Zusammenfassung aus der App bleibt bei einem neuen KI-Entwurf
        erhalten. Ist die KI der Schule eingeschaltet, liest die KI sie dabei mit, mit ersetzten Namen wie in
        Abschnitt {{ $no('ki') }} beschrieben. Schüler:innen lesen die Zusammenfassung vor den Fragen der Hausaufgabe.
    </p>
    <div class="legal-note">
        <p class="legal-note-title">Hinweis für Lehrkräfte</p>
        <p>
            Bitte tragen Sie in Zusammenfassungen, die Sie oder eine App setzen, keine personenbezogenen Daten von
            Schüler:innen ein, denn Schüler:innen lesen sie. Verbinden Sie eine App nur auf einem Gerät, das Sie selbst
            nutzen und mit einer Sperre sichern, und entziehen Sie den Zugang, wenn Sie das Gerät abgeben oder verlieren.
            Ob Sie dafür ein privates Gerät nutzen dürfen, regelt Ihre Schule.
        </p>
    </div>
    <h3>Was gespeichert wird</h3>
    <ul>
        <li>
            <strong>Einmal-Code:</strong> nur als Hash, der Code selbst wird nirgends gespeichert. Dazu das Konto, die
            App, die Adresse, zu der der Browser zurückkehrt, der Prüfwert der App, der Ablaufzeitpunkt und wann der Code
            eingelöst wurde.
        </li>
        <li>
            <strong>Zugang:</strong> das Konto, der Name, den die App für das Gerät angibt (zum Beispiel „iPhone von Frau
            Schulze“), der Hash des Zugangsschlüssels (nie der Schlüssel selbst), die Berechtigungen (Stunden und Klassen
            ansehen, Stunden anlegen, Zusammenfassung setzen, Namen der Schüler:innen abrufen) sowie der Zeitpunkt der
            Verbindung, der letzten Nutzung und des Ablaufs. Zum Zugang speichert Tuto keine IP-Adresse, keine Gerätekennung und keine E-Mail-Adresse.
        </li>
    </ul>
    <p>
        Die Person sieht ihre Zugänge in ihrem Profil unter „Verbundene Apps“ und entzieht jeden dort sofort mit „Zugang
        entziehen“. Sie stehen auch in der Datei, die sie mit „Meine Daten herunterladen“ bekommt, ohne den Schlüssel.
        Sonst sieht sie niemand, auch nicht die Schuladministration oder die Plattform-Administration. Der
        Zugangsschlüssel liegt auf dem Gerät, auf dem die App läuft. Wer das Gerät verliert, entzieht den Zugang im
        Profil.
    </p>
    <p>
        Anfragen der App erscheinen wie jeder Aufruf im Zugriffsprotokoll des Webservers (Abschnitt {{ $no('hosting') }}).
        Den Austausch des Codes begrenzt der Server auf 10 Anfragen je Minute und IP-Adresse und zählt sie dafür kurz mit.
    </p>
    <h3>Wie lange</h3>
    <p>
        Der Einmal-Code gilt {{ $appCodeMinutes }} Minuten und lässt sich nur einmal einlösen. Die Plattform löscht benutzte
        und abgelaufene Codes täglich automatisch.
        @if ($appAccessDays)
            Ein Zugang gilt {{ $appAccessDays }} Tage ab der Verbindung, auch wenn er genutzt wird. Danach funktioniert er
            nicht mehr und wird täglich automatisch gelöscht, sobald er seit mehr als einem Tag abgelaufen ist.
        @else
            Ein Zugang gilt, bis die Person ihn entzieht.
        @endif
        Wird er im Profil entzogen, ist er sofort gelöscht. Zugänge und offene Codes gehen außerdem mit dem Konto und mit
        jedem neuen Passwort des Kontos, die Person verbindet ihre Apps dann neu. Stunden und Zusammenfassungen, die eine
        App angelegt oder gesetzt hat, folgen den Fristen der Unterrichtsstunden (Abschnitt {{ $no('speicherdauer') }}).
    </p>
    <h3>Rechtsgrundlage</h3>
    <p>
        Im Schulbetrieb Art. 6 Abs. 1 lit. e DSGVO in Verbindung mit dem jeweiligen Landesschulgesetz, im Demo- und
        Pilotbetrieb Art. 6 Abs. 1 lit. f DSGVO, wie in Abschnitt {{ $no('benutzerkonten') }} beschrieben. Das Verbinden
        einer App ist freiwillig, die Nutzung von Tuto hängt nicht davon ab.
    </p>

    <h2 id="schriftarten">{{ $heading('schriftarten') }}</h2>
    <p>
        Die Plattform verwendet die Schriftart Lexend. Sie wird von unserem eigenen Server ausgeliefert. Es besteht keine
        Verbindung zu Servern von Schriftanbietern, deshalb werden dabei keine Daten, insbesondere keine IP-Adressen, an
        Dritte übermittelt.
    </p>
    @if ($externalAvatars)
        <h3>Platzhalter-Bild im Menü</h3>
        <p>
            Im angemeldeten Bereich zeigt die Plattform neben dem Namen ein Platzhalter-Bild mit den Initialen der
            angemeldeten Person. Dieses Bild lädt der Browser derzeit vom Dienst ui-avatars.com. Dorthin gelangen die
            IP-Adresse, Angaben zum Browser und die Initialen des Namens, die in der Bildadresse stehen. Den Dienst
            betreiben wir nicht, sein Sitz und sein Serverstandort sind uns nicht bekannt, eine Übermittlung in ein
            Drittland ist deshalb nicht ausgeschlossen. Mit dem Anbieter haben wir keinen Vertrag geschlossen.
            Rechtsgrundlage ist im Demo- und Pilotbetrieb Art. 6 Abs. 1 lit. f DSGVO, im Schulbetrieb lit. e. Das Bild
            lässt sich durch eines vom eigenen Server ersetzen, diese Erklärung wird dann angepasst.
        </p>
        <p>
            Außer diesem Bild werden keine Inhalte von fremden Servern eingebunden, etwa Videos, Karten oder
            Reichweitenmessung.
        </p>
    @else
        <p>
            Auch sonst werden keine Inhalte von fremden Servern eingebunden, etwa Videos, Karten, Profilbilder oder
            Reichweitenmessung.
        </p>
    @endif

    <h2 id="kontakt">{{ $heading('kontakt') }}</h2>
    <p>
        Wenn Sie uns per E-Mail schreiben, verarbeiten wir Ihre E-Mail-Adresse und den Inhalt Ihrer Nachricht, um Ihre
        Anfrage zu beantworten. Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO, wenn es um einen Vertrag oder dessen
        Anbahnung geht, sonst Art. 6 Abs. 1 lit. f DSGVO. Wir löschen die Nachricht, sobald die Anfrage erledigt ist und
        keine gesetzliche Aufbewahrungspflicht besteht. Ein Kontaktformular gibt es nicht.
    </p>
    <p>
        Eine normale E-Mail ist nicht verschlüsselt und kann auf dem Weg mitgelesen werden. Bitte schicken Sie uns darin
        keine Daten von Schüler:innen oder andere sensible Angaben.
    </p>
    <p>E-Mails, die die Plattform selbst verschickt, beschreibt Abschnitt {{ $no('benutzerkonten') }} unter „Passwort zurücksetzen“.</p>

    <h2 id="speicherdauer">{{ $heading('speicherdauer') }}</h2>
    <p>Wir speichern personenbezogene Daten nur so lange, wie es für den jeweiligen Zweck nötig ist.</p>
    <ul>
        <li>
            <strong>Sitzungen:</strong> Eine Sitzung läuft nach {{ $sessionMinutes }} Minuten ohne Aktivität ab.
            Abgelaufene Sitzungen werden regelmäßig aus der Datenbank entfernt.
        </li>
        <li>
            <strong>Server-Logfiles und Fehlerprotokoll:</strong> nur so lange, wie wir sie brauchen, siehe Abschnitt {{ $no('hosting') }}.
        </li>
        <li>
            <strong>Links zum Zurücksetzen des Passworts:</strong> 60 Minuten gültig, siehe Abschnitt {{ $no('benutzerkonten') }}.
        </li>
        <li>
            <strong>Konten:</strong> Die Plattform löscht Konten nicht automatisch. Sie werden gelöscht, wenn sie nicht
            mehr gebraucht werden, auf Anfrage oder durch die Schule nach deren eigenen Aufbewahrungs- und Löschfristen.
            Die Schuladministration kann Konten ihrer Schule löschen, dabei verschwinden auch die zugehörigen Abgaben,
            Gespräche und Rückfragen der Schüler:innen. Mit dem Konto einer Lehrkraft gehen ihre Unterrichtsstunden
            samt den Abgaben und Rückfragen dazu, Einträge im Klassenbuch bleiben. Die gewählte Sprache geht mit dem Konto.
        </li>
        <li>
            <strong>Unterrichts- und Lerndaten:</strong> Unterrichtsstunden mit ihren Fragen, Abgaben, Antworten und
            Rückfragen, „Erklär’s mir“-Gespräche und Einträge im Klassenbuch löscht die Plattform täglich automatisch,
            wenn sie älter sind als die Aufbewahrungsfrist. Aufbewahrt wird {{ $keptPhrase }}.
            @if ($transitionDate)
                Übergangsweise bleiben bis zum {{ $transitionDate }} noch zwei Schuljahre gespeichert, danach wird jedes
                Schuljahr zu seinem Ende gelöscht.
            @endif
            Lehrkräfte können Abgaben schon vorher löschen, etwa damit eine Hausaufgabe neu bearbeitet werden kann.
            Einzelne „Erklär’s mir“-Gespräche löschen wir auf Anfrage. Hochgeladene Arbeitsblätter entfernen wir auf
            Wunsch auch vom Server.
        </li>
        <li>
            <strong>Benachrichtigungen:</strong> {{ $notificationReadDays }} Tage nachdem sie gelesen wurden, ungelesene
            spätestens {{ $notificationDays }} Tage nach dem Eingang.
        </li>
        <li>
            <strong>Klassenzuordnung und Vertretungen:</strong> bis die Zuordnung aufgehoben oder die Klasse oder das Konto
            gelöscht wird. Eine abgelaufene Vertretung gewährt nichts mehr. Sie wird mit ihrem Hinweis täglich automatisch
            gelöscht, sobald sie länger zurückliegt als die Aufbewahrungsfrist der Unterrichtsstunden
            (aufbewahrt wird {{ $keptPhrase }}) und die Lehrkraft keine Stunde mehr in der Klasse hat.
        </li>
        <li>
            <strong>Stundenplan:</strong> bis die Schuladministration den Eintrag ändert oder löscht oder die Klasse
            gelöscht wird. Wird das Konto einer Lehrkraft gelöscht oder ist sie keine Lehrkraft mehr, bleibt der Eintrag
            ohne ihren Namen stehen.
        </li>
        <li>
            <strong>Tuto-Mikro:</strong> Ton wird nicht gespeichert. Der Nachweis über eine Sitzung wird
            {{ $mikroSessionDays }} Tage nach ihrem Beginn automatisch gelöscht. Einwilligungsnachweise der
            Erziehungsberechtigten bleiben bis zur Löschung des Schülerkontos gespeichert.
        </li>
        <li>
            <strong>Verbundene Apps:</strong> Der Einmal-Code gilt {{ $appCodeMinutes }} Minuten. Ein Zugang gilt
            {{ $appAccessDays ? $appAccessDays.' Tage' : 'ohne feste Frist' }}, endet früher, wenn die Person ihn
            entzieht oder das Passwort ändert, und geht mit dem Konto, siehe Abschnitt {{ $no('apps') }}.
        </li>
        <li>
            <strong>Daten der Schule bei der Registrierung:</strong> solange die Schule die Plattform nutzt, der Nachweis
            über die Annahme des Vertrags danach nur so lange, wie wir ihn zum Nachweis brauchen. Auch eine
            gesperrte Schule bleibt gespeichert. Auf Wunsch der Schule löschen wir sie samt Konten sowie Unterrichts- und
            Lerndaten.
        </li>
        <li><strong>E-Mails:</strong> bis die Anfrage erledigt ist, siehe Abschnitt {{ $no('kontakt') }}.</li>
    </ul>
    <div class="legal-note">
        <p class="legal-note-title">Demodaten</p>
        <p>
            Die Daten in der Demo sind erfunden. Bitte geben Sie keine echten personenbezogenen Daten ein. Demodaten
            können jederzeit ohne Vorankündigung zurückgesetzt oder gelöscht werden.
        </p>
    </div>

    <h2 id="kinder">{{ $heading('kinder') }}</h2>
    <p>
        Tuto ist für Schüler:innen ab etwa 10 Jahren gedacht. Die Schule stellt es bereit, genutzt wird es im Unterricht
        und für Hausaufgaben. Konten entstehen durch die Schuladministration oder durch die Registrierung mit dem
        Klassencode der Lehrkraft (siehe Abschnitt {{ $no('benutzerkonten') }}). Kinder und Jugendliche brauchen
        besonderen Schutz. Deshalb erheben wir nur, was die Plattform braucht, nutzen die Daten nicht für Werbung oder für
        eigene geschäftliche Zwecke und haben mit der Kurzfassung in Abschnitt {{ $no('ueberblick') }} für eine
        verständliche Sprache gesorgt.
    </p>
    <p>
        Eltern und Sorgeberechtigte können die Rechte ihrer Kinder ausüben, solange diese deren Tragweite noch nicht
        selbst überblicken. Mit zunehmender Reife können Kinder und Jugendliche ihre Rechte auch selbst wahrnehmen. Für
        das Tuto-Mikro holt die Schule die Einwilligung der Erziehungsberechtigten ein und informiert darüber selbst.
    </p>

    <h2 id="rechte">{{ $heading('rechte') }}</h2>
    <p>Sie haben nach der DSGVO folgende Rechte:</p>
    <ul>
        <li><strong>Auskunft</strong> über die gespeicherten Daten (Art. 15 DSGVO)</li>
        <li><strong>Berichtigung</strong> falscher Daten (Art. 16 DSGVO)</li>
        <li><strong>Löschung</strong> der Daten, wenn die gesetzlichen Voraussetzungen vorliegen (Art. 17 DSGVO)</li>
        <li><strong>Einschränkung</strong> der Verarbeitung (Art. 18 DSGVO)</li>
        <li><strong>Datenübertragbarkeit</strong> in einem gängigen, maschinenlesbaren Format (Art. 20 DSGVO)</li>
        <li>
            <strong>Widerspruch</strong> gegen eine Verarbeitung, die auf Art. 6 Abs. 1 lit. e oder f DSGVO beruht. Sie
            können ihn aus Gründen einlegen, die sich aus Ihrer besonderen Situation ergeben (Art. 21 DSGVO).
        </li>
        <li>
            <strong>Widerruf einer Einwilligung</strong> mit Wirkung für die Zukunft (Art. 7 Abs. 3 DSGVO). Nur das
            Tuto-Mikro stützt sich auf Einwilligungen (Art. 6 Abs. 1 lit. a DSGVO), die die Schule einholt. Eltern
            widerrufen über den Link in ihrem Einwilligungsschreiben oder bei der Schule.
        </li>
        <li><strong>Beschwerde</strong> bei einer Aufsichtsbehörde (siehe Abschnitt {{ $no('beschwerde') }})</li>
    </ul>
    <p>
        Die Daten zu Ihrem Konto können Sie in Ihrem Profil mit „Meine Daten herunterladen“ selbst als Datei laden. Für
        alles Weitere schreiben Sie an <a href="mailto:info@sebastian-bleuel.de">info@sebastian-bleuel.de</a>. Im
        Schulbetrieb wenden Sie sich bitte zuerst an Ihre Schule, wir unterstützen sie als Auftragsverarbeiter. Wir
        beantworten Anfragen innerhalb eines Monats. Um Missbrauch zu verhindern, prüfen wir vorher, ob die Anfrage von der
        betroffenen Person oder ihren Sorgeberechtigten kommt.
    </p>

    <h2 id="beschwerde">{{ $heading('beschwerde') }}</h2>
    <p>
        Sie haben das Recht, sich bei einer Datenschutz-Aufsichtsbehörde zu beschweren, wenn Sie meinen, dass Ihre Daten
        rechtswidrig verarbeitet werden (Art. 77 DSGVO). Für uns als Betreiber ist zuständig:
    </p>
    <address>
        Der Hessische Beauftragte für Datenschutz und Informationsfreiheit<br>
        Gustav-Stresemann-Ring 1<br>
        65189 Wiesbaden<br>
        <a href="https://datenschutz.hessen.de" rel="noopener noreferrer">datenschutz.hessen.de</a>
    </address>
    <p>
        Im Schulbetrieb können Sie sich außerdem an die Datenschutz-Aufsichtsbehörde des Landes wenden, in dem die
        Schule liegt.
    </p>

    <h2 id="aenderungen">{{ $heading('aenderungen') }}</h2>
    <p>
        Wir passen diese Datenschutzerklärung an, wenn sich die Plattform, die Verarbeitung oder die Rechtslage ändern,
        zum Beispiel bevor ein anderer KI-Anbieter eingesetzt wird. Es gilt die Fassung, die hier veröffentlicht ist.
    </p>
    <p>Stand: Oktober 2026</p>
@endsection
