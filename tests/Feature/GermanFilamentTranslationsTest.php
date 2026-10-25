<?php

namespace Tests\Feature;

use Tests\TestCase;

class GermanFilamentTranslationsTest extends TestCase
{
    public function test_the_hints_of_required_selection_fields_are_translated_so_screen_readers_do_not_read_a_raw_key(): void
    {
        app()->setLocale('de');

        foreach ([
            'filament-forms::components.select.required_description' => 'Wähle eine Option aus.',
            'filament-forms::components.checkbox_list.required_description' => 'Wähle mindestens eine Option aus.',
            'filament-forms::components.toggle_buttons.required_description' => 'Wähle mindestens eine Option aus.',
        ] as $key => $translation) {
            $this->assertSame($translation, __($key));
        }
    }

    public function test_the_interface_speaks_to_people_with_du_not_with_the_formal_sie_of_filament(): void
    {
        app()->setLocale('de');

        foreach ([
            'filament-forms::components.select.placeholder' => 'Wähle eine Option',
            'filament-forms::components.select.search_prompt' => 'Tippe zum Suchen',
            'filament-tables::table.columns.select.search_prompt' => 'Tippe zum Suchen',
            'filament-actions::modal.confirmation' => 'Möchtest du das wirklich tun?',
            'filament-actions::notifications.throttled.body' => 'Bitte versuche es in :seconds Sekunden erneut.',
            'filament-panels::error-notifications.body' => 'Beim Laden dieser Seite ist ein Fehler aufgetreten. Bitte versuche es später noch einmal.',
            'filament-notifications::database.modal.empty.description' => 'Schau später noch einmal vorbei.',
        ] as $key => $translation) {
            $this->assertSame($translation, __($key));
        }
    }

    public function test_filament_sentences_that_were_clumsy_in_german_read_properly(): void
    {
        app()->setLocale('de');

        $this->assertSame('Erstellen und weitere anlegen', __('filament-actions::create.single.modal.actions.create_another.label'));
        $this->assertSame('Erstellen und weitere anlegen', __('filament-panels::resources/pages/create-record.form.actions.create_another.label'));
        $this->assertSame('Lege den ersten Eintrag an, um zu beginnen.', __('filament-tables::table.empty.description', ['model' => 'Frage']));
        // The package's other keys of the same file still come from Filament.
        $this->assertSame('Erstellen', __('filament-actions::create.single.modal.actions.create.label'));
    }

    public function test_the_screen_reader_texts_of_the_global_search_are_german_and_not_a_raw_key(): void
    {
        app()->setLocale('de');
        // The fallback language may still be German in a local .env, which used to show the raw key.
        app()->setFallbackLocale('de');

        $instructions = __('filament-panels::global-search.field.instructions');

        $this->assertNotSame('filament-panels::global-search.field.instructions', $instructions);
        $this->assertStringContainsString('Pfeiltaste', $instructions);
    }
}
