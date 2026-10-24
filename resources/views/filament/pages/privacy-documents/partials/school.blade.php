{{-- The school as controller, prefilled from the school's master data. --}}
<dl class="pd-facts">
    <dt>Schule</dt>
    <dd>{{ $school['name'] ?? $blank }}</dd>

    <dt>Schulnummer</dt>
    <dd>{{ $school['number'] ?? $blank }}</dd>

    <dt>Schulform</dt>
    <dd>{{ $school['type'] ?? $blank }}</dd>

    <dt>Ort, Bundesland</dt>
    <dd>{{ collect([$school['city'], $school['state']])->filter()->implode(', ') ?: $blank }}</dd>

    <dt>Anschrift</dt>
    <dd>{{ $blank }}</dd>

    <dt>Kontakt-E-Mail</dt>
    <dd>{{ $school['email'] ?? $blank }}</dd>

    <dt>Schulleitung</dt>
    <dd>{{ $blank }}</dd>

    <dt>Datenschutzbeauftragte(r)</dt>
    <dd>{{ $blank }}</dd>
</dl>
