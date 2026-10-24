@extends('filament.legal.layout')

@php
    use App\Filament\Pages\MySchool;
    use App\Models\DpaAcceptance;

    /*
     * One page serves three readers. Without `$school` it is the public text. The authenticated route
     * (SchoolDpaController) passes the signed-in administration's own school, which fills in the Auftraggeber, and
     * with `$acceptance` the text exactly as that school saw it when it accepted, even if the current text changed since.
     * The contract itself is in avv-contract.blade.php, so the same markup can be stored with an acceptance.
     */
    $school = $school ?? null;
    $acceptance = $acceptance ?? null;
    $title = (string) config('lernplattform.dpa.title');
    $version = (string) config('lernplattform.dpa.version');
    $isFinal = DpaAcceptance::isFinal();
    $mySchoolUrl = $school ? MySchool::getUrl() : null;
@endphp

@section('title', $title)
@section('description', 'Vertrag zur Auftragsverarbeitung nach Art. 28 DSGVO für Schulen, die die Lernplattform nutzen')

@section('content')
    <style>
        /* Contract text: hanging clause numbers. */
        .avv-clause {
            position: relative;
            padding-inline-start: 2.75rem;
        }

        /* Positioned out of the line, so the space after it never shifts the first line against the others. */
        .avv-no {
            position: absolute;
            inset-inline-start: 0;
            color: var(--primary-700);
            font-weight: 600;
        }

        .legal-toc .legal-note-title:not(:first-child) {
            margin-top: 1rem;
        }

        .dark .avv-no {
            color: var(--primary-300);
        }

        .legal-prose ul.avv-list,
        .legal-prose ol.avv-list {
            margin-inline-start: 2.75rem;
        }

        .legal-prose ol.avv-alpha {
            list-style: lower-alpha;
        }

        /* The title and the headings shrink a little on phones, so even "Unterauftragsverarbeiter" fits a 320 px line. */
        .legal-prose h1 {
            font-size: clamp(1.5rem, 7vw, 1.875rem);
        }

        .legal-prose h2 {
            font-size: clamp(1.1875rem, 5.5vw, 1.375rem);
        }

        /* The annexes sit in sections, so the spacing between direct children of the article does not reach them. */
        .avv-annex > * + * {
            margin-top: 1rem;
        }

        /* A fact the operator still has to fill in. */
        .avv-placeholder {
            padding: 0.05em 0.3em;
            border-radius: 0.25rem;
            background: color-mix(in oklab, #f59e0b 30%, transparent);
            color: inherit;
            box-decoration-break: clone;
            -webkit-box-decoration-break: clone;
        }

        /* Marks a part that only applies when the school switches the module on. */
        .avv-optional {
            display: inline-block;
            padding: 0.0625rem 0.625rem;
            border-radius: 9999px;
            background: var(--primary-50);
            box-shadow: inset 0 0 0 1px var(--primary-200);
            color: var(--primary-800);
            font-size: 0.8125rem;
            font-weight: 600;
            line-height: 1.5;
            hyphens: none;
        }

        .dark .avv-optional {
            background: color-mix(in oklab, var(--primary-400) 12%, transparent);
            box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--primary-400) 40%, transparent);
            color: var(--primary-200);
        }

        .avv-button {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            background: var(--primary-600);
            color: #fff;
            font-size: 0.9375rem;
            font-weight: 500;
            line-height: 1.4;
            cursor: pointer;
        }

        .avv-button:hover {
            background: var(--primary-700);
        }

        .avv-button[hidden] {
            display: none;
        }

        .avv-button:focus-visible {
            outline: 2px solid var(--primary-600);
            outline-offset: 2px;
        }

        .dark .avv-button:focus-visible {
            outline-color: var(--primary-400);
        }

        /* On phones the clause number runs in line with the text instead of taking a column of its own. */
        @media (max-width: 40rem) {
            .avv-clause {
                padding-inline-start: 0;
            }

            .avv-no {
                position: static;
                margin-inline-end: 0.5em;
            }

            .legal-prose ul.avv-list,
            .legal-prose ol.avv-list {
                margin-inline-start: 0;
            }
        }

        /* Each annex starts on its own page; headings and rows stay together. */
        @media print {
            .avv-annex {
                break-before: page;
            }

            .avv-noprint {
                display: none;
            }

            .legal-sheet {
                padding: 0;
                box-shadow: none;
            }

            .legal-sheet::before {
                display: none;
            }

            .legal-prose h2,
            .legal-prose h3 {
                break-after: avoid;
            }

            .legal-prose tr,
            .avv-clause {
                break-inside: avoid;
            }

            .avv-placeholder {
                background: none;
                outline: 1px dashed currentColor;
            }
        }
    </style>

    <h1>{{ $title }}</h1>
    <p>
        <strong>nach Art. 28 DSGVO</strong> · Stand/Version: <strong>{{ $acceptance?->version ?? $version }}</strong>
    </p>

    @unless ($isFinal)
        <div class="legal-note" role="note">
            <p class="legal-note-title">Entwurf – vor Vertragsschluss rechtlich prüfen lassen; keine Rechtsberatung.</p>
            <p>
                Dieser Text folgt den üblichen Mustern für Verträge nach Art. 28 DSGVO und ist auf die Lernplattform
                zugeschnitten. Er ersetzt keine rechtliche Prüfung durch eine Anwältin oder einen Anwalt oder durch die
                Datenschutzbeauftragte der Schule. Angaben, die der Betreiber noch ergänzen muss, sind
                <span class="avv-placeholder">gelb markiert</span>. Solange der Betreiber den Vertrag nicht freigegeben hat,
                kann ihn keine Schule annehmen.
            </p>
        </div>
    @endunless

    @if ($school)
        <div class="legal-note" role="note" aria-labelledby="avv-schule">
            <p class="legal-note-title" id="avv-schule">{{ $acceptance ? 'Angenommene Fassung für '.$school->name : 'Ihre Fassung für '.$school->name }}</p>
            <p class="avv-noprint">
                @if ($acceptance)
                    Das ist der Vertragstext, wie Ihre Schule ihn am {{ $acceptance->accepted_at->format('d.m.Y') }} gesehen
                    und angenommen hat, mit den Stammdaten von damals. Die
                    <a href="{{ route('legal.dpa.school') }}">aktuelle Fassung mit den heutigen Stammdaten</a> kann davon
                    abweichen. Sie können diese Fassung ausdrucken oder als PDF speichern.
                @else
                    Die Angaben zum Auftraggeber sind mit den Stammdaten Ihrer Schule ausgefüllt. Diese Fassung sehen nur
                    angemeldete Schuladministrationen und nur für die eigene Schule. Sie können sie ausdrucken oder als PDF
                    speichern.
                @endif
            </p>
            <p>
                @if ($school->hasAcceptedCurrentDpa())
                    Status: Version {{ $school->dpa_version }} angenommen am {{ $school->dpa_accepted_at->format('d.m.Y') }}.
                @elseif ($school->hasAcceptedDpa())
                    Status: Version {{ $school->dpa_version }} angenommen, die aktuelle Version {{ $version }} ist noch offen.
                @else
                    Status: noch nicht angenommen.
                    @unless ($isFinal)
                        Der Vertrag ist noch ein Entwurf und kann erst nach der Freigabe angenommen werden.
                    @endunless
                @endif
            </p>
        </div>
    @endif

    <p class="avv-noprint">
        <button type="button" class="avv-button" data-print hidden>Seite drucken</button>
    </p>
    <script>
        document.querySelectorAll('[data-print]').forEach(function (button) {
            button.hidden = false;
            button.addEventListener('click', function () { window.print(); });
        });

        // Paper is white: a reader in dark mode gets the light colours for the print and the dark ones back afterwards.
        (function () {
            var root = document.documentElement;
            var wasDark = false;

            window.addEventListener('beforeprint', function () {
                wasDark = root.classList.contains('dark');
                root.classList.remove('dark');
            });

            window.addEventListener('afterprint', function () {
                if (wasDark) {
                    root.classList.add('dark');
                }
            });
        })();
    </script>

    @if ($acceptance)
        {!! $acceptance->content !!}
    @else
        @include('filament.legal.avv-contract', ['school' => $school])
    @endif

    <h2 id="annahme">Annahme</h2>
    @if ($school)
        @if ($school->hasAcceptedCurrentDpa())
            <p>
                {{ $school->name }} hat diesen Vertrag in der Version {{ $school->dpa_version }} am
                {{ $school->dpa_accepted_at->format('d.m.Y') }} um {{ $school->dpa_accepted_at->format('H:i') }} Uhr
                durch {{ $school->dpa_accepted_by_name }} (Benutzername {{ $school->dpa_accepted_by_username }})
                elektronisch angenommen.
            </p>
        @elseif ($school->hasAcceptedDpa())
            <p>
                {{ $school->name }} hat die Version {{ $school->dpa_version }} am
                {{ $school->dpa_accepted_at->format('d.m.Y') }} angenommen
                (durch {{ $school->dpa_accepted_by_name }}, Benutzername {{ $school->dpa_accepted_by_username }}). Die
                aktuelle Version {{ $version }} ist noch nicht angenommen.
                <span class="avv-noprint">Das geht unter <a href="{{ $mySchoolUrl }}">„Meine Schule“</a>.</span>
            </p>
        @else
            <p>
                {{ $school->name }} hat diesen Vertrag noch nicht angenommen.
                @if ($isFinal)
                    <span class="avv-noprint">Das geht unter <a href="{{ $mySchoolUrl }}">„Meine Schule“</a>.</span>
                @else
                    Er ist noch ein Entwurf und kann erst nach der Freigabe angenommen werden.
                @endif
            </p>
        @endif
    @else
        <p>
            @if ($isFinal)
                Die Schuladministration nimmt den Vertrag bei der Registrierung der Schule an, später unter „Meine Schule“.
            @else
                Solange der Vertrag ein Entwurf ist, kann ihn keine Schule annehmen. Nach der Freigabe nimmt die
                Schuladministration ihn bei der Registrierung der Schule an, später unter „Meine Schule“.
            @endif
            Dort steht auch, welche Version wann und von wem angenommen wurde.
        </p>
    @endif
@endsection
