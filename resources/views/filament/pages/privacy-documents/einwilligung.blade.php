@php
    use App\Models\ConsentForm;

    /*
     * The three purposes of the platform's consent: audio, transcript, ai_analysis. Each is answered on its own and
     * builds on the one before, so a person can agree to the Tuto-Mikro and still refuse the AI provider.
     */
    $purposes = [
        'audio' => [
            'title' => ConsentForm::PURPOSES['audio'],
            'guardian' => 'Ich willige ein, dass in Stunden, in denen mein Kind spricht, das Mikrofon der Lehrkraft eingeschaltet wird (Tuto-Mikro, nur Ton, kein Bild, kein Video). Der Ton wird nicht gespeichert. Im Ton können zufällig auch Angaben zu Gesundheit, Herkunft, Religion oder Familie vorkommen; darin willige ich ausdrücklich ein (Art. 9 Abs. 2 lit. a DSGVO).',
            'staff' => 'Ich willige ein, dass in Stunden, in denen ich spreche, das Mikrofon der Lehrkraft eingeschaltet wird (Tuto-Mikro, nur Ton, kein Bild, kein Video). Der Ton wird nicht gespeichert. Im Ton können zufällig auch Angaben zu Gesundheit, Herkunft, Religion oder Familie vorkommen; darin willige ich ausdrücklich ein (Art. 9 Abs. 2 lit. a DSGVO).',
        ],
        'transcript' => [
            'title' => ConsentForm::PURPOSES['transcript'],
            'guardian' => "Ich willige ein, dass aus dem Ton Text wird (Notizen aus der Stunde): im Browser der Lehrkraft, durch Eingabe der Lehrkraft oder, wenn ich auch Zweck 3 zustimme, durch den KI-Anbieter. Die Notizen bleiben im Browser der Lehrkraft, bis sie etwas in ihre Unterrichtsnotizen übernimmt. Übernommener Text wird mit ersetzten Namen gespeichert und {$retention} automatisch gelöscht.",
            'staff' => "Ich willige ein, dass aus dem Ton Text wird (Notizen aus der Stunde), im Browser der Lehrkraft, durch ihre Eingabe oder bei Zustimmung zu Zweck 3 durch den KI-Anbieter. Übernommener Text wird mit ersetzten Namen gespeichert und {$retention} automatisch gelöscht.",
        ],
        'ai_analysis' => [
            'title' => ConsentForm::PURPOSES['ai_analysis'],
            'guardian' => 'Ich willige ein, dass kurze Ton-Abschnitte zur Spracherkennung an den KI-Anbieter gehen. Darin sind echte Stimmen und gesprochene Namen zu hören; sie werden nicht pseudonymisiert und danach verworfen. Außerdem darf der KI-Anbieter aus den Notizen eine Zusammenfassung erstellen; dafür werden die Namen der Klasse vorher durch Platzhalter ersetzt. Die Lehrkraft liest das Ergebnis und entscheidet, was daraus folgt. Lehne ich diesen Zweck ab, bleibt das Tuto-Mikro aus, solange die Schule ihre KI-Funktionen nutzt und mein Kind im Raum ist.',
            'provider' => ['KI-Anbieter', $aiProvider],
            'staff' => 'Ich willige ein, dass kurze Ton-Abschnitte zur Spracherkennung an den KI-Anbieter gehen (echte Stimmen, nicht pseudonymisiert, danach verworfen) und dass er aus den Notizen eine Zusammenfassung erstellt, nachdem Namen durch Platzhalter ersetzt wurden.',
        ],
    ];

    $statusOfAi = $ai['enabled'] ? "derzeit eingeschaltet (Anbieter {$aiProvider})" : 'derzeit ausgeschaltet';
@endphp

