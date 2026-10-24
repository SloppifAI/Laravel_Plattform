@php
    $aiStatus = $ai['enabled']
        ? "eingeschaltet: {$aiData} gehen an {$aiProvider}, beim Tuto-Mikro außerdem Ton-Abschnitte zur Spracherkennung"
        : 'ausgeschaltet: keine Daten gehen an einen KI-Anbieter, eine einfache Demo-Logik arbeitet auf der Plattform';
    $blockedList = implode(', ', $blockedSubjects) ?: 'keine';

    $criteria = [
        ['Daten von Kindern', 'Schüler:innen sind überwiegend minderjährig und besonders schutzbedürftig.', true],
        ['Verarbeitung des gesprochenen Wortes', 'Das Tuto-Mikro macht aus dem Ton einer Stunde Text, sobald eine Lehrkraft es einschaltet. Der Ton wird nicht gespeichert, bei eingeschalteten KI-Funktionen aber in Abschnitten an den KI-Anbieter gesendet.', true],
        ['Besondere Kategorien personenbezogener Daten möglich', 'Im Ton, in Notizen und in Antworten können zufällig Angaben zu Gesundheit, Herkunft, Religion oder Familie vorkommen (Art. 9 DSGVO). Die Plattform kann sie weder erkennen noch ausfiltern. In gesperrten Fächern bleibt das Tuto-Mikro aus.', true],
        ['Bewertung persönlicher Aspekte', 'Der Verständniswert (0 bis 100) ist eine KI-gestützte Einschätzung zu einzelnen Schüler:innen. Er ist keine Note. Das Klassenbuch hält Fehlzeiten fest.', true],
        ['Neue Technologien', 'Sprachmodell und Spracherkennung eines KI-Anbieters. Beides ist für jede Schule ausgeschaltet, bis die Schuladministration die KI-Funktionen einschaltet.', true],
        ['Automatisierte Einzelentscheidung mit Rechtswirkung', 'Gibt es nicht. Menschen entscheiden, was aus den Ergebnissen folgt.', false],
    ];

    $dataCategories = [
        [
            'title' => 'Kontodaten',
            'data' => 'Name, Benutzername, auf Wunsch E-Mail-Adresse, Rolle, Schule, Klasse, gewählte Sprache. Das Passwort nur als Hash.',
            'people' => 'Schüler:innen, Lehrkräfte, Schuladministration',
            'access' => 'Schuladministration der Schule. Lehrkräfte sehen die Namen der Schüler:innen der Klassen, die sie leiten, unterrichten oder vertreten. Die Plattform-Administration sieht nur Administrationskonten.',
            'retention' => 'Bis die Schule das Konto löscht.',
        ],
        [
            'title' => 'Klassen, Zuordnungen und Vertretungen',
            'data' => 'Klasse, Klassenleitung, zugeordnete Lehrkräfte; je Vertretung die Lehrkraft, der Zeitraum, wer sie eingetragen hat und eine Notiz.',
            'people' => 'Lehrkräfte',
            'access' => 'Schuladministration und Lehrkräfte der Klasse.',
            'retention' => "Eine Vertretung dauert höchstens {$substitutionMaxDays} Tage und gewährt danach nichts mehr. Der Eintrag bleibt als Nachweis, bis die Klasse oder das Konto gelöscht wird.",
        ],
        [
            'title' => 'Unterrichtsstunden, Notizen, Arbeitsblätter, Hausaufgaben',
            'data' => 'Fach, Thema, Datum, Notizen (auch aus dem Tuto-Mikro übernommener Text), hochgeladene Arbeitsblätter, Zusammenfassung, Fragen in bis zu drei Stufen (Einstieg, Standard, Profi) mit Erwartungshorizont, Fassungen in Einfacher Sprache. Das trägt die Lehrkraft selbst ein oder lässt es von der KI entwerfen.',
            'people' => 'Lehrkräfte; nur zufällig genannte Dritte',
            'access' => 'Lehrkraft der Stunde und Schuladministration. Schüler:innen sehen freigegebene Hausaufgaben (Zusammenfassung und Fragen), nicht die Notizen, Arbeitsblätter und den Erwartungshorizont.',
            'retention' => 'Automatisch '.$retention.'.',
        ],
        [
            'title' => 'Abgaben, Antworten, Verständniswerte',
            'data' => 'Zusammenfassung und Antworten der Schüler:in mit der gewählten Stufe, Verständniswert, Rückmeldung, Hinweise auf Lücken.',
            'people' => 'Schüler:innen',
            'access' => 'Die Schüler:in selbst, die Lehrkraft der Stunde (eine Vertretung nur, solange sie dauert) und die Schuladministration. Den Lernstand einer Klasse sehen die Lehrkräfte der Klasse. Nie andere Schüler:innen.',
            'retention' => 'Automatisch '.$retention.'.',
        ],
        [
            'title' => '„Erklär’s mir“-Gespräche und Rückfragen',
            'data' => 'Thema, Gesprächsverlauf mit Tuto, Verständniswert, Rückmeldung; Rückfragen an die Lehrkraft und ihre Antwort.',
            'people' => 'Schüler:innen, Lehrkräfte',
            'access' => 'Gespräche und Rückfragen zu einer Stunde: die Schüler:in selbst, die Lehrkraft der Stunde (eine Vertretung nur, solange sie dauert) und die Schuladministration. Gespräche zu einem frei gewählten Thema: nur die Schüler:in selbst.',
            'retention' => 'Automatisch '.$retention.'. Rückfragen werden mit ihrer Stunde gelöscht.',
        ],
        [
            'title' => 'Klassenbuch',
            'data' => 'Datum, Fach, Thema, Inhalt, Hausaufgabe, Fehlzeiten (welche Schüler:innen gefehlt haben), Bemerkungen, Bestätigung. Den Entwurf erstellt Tuto aus der Stunde; die Fehlzeiten schlägt Tuto aus der Anwesenheitsliste des Tuto-Mikro vor, die Lehrkraft prüft und bestätigt.',
            'people' => 'Schüler:innen, Lehrkräfte',
            'access' => 'Lehrkräfte der Klasse (eine Vertretung, solange sie dauert, mit allen Einträgen und dem Export) und die Schuladministration. Nie Schüler:innen.',
            'retention' => 'Automatisch '.$retention.', nach dem Datum des Eintrags.',
        ],
        [
            'title' => 'Tuto-Mikro (optional)',
            'data' => 'Ton der Stunde: wird nicht gespeichert. Notizen aus der Stunde (erkannter Text): nur im Browser der Lehrkraft, bis sie etwas in die Stundennotizen übernimmt. Gespeichert wird je Sitzung ein Nachweis: Lehrkraft, Beginn, Ende, Dauer, angehakte Schüler:innen, Zeitpunkt der Bestätigung der Anwesenheit, Fassung der Einwilligung, geprüfte Zwecke und ob Ton-Abschnitte an den KI-Anbieter gingen. Im Ton kann zufällig auch Gesprochenes nach Art. 9 DSGVO vorkommen.',
            'people' => 'Schüler:innen (minderjährig), Lehrkraft, weitere anwesende Personen',
            'access' => 'Den Nachweis sieht nur die Lehrkraft der Stunde. Nicht die Schuladministration, nicht andere Lehrkräfte, nicht die Plattform-Administration. Schüler:innen finden nur die eigene Anwesenheit (Fach, Thema, Beginn, Ende) in ihrem Datenexport.',
            'retention' => "Nachweis: {$sessionKeepDays} Tage ab Beginn, früher mit der Stunde oder dem Konto der Lehrkraft. In die Stundennotizen übernommener Text: wie die Notizen.",
        ],
        [
            'title' => 'Einwilligungen für das Tuto-Mikro (optional)',
            'data' => 'Pro Schüler:in: Name der erklärenden Person, Zwecke (Ton, Notizen aus der Stunde, Spracherkennung und Zusammenfassung durch den KI-Anbieter), Fassung des Textes, Zeitpunkt, Art (digital oder Papier), Widerruf, auf Wunsch die eigene Bestätigung der Schüler:in. Der Link zur Einwilligung wird nur als Hash gespeichert.',
            'people' => 'Erziehungsberechtigte, Schüler:innen',
            'access' => 'Schuladministration und die Lehrkräfte der Klasse (Klassenleitung, zugeordnete Lehrkräfte, laufende Vertretung). Schüler:innen nur die eigene. Die Plattform-Administration sieht keine Einwilligungen.',
            'retention' => 'Solange das Konto der Schüler:in besteht. Die Schule bewahrt Papier-Originale nach ihren eigenen Fristen auf.',
        ],
        [
            'title' => 'Benachrichtigungen',
            'data' => 'Hinweise in der Glocke, etwa zu einer neuen Hausaufgabe oder einer Rückfrage, mit Thema, Fach und Klasse der Stunde; Zeitpunkt des Lesens.',
            'people' => 'alle Nutzenden',
            'access' => 'Nur die Person, die sie erhält.',
            'retention' => "{$notificationReadDays} Tage nach dem Lesen, spätestens {$notificationMaxDays} Tage nach dem Erstellen.",
        ],
        [
            'title' => 'KI-Einstellung der Schule',
            'data' => 'Die vier Bestätigungen der Checkliste, Zeitpunkt des Einschaltens und die Schuladministration, die eingeschaltet hat.',
            'people' => 'Schuladministration',
            'access' => 'Schuladministration der Schule.',
            'retention' => 'Bis zum Ausschalten oder zur Löschung der Schule.',
        ],
        [
            'title' => 'Technische Daten',
            'data' => 'Sitzungskennung, IP-Adresse, Browserangabe, Zeitpunkt der letzten Aktivität, gewählte Sprache, Server-Logfiles, Fehlerprotokoll. Im Browser bleiben die Einstellungen „Einfache Sprache“ und „Namen verbergen“; sie verlassen das Gerät nicht.',
            'people' => 'alle Nutzenden',
            'access' => 'Betreiber, nur für Betrieb, Sicherheit und Fehlersuche.',
            'retention' => "Sitzung {$sessionMinutes} Minuten ohne Aktivität; Logfiles nach der Frist des Betreibers.",
        ],
    ];

    $risks = [
        [
            'title' => 'Verhaltensanpassung durch Überwachungsdruck',
            'impact' => 'Schüler:innen und Lehrkräfte sprechen nicht mehr frei, stellen weniger Fragen oder äußern sich vorsichtiger, weil das Mikro an ist (Chilling Effect). Das beeinträchtigt Unterricht und die freie Entfaltung der Persönlichkeit, bei Kindern besonders.',
            'measures' => [
                'Das Tuto-Mikro ist die bewusste Ausnahme einer Lehrkraft für eine Stunde. Sie schaltet es ein und aus; es gibt keinen Dauer- oder Hintergrundbetrieb.',
                'Nur das Mikrofon der Lehrkraft, nur Ton, kein Bild. Tuto speichert keinen Ton.',
                'Eine feste Leiste zeigt auf dem Gerät der Lehrkraft die ganze Zeit „Mikro an – Tuto schreibt mit“ oder „Mikro aus“. Die Lehrkraft kann außerdem eine Anzeige für den Beamer öffnen, die der Klasse nur „an“ oder „aus“ und das Thema der Stunde zeigt, keine Namen und keinen Text. Die Plattform erzwingt das Öffnen nicht (siehe „Was die Plattform nicht leistet“).',
                "In gesperrten Fächern bleibt das Tuto-Mikro aus (derzeit: {$blockedList}), ebenso bei Förderaufgaben.",
                'Schuladministration und andere Lehrkräfte sehen weder die Notizen aus der Stunde noch den Nachweis der Sitzung. Die Ergebnisse sind für die Lehrkraft gedacht, nicht zur Kontrolle.',
            ],
        ],
        [
            'title' => 'Missbrauch und Weitergabe von Ton oder Notizen',
            'impact' => 'Stimmen oder Äußerungen von Kindern werden weitergegeben, in Messengern geteilt, für Mobbing oder zur Bloßstellung genutzt, oder dienen der Leistungs- oder Verhaltenskontrolle von Lehrkräften.',
            'measures' => [
                'Es gibt keine Tondatei: Tuto speichert einen Abschnitt nirgends und erkennt ihn aus dem Arbeitsspeicher der Anfrage. Es gibt nichts zum Anhören oder Herunterladen. Grenze: Der Webserver kann einen größeren Abschnitt für die Dauer der Anfrage kurz in einer temporären Datei zwischenpuffern; sie wird mit dem Ende der Anfrage gelöscht.',
                'Die Notizen aus der Stunde liegen nur im Browser der Lehrkraft und sind weg, wenn sie die Seite schließt. Die Zusammenfassung wird nicht gespeichert.',
                'Grenze: Die Lehrkraft kann Text von der Seite kopieren. Was sie in die Stundennotizen übernimmt, sieht wie alle Notizen auch die Schuladministration. Das regelt die Dienstanweisung.',
            ],
        ],
        [
            'title' => 'Unbefugter Zugriff',
            'impact' => 'Fremde lesen Lerndaten, Fehlzeiten oder Notizen, etwa durch übernommene Konten, geteilte Passwörter, geteilte Geräte oder Schwachstellen.',
            'measures' => [
                'Passwörter werden nur als Hash gespeichert. Eine Sitzung läuft nach '.$sessionMinutes.' Minuten ohne Aktivität ab.',
                'Jede Schule sieht nur ihre Daten. Die Plattform-Administration hat keine Einsicht in Unterrichts- und Lerndaten.',
                'Lehrkräfte sehen Lernstände, Klassenbuch und Einwilligungen nur in Klassen, die sie leiten, unterrichten oder gerade vertreten. Eine gehaltene Stunde allein gewährt das nicht.',
                'Alles läuft über eine gesicherte Verbindung (HTTPS); Anfragen sind gegen Fälschung geschützt (CSRF). Der persönliche Link der Eltern wird nur als Hash gespeichert.',
                'Grenze: Die Anwendung verschlüsselt die Datenbank nicht zusätzlich. Server, Datenträger und Sicherungen schützt der Betrieb; das beschreibt die Anlage zu den technischen und organisatorischen Maßnahmen des Auftragsverarbeitungsvertrags.',
            ],
        ],
        [
            'title' => 'Wiedererkennung trotz Pseudonymisierung',
            'impact' => 'Personen werden aus Texten, die an die KI gehen, wiedererkannt, obwohl Namen ersetzt wurden: durch Namen, die nicht bekannt sind (Eltern, andere Lehrkräfte, Spitznamen), durch Erkennungsfehler, durch Inhalte oder durch die Stimme selbst.',
            'measures' => [
                'Vor jeder Anfrage an die KI ersetzt Tuto die Namen der Schüler:innen der Klasse und der Lehrkräfte der Klasse durch Platzhalter („Schüler:in A“, „Lehrkraft“, bei mehrdeutigen Namensteilen „Person“). Das gilt für alle Texte, auch für die Notizen aus dem Tuto-Mikro und für Text, der in die Stundennotizen übernommen wird.',
                'Benutzernamen, E-Mail-Adressen, Konto-Kennungen sowie Namen von Klasse und Schule gehen nie an die KI. Die KI bekommt die Anweisung, Personen nie zu erraten oder zu identifizieren.',
                'Grenze: Die Ersetzung ist eine technische Hilfe und keine Anonymisierung. Namen anderer Personen, Spitznamen und falsch geschriebene Namen bleiben stehen. Die Schule geht von personenbezogenen Daten aus.',
                'Grenze: Arbeitsblatt-Dateien (Fotos, PDF) gehen unverändert an den Anbieter. Die Ton-Abschnitte des Tuto-Mikro gehen unverändert zur Spracherkennung: Darin sind echte Stimmen und gesprochene Namen.',
            ],
        ],
        [
            'title' => 'Fehler und Verzerrungen der KI',
            'impact' => 'Entwürfe, Einschätzungen oder Zusammenfassungen sind falsch, unvollständig oder verzerrt. Ein Verständniswert wird wie eine Note gelesen, Vorschläge werden ungeprüft übernommen (Automatisierungsneigung), Kinder werden dadurch benachteiligt.',
            'measures' => [
                'Es gibt keine automatisierte Entscheidung im Sinne von Art. 22 DSGVO. Der Verständniswert ist ausdrücklich keine Note; die Lehrkraft entscheidet, was daraus folgt.',
                'Hausaufgaben-Entwürfe und Stufen bleiben Entwürfe, bis die Lehrkraft sie freigibt. Die Zusammenfassung aus dem Tuto-Mikro ist ein Vorschlag; die Lehrkraft übernimmt nur, was sie will.',
                'Grenze: Eine Förderaufgabe mit „Direkt freigeben“ geht ohne getrennte Prüfung an die Schüler:innen. Die Einschätzung einer Abgabe und Tutos Rückfragen in „Erklär’s mir“ sehen die Schüler:innen sofort, ohne vorherige Prüfung durch die Lehrkraft.',
                'Fällt der Anbieter aus, zeigt Tuto einen Fehler und weicht nicht still auf die Demo-Logik aus.',
            ],
        ],
        [
            'title' => 'Übermittlung an den KI-Anbieter, besonders in Drittländer',
            'impact' => 'Texte, Arbeitsblätter oder Ton gelangen an einen Anbieter außerhalb der Kontrolle der Schule, möglicherweise in die USA, ohne ausreichende Garantien, ohne Vertrag oder zur Verbesserung seiner Produkte.',
            'measures' => array_values(array_filter([
                'Nichts geht an einen KI-Anbieter, solange der Schalter der Plattform aus ist oder die Schuladministration die KI-Funktionen nicht selbst eingeschaltet hat. Dafür bestätigt sie vier Punkte: Auftragsverarbeitung mit dem Anbieter, bezahlter Tarif ohne Nutzung der Eingaben zur Produktverbesserung, Folgenabschätzung, Eltern und Schüler:innen informiert. Aktuell: KI-Funktionen '.($ai['enabled'] ? 'eingeschaltet' : 'ausgeschaltet').', Anbieter '.$aiProvider.'.',
                $aiProviderIsLocal ? 'Der Betreiber nutzt ein selbst betriebenes Sprachmodell (Ollama); die Daten gehen dann an keinen fremden Anbieter.' : null,
                'Im kostenlosen Tarif von Gemini darf Google Eingaben zur Verbesserung seiner Produkte nutzen. Deshalb verlangt die Checkliste einen bezahlten Tarif.',
                'Tuto bittet den Anbieter bei jeder Anfrage, nichts zu speichern („store: false“, so sendet es die KI-Bibliothek), und führt dort keine Gesprächsverläufe. Ob und wie lange der Anbieter Daten dennoch vorhält, regelt sein Vertrag, nicht die Plattform.',
                'Der Ton des Tuto-Mikro geht nur bei eingeschalteten KI-Funktionen und nur dann zur Spracherkennung, wenn für alle angehakten Schüler:innen auch diesem Zweck zugestimmt wurde. Sonst verlässt der Ton das Gerät der Lehrkraft nicht.',
                'Grenze: Die Plattform prüft nicht, ob Vertrag und Garantien nach Art. 44 ff. DSGVO vorliegen. Das lässt sich die Schule bestätigen (Checkliste).',
            ])),
        ],
        [
            'title' => 'Tuto-Mikro ohne wirksame Einwilligung',
            'impact' => 'Die Stimme einer Person wird verarbeitet, ohne dass sie oder ihre Eltern eingewilligt haben, oder die Einwilligung ist unwirksam, weil sie nicht freiwillig war (Abhängigkeit, Gruppendruck) oder nicht informiert. Das ist unzulässig und kann nach § 201 StGB strafbar sein.',
            'measures' => [
                'Das Tuto-Mikro ist je Schule ausgeschaltet, bis die Schuladministration es einschaltet; vorher lässt die Plattform das Tuto-Mikro nicht starten. Starten kann es nur die Lehrkraft der Stunde, und nur in einer Klasse, die sie leitet, unterrichtet oder gerade vertritt.',
                'Die Lehrkraft hakt an, wer im Raum ist, und bestätigt: „Nur die Schüler:innen, die hier angehakt sind, sind im Raum.“ Der Server startet das Tuto-Mikro nur, wenn für jede angehakte Schüler:in eine gültige Einwilligung zur aktuellen Fassung vorliegt, die Ton und Notizen abdeckt und, bei eingeschalteten KI-Funktionen, auch die Spracherkennung und Zusammenfassung durch den KI-Anbieter.',
                "Die Einwilligungen werden während der Sitzung etwa alle {$segmentSeconds} Sekunden und vor jeder Zusammenfassung erneut geprüft. Ein Widerruf oder ein ausgeschalteter Schalter beendet das Tuto-Mikro bei der nächsten Prüfung durch den Server. Eine Sitzung, die älter als {$maxSessionHours} Stunden ist, nimmt nichts mehr an.",
                'Grenze: Ohne Verbindung zum Server kann die Prüfung nicht stattfinden. Dann geht nichts an den KI-Anbieter, aber die Spracherkennung auf dem Gerät der Lehrkraft läuft weiter, bis die Verbindung wieder da ist oder die Lehrkraft das Mikro ausschaltet.',
                'Grenze: Die Anwesenheitsliste ist die Erklärung der Lehrkraft; eine eigene Anwesenheitserfassung hat die Plattform nicht. Nicht angehakte Personen prüft sie nicht, und wer später dazukommt, ist erst nach einem Neustart erfasst. Einwilligungen von Lehrkräften und weiteren Personen im Raum kennt sie nicht, und ob eine Einwilligung wirklich freiwillig zustande kam, kann sie nicht beurteilen.',
            ],
        ],
        [
            'title' => 'Zu lange Speicherung, unvollständige Löschung',
            'impact' => 'Daten bleiben länger gespeichert als nötig: in Sicherungen, in kopierten Texten, in übernommenen Notizen, im Klassenbuch. Sie können nach einem Widerruf nicht mehr gefunden werden.',
            'measures' => [
                'Ton wird gar nicht gespeichert, die Notizen aus der Stunde nur im Browser. Reste der früheren Version mit Tondateien räumt eine tägliche Löschung ab.',
                "Der Nachweis einer Tuto-Mikro-Sitzung wird täglich nach {$sessionKeepDays} Tagen gelöscht, früher mit der Stunde oder dem Konto der Lehrkraft.",
                'Unterrichtsstunden mit Abgaben, Antworten, Rückfragen und Arbeitsblättern, „Erklär’s mir“-Gespräche und Klassenbuch-Einträge löscht Tuto automatisch '.$retention.'.',
                "Benachrichtigungen werden {$notificationReadDays} Tage nach dem Lesen gelöscht, spätestens nach {$notificationMaxDays} Tagen.",
                'Grenze: Sicherungen des Betreibers und Kopien der Lehrkraft folgen eigenen Fristen. Die Schule klärt das mit dem Betreiber (Auftragsverarbeitungsvertrag) und in der Dienstanweisung.',
            ],
        ],
        [
            'title' => 'Zweckentfremdung',
            'impact' => 'Notizen, Nachweise oder Fehlzeiten werden für andere Zwecke verwendet als vereinbart, etwa zur Beurteilung von Lehrkräften, als Beweismittel oder für Disziplinarmaßnahmen.',
            'measures' => [
                'Den Nachweis einer Sitzung sieht nur die Lehrkraft der Stunde. Die Schulleitung kann ihn nicht über die Plattform abrufen.',
                'Der Zweck ist in Einwilligung und Elterninformation festgelegt. Die Dienstvereinbarung kann ihn für Beschäftigte festschreiben.',
                'Grenze: Die Anwesenheitsliste des Tuto-Mikro dient auch als Vorschlag für die Fehlzeiten im Klassenbuch (nur für Schüler:innen mit Einwilligung, die nicht angehakt waren). Die Lehrkraft prüft und bestätigt den Eintrag. Die Schule nimmt diese Nutzung in ihre Regeln zum Klassenbuch auf.',
            ],
        ],
        [
            'title' => 'Zu weiter Zugriff innerhalb der Schule',
            'impact' => 'Mehr Lehrkräfte als nötig sehen Lernstände, Fehlzeiten oder Einwilligungen, etwa nach einem Wechsel der Lehrkraft oder durch eine Vertretung.',
            'measures' => [
                'Der Zugriff folgt der Zuordnung zur Klasse: Klassenleitung, zugeordnete Lehrkräfte und laufende Vertretung.',
                "Eine Vertretung trägt die Klassenleitung oder die Schuladministration ein, für höchstens {$substitutionMaxDays} Tage. Sie gewährt: {$substitutionGrants}. Nach dem letzten Tag endet der Zugriff von selbst, auch auf die Ergebnisse der Stunden, die die Vertretung gehalten hat.",
                'Grenze: Die Zuordnungen pflegt die Schule. Bleibt eine Lehrkraft einer Klasse zugeordnet, die sie nicht mehr unterrichtet, behält sie den Zugriff.',
            ],
        ],
        [
            'title' => 'Betroffenenrechte nicht erfüllbar',
            'impact' => 'Betroffene können Auskunft, Berichtigung, Löschung oder Widerruf nicht durchsetzen, weil niemand weiß, wo Daten liegen oder wer zuständig ist.',
            'measures' => [
                'Jede Person kann ihre Daten im Profil herunterladen. Das Konto mit der gewählten Sprache und die Benachrichtigungen gehören immer dazu. Bei Schüler:innen außerdem: Hausaufgaben, Abgaben mit Stufe, Gespräche, Rückfragen, Fehlzeiten im Klassenbuch, die Einwilligung zum Tuto-Mikro und die eigene Anwesenheit in Sitzungen. Bei Lehrkräften: Klassen, Vertretungen, Unterrichtsstunden, beantwortete Rückfragen, Klassenbuch-Einträge und Tuto-Mikro-Sitzungen. Die Schuladministration kann Konten und damit zugehörige Daten löschen.',
                "Grenze: Einen einzelnen Nachweis einer Tuto-Mikro-Sitzung kann über die Plattform niemand löschen. Die Schuladministration kann ihn nur mit der Stunde oder dem Konto der Lehrkraft löschen, oder sie beauftragt den Betreiber. Spätestens löscht die Plattform ihn nach {$sessionKeepDays} Tagen. Papier-Originale von Einwilligungen bewahrt die Schule selbst auf; sie sind nicht im Download.",
                'Eltern widerrufen eine Einwilligung jederzeit über ihren persönlichen Link; die Schule erfasst Widerrufe auf Papier in der Plattform.',
                'Die Elterninformation benennt Ansprechpartner und Rechte.',
            ],
        ],
    ];

    $platformMeasures = [
        'Trennung der Schulen' => [
            'Jede Schule sieht nur ihre eigenen Klassen, Konten, Stunden, Abgaben, Gespräche, Klassenbücher und Einwilligungen. Schulen sehen nichts voneinander.',
            'Die Plattform-Administration betreut die Plattform und die Schulen. Sie sieht keine Unterrichts- und Lerndaten.',
        ],
        'Rollen und Rechte' => [
            'Vier Rollen mit festen Rechten: Plattform-Administration, Schuladministration, Lehrkraft, Schüler:in. Die Prüfung erfolgt serverseitig bei jedem Abruf. Die Seite „Rollen & Rechte“ macht sie sichtbar.',
            'Lehrkräfte arbeiten nur in Klassen, die sie leiten, unterrichten oder vertreten. Eine Vertretung ist zeitlich begrenzt und endet von selbst.',
            'Das Tuto-Mikro und sein Nachweis gehören der Lehrkraft der Stunde allein.',
        ],
        'Einwilligung und Transparenz' => [
            'Das Tuto-Mikro ist für jede Schule zunächst ausgeschaltet. Die Schuladministration schaltet es erst ein, nachdem sie fünf Punkte bestätigt hat (Folgenabschätzung, Personalrat, Eltern informiert, Einwilligungserklärung freigegeben, Aufbewahrungsfrist) und eine Einwilligungserklärung veröffentlicht hat. Die Plattform kann nicht prüfen, ob diese Bestätigungen stimmen.',
            'Eltern willigen je Schüler:in über einen persönlichen Link oder auf Papier ein, getrennt nach Zweck, und können über denselben Link widerrufen. Wird der Text geändert, gelten Einwilligungen zur alten Fassung als veraltet.',
            'Die Plattform startet das Tuto-Mikro nur, wenn für alle angehakten Schüler:innen eine gültige, nicht widerrufene Einwilligung zur aktuellen Fassung vorliegt, die die benötigten Zwecke abdeckt, und, wenn die Schule es verlangt, die Schüler:in selbst bestätigt hat. Sie prüft das während der Sitzung erneut.',
            'Während das Mikro an ist, zeigt eine feste Leiste den Zustand. Öffnet die Lehrkraft die Beamer-Anzeige, sieht die Klasse dort „an“ oder „aus“.',
            'Die Seite „Datenschutz & KI“ erklärt allen Rollen in einfacher Sprache, was gespeichert wird, wer es sieht und was die KI bekommt.',
        ],
        'Datensparsamkeit und Fristen' => [
            'Kein Ton wird gespeichert, kein Bild, kein Video. Die Notizen aus der Stunde bleiben im Browser der Lehrkraft, bis sie etwas übernimmt.',
            'Unterrichts- und Lerndaten und das Klassenbuch löscht Tuto automatisch '.$retention.'. Nachweise des Tuto-Mikro nach '.$sessionKeepDays.' Tagen, Benachrichtigungen spätestens nach '.$notificationMaxDays.' Tagen.',
            'Die Einstellungen „Einfache Sprache“ und „Namen verbergen“ bleiben im Browser.',
            'Auskunft und Datenübertragbarkeit: Jede Person kann ihre Daten im Profil herunterladen.',
        ],
        'KI und Dritte' => [
            'KI-Funktionen laufen nur, wenn der Schalter der Plattform an ist und die Schule sie selbst eingeschaltet hat; sonst arbeitet eine Demo-Logik auf der Plattform.',
            'Vor jeder Anfrage werden die Namen der Klasse und ihrer Lehrkräfte durch Platzhalter ersetzt (Pseudonymisierung, keine Anonymisierung). Arbeitsblatt-Dateien und Ton-Abschnitte gehen unverändert.',
            'Einfache Sprache und Stufen bekommen nur Texte der Lehrkraft, nie Texte der Schüler:innen.',
            'Keine automatisierten Einzelentscheidungen. Der Verständniswert ist keine Note.',
        ],
        'Technik' => [
            'Passwörter nur als Hash, Sitzungsende nach '.$sessionMinutes.' Minuten ohne Aktivität, Schutz gegen gefälschte Anfragen.',
            'Tuto schreibt keinen Ton in eine Datei oder Datenbank: Ein Abschnitt wird aus dem Arbeitsspeicher der Anfrage erkannt und danach verworfen. Der Webserver kann ihn für die Dauer der Anfrage kurz zwischenpuffern.',
            'Kein Tracking, keine Werbung, keine Schriftarten oder Inhalte von fremden Servern im Kern der Plattform. Nur technisch notwendige Cookies.',
            'Server, Sicherungen und Unterauftragsverarbeiter beschreibt der Auftragsverarbeitungsvertrag.',
        ],
    ];
