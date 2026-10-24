{{--
    The parents' consent page behind the link of the letter. Public, but the token in the URL is the credential:
    the page is not indexed, not cached and sends no Referer, and shows only the school, the child's first name and the class.
--}}
@extends('filament.legal.layout')

@php
    use App\ConsentStatus;
    use App\Models\ConsentForm;
    use Filament\Support\Icons\Heroicon;

    $isGranted = in_array($status, [ConsentStatus::Granted, ConsentStatus::Partial], true);
    $selected = $input['purposes'] ?? ($isGranted ? ($consent->purposes ?? []) : []);
    $guardianName = $input['guardian_name'] ?? '';
@endphp

@section('title', 'Einwilligung zur Tonaufnahme')
@section('description', 'Einwilligung der Erziehungsberechtigten zur Tonaufnahme im Unterricht')

@push('head')
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <style>
        .consent-banner {
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
            padding: 1rem 1.25rem;
            border-radius: 0.75rem;
            background: var(--banner-bg);
            box-shadow: inset 4px 0 0 var(--banner-edge);
            color: var(--banner-ink);
        }

        .consent-banner svg {
            flex: none;
            width: 1.5rem;
            height: 1.5rem;
            margin-top: 0.125rem;
        }

        .consent-banner p {
            margin: 0;
        }

        .consent-banner-success { --banner-bg: #ecfdf5; --banner-edge: #059669; --banner-ink: #064e3b; }
        .consent-banner-warning { --banner-bg: #fffbeb; --banner-edge: #d97706; --banner-ink: #78350f; }
        .consent-banner-danger { --banner-bg: #fef2f2; --banner-edge: #dc2626; --banner-ink: #7f1d1d; }

        .dark .consent-banner-success { --banner-bg: rgb(16 185 129 / 0.12); --banner-edge: #34d399; --banner-ink: #a7f3d0; }
        .dark .consent-banner-warning { --banner-bg: rgb(245 158 11 / 0.12); --banner-edge: #fbbf24; --banner-ink: #fde68a; }
        .dark .consent-banner-danger { --banner-bg: rgb(244 63 94 / 0.12); --banner-edge: #fb7185; --banner-ink: #fecdd3; }

        .consent-form > * + * {
            margin-top: 1.25rem;
        }

        .consent-form fieldset {
            min-width: 0;
            margin: 0;
            padding: 0;
            border: 0;
        }

        .consent-field,
        .consent-input,
        .consent-button {
            max-width: 100%;
            box-sizing: border-box;
        }

        .consent-legend {
            padding: 0;
            color: var(--gray-950);
            font-weight: 600;
        }

        .dark .consent-legend {
            color: #fff;
        }

        .consent-field {
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
            min-height: 3rem;
            padding: 0.75rem 0.875rem;
            border-radius: 0.75rem;
            box-shadow: 0 0 0 1px var(--gray-400);
            cursor: pointer;
        }

        .consent-field + .consent-field {
            margin-top: 0.5rem;
        }

        .consent-field input {
            flex: none;
            width: 1.25rem;
            height: 1.25rem;
            margin-top: 0.15rem;
            accent-color: var(--primary-600);
        }

        .consent-field:has(input:checked) {
            background: var(--primary-50);
            box-shadow: 0 0 0 2px var(--primary-600);
        }

        .dark .consent-field {
            box-shadow: 0 0 0 1px rgb(255 255 255 / 0.3);
        }

        .dark .consent-field:has(input:checked) {
            background: color-mix(in oklab, var(--primary-400) 12%, transparent);
            box-shadow: 0 0 0 2px var(--primary-400);
        }

        .consent-input {
            display: block;
            width: 100%;
            min-height: 2.75rem;
            margin-top: 0.375rem;
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--gray-500);
            border-radius: 0.5rem;
            background: #fff;
            color: var(--gray-950);
            font: inherit;
        }

        .dark .consent-input {
            background: var(--gray-900);
            color: #fff;
        }

        .consent-label {
            color: var(--gray-950);
            font-weight: 600;
        }

        .dark .consent-label {
            color: #fff;
        }

        .consent-hint {
            color: var(--gray-600);
            font-size: 0.875rem;
        }

        .dark .consent-hint {
            color: var(--gray-400);
        }

        .consent-error {
            margin-top: 0.375rem;
            color: #b91c1c;
            font-size: 0.9375rem;
        }

        .dark .consent-error {
            color: #fda4af;
        }

        .consent-field:has(input:focus-visible),
        .consent-input:focus-visible,
        .consent-button:focus-visible,
        .consent-details summary:focus-visible {
            outline: 2px solid var(--primary-600);
            outline-offset: 2px;
        }

        .dark .consent-field:has(input:focus-visible),
        .dark .consent-input:focus-visible,
        .dark .consent-button:focus-visible,
        .dark .consent-details summary:focus-visible {
            outline-color: var(--primary-400);
        }

        .consent-button {
            min-height: 2.75rem;
            padding: 0.5rem 1.25rem;
            border: 1px solid transparent;
            border-radius: 0.5rem;
            background: var(--primary-600);
            color: #fff;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        .consent-button:hover {
            background: var(--primary-700);
        }

        .consent-button-secondary {
            border-color: var(--gray-500);
            background: transparent;
            color: var(--gray-900);
        }

        .consent-button-secondary:hover {
            background: var(--gray-100);
        }

        .dark .consent-button-secondary {
            color: #fff;
        }

        .dark .consent-button-secondary:hover {
            background: rgb(255 255 255 / 0.08);
        }

        .consent-details {
            border-radius: 0.75rem;
            box-shadow: 0 0 0 1px var(--gray-300);
        }

        .dark .consent-details {
            box-shadow: 0 0 0 1px rgb(255 255 255 / 0.15);
        }

        .consent-details summary {
            min-height: 2.75rem;
            padding: 0.625rem 1rem;
            color: var(--gray-950);
            font-weight: 600;
            cursor: pointer;
        }

        .dark .consent-details summary {
            color: #fff;
        }

        .consent-details > div {
            padding: 0 1rem 1rem;
        }
    </style>
@endpush

@section('content')
    <h1>{{ $form?->title ?? 'Einwilligung zur Tonaufnahme' }}</h1>

    <dl class="legal-facts">
        <dt>Schule</dt>
        <dd>{{ $school->name }}, {{ $school->city }}</dd>

        <dt>Schüler:in</dt>
        <dd>{{ $firstName }}</dd>

        <dt>Klasse</dt>
        <dd>{{ $schoolClass?->name ?? '–' }}</dd>
    </dl>

    @if ($form === null)
        <p class="consent-banner consent-banner-warning" role="status">
            <x-filament::icon :icon="Heroicon::OutlinedExclamationTriangle" aria-hidden="true" />
            <span>Die Einwilligungserklärung der Schule steht noch nicht bereit. Bitte versuchen Sie es später noch einmal.</span>
        </p>
    @else
        @if ($errors->any())
            <div class="consent-banner consent-banner-danger" role="alert" id="fehler">
                <x-filament::icon :icon="Heroicon::OutlinedExclamationCircle" aria-hidden="true" />
                <div>
                    <p><strong>Ihre Eingabe konnte nicht gespeichert werden.</strong></p>
                    <ul>
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        @if ($result === 'granted')
            <div class="consent-banner consent-banner-success" role="status">
                <x-filament::icon :icon="Heroicon::OutlinedCheckCircle" aria-hidden="true" />
                <p><strong>Vielen Dank, Ihre Einwilligung wurde gespeichert.</strong> Sie gilt für: {{ implode(', ', array_intersect_key(ConsentForm::PURPOSES, array_flip($consent->purposes ?? []))) }}. Sie können sie jederzeit auf dieser Seite widerrufen.</p>
            </div>
        @elseif ($result === 'revoked')
            <div class="consent-banner consent-banner-danger" role="status">
                <x-filament::icon :icon="Heroicon::OutlinedXCircle" aria-hidden="true" />
                <p><strong>Ihre Einwilligung wurde widerrufen.</strong> Ab sofort werden keine Aufnahmen mehr gemacht, bei denen Ihr Kind anwesend ist. Sie können jederzeit neu einwilligen.</p>
            </div>
        @elseif ($status === ConsentStatus::Outdated)
            <div class="consent-banner consent-banner-warning" role="status">
                <x-filament::icon :icon="Heroicon::OutlinedArrowPath" aria-hidden="true" />
                <p><strong>Der Text hat sich geändert.</strong> Ihre Einwilligung vom {{ $consent->granted_at->format('d.m.Y') }} bezog sich auf eine frühere Fassung. Bitte lesen Sie den neuen Text und bestätigen Sie ihn erneut. Bis dahin gilt Ihre bisherige Einwilligung nicht.</p>
            </div>
        @elseif ($status === ConsentStatus::Revoked)
            <div class="consent-banner consent-banner-danger" role="status">
                <x-filament::icon :icon="Heroicon::OutlinedXCircle" aria-hidden="true" />
                <p><strong>Sie haben Ihre Einwilligung am {{ $consent->revoked_at->format('d.m.Y') }} widerrufen.</strong> Es werden keine Aufnahmen gemacht, bei denen Ihr Kind anwesend ist. Sie können unten jederzeit neu einwilligen.</p>
            </div>
        @elseif ($isGranted && $result === null)
            <div class="consent-banner consent-banner-success" role="status">
                <x-filament::icon :icon="Heroicon::OutlinedCheckCircle" aria-hidden="true" />
                <p><strong>Einwilligung erteilt am {{ $consent->granted_at->format('d.m.Y') }}.</strong> Sie gilt für: {{ implode(', ', array_intersect_key(ConsentForm::PURPOSES, array_flip($consent->purposes ?? []))) }}.</p>
            </div>
        @endif

        @if ($isGranted)
            <form method="POST" action="{{ route('consents.revoke', $token) }}">
                <button type="submit" class="consent-button consent-button-secondary">Einwilligung widerrufen</button>
            </form>

            <details class="consent-details">
                <summary>Text der Einwilligung (Fassung {{ $form->version }}) lesen</summary>
                <div>{!! $form->html() !!}</div>
            </details>

            <details class="consent-details" @if ($errors->any()) open @endif>
                <summary>Auswahl ändern</summary>
                <div>@include('filament.consents.public-form')</div>
            </details>
        @else
            {!! $form->html() !!}

            <h2 id="einwilligung">Ihre Einwilligung</h2>
            @include('filament.consents.public-form')
        @endif
    @endif
@endsection
