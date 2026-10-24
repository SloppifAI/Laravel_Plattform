@php
    use App\Models\ConsentForm;

    /*
     * The three purposes of the platform's consent: audio, transcript, ai_analysis. Each is answered on its own and
     * builds on the one before, so a person can agree to the recording and still refuse the AI.
     */
    $purposes = [
        'audio' => [
            'title' => ConsentForm::PURPOSES['audio'],
            'guardian' => "Ich willige ein, dass Unterricht, in dem mein Kind spricht, als Ton aufgenommen wird (nur Ton, kein Video, kein Bild). Die Aufnahme kann nur die aufnehmende Lehrkraft hören. Sie wird spätestens nach {$recordingDays} Tagen automatisch gelöscht. Im Ton können zufällig auch Angaben zu Gesundheit, Herkunft, Religion oder Familie vorkommen; darin willige ich ausdrücklich ein (Art. 9 Abs. 2 lit. a DSGVO).",
            'staff' => "Ich willige ein, dass Unterricht, in dem ich spreche, als Ton aufgenommen wird (nur Ton, kein Video, kein Bild). Die Aufnahme kann nur die aufnehmende Lehrkraft hören. Sie wird spätestens nach {$recordingDays} Tagen automatisch gelöscht. Im Ton können zufällig auch Angaben zu Gesundheit, Herkunft, Religion oder Familie vorkommen; darin willige ich ausdrücklich ein (Art. 9 Abs. 2 lit. a DSGVO).",
        ],
        'transcript' => [
            'title' => ConsentForm::PURPOSES['transcript'],
            'guardian' => "Ich willige ein, dass die Aufnahme in einen Text umgewandelt wird (Transkript). Das geschieht auf dem Gerät der Lehrkraft, durch Eingabe der Lehrkraft oder, wenn die Schule einen Dienstleister dafür einsetzt, durch diesen. Der Ton geht dann an den unten genannten Dienstleister. Text, den die Lehrkraft aus dem Transkript in ihre Unterrichtsnotizen übernimmt, wird nicht nach {$recordingDays} Tagen gelöscht, sondern folgt den Fristen der Notizen ({$schoolYearsKept} Schuljahre).",
            'provider' => ['Dienstleister für die Texterkennung, falls einer eingesetzt wird', null],
            'staff' => "Ich willige ein, dass die Aufnahme in einen Text umgewandelt wird (Transkript), auf dem Gerät der Lehrkraft, durch Eingabe der Lehrkraft oder, wenn die Schule das einrichtet, durch einen Dienstleister. Text, den die Lehrkraft in ihre Unterrichtsnotizen übernimmt, folgt den Fristen der Notizen ({$schoolYearsKept} Schuljahre).",
        ],
        'ai_analysis' => [
            'title' => ConsentForm::PURPOSES['ai_analysis'],
            'guardian' => 'Ich willige ein, dass das Transkript mit Hilfe künstlicher Intelligenz ausgewertet wird (Zusammenfassung, Fachbegriffe, Tafelbild-Vorschlag). Namen der Klasse werden vorher durch Platzhalter ersetzt, der Ton wird nie an die KI gesendet. Die Lehrkraft liest das Ergebnis und entscheidet, was daraus folgt. Lehne ich diesen Zweck ab, verwendet die Schule Text aus der Aufnahme auch nicht für die Hausaufgaben-Funktion mit KI.',
            'provider' => ['KI-Anbieter und Land der Verarbeitung', $aiEnabled ? 'Anthropic PBC, USA' : null],
            'staff' => 'Ich willige ein, dass das Transkript mit Hilfe künstlicher Intelligenz ausgewertet wird (Zusammenfassung, Fachbegriffe, Tafelbild-Vorschlag). Namen werden vorher durch Platzhalter ersetzt, der Ton wird nie an die KI gesendet. Lehne ich diesen Zweck ab, verwendet die Schule Text aus der Aufnahme auch nicht für die Hausaufgaben-Funktion mit KI.',
        ],
    ];

    $statusOfAi = $aiEnabled ? 'derzeit eingeschaltet (Anbieter Anthropic PBC, USA)' : 'derzeit ausgeschaltet';
