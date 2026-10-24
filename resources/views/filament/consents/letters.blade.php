{{--
    Printable parent letters: one A4 page per student with the personal link and its QR code (inline SVG, no external
    service), the consent text and a slip for a paper reply. Shown once, right after the links were made.
    The sheet is always white paper, also in dark mode, so the QR code stays scannable.
--}}
<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow, noarchive">
        <meta name="referrer" content="no-referrer">

        <title>Elternbriefe {{ $schoolClass->name }} – Tuto</title>

        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <link rel="stylesheet" href="{{ asset('fonts/lexend/lexend.css') }}">

        <style>
            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                background: #e2e8f0;
                color: #0f172a;
                font-family: 'Lexend', ui-sans-serif, system-ui, sans-serif;
                font-size: 1rem;
                line-height: 1.55;
            }

            .toolbar {
                display: flex;
                flex-wrap: wrap;
                gap: 0.75rem 1rem;
                align-items: center;
                justify-content: space-between;
                max-width: 52rem;
                margin: 0 auto;
                padding: 1rem;
            }

            .toolbar p {
                flex: 1 1 18rem;
                margin: 0;
                color: #1e293b;
                font-size: 0.9375rem;
            }

            .toolbar-actions {
                display: flex;
                flex-wrap: wrap;
                gap: 0.75rem;
            }

            .button {
                display: inline-flex;
                min-height: 2.75rem;
                align-items: center;
                padding: 0.5rem 1.25rem;
                border: 1px solid #1e3a8a;
                border-radius: 0.5rem;
                background: #1e3a8a;
                color: #fff;
                font: inherit;
                font-weight: 600;
                text-decoration: none;
                cursor: pointer;
            }

            .button-secondary {
                background: #fff;
                color: #1e3a8a;
            }

            .button:focus-visible {
                outline: 3px solid #1e3a8a;
                outline-offset: 2px;
            }

            .letter {
                max-width: 52rem;
                margin: 0 auto 1.5rem;
                padding: 1.25rem;
                background: #fff;
                box-shadow: 0 1px 3px rgb(15 23 42 / 0.2);
            }

            .letter h1 {
                margin: 0.25rem 0 0.5rem;
                font-size: 1.375rem;
                line-height: 1.25;
            }

            .letter h2 {
                margin: 1.25rem 0 0.5rem;
                font-size: 1.0625rem;
            }

            .letter p {
                margin: 0.5rem 0;
            }

            .school {
                color: #334155;
                font-size: 0.9375rem;
            }

            .access {
                display: flex;
                flex-wrap: wrap;
                gap: 1rem;
                align-items: center;
                margin: 1rem 0;
                padding: 1rem;
                border: 2px solid #1e3a8a;
                border-radius: 0.75rem;
            }

            .access img {
                width: 9rem;
                height: 9rem;
                flex: none;
            }

            .access div {
                flex: 1 1 14rem;
                min-width: 0;
            }

            .link {
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-size: 0.875rem;
                font-weight: 600;
                overflow-wrap: anywhere;
            }

            .text {
                padding: 0.75rem 1rem;
                border-left: 3px solid #94a3b8;
                color: #1e293b;
                font-size: 0.875rem;
                line-height: 1.5;
            }

            .text h2 {
                margin: 0.75rem 0 0.25rem;
                font-size: 0.9375rem;
            }

            .text p,
            .text ul {
                margin: 0.25rem 0;
            }

            .text ul {
                padding-inline-start: 1.25rem;
                list-style: disc;
            }

            .slip {
                margin-top: 1.25rem;
                padding-top: 1rem;
                border-top: 2px dashed #64748b;
                font-size: 0.9375rem;
            }

            .slip ul {
                margin: 0.5rem 0;
                padding: 0;
                list-style: none;
            }

            .slip li {
                margin: 0.25rem 0;
            }

            .line {
                display: inline-block;
                min-width: 12rem;
                border-bottom: 1px solid #0f172a;
            }

            @media print {
                @page {
                    size: A4;
                    margin: 13mm 15mm;
                }

                body {
                    background: #fff;
                    font-size: 10pt;
                    line-height: 1.4;
                }

                .toolbar {
                    display: none;
                }

                .letter {
                    max-width: none;
                    margin: 0;
                    padding: 0;
                    box-shadow: none;
                    break-after: page;
                }

                .letter:last-of-type {
                    break-after: auto;
                }

                .letter h1 {
                    font-size: 15pt;
                }

                .letter h2 {
                    margin-top: 0.7rem;
                }

                .access {
                    margin: 0.6rem 0;
                    padding: 0.6rem 0.8rem;
                    break-inside: avoid;
                }

                .access img {
                    width: 3cm;
                    height: 3cm;
                }

                /* The long form text runs in two columns so a letter, its link and the reply slip fit on one page. */
                .text {
                    column-count: 2;
                    column-gap: 1.2rem;
                    font-size: 8pt;
                    line-height: 1.32;
                }

                .text h2 {
                    margin-top: 0.4rem;
                    font-size: 8.5pt;
                    break-after: avoid;
                }

                .text h2:first-child {
                    margin-top: 0;
                }

                .slip {
                    margin-top: 0.7rem;
                    padding-top: 0.6rem;
                    font-size: 9.5pt;
                    break-inside: avoid;
                }
            }
        </style>
    </head>

    <body>
        <div class="toolbar">
            <p>
                {{ count($letters) }} {{ count($letters) === 1 ? 'Brief' : 'Briefe' }} für die Klasse {{ $schoolClass->name }}.
                Die Links gibt es nur auf dieser Seite. Wenn du sie neu lädst oder später neue Briefe erzeugst, werden die bisherigen Links ungültig.
            </p>

            <div class="toolbar-actions">
                <button type="button" class="button" onclick="window.print()">Drucken</button>
                <a href="{{ $backUrl }}" class="button button-secondary">Zurück zu den Einwilligungen</a>
            </div>
        </div>

        @foreach ($letters as $letter)
            <section class="letter" aria-label="Brief für {{ $letter['name'] }}">
                <p class="school">{{ $school->name }}, {{ $school->city }}</p>

                <h1>{{ $form->title }}</h1>
                <p><strong>Klasse {{ $schoolClass->name }}</strong>, Schüler:in {{ $letter['name'] }}</p>

                <p>
                    Sehr geehrte Erziehungsberechtigte, wir möchten in einzelnen Unterrichtsstunden das Tuto-Mikro einschalten. Es erfasst nur den Ton, damit
                    die Lehrkraft den Unterricht besser nachbereiten kann. Tuto speichert keinen Ton. Dafür brauchen wir Ihre freiwillige Einwilligung.
                    Sie können sie jederzeit widerrufen.
                </p>

                <div class="access">
                    <img src="{{ $letter['qr'] }}" alt="QR-Code zum persönlichen Link für {{ $letter['name'] }}">

                    <div>
                        <p><strong>Online einwilligen:</strong> Scannen Sie den QR-Code oder öffnen Sie diesen persönlichen Link. Über denselben Link können Sie später widerrufen.</p>
                        <p class="link">{{ $letter['url'] }}</p>
                    </div>
                </div>

                <h2>Einwilligungserklärung (Fassung {{ $form->version }})</h2>
                <div class="text">{!! $form->html() !!}</div>

                <div class="slip">
                    <p><strong>Lieber auf Papier?</strong> Dann kreuzen Sie unten an, unterschreiben und geben den Abschnitt der Lehrkraft zurück.</p>

                    <p>Ich bin erziehungsberechtigt und willige für <strong>{{ $letter['name'] }}</strong> (Klasse {{ $schoolClass->name }}) ein in:</p>

                    <ul>
                        @foreach ($form->purposeLabels() as $label)
                            <li>&#9744; {{ $label }}</li>
                        @endforeach
                    </ul>

                    <p>Name: <span class="line"></span> Datum: <span class="line" style="min-width: 6rem"></span></p>
                    <p>Unterschrift: <span class="line" style="min-width: 16rem"></span></p>
                </div>
            </section>
        @endforeach
    </body>
</html>
