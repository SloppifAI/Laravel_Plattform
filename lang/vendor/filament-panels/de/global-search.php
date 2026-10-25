<?php

/*
 * The screen-reader texts of the global search are missing in Filament's German file, so the raw key was read out
 * wherever the fallback language is German. Only the missing keys are listed; everything else comes from the package.
 */
return [

    'field' => [
        'instructions' => 'Drücke die Pfeiltaste nach unten, um die Ergebnisse zu durchsuchen. Mit den Pfeiltasten nach oben und unten wechselst du zwischen den Ergebnissen, mit der Tabulatortaste erreichst du die Aktionen eines Ergebnisses. Mit Escape schließt du die Ergebnisse.',
    ],

    'results_label' => 'Ergebnisse der globalen Suche',

    'results_count' => ':count Ergebnis wird angezeigt.|:count Ergebnisse werden angezeigt.',

    'loading_message' => 'Sucht ...',

    'failure_message' => 'Die Suche konnte nicht abgeschlossen werden. Lade die Seite neu und versuche es noch einmal.',

];