@endphp

@include('filament.pages.privacy-documents.partials.head', [
    'title' => 'Einwilligung in Tonaufnahmen im Unterricht (Muster)',
    'lead' => 'Muster für die Einwilligung von Eltern und Schüler:innen, mit einer kurzen Variante für Lehrkräfte und weitere anwesende Personen. Es gibt drei getrennte Zwecke: Tonaufnahme, Transkript und KI-Auswertung. Der Text ist an Eltern gerichtet und verwendet die Anrede „Sie“.',
])

<h3>Einwilligung für Schüler:innen</h3>

<dl class="pd-facts">
    <dt>Schule</dt>
    <dd>{{ $school['name'] ?? $blank }}</dd>

    <dt>Schüler:in</dt>
    <dd>{{ $blank }}</dd>

    <dt>Klasse</dt>
    <dd>{{ $blank }}</dd>

    <dt>Erziehungsberechtigte(r)</dt>
    <dd>{{ $blank }}</dd>
</dl>

<p>
    Lehrkräfte können an unserer Schule im Unterricht den Ton einer Stunde aufnehmen, um daraus Notizen, eine Zusammenfassung und einen Tafelbild-Vorschlag für den Unterricht zu gewinnen. Die Aufnahme dient nicht der Kontrolle von Schüler:innen oder Lehrkräften und nicht der Benotung. Alles Weitere steht in der Elterninformation. Bitte kreuzen Sie für jeden Zweck einzeln an:
</p>

<div class="space-y-4">
    @foreach ($purposes as $purpose)
        <section class="pd-card">
            <h4>{{ $loop->iteration }}. {{ $purpose['title'] }}</h4>
            <p>{{ $purpose['guardian'] }}</p>
            @isset ($purpose['provider'])
                <p><strong>{{ $purpose['provider'][0] }}:</strong> {{ $purpose['provider'][1] ?? $blank }}</p>
            @endisset
            @if ($loop->first)
                <p>Ich nehme zur Kenntnis, dass nur aufgenommen wird, wenn alle Anwesenden einwilligen.</p>
            @else
                <p>Das setzt die Einwilligung in Zweck {{ $loop->iteration - 1 }} voraus.</p>
            @endif
            <ul class="pd-options">
                <li><span class="pd-check" aria-hidden="true"></span> Ja, ich willige ein</li>
                <li><span class="pd-check" aria-hidden="true"></span> Nein, ich willige nicht ein</li>
            </ul>
        </section>
    @endforeach
</div>

<h4>Meine Rechte</h4>
<ul>
    <li><strong>Freiwillig:</strong> Die Einwilligung ist freiwillig. Wer nicht einwilligt, hat keine Nachteile. Dann findet keine Aufnahme statt.</li>
    <li><strong>Widerruf:</strong> Ich kann die Einwilligung jederzeit ohne Angabe von Gründen widerrufen, formlos und mit Wirkung für die Zukunft, bei {{ $blank }}. Die bisherige Verarbeitung bleibt rechtmäßig. Nach einem Widerruf wird nicht mehr aufgenommen, und bestehende Aufnahmen, auf denen mein Kind zu hören sein kann, werden auf Wunsch gelöscht.</li>
    <li><strong>Information:</strong> Ich habe die Elterninformation zur Lernplattform erhalten und gelesen. Die KI-Anbindung ist {{ $statusOfAi }}. Diese Einwilligung betrifft nur Aufnahmen. Für die übrigen KI-Funktionen der Plattform (Hausaufgaben, Abgaben, „Erklär’s mir“, mit Texten und Arbeitsblatt-Dateien) gilt die Elterninformation.</li>
    <li><strong>Dauer:</strong> Die Einwilligung gilt bis zum Widerruf, längstens bis {{ $blank }}.</li>
</ul>

@include('filament.pages.privacy-documents.partials.signatures', [
    'lines' => ['Ort, Datum', 'Unterschrift Erziehungsberechtigte(r)', 'Unterschrift weitere erziehungsberechtigte Person, falls von der Schule verlangt', 'Unterschrift Schüler:in, ab dem von der Schule festgelegten Alter'],
])

