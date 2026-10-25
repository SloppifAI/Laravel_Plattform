<?php

/*
 * Filament's German texts speak to people with the formal "Sie", the platform says "du" to everybody. The empty-state
 * hint is Filament's "Erstelle ein(e) :model um zu beginnen.", which cannot be right for every gender and lacks a comma.
 * Only the replaced keys are listed; everything else comes from the package.
 */
return [

    'columns' => [

        'select' => [
            'no_search_results_message' => 'Die Suche ergab keine Treffer.',
            // Short on purpose: it is the placeholder of the search field and has to fit on a phone.
            'search_prompt' => 'Tippe zum Suchen',
        ],

    ],

    'empty' => [
        'description' => 'Lege den ersten Eintrag an, um zu beginnen.',
    ],

];
