<?php

namespace Tests\Feature\Import;

use App\Filament\Pages\ImportStudents;
use App\Models\School;
use App\Models\User;
use App\Support\StudentImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The tests of the import run in English; this one makes sure the German interface (the default) still reads German.
 */
class ImportGermanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('de');
    }

    public function test_the_page_and_its_preview_read_german(): void
    {
        $upload = UploadedFile::fake()->createWithContent('schueler.csv', "Nachname;Vorname;Klasse\nMüller;Anna;5a\n;Mehmet;5b\n");

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ImportStudents::class)
            ->assertSee('Schülerliste importieren')
            ->assertSee('So muss die Datei aufgebaut sein')
            ->assertDontSee('Import student list')
            ->fillForm(['file' => $upload])
            ->call('createPreview')
            ->assertSee('Das ist nur eine Vorschau. Es wurde noch nichts gespeichert.')
            ->assertSee('Wird angelegt')
            ->assertSee('Nachname fehlt.')
            ->assertSee('Import ausführen');
    }

    public function test_the_messages_and_the_credentials_csv_read_german(): void
    {
        $import = new StudentImport;
        $school = School::factory()->create();

        $this->assertSame('Die Datei enthält keine Zeilen.', $import->parse(" \n\n")['problem']);
        $this->assertStringContainsString('Vorname, Klasse', $import->parse("Nachname;Schule\nMüller;X\n")['problem']);

        $outcome = $import->import("Nachname;Vorname;Klasse\nMüller;Anna;5a\n", $school);

        $this->assertSame('Konto angelegt.', $outcome['rows'][0]['message']);
        $this->assertStringStartsWith("\xEF\xBB\xBFName;Klasse;Benutzername;Passwort\r\n", $import->credentialsCsv($outcome['rows']));
    }
}