<h3 class="print:break-before-page">Variante für Lehrkräfte und weitere anwesende Personen</h3>
<p>
    Für Lehrkräfte, Vertretungen, Referendar:innen, Gäste und Begleitpersonen, die in einer aufgenommenen Stunde sprechen. Die Einwilligung ist freiwillig. Bei Beschäftigten gilt zusätzlich das Landesrecht beziehungsweise § 26 BDSG.
</p>

<dl class="pd-facts">
    <dt>Name</dt>
    <dd>{{ $blank }}</dd>

    <dt>Funktion</dt>
    <dd>{{ $blank }}</dd>
</dl>

<div class="space-y-4">
    @foreach ($purposes as $purpose)
        <section class="pd-card">
            <h4>{{ $loop->iteration }}. {{ $purpose['title'] }}</h4>
            <p>{{ $purpose['staff'] }}</p>
            @isset ($purpose['provider'])
                <p><strong>{{ $purpose['provider'][0] }}:</strong> {{ $purpose['provider'][1] ?? $blank }}</p>
            @endisset
            <ul class="pd-options">
                <li><span class="pd-check" aria-hidden="true"></span> Ja, ich willige ein</li>
                <li><span class="pd-check" aria-hidden="true"></span> Nein, ich willige nicht ein</li>
            </ul>
        </section>
    @endforeach
</div>

<p>
    Ich kann die Einwilligung jederzeit ohne Angabe von Gründen mit Wirkung für die Zukunft widerrufen, bei {{ $blank }}. Wer nicht einwilligt, hat keine Nachteile; dann wird nicht aufgenommen. Die Aufnahme dient nicht der Kontrolle oder Beurteilung meiner Arbeit.
</p>

@include('filament.pages.privacy-documents.partials.signatures', [
    'lines' => ['Ort, Datum', 'Unterschrift'],
])

<div class="pd-notice print:hidden" role="note" aria-labelledby="pd-school-hints">
    <p class="pd-notice-title" id="pd-school-hints">
        <x-filament::icon icon="heroicon-o-light-bulb" />
        <span>Hinweise für die Schule (werden nicht mitgedruckt)</span>
    </p>
    <ul>
        <li><strong>Anpassen:</strong> Land, Alter, ab dem Schüler:innen selbst unterschreiben, und die Zahl der erforderlichen Unterschriften der Sorgeberechtigten sind nach Landesrecht und Schulpraxis festzulegen. Dienstleister und KI-Anbieter sind einzutragen, sobald sie eingeschaltet werden.</li>
        <li><strong>Digital und auf Papier:</strong> Den Text der digitalen Einwilligung, die Eltern über einen persönlichen Link erteilen, veröffentlicht die Schuladministration unter „Aufnahmen & Einwilligungen“. Diese Vorlage hat dieselben drei Zwecke und ist als Papierfassung und als Grundlage für den digitalen Text gedacht.</li>
        <li><strong>Freiwilligkeit:</strong> Die Teilnahme am Unterricht oder andere Vorteile dürfen nicht von der Einwilligung abhängen (Art. 7 Abs. 4 DSGVO). Rückmeldungen am besten einzeln und schriftlich einholen, nicht per Handzeichen, damit kein Gruppendruck entsteht.</li>
        <li><strong>Eine Stimme fehlt, keine Aufnahme:</strong> Die Aufnahme erfasst alle im Raum. Fehlt eine Einwilligung, einschließlich einer Vertretung oder eines Gasts, darf nicht aufgenommen werden (auch § 201 StGB).</li>
        <li><strong>Nachweis:</strong> Unterschriebene Erklärungen auffindbar aufbewahren (Art. 7 Abs. 1 DSGVO), Widerrufe dokumentieren und an die Lehrkräfte weitergeben. Die Plattform speichert nur, dass die Lehrkraft vor einer Aufnahme bestätigt hat, und wann.</li>
        <li><strong>Erneuern:</strong> Bei neuen Anbietern, neuen Zwecken oder Klassenwechsel prüfen, ob die Einwilligung noch trägt.</li>
    </ul>
</div>
