<?php

/*
 * Filament's German texts speak to people with the formal "Sie", the platform says "du" to everybody. The keys below
 * replace the ones a user sees in the interface (selects, tag lists); the three "required_description" hints are read
 * out by screen readers and are missing in Filament's German files (the raw key was read out). Everything else comes
 * from the package.
 */
return [

    'checkbox_list' => [
        'required_description' => 'Wähle mindestens eine Option aus.',
    ],

    'select' => [
        'placeholder' => 'Wähle eine Option',
        'required_description' => 'Wähle eine Option aus.',
        // Short on purpose: it is the placeholder of the search field and has to fit on a phone.
        'search_prompt' => 'Tippe zum Suchen',
    ],

    'tags_input' => [
        'actions' => [
            'delete' => [
                'label' => 'Entfernen',
            ],
        ],
    ],

    'toggle_buttons' => [
        'required_description' => 'Wähle mindestens eine Option aus.',
    ],

];
