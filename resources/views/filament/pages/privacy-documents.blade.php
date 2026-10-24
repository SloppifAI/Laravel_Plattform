<x-filament-panels::page>
    <style>
        /* The templates: one calm reading column, plain HTML inside, styled here so the sub-views stay readable. */
        .pd-doc {
            padding: 1.5rem 1.25rem;
            color: var(--gray-800);
            font-size: 1rem;
            line-height: 1.7;
            overflow-wrap: break-word;
            hyphens: auto;
        }

        @media (min-width: 40rem) {
            .pd-doc {
                padding: 2.5rem 3rem;
            }
        }

        .dark .pd-doc {
            color: var(--gray-200);
        }

        .pd-doc > * + * {
            margin-top: 1rem;
        }

        .pd-doc h2 {
            color: var(--primary-800);
            font-size: 1.75rem;
            font-weight: 600;
            line-height: 1.2;
            text-wrap: balance;
        }

        .pd-doc h3 {
            margin-top: 2.25rem;
            color: var(--gray-950);
            font-size: 1.25rem;
            font-weight: 600;
            line-height: 1.3;
            break-after: avoid;
        }

        .pd-doc h4 {
            margin-top: 1.5rem;
            color: var(--gray-900);
            font-size: 1.0625rem;
            font-weight: 600;
            line-height: 1.4;
            break-after: avoid;
        }

        .dark .pd-doc h2 {
            color: var(--primary-200);
        }

        .dark .pd-doc h3,
        .dark .pd-doc h4 {
            color: #fff;
        }

        .pd-doc h3 + *,
        .pd-doc h4 + * {
            margin-top: 0.5rem;
        }

        .pd-doc ul,
        .pd-doc ol {
            padding-inline-start: 1.5rem;
        }

        .pd-doc ul {
            list-style: disc;
        }

        .pd-doc ol {
            list-style: decimal;
        }

        .pd-doc li + li {
            margin-top: 0.375rem;
        }

        .pd-doc a {
            color: var(--primary-700);
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        .dark .pd-doc a {
            color: var(--primary-300);
        }

        .pd-doc strong {
            color: var(--gray-950);
            font-weight: 600;
        }

        .dark .pd-doc strong {
            color: #fff;
        }

        .pd-kicker {
            color: var(--gray-600);
            font-size: 0.9375rem;
            font-weight: 500;
        }

        .dark .pd-kicker {
            color: var(--gray-400);
        }

        /* The "this is a template" note: bordered and labelled, never colour alone, and it prints with the text. */
        .pd-notice {
            border: 2px solid var(--primary-700);
            border-radius: 0.75rem;
            padding: 1rem 1.25rem;
            background: var(--primary-50);
            color: var(--gray-900);
            font-size: 0.9375rem;
            line-height: 1.6;
        }

        .dark .pd-notice {
            border-color: var(--primary-400);
            background: color-mix(in oklab, var(--primary-400) 12%, transparent);
            color: var(--gray-100);
        }

        .pd-notice > * + * {
            margin-top: 0.5rem;
        }

        .pd-notice-title {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            font-weight: 600;
        }

        .pd-notice-title svg {
            flex-shrink: 0;
            width: 1.5rem;
            height: 1.5rem;
            margin-top: 0.125rem;
        }

        /* Label and value pairs: side by side from the sm breakpoint on, stacked on phones. */
        .pd-facts {
            display: grid;
            gap: 0.125rem 1.5rem;
        }

        .pd-facts dt {
            color: var(--gray-950);
            font-weight: 600;
        }

        .dark .pd-facts dt {
            color: #fff;
        }

        .pd-facts dd {
            margin: 0 0 0.75rem;
        }

        @media (min-width: 40rem) {
            .pd-facts {
                grid-template-columns: minmax(9rem, 15rem) minmax(0, 1fr);
            }

            .pd-facts dd {
                margin-bottom: 0.5rem;
            }
        }

        /* A line to write on and a box to write in. */
        .pd-blank {
            display: inline-block;
            min-width: min(14rem, 100%);
            height: 1.25em;
            border-bottom: 1.5px solid currentColor;
            vertical-align: baseline;
        }

        .pd-field {
            break-inside: avoid;
        }

        .pd-field-box {
            min-height: 5.5rem;
            margin-top: 0.375rem;
            border: 1.5px solid var(--gray-500);
            border-radius: 0.5rem;
        }

        .pd-field-box.pd-tall {
            min-height: 9rem;
        }

        .pd-check {
            display: inline-block;
            flex-shrink: 0;
            width: 1.125em;
            height: 1.125em;
            border: 2px solid currentColor;
            border-radius: 0.2em;
            vertical-align: -0.2em;
        }

        /* The tick is a border, not a background: browsers leave backgrounds out of the printout by default. */
        .pd-check.pd-on {
            position: relative;
        }

        .pd-check.pd-on::after {
            content: '';
            position: absolute;
            top: 42%;
            left: 50%;
            width: 0.3em;
            height: 0.6em;
            border: solid currentColor;
            border-width: 0 0.15em 0.15em 0;
            transform: translate(-50%, -50%) rotate(45deg);
        }

        .pd-options {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem 1.5rem;
            padding-inline-start: 0 !important;
            list-style: none !important;
        }

        .pd-options li {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0 !important;
        }

        /* Checklist: the box, the task, and a line for "done on / by". */
        .pd-checklist {
            padding-inline-start: 0 !important;
            list-style: none !important;
        }

        .pd-checklist > li {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.75rem 0;
            border-top: 1px solid var(--gray-300);
            break-inside: avoid;
        }

        .dark .pd-checklist > li {
            border-color: color-mix(in oklab, #fff 18%, transparent);
        }

        .pd-checklist .pd-check {
            margin-top: 0.3rem;
            font-size: 1.125rem;
        }

        .pd-done {
            margin-top: 0.375rem;
            color: var(--gray-600);
            font-size: 0.875rem;
        }

        .dark .pd-done {
            color: var(--gray-400);
        }

        /* Cards for risks, data categories and register entries: a grid of bordered blocks that never needs a wide table. */
        .pd-cards {
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 24rem), 1fr));
        }

        .pd-card {
            border: 1.5px solid var(--gray-300);
            border-radius: 0.75rem;
            padding: 1rem 1.125rem;
            break-inside: avoid;
        }

        .dark .pd-card {
            border-color: color-mix(in oklab, #fff 22%, transparent);
        }

        .pd-card > * + * {
            margin-top: 0.75rem;
        }

        .pd-card h4 {
            margin-top: 0;
        }

        .pd-card .pd-facts dd:last-child {
            margin-bottom: 0;
        }

        .pd-signature {
            display: grid;
            gap: 1.25rem 2rem;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 16rem), 1fr));
            margin-top: 2.5rem;
        }

        .pd-signature > div {
            border-top: 1.5px solid currentColor;
            padding-top: 0.25rem;
            font-size: 0.875rem;
            break-inside: avoid;
        }

        @media print {
            @page {
                margin: 1.5cm;
            }

            .fi-sidebar, .fi-topbar-ctn, .fi-layout-sidebar-toggle-btn-ctn, .fi-header, .fi-breadcrumbs, .fi-sidebar-close-overlay, .lp-footer {
                display: none !important;
            }

            .fi-main-ctn, .fi-main {
                margin: 0 !important;
                padding: 0 !important;
                max-width: none !important;
            }

            .fi-body {
                background: #fff !important;
            }

            /* Browsers drop backgrounds on paper and the dark theme would leave white text on white: print dark on white, outlined. */
            .pd-doc {
                padding: 0;
                box-shadow: none !important;
                background: #fff !important;
                font-size: 10.5pt;
                line-height: 1.55;
            }

            .pd-doc, .pd-doc * {
                background-color: transparent !important;
                color: #111827 !important;
            }

            .pd-doc a {
                text-decoration: underline;
            }

            .pd-doc h2 {
                font-size: 20pt;
            }

            .pd-doc h3 {
                break-after: avoid;
            }

            .pd-doc li, .pd-doc dt {
                break-inside: avoid;
            }
        }
    </style>

    @if ($school['name'] === null)
        <p class="max-w-3xl text-sm text-gray-700 print:hidden dark:text-gray-300">
            Du siehst die Vorlagen ohne Angaben zu einer Schule. Eine Schuladministration sieht sie mit den Stammdaten ihrer Schule und kann sie in ihrem Namen ausdrucken.
        </p>
    @endif

    <nav aria-label="Unterlagen" class="print:hidden">
        <ul class="flex flex-wrap gap-2">
            @foreach ($documents as $key => $item)
                @php($isCurrent = $key === $currentDocument)
                <li>
                    <a
                        href="{{ $item['url'] }}"
                        @if ($isCurrent) aria-current="page" @endif
                        title="{{ $item['description'] }}"
                        @class([
                            'lp-focus inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm ring-1',
                            'bg-primary-50 font-semibold text-primary-900 ring-2 ring-primary-700 dark:bg-primary-400/10 dark:text-primary-100 dark:ring-primary-400' => $isCurrent,
                            'bg-white font-medium text-gray-800 ring-gray-950/15 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-200 dark:ring-white/20 dark:hover:bg-white/5' => ! $isCurrent,
                        ])
                    >
                        <x-filament::icon :icon="$item['icon']" class="size-5 shrink-0" />
                        <span>{{ $item['label'] }}</span>
                        @if ($isCurrent)
                            <span class="sr-only">(geöffnet)</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
        <p class="max-w-2xl text-sm text-gray-700 dark:text-gray-300">{{ $documents[$currentDocument]['description'] }}. Drucken gibt nur diese Vorlage aus, ohne Menü.</p>

        <x-filament::button color="gray" icon="heroicon-m-printer" x-data x-on:click="window.print()">
            Vorlage drucken
        </x-filament::button>
    </div>

    <article class="lp-card pd-doc" lang="de" aria-labelledby="pd-title">
        @include('filament.pages.privacy-documents.'.$currentDocument)
    </article>
</x-filament-panels::page>
