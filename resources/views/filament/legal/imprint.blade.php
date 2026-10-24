@extends('filament.legal.layout')

@section('title', 'Impressum')
@section('description', 'Anbieterkennzeichnung von Tuto nach § 5 DDG')

@section('content')
    <h1>Impressum</h1>

    <h2 id="angaben">Angaben gemäß § 5 DDG</h2>
    <address>
        <strong>Softwareentwicklung Sebastian Bleuel</strong><br>
        Ortesweg 10<br>
        36145 Hofbieber<br>
        Deutschland
    </address>
    <p>Vertreten durch: Sebastian Bleuel (Geschäftsführer, Inhaber)</p>

    <h2 id="kontakt">Kontakt</h2>
    <dl class="legal-facts">
        <dt>Telefon</dt>
        <dd><a href="tel:+4915732599053">0157 32599053</a></dd>

        <dt>E-Mail</dt>
        <dd><a href="mailto:info@sebastian-bleuel.de">info@sebastian-bleuel.de</a></dd>
    </dl>

    <h2 id="umsatzsteuer">Umsatzsteuer-Identifikationsnummer</h2>
    <p>Umsatzsteuer-Identifikationsnummer gemäß § 27a Umsatzsteuergesetz: DE370290830</p>

    <h2 id="verantwortlich">Verantwortlich für den Inhalt</h2>
    <p>Verantwortlich im Sinne des § 18 Abs. 2 MStV:</p>
    <address>
        Sebastian Bleuel<br>
        Ortesweg 10<br>
        36145 Hofbieber
    </address>

    <h2 id="angebot">Hinweis zum Angebot</h2>
    <p>
        Tuto ist eine Lernplattform, die als Prototyp bei einem Hackathon entstanden ist. Er wird als Pilot für Schulen und
        Schulträger erprobt. Das Angebot richtet sich an öffentliche Stellen, etwa Schulen und Schulträger, sowie an
        Unternehmen. Es richtet sich nicht an Verbraucher, mit Verbrauchern werden keine Verträge geschlossen.
    </p>
    <p>
        Die Plattform enthält derzeit ausschließlich erfundene Demodaten. Bitte geben Sie keine echten
        personenbezogenen Daten ein. Wie wir mit personenbezogenen Daten umgehen, steht in der
        <a href="{{ route('legal.privacy') }}">Datenschutzerklärung</a>.
    </p>

    <h2 id="streitbeilegung">Verbraucherstreitbeilegung</h2>
    <p>
        Wir sind weder bereit noch verpflichtet, an Streitbeilegungsverfahren vor einer
        Verbraucherschlichtungsstelle teilzunehmen.
    </p>

    <h2 id="haftung-inhalte">Haftung für Inhalte</h2>
    <p>
        Für die Inhalte, die wir selbst auf der Plattform bereitstellen, haften wir nach den allgemeinen Gesetzen.
        Fremde Informationen, die Nutzerinnen und Nutzer eingeben oder hochladen, müssen wir nicht von uns aus
        überwachen und auch nicht gezielt nach Hinweisen auf Rechtsverstöße durchsuchen. Das ergibt sich aus Art. 4 bis 8
        der Verordnung (EU) 2022/2065 (Digital Services Act), auf die § 7 Abs. 1 DDG verweist. Erfahren wir von einem
        konkreten Rechtsverstoß, prüfen wir ihn und entfernen oder sperren den Inhalt zügig. Pflichten aus gerichtlichen
        oder behördlichen Anordnungen bleiben unberührt.
    </p>
    <p>
        Notizen, Arbeitsblätter, Antworten und Gesprächsbeiträge, die Lehrkräfte und Schüler:innen auf der Plattform
        eingeben oder hochladen, stammen von den jeweiligen Nutzerinnen und Nutzern.
    </p>
    <p>
        Zusammenfassungen, Hausaufgabenentwürfe, Verständniswerte und Rückmeldungen, die mit KI-Unterstützung oder durch
        die Auswertungslogik der Plattform entstehen, sind Hinweise und Vorschläge. Wir übernehmen keine Gewähr dafür,
        dass sie richtig und vollständig sind oder im Einzelfall passen. Sie ersetzen weder die fachliche Einschätzung
        noch die Benotung durch Lehrkräfte.
    </p>

    <h2 id="haftung-links">Haftung für Links</h2>
    <p>
        Wir verlinken nur wenige externe Seiten, etwa die Aufsichtsbehörde in der Datenschutzerklärung. Auf deren Inhalte
        haben wir keinen Einfluss, wir machen sie uns nicht zu eigen. Verantwortlich ist der jeweilige Betreiber. Beim
        Setzen des Links ist uns kein Rechtsverstoß aufgefallen. Wir kontrollieren die Seiten nicht laufend, gehen aber
        Hinweisen nach und entfernen den Link, wenn sich ein Verstoß bestätigt.
    </p>

    <h2 id="urheberrecht">Urheberrecht</h2>
    <p>
        Texte, Gestaltung und Programmcode, die wir selbst für die Plattform erstellt haben, sind urheberrechtlich
        geschützt. Wer sie über das gesetzlich Erlaubte hinaus vervielfältigen, verändern oder weitergeben möchte, braucht
        vorher unsere schriftliche Zustimmung. Inhalte, die Nutzerinnen und Nutzer einstellen, bleiben deren Werke. Wenn
        Sie eine Urheberrechtsverletzung entdecken, schreiben Sie uns bitte an die E-Mail-Adresse oben. Wir prüfen den
        Hinweis und nehmen den Inhalt bei einem Verstoß umgehend heraus.
    </p>
    <p>
        Lehrkräfte laden Arbeitsblätter und andere Materialien nur hoch, wenn sie dazu berechtigt sind.
    </p>
    <p>
        Die Schriftart Lexend steht unter der SIL Open Font License 1.1.
    </p>
@endsection