@endphp

@include('filament.pages.privacy-documents.partials.head', [
    'title' => 'Datenschutz-Folgenabschätzung (Art. 35 DSGVO)',
    'lead' => 'Vorlage für Tuto einschließlich der KI-Funktionen und des optionalen Tuto-Mikro: Beschreibung, Notwendigkeit, Risiken für die Rechte der Kinder, Maßnahmen und die Bewertung der Schule.',
])

<h3>0. Beteiligte und Stand</h3>
<dl class="pd-facts">
    <dt>Verantwortliche Stelle</dt>
    <dd>{{ $school['name'] ?? $blank }}@if (filled($school['city'])), {{ $school['city'] }}@endif</dd>

    <dt>Schulnummer</dt>
    <dd>{{ $school['number'] ?? $blank }}</dd>

    <dt>Datenschutzbeauftragte(r)</dt>
    <dd>{{ $blank }}</dd>

    <dt>Auftragsverarbeiter (Betreiber)</dt>
    <dd>@include('filament.pages.privacy-documents.partials.operator')</dd>

    <dt>Bewertet von / mit</dt>
    <dd>{{ $blank }}</dd>

    <dt>Datum der Bewertung</dt>
    <dd>{{ $blank }}</dd>
</dl>

<h3>1. Anlass und Schwellwertprüfung</h3>
<p>
    Eine Folgenabschätzung ist nötig, wenn eine Verarbeitung voraussichtlich ein hohes Risiko für die Rechte und Freiheiten natürlicher Personen bringt (Art. 35 Abs. 1 DSGVO). Hier treffen mehrere Merkmale zusammen:
