{{-- Title block and the "this is only a template" notice every template starts with. Needs $title and $lead. --}}
<header class="space-y-2">
    <p class="pd-kicker">{{ $school['name'] ?? 'Name der Schule' }} · Datenschutz bei Tuto</p>
    <h2 id="pd-title">{{ $title }}</h2>
    <p>{{ $lead }}</p>
</header>

<div class="pd-notice" role="note" aria-labelledby="pd-notice-title">
    <p class="pd-notice-title" id="pd-notice-title">
        <x-filament::icon icon="heroicon-o-information-circle" />
        <span>Vorlage – an die Gegebenheiten der Schule anpassen, keine Rechtsberatung</span>
    </p>
    <p>
        Dieser Text ist ein Entwurf. Er ersetzt weder die Prüfung durch die oder den Datenschutzbeauftragten der Schule noch eine Rechtsberatung. Welche Schritte und Formulierungen nach Landesrecht und Schulträger nötig sind, entscheidet die Schule.
    </p>
    <p>
        Vorausgefüllt sind die Stammdaten der Schule und die Einstellungen dieser Plattform (Stand {{ $printedOn }}). Ändern sie sich, ist der Text zu prüfen. Linien und Felder zum Ausfüllen bleiben leer.
    </p>
</div>
