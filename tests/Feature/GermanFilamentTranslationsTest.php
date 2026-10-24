<?php

namespace Tests\Feature;

use Tests\TestCase;

class GermanFilamentTranslationsTest extends TestCase
{
    public function test_the_hints_of_required_selection_fields_are_translated_so_screen_readers_do_not_read_a_raw_key(): void
    {
        app()->setLocale('de');

        foreach ([
            'filament-forms::components.select.required_description' => 'Wählen Sie eine Option aus.',
            'filament-forms::components.checkbox_list.required_description' => 'Wählen Sie mindestens eine Option aus.',
            'filament-forms::components.toggle_buttons.required_description' => 'Wählen Sie mindestens eine Option aus.',
        ] as $key => $translation) {
            $this->assertSame($translation, __($key));
        }
    }
}