</p>
<ul class="pd-checklist">
    @foreach ($criteria as [$criterion, $explanation, $applies])
        <li>
            <span @class(['pd-check', 'pd-on' => $applies]) aria-hidden="true"></span>
            <div>
                <p><strong>{{ $criterion }}: {{ $applies ? 'trifft zu' : 'trifft nicht zu' }}</strong></p>
                <p>{{ $explanation }}</p>
            </div>
        </li>
    @endforeach
</ul>
<p>
    Weil mehrere Merkmale zusammentreffen, ist eine Folgenabschätzung zu empfehlen, jedenfalls vor dem Einschalten der KI-Funktionen und vor dem Tuto-Mikro. Ob die Aufsichtsbehörde des Landes die Verarbeitung ohnehin in ihrer Liste nach Art. 35 Abs. 4 DSGVO führt, prüft die Schule.
</p>

<h3>2. Systematische Beschreibung der Verarbeitung</h3>

<h4>2.1 Zwecke</h4>
<ul>
    <li>
        <strong>Tuto:</strong> Lehrkräfte halten fest, was sie im Unterricht behandelt haben, und geben daraus Hausaufgaben frei, auch in Stufen und in Einfacher Sprache. Schüler:innen sehen, was sie schon verstanden haben, üben mit „Erklär’s mir“ und stellen Rückfragen. Lehrkräfte erkennen Förderbedarf und führen das Klassenbuch. Vertretungen übernehmen eine Klasse für eine begrenzte Zeit.
    </li>
    <li>
        <strong>KI-Funktionen (optional, je Schule):</strong> Ein KI-Anbieter entwirft Hausaufgaben, Förderaufgaben, Stufen und Fassungen in Einfacher Sprache, schätzt Abgaben ein und führt das Gespräch in „Erklär’s mir“.
    </li>
    <li>
        <strong>Tuto-Mikro (optional, je Schule):</strong> Das Mikrofon der Lehrkraft macht aus dem Ton einer Stunde Notizen, aus denen die Lehrkraft eine Zusammenfassung und einen Tafelbild-Vorschlag für ihre Unterrichtsnotizen gewinnt. Es dient der Vor- und Nachbereitung durch die Lehrkraft, nicht der Kontrolle von Schüler:innen oder Lehrkräften.
    </li>
