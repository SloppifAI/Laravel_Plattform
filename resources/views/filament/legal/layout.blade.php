{{--
    Standalone page frame for the public legal texts (Impressum, Datenschutzerklärung).
    It lives outside the Filament panel, so it loads the panel theme itself and defines the palette variables
    that Filament would otherwise inject. Pages extend it and fill the `title` and `content` sections.
--}}
@php
    use App\Providers\Filament\LernplattformPanelProvider;
    use Filament\Support\Colors\Color;

    $legalLinks = [
        'legal.imprint' => 'Impressum',
        'legal.privacy' => 'Datenschutzerklärung',
        'legal.dpa' => 'Auftragsverarbeitung (AVV)',
    ];
@endphp
<!DOCTYPE html>
<html lang="de" class="fi">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">

        <title>@yield('title') – Lernplattform</title>
        @hasSection('description')
            <meta name="description" content="@yield('description')">
        @endif
        {{-- Extra head tags of a single page, e.g. the robots meta of the consent page. --}}
        @stack('head')

        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        {{-- Lexend is served from public/fonts, so no request leaves for a font provider. --}}
        <link rel="stylesheet" href="{{ asset('fonts/lexend/lexend.css') }}">

        <style>
            :root {
                @foreach (['primary' => LernplattformPanelProvider::KOENIGSBLAU, 'gray' => Color::Slate] as $colorName => $palette)
                    @foreach ($palette as $shade => $value)
                        --{{ $colorName }}-{{ $shade }}: {{ $value }};
                    @endforeach
                @endforeach
            }

            body {
                font-family: 'Lexend', ui-sans-serif, system-ui, sans-serif;
            }

            /* Skip link: invisible until it receives keyboard focus. */
            .legal-skip {
                position: absolute;
                inset-inline-start: 0.75rem;
                top: -4rem;
                z-index: 10;
                padding: 0.625rem 1rem;
                border-radius: 0.5rem;
                background: var(--primary-600);
                color: #fff;
                font-weight: 500;
            }

            .legal-skip:focus {
                top: 0.75rem;
            }

            main:focus {
                outline: none;
            }

            /* The sheet: one exercise-book page with the red margin line on wider screens. */
            .legal-sheet {
                position: relative;
                padding: 1.25rem;
            }

            @media (min-width: 40rem) {
                .legal-sheet {
                    padding: 2.5rem 2.5rem 2.5rem 4.5rem;
                }

                .legal-sheet::before {
                    content: '';
                    position: absolute;
                    inset-block: 0;
                    inset-inline-start: 3rem;
                    width: 2px;
                    background: var(--lp-margin);
                }
            }

            /* Reading text: about 65 characters per line at the sheet width. */
            .legal-prose {
                color: var(--gray-700);
                font-size: 1rem;
                line-height: 1.75;
                overflow-wrap: break-word;
                hyphens: auto;
            }

            .dark .legal-prose {
                color: var(--gray-300);
            }

            .legal-prose > * + * {
                margin-top: 1rem;
            }

            .legal-prose h1 {
                color: var(--primary-800);
                font-size: 1.875rem;
                font-weight: 600;
                letter-spacing: -0.015em;
                line-height: 1.2;
                text-wrap: balance;
            }

            .dark .legal-prose h1 {
                color: var(--primary-200);
            }

            .legal-prose h2 {
                margin-top: 2.5rem;
                color: var(--gray-950);
                font-size: 1.375rem;
                font-weight: 600;
                line-height: 1.3;
                scroll-margin-top: 1.5rem;
                text-wrap: balance;
            }

            .legal-prose h3 {
                margin-top: 1.75rem;
                color: var(--gray-900);
                font-size: 1.0625rem;
                font-weight: 600;
                line-height: 1.4;
            }

            .dark .legal-prose h2 {
                color: #fff;
            }

            .dark .legal-prose h3 {
                color: var(--gray-100);
            }

            .legal-prose h2 + *,
            .legal-prose h3 + * {
                margin-top: 0.75rem;
            }

            .legal-prose strong {
                color: var(--gray-950);
                font-weight: 600;
            }

            .dark .legal-prose strong {
                color: #fff;
            }

            .legal-prose ul,
            .legal-prose ol {
                padding-inline-start: 1.375rem;
            }

            .legal-prose ul {
                list-style: disc;
            }

            .legal-prose ol {
                list-style: decimal;
            }

            .legal-prose li + li {
                margin-top: 0.375rem;
            }

            .legal-prose li::marker {
                color: var(--primary-600);
            }

            .dark .legal-prose li::marker {
                color: var(--primary-400);
            }

            .legal-prose address {
                font-style: normal;
                line-height: 1.65;
            }

            .legal-prose a {
                color: var(--primary-700);
                text-decoration: underline;
                text-decoration-thickness: 1px;
                text-underline-offset: 0.2em;
            }

            .legal-prose a:hover {
                text-decoration-thickness: 2px;
            }

            .dark .legal-prose a {
                color: var(--primary-300);
            }

            .legal-prose code {
                padding: 0.1em 0.35em;
                border-radius: 0.25rem;
                background: var(--gray-100);
                color: var(--gray-900);
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-size: 0.875em;
                hyphens: none;
                overflow-wrap: anywhere;
            }

            .dark .legal-prose code {
                background: rgb(255 255 255 / 0.08);
                color: var(--gray-100);
            }

            a:focus-visible,
            .legal-table-wrap:focus-visible {
                outline: 2px solid var(--primary-600);
                outline-offset: 2px;
                border-radius: 0.25rem;
            }

            .dark a:focus-visible,
            .dark .legal-table-wrap:focus-visible {
                outline-color: var(--primary-400);
            }

            /* Key/value facts such as phone and e-mail. */
            .legal-facts {
                display: grid;
                gap: 0.125rem 1.5rem;
            }

            .legal-facts dt {
                color: var(--gray-950);
                font-weight: 600;
            }

            .legal-facts dd + dt {
                margin-top: 0.5rem;
            }

            .dark .legal-facts dt {
                color: #fff;
            }

            @media (min-width: 40rem) {
                .legal-facts {
                    grid-template-columns: 12rem minmax(0, 1fr);
                }

                .legal-facts dd + dt {
                    margin-top: 0;
                }
            }

            /* Callouts and the table of contents. */
            .legal-note,
            .legal-toc {
                padding: 1rem 1.25rem;
                border-radius: 0.75rem;
            }

            .legal-note {
                background: var(--primary-50);
                box-shadow: inset 4px 0 0 var(--primary-600);
            }

            .dark .legal-note {
                background: color-mix(in oklab, var(--primary-400) 10%, transparent);
                box-shadow: inset 4px 0 0 var(--primary-400);
            }

            .legal-note > * + * {
                margin-top: 0.5rem;
            }

            .legal-note-title {
                color: var(--primary-900);
                font-weight: 600;
            }

            .dark .legal-note-title {
                color: var(--primary-100);
            }

            .legal-toc {
                box-shadow: 0 0 0 1px var(--gray-200);
            }

            .dark .legal-toc {
                box-shadow: 0 0 0 1px rgb(255 255 255 / 0.1);
            }

            .legal-toc ol {
                margin-top: 0.5rem;
                padding-inline-start: 1.75rem;
            }

            /* From md upwards a table scrolls inside its own box, so the page itself never scrolls sideways. */
            .legal-table-wrap {
                overflow-x: auto;
                border-radius: 0.75rem;
                box-shadow: 0 0 0 1px var(--gray-200);
            }

            .dark .legal-table-wrap {
                box-shadow: 0 0 0 1px rgb(255 255 255 / 0.1);
            }

            .legal-table {
                width: 100%;
                min-width: 34rem;
                border-collapse: collapse;
                font-size: 0.9375rem;
                line-height: 1.5;
            }

            /* The caption names the table for screen readers; the heading above it already does that visually. */
            .legal-table caption {
                position: absolute;
                width: 1px;
                height: 1px;
                overflow: hidden;
                clip-path: inset(50%);
                white-space: nowrap;
            }

            .legal-table th,
            .legal-table td {
                padding: 0.625rem 0.875rem;
                border-bottom: 1px solid var(--gray-200);
                text-align: start;
                vertical-align: top;
            }

            .legal-table thead th {
                background: var(--gray-50);
                color: var(--gray-900);
                font-weight: 600;
            }

            .legal-table tbody tr:last-child > * {
                border-bottom: 0;
            }

            .legal-table tbody th {
                color: var(--gray-950);
                font-weight: 500;
            }

            .dark .legal-table th,
            .dark .legal-table td {
                border-bottom-color: rgb(255 255 255 / 0.1);
            }

            .dark .legal-table thead th {
                background: rgb(255 255 255 / 0.04);
                color: var(--gray-100);
            }

            .dark .legal-table tbody th {
                color: #fff;
            }

            /* The column name shown inside each cell on phones; the header row does that job from md. */
            .legal-cell-label {
                display: none;
            }

            /*
             * Below md nothing scrolls sideways: every row becomes a card, its first cell the card title and the
             * header row gives way to a label in each cell. Print keeps the real table (screen only).
             */
            @media screen and (width < 48rem) {
                .legal-table-wrap {
                    overflow: visible;
                    border-radius: 0;
                    box-shadow: none;
                }

                .legal-table,
                .legal-table tbody,
                .legal-table tr,
                .legal-table th,
                .legal-table td {
                    display: block;
                    min-width: 0;
                }

                .legal-table thead {
                    display: none;
                }

                .legal-table tbody tr {
                    padding: 0.25rem 0;
                    border-radius: 0.75rem;
                    box-shadow: 0 0 0 1px var(--gray-200);
                }

                .legal-table tbody tr + tr {
                    margin-top: 0.75rem;
                }

                .legal-table tbody th,
                .legal-table tbody td {
                    border-bottom: 0;
                    overflow-wrap: anywhere;
                }

                .legal-table tbody th {
                    padding-bottom: 0.25rem;
                    font-weight: 600;
                }

                .legal-table tbody td {
                    padding-block: 0.375rem;
                }

                .legal-cell-label {
                    display: block;
                    color: var(--gray-600);
                    font-size: 0.8125rem;
                    font-weight: 600;
                    line-height: 1.4;
                }

                .dark .legal-table tbody tr {
                    box-shadow: 0 0 0 1px rgb(255 255 255 / 0.1);
                }

                .dark .legal-cell-label {
                    color: var(--gray-400);
                }
            }

            @media print {
                .legal-skip,
                header,
                footer {
                    display: none;
                }

                /* A scroll box would cut the table off on paper. */
                .legal-table-wrap {
                    overflow: visible;
                }
            }
        </style>

        @vite('resources/css/filament/lernplattform/theme.css')

        {{-- Follows the choice made in the panel (Filament's own key), otherwise the system setting. --}}
        <script>
            (function () {
                var mode = null;

                try {
                    mode = localStorage.getItem('filament:theme:mode');
                } catch (error) {}

                if (mode === 'dark' || (mode !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>
    </head>

    <body class="fi-body flex min-h-dvh flex-col">
        <a href="#inhalt" class="legal-skip">Zum Inhalt springen</a>

        <header class="border-b border-gray-200 bg-white/90 backdrop-blur-md dark:border-white/10 dark:bg-gray-900/90">
            <div class="mx-auto flex w-full max-w-3xl items-center px-3 py-3 sm:px-4">
                <a href="{{ url('/') }}" class="lp-focus -mx-1 inline-flex items-center rounded-lg px-1 py-1">
                    @include('filament.lernplattform.wordmark', ['class' => 'h-8'])
                </a>
            </div>
        </header>

        <main id="inhalt" tabindex="-1" class="mx-auto w-full max-w-3xl flex-1 px-3 py-6 sm:px-4 sm:py-10">
            <article class="lp-card legal-sheet legal-prose">
                @yield('content')
            </article>
        </main>

        <footer class="border-t border-gray-200 bg-white/90 dark:border-white/10 dark:bg-gray-900/90">
            <div class="mx-auto flex w-full max-w-3xl flex-col gap-3 px-3 py-6 text-sm text-gray-600 sm:flex-row sm:items-center sm:justify-between sm:px-4 dark:text-gray-400">
                <nav aria-label="Rechtliches">
                    <ul class="flex flex-wrap gap-x-6 gap-y-2">
                        @foreach ($legalLinks as $routeName => $label)
                            <li>
                                <a
                                    href="{{ route($routeName) }}"
                                    @if (request()->routeIs($routeName)) aria-current="page" @endif
                                    @class([
                                        'lp-focus rounded-sm underline underline-offset-4 hover:text-gray-950 dark:hover:text-white',
                                        'font-semibold text-gray-950 dark:text-white' => request()->routeIs($routeName),
                                    ])
                                >{{ $label }}</a>
                            </li>
                        @endforeach
                    </ul>
                </nav>

                <p>Prototyp mit erfundenen Demodaten</p>
            </div>
        </footer>
    </body>
</html>