@include('filament.pages.privacy-documents.partials.head', [
    'title' => 'Einwilligung zum Tuto-Mikro im Unterricht (Muster)',
    'lead' => 'Muster für die Einwilligung von Eltern und Schüler:innen, mit einer kurzen Variante für Lehrkräfte und weitere anwesende Personen. Es gibt drei getrennte Zwecke: Ton, Notizen aus der Stunde und die Verarbeitung durch den KI-Anbieter. Der Text ist an Eltern gerichtet und verwendet die Anrede „Sie“.',
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
    Lehrkräfte können an unserer Schule in einzelnen Stunden das Tuto-Mikro einschalten, um aus dem Ton der Stunde Notizen, eine Zusammenfassung und einen Tafelbild-Vorschlag für den Unterricht zu gewinnen. Tuto speichert keinen Ton. Die Liste, wer angehakt war, kann der Lehrkraft die Fehlzeiten im Klassenbuch vorschlagen, und zwar nur für Schüler:innen mit Einwilligung, die nicht angehakt waren; die Lehrkraft prüft das. Das Tuto-Mikro dient nicht der Kontrolle von Schüler:innen oder Lehrkräften und nicht der Benotung. Alles Weitere steht in der Elterninformation. Bitte kreuzen Sie für jeden Zweck einzeln an:
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
                <p>Ich nehme zur Kenntnis, dass das Tuto-Mikro nur eingeschaltet wird, wenn für alle Anwesenden eine Einwilligung vorliegt.</p>
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
    <li><strong>Freiwillig:</strong> Die Einwilligung ist freiwillig. Wer nicht einwilligt, hat keine Nachteile. Dann bleibt das Tuto-Mikro aus, wenn mein Kind im Raum ist.</li>
    <li><strong>Widerruf:</strong> Ich kann die Einwilligung jederzeit ohne Angabe von Gründen widerrufen, formlos und mit Wirkung für die Zukunft, über den persönlichen Link oder bei {{ $blank }}. Die bisherige Verarbeitung bleibt rechtmäßig. Nach einem Widerruf bleibt das Tuto-Mikro aus, wenn mein Kind im Raum ist, auch in einer laufenden Stunde.</li>
    <li><strong>Information:</strong> Ich habe die Elterninformation zu Tuto erhalten und gelesen. Die KI-Funktionen der Schule sind {{ $statusOfAi }}. Diese Einwilligung betrifft nur das Tuto-Mikro. Für die übrigen KI-Funktionen (Hausaufgaben, Abgaben, „Erklär’s mir“, Einfache Sprache) gilt die Elterninformation.</li>
    <li><strong>Dauer:</strong> Die Einwilligung gilt bis zum Widerruf, längstens bis {{ $blank }}.</li>
</ul>

@include('filament.pages.privacy-documents.partials.signatures', [
    'lines' => ['Ort, Datum', 'Unterschrift Erziehungsberechtigte(r)', 'Unterschrift weitere erziehungsberechtigte Person, falls von der Schule verlangt', 'Unterschrift Schüler:in, ab dem von der Schule festgelegten Alter'],
])

<h3 class="print:break-before-page">Variante für Lehrkräfte und weitere anwesende Personen</h3>
<p>
    Für Lehrkräfte, Vertretungen, Referendar:innen, Gäste und Begleitpersonen, die in einer Stunde mit Tuto-Mikro sprechen. Die Einwilligung ist freiwillig. Bei Beschäftigten gilt zusätzlich das Landesrecht beziehungsweise § 26 BDSG.
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
    Ich kann die Einwilligung jederzeit ohne Angabe von Gründen mit Wirkung für die Zukunft widerrufen, bei {{ $blank }}. Wer nicht einwilligt, hat keine Nachteile; dann bleibt das Tuto-Mikro aus, wenn ich im Raum bin. Das Tuto-Mikro dient nicht der Kontrolle oder Beurteilung meiner Arbeit.
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
        <li><strong>Anpassen:</strong> Land, Alter, ab dem Schüler:innen selbst unterschreiben, und die Zahl der erforderlichen Unterschriften der Sorgeberechtigten sind nach Landesrecht und Schulpraxis festzulegen. Den KI-Anbieter prüfen, bevor die KI-Funktionen eingeschaltet werden.</li>
        <li><strong>Digital und auf Papier:</strong> Den Text der digitalen Einwilligung, die Eltern über einen persönlichen Link erteilen, veröffentlicht die Schuladministration unter „Tuto-Mikro &amp; Einwilligungen“. Diese Vorlage hat dieselben drei Zwecke und ist als Papierfassung und als Grundlage für den digitalen Text gedacht. Papier-Einwilligungen und Widerrufe erfasst die Lehrkraft der Klasse oder die Schuladministration unter „Einwilligungen“.</li>
        <li><strong>Freiwilligkeit:</strong> Die Teilnahme am Unterricht oder andere Vorteile dürfen nicht von der Einwilligung abhängen (Art. 7 Abs. 4 DSGVO). Rückmeldungen am besten einzeln und schriftlich einholen, nicht per Handzeichen, damit kein Gruppendruck entsteht.</li>
        <li><strong>Eine Einwilligung fehlt, Mikro aus:</strong> Das Mikro erfasst alle im Raum. Fehlt eine Einwilligung, einschließlich einer Vertretung oder eines Gasts, bleibt das Tuto-Mikro aus (auch § 201 StGB). Die Plattform prüft nur die Schüler:innen, die die Lehrkraft als anwesend anhakt.</li>
        <li><strong>Nachweis:</strong> Unterschriebene Erklärungen auffindbar aufbewahren (Art. 7 Abs. 1 DSGVO), Widerrufe in der Plattform erfassen. Die Plattform speichert je Sitzung, wer angehakt war, wann die Lehrkraft die Liste bestätigt hat und welche Fassung galt, für {{ $sessionKeepDays }} Tage.</li>
        <li><strong>Erneuern:</strong> Bei einem anderen KI-Anbieter, neuen Zwecken oder Klassenwechsel prüfen, ob die Einwilligung noch trägt.</li>
    </ul>
</div>