</ul>

<h4>2.2 Betroffene Personen</h4>
<p>
    Schüler:innen (überwiegend minderjährig), Lehrkräfte und Schuladministration, für die Einwilligungen die Erziehungsberechtigten. Beim Tuto-Mikro zusätzlich alle weiteren Personen, die im Raum sprechen, etwa Vertretungslehrkräfte, Referendar:innen, Gäste, Praktikant:innen und Schulbegleitungen.
</p>

<h4>2.3 Daten, Zugriff und Fristen</h4>
<div class="pd-cards">
    @foreach ($dataCategories as $category)
        <section class="pd-card">
            <h4>{{ $category['title'] }}</h4>
            <dl class="pd-facts">
                <dt>Daten</dt>
                <dd>{{ $category['data'] }}</dd>

                <dt>Betroffene</dt>
                <dd>{{ $category['people'] }}</dd>

                <dt>Zugriff</dt>
                <dd>{{ $category['access'] }}</dd>

                <dt>Frist</dt>
                <dd>{{ $category['retention'] }}</dd>
            </dl>
        </section>
    @endforeach
</div>

<h4>2.4 Was an die KI geht</h4>
<p>Nur bei eingeschalteten KI-Funktionen der Schule. Alle Texte werden vorher pseudonymisiert.</p>
<ul>
    <li><strong>Hausaufgaben-Entwurf:</strong> Fach, Thema, Datum, Zusammenfassung und Notizen der Lehrkraft, die Kompetenzen des Fachs, Arbeitsblätter (Fotos, PDF). Ergebnis: Zusammenfassung und fünf Fragen als Entwurf.</li>
    <li><strong>Förderaufgabe:</strong> Fach, Kompetenz, anonyme Hinweise auf Lücken, Thema, Datum und Notizen der Stunden, in denen sich die Lücke zeigte. Ergebnis: drei bis vier Übungsfragen.</li>
    <li><strong>Einschätzung einer Abgabe:</strong> die Stunde wie beim Entwurf mit Arbeitsblättern, die Fragen mit Erwartungshorizont und gewählter Stufe, Zusammenfassung und Antworten der Schüler:in. Ergebnis: Verständniswert, Rückmeldung, bis zu drei Hinweise auf Lücken, Punkte je Antwort.</li>
    <li><strong>„Erklär’s mir“:</strong> Thema und Gesprächsverlauf, bei einer gewählten Stunde deren Zusammenfassung und Notizen als Hintergrund. Ergebnis: Tutos Rückfragen und zum Schluss Verständniswert und Rückmeldung.</li>
    <li><strong>Stufen:</strong> Fach, Thema, Zusammenfassung der Lehrkraft, Art und Text der Frage. Nicht: Erwartungshorizont, Notizen, Arbeitsblätter, Texte der Schüler:innen.</li>
    <li><strong>Einfache Sprache:</strong> Fach, Thema und ein Text der Lehrkraft (Zusammenfassung oder Frage). Nie Texte der Schüler:innen. Das Ergebnis wird an der Stunde oder Frage gespeichert.</li>
    <li><strong>Tuto-Mikro, Spracherkennung:</strong> Ton-Abschnitte von etwa {{ $segmentSeconds }} Sekunden, unverändert, Sprache Deutsch, ohne Sprechertrennung. Ergebnis: Text, der nur an den Browser der Lehrkraft geht.</li>
    <li><strong>Tuto-Mikro, Zusammenfassung:</strong> die von der Lehrkraft gelesenen Notizen, Fach und Thema. Nie Ton. Ergebnis: Zusammenfassung, Fachbegriffe, Tafelbild-Vorschlag, Fragen aus der Klasse; nichts davon wird gespeichert.</li>
    <li><strong>Nie:</strong> Benutzernamen, E-Mail-Adressen, Konto-Kennungen, Namen von Klasse und Schule.</li>
</ul>

<h4>2.5 Ablauf mit dem Tuto-Mikro</h4>
<ol>
    <li>Die Schule hat das Tuto-Mikro eingeschaltet und Einwilligungen der Eltern eingeholt. Das Fach ist nicht gesperrt (derzeit gesperrt: {{ $blockedList }}), die Stunde ist keine Förderaufgabe.</li>
    <li>Die Lehrkraft der Stunde öffnet das Tuto-Mikro, hakt an, wer im Raum ist, und bestätigt die Liste. Der Browser fragt nach dem Mikrofon (ohne KI-Funktionen und ohne Spracherkennung auf dem Gerät öffnet er es gar nicht, die Lehrkraft tippt). Der Server prüft die Einwilligungen jeder angehakten Schüler:in und startet nur, wenn alle gültig sind; sonst schließt der Browser das Mikrofon wieder, ohne etwas zu verarbeiten. Beim Start speichert der Server den Nachweis der Sitzung.</li>
    <li>Eine feste Leiste zeigt „Mikro an – Tuto schreibt mit“ oder „Mikro aus“. Öffnet die Lehrkraft die Beamer-Anzeige, sieht die Klasse dort nur „an“ oder „aus“ und das Thema. Sonst sagt sie der Klasse an, dass das Mikro läuft.</li>
    <li>Mit eingeschalteten KI-Funktionen gehen Ton-Abschnitte von etwa {{ $segmentSeconds }} Sekunden über HTTPS an den Server, werden nur im Arbeitsspeicher an den KI-Anbieter zur Spracherkennung weitergereicht und danach verworfen. Ohne KI-Funktionen verlässt der Ton das Gerät nicht: Der Browser erkennt die Sprache nur, wenn er das auf dem Gerät kann; sonst tippt die Lehrkraft.</li>
    <li>Der Text liegt nur im Browser der Lehrkraft. Sie liest und korrigiert ihn. Auf ihren Wunsch entsteht eine Zusammenfassung; vorher prüft der Server die Einwilligungen erneut und ersetzt die Namen. Die KI-Funktionen sind {{ $aiStatus }}.</li>
    <li>Was die Lehrkraft in die Stundennotizen übernimmt, wird dort erneut pseudonymisiert gespeichert und folgt den Fristen der Notizen. Daraus kann sie eine Hausaufgabe entwerfen lassen.</li>
</ol>

<h4>2.6 Empfänger</h4>
<ul>
    <li>Innerhalb der Schule nur die in 2.3 genannten Rollen.</li>
    <li>Der Betreiber der Plattform als Auftragsverarbeiter, technisch nur für Betrieb, Wartung und Fehlersuche.</li>
    <li>Unterauftragsverarbeiter des Betreibers, etwa für das Hosting, gemäß Auftragsverarbeitungsvertrag.</li>
    <li>Nur bei eingeschalteten KI-Funktionen: der KI-Anbieter {{ $aiProvider }}{{ $aiProviderIsLocal ? ' (vom Betreiber selbst betrieben)' : '' }}, nur mit Vertrag nach Art. 28 DSGVO und, außerhalb der EU, mit geeigneten Garantien nach Art. 44 ff. DSGVO. Die Plattform prüft das nicht; die Schuladministration bestätigt es beim Einschalten.</li>
</ul>

<h4>2.7 Aktueller Stand dieser Plattform</h4>
<dl class="pd-facts">
    <dt>KI-Funktionen</dt>
    <dd>{{ ucfirst($aiStatus) }}</dd>

    <dt>KI-Anbieter</dt>
    <dd>{{ $aiProvider }}</dd>

    <dt>Ton</dt>
    <dd>Wird nicht gespeichert</dd>

    <dt>Nachweis des Tuto-Mikro</dt>
    <dd>{{ $sessionKeepDays }} Tage</dd>

    <dt>Aufbewahrung von Lerndaten</dt>
    <dd>Automatisch {{ $retention }}</dd>
</dl>

<h3>3. Notwendigkeit und Verhältnismäßigkeit</h3>
<dl class="pd-facts">
    <dt>Rechtsgrundlage Tuto</dt>
    <dd>Im Schulbetrieb Art. 6 Abs. 1 lit. e DSGVO in Verbindung mit dem Schulgesetz und den Datenschutzvorschriften des Landes, auch für die KI-Funktionen und das Klassenbuch. Die Schule prüft die genaue Norm.</dd>

    <dt>Rechtsgrundlage Tuto-Mikro</dt>
    <dd>Einwilligung nach Art. 6 Abs. 1 lit. a, Art. 7 DSGVO, zusätzlich zum Schutz des nicht öffentlich gesprochenen Wortes (§ 201 StGB). Zufällig Gesprochenes aus besonderen Kategorien (Art. 9 DSGVO, etwa Gesundheit, Herkunft, Religion, Familie) erfasst die Einwilligung nur, wenn sie ausdrücklich danach fragt (Art. 9 Abs. 2 lit. a, siehe Vorlage „Einwilligung“). Sie ist freiwillig, nachweisbar und jederzeit ohne Nachteil mit Wirkung für die Zukunft widerrufbar. Bei Minderjährigen willigen in der Regel die Sorgeberechtigten ein, je nach Land, Alter und Einsichtsfähigkeit zusätzlich die Schüler:innen. Bei Beschäftigten ist die Freiwilligkeit besonders zu prüfen (§ 26 BDSG oder die Vorschrift des Landes).</dd>

    <dt>Zweckbindung</dt>
    <dd>Die Zwecke sind in 2.1 festgelegt und stehen in Einwilligung und Elterninformation. Eine Nutzung zur Kontrolle von Schüler:innen oder Lehrkräften ist nicht vorgesehen.</dd>

    <dt>Datenminimierung</dt>
    <dd>Kein Ton wird gespeichert, kein Bild, kein Video. Die KI bekommt nur pseudonymisierte Texte; Stufen und Einfache Sprache nur Texte der Lehrkraft. Der Nachweis des Tuto-Mikro ist nur für die Lehrkraft der Stunde zugänglich.</dd>

    <dt>Speicherbegrenzung</dt>
    <dd>Lerndaten und Klassenbuch {{ $retention }}; Nachweise des Tuto-Mikro nach {{ $sessionKeepDays }} Tagen; Benachrichtigungen spätestens nach {{ $notificationMaxDays }} Tagen, jeweils automatisch.</dd>

    <dt>Transparenz</dt>
    <dd>Elterninformation, Einwilligungstext, Seite „Datenschutz &amp; KI“ und Datenschutzerklärung.</dd>

    <dt>Betroffenenrechte</dt>
    <dd>Auskunft per Datenexport im Profil (Umfang siehe R11: Konto, Lerndaten, Rückfragen, Fehlzeiten, Einwilligung und Anwesenheit beim Tuto-Mikro, bei Lehrkräften auch Klassen, Vertretungen, Stunden und Sitzungen), Löschung von Konten durch die Schuladministration, Löschung eines Nachweises mit der Stunde oder auf Weisung durch den Betreiber (siehe R11), Widerruf der Einwilligung über den persönlichen Link oder die Schule.</dd>
</dl>

@include('filament.pages.privacy-documents.partials.field', [
    'label' => 'Sind KI-Funktionen und Tuto-Mikro für den Zweck erforderlich? Gibt es mildere Mittel?',
    'hint' => 'Zum Beispiel eigene Notizen der Lehrkraft, ein Foto des Tafelbilds, ein Gedächtnisprotokoll, die Demo-Logik ohne KI, das Tuto-Mikro nur in Einzelfällen. Begründung der Schule.',
])

<h3>4. Risiken für die Rechte und Freiheiten der Betroffenen</h3>
<p>
    Die Risiken betreffen vor allem Kinder. Zu jedem Risiko stehen die Maßnahmen, die die Plattform umsetzt, und die Grenzen dieser Maßnahmen. Die Schule bewertet Eintrittswahrscheinlichkeit und Schwere und trägt weitere Maßnahmen ein.
</p>

<div class="pd-cards">
    @foreach ($risks as $risk)
        <section class="pd-card">
            <h4>R{{ $loop->iteration }}: {{ $risk['title'] }}</h4>

            <dl class="pd-facts">
                <dt>Mögliche Folgen</dt>
                <dd>{{ $risk['impact'] }}</dd>

                <dt>Maßnahmen der Plattform</dt>
                <dd>
                    <ul>
                        @foreach ($risk['measures'] as $measure)
                            <li>{{ $measure }}</li>
                        @endforeach
                    </ul>
                </dd>

                <dt>Weitere Maßnahmen der Schule</dt>
                <dd><div class="pd-field-box" style="min-height: 4rem"></div></dd>

                <dt>Eintritt</dt>
                <dd>
                    <ul class="pd-options">
                        <li><span class="pd-check" aria-hidden="true"></span> gering</li>
                        <li><span class="pd-check" aria-hidden="true"></span> mittel</li>
                        <li><span class="pd-check" aria-hidden="true"></span> hoch</li>
                    </ul>
                </dd>

                <dt>Schwere</dt>
                <dd>
                    <ul class="pd-options">
                        <li><span class="pd-check" aria-hidden="true"></span> gering</li>
                        <li><span class="pd-check" aria-hidden="true"></span> mittel</li>
                        <li><span class="pd-check" aria-hidden="true"></span> hoch</li>
                    </ul>
                </dd>

                <dt>Restrisiko</dt>
                <dd>
                    <ul class="pd-options">
                        <li><span class="pd-check" aria-hidden="true"></span> akzeptabel</li>
                        <li><span class="pd-check" aria-hidden="true"></span> nur mit weiteren Maßnahmen</li>
                        <li><span class="pd-check" aria-hidden="true"></span> nicht akzeptabel</li>
                    </ul>
                </dd>
            </dl>
        </section>
    @endforeach
</div>

<h3>5. Maßnahmen der Plattform</h3>
<p>Das setzt die Plattform um. Die Liste folgt dem tatsächlichen Verhalten der Software, nicht einem Wunsch.</p>

<div class="pd-cards">
    @foreach ($platformMeasures as $heading => $measures)
        <section class="pd-card">
            <h4>{{ $heading }}</h4>
            <ul>
                @foreach ($measures as $measure)
                    <li>{{ $measure }}</li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>

<h4>Was die Plattform nicht leistet</h4>
<ul>
    <li>Sie prüft nur die Einwilligungen der Schüler:innen, die die Lehrkraft als anwesend anhakt. Sie hat keine eigene Anwesenheitserfassung, kennt keine Einwilligungen von Lehrkräften und weiteren Personen im Raum und prüft nicht, ob die Bestätigungen der Schule (Folgenabschätzung, Personalrat, Auftragsverarbeitung mit dem KI-Anbieter und so weiter) stimmen. Das ist Sache der Schule und der Dienstanweisung.</li>
    <li>Die Ersetzung von Namen ist keine Anonymisierung. Unbekannte Namen, Spitznamen und Erkennungsfehler bleiben im Text. Arbeitsblatt-Dateien und Ton-Abschnitte gehen bei eingeschalteten KI-Funktionen ohne Ersetzung an den Anbieter.</li>
    <li>Sie erzwingt nicht, dass die Lehrkraft die Notizen aus der Stunde vor der Zusammenfassung liest. Text, der in die Stundennotizen übernommen wurde, bleibt so lange wie diese und geht beim Hausaufgaben-Entwurf pseudonymisiert an den KI-Anbieter, auch wenn er aus einer Sitzung ohne KI stammt.</li>
    <li>Einen einzelnen Nachweis des Tuto-Mikro kann niemand über die Plattform löschen; das geht nur mit der Stunde, dem Konto der Lehrkraft, über den Betreiber oder automatisch (siehe R11).</li>
    <li>Sie erzwingt nicht, dass die Beamer-Anzeige geöffnet wird. Ob die Klasse sieht, dass das Mikro an ist, hängt davon ab, dass die Lehrkraft die Anzeige öffnet oder es ansagt (Dienstanweisung).</li>
    <li>Sie stoppt die Spracherkennung auf dem Gerät der Lehrkraft nicht, solange der Server nicht erreichbar ist: Ein Widerruf wirkt bei der nächsten Prüfung durch den Server, die ohne Verbindung ausbleibt.</li>
    <li>Sie kann nicht ausschließen, dass der Webserver des Betreibers einen Ton-Abschnitt für die Dauer einer Anfrage kurz in einer temporären Datei zwischenpuffert. Tuto selbst speichert keinen Ton; Einstellung und Löschung der Zwischenspeicher klärt die Schule mit dem Betreiber.</li>
    <li>Sie kann kopierten Text nicht kontrollieren oder zurückholen und kann nicht beeinflussen, was ein Mikrofon in der Nähe der Lehrkraft außer ihrer Stimme erfasst.</li>
</ul>

<h3>6. Weitere Maßnahmen der Schule</h3>
@include('filament.pages.privacy-documents.partials.field', [
    'label' => 'Organisatorische Maßnahmen',
    'hint' => 'Zum Beispiel Dienstanweisung (Anwesenheitsliste gewissenhaft anhaken, bei Gästen das Tuto-Mikro ausschalten, die Beamer-Anzeige öffnen oder der Klasse ansagen, dass das Mikro läuft, Notizen vor der Zusammenfassung lesen, keine Namen in Notizen und auf Arbeitsblättern, wer Vertretungen einträgt), Dienstvereinbarung, Unterweisung der Lehrkräfte, Ankündigung vor jedem Einschalten, Prüfung der Sicherungen mit dem Betreiber.',
    'tall' => true,
])
@include('filament.pages.privacy-documents.partials.field', [
    'label' => 'Offene Restrisiken und wie die Schule damit umgeht',
    'tall' => true,
])

<h3>7. Stellungnahmen, Ergebnis und Freigabe</h3>
@include('filament.pages.privacy-documents.partials.field', [
    'label' => 'Stellungnahme der oder des Datenschutzbeauftragten (Art. 35 Abs. 2 DSGVO)',
])
@include('filament.pages.privacy-documents.partials.field', [
    'label' => 'Standpunkte betroffener Personen oder ihrer Vertretungen (Art. 35 Abs. 9 DSGVO)',
    'hint' => 'Elternvertretung, Schülervertretung, Personalrat',
])

<h4>Entscheidung der Schulleitung</h4>
<ul class="pd-options">
    <li><span class="pd-check" aria-hidden="true"></span> KI-Funktionen freigegeben</li>
    <li><span class="pd-check" aria-hidden="true"></span> Tuto-Mikro freigegeben</li>
    <li><span class="pd-check" aria-hidden="true"></span> freigegeben mit Auflagen (siehe Teil 6)</li>
    <li><span class="pd-check" aria-hidden="true"></span> nicht freigegeben</li>
    <li><span class="pd-check" aria-hidden="true"></span> Konsultation der Aufsichtsbehörde nötig (Art. 36 DSGVO)</li>
</ul>

<p>
    Überprüfung der Folgenabschätzung spätestens am {{ $blank }}, außerdem bei jeder Änderung der Verarbeitung, zum Beispiel bei einem anderen KI-Anbieter oder neuen KI-Funktionen (Art. 35 Abs. 11 DSGVO).
</p>

@include('filament.pages.privacy-documents.partials.signatures', [
    'lines' => ['Ort, Datum', 'Schulleitung', 'Datenschutzbeauftragte(r)'],
])
