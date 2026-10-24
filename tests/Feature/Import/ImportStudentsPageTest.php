<?php

namespace Tests\Feature\Import;

use App\Filament\Pages\ImportStudents;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ImportStudentsPageTest extends TestCase
{
    use RefreshDatabase;

    private const string PAGE_URL = '/schuelerliste-importieren';

    public function test_only_administrators_can_open_the_page(): void
    {
        $this->get(self::PAGE_URL)->assertRedirect();

        $this->actingAs(User::factory()->teacher()->create())->get(self::PAGE_URL)->assertForbidden();
        $this->actingAs(User::factory()->student()->create())->get(self::PAGE_URL)->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())
            ->get(self::PAGE_URL)
            ->assertOk()
            ->assertSee('Schülerliste importieren')
            ->assertSee('Vorschau erstellen');
    }

    public function test_the_page_is_only_listed_in_the_navigation_for_administrators(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->assertTrue(ImportStudents::canAccess());

        $this->actingAs(User::factory()->teacher()->create());
        $this->assertFalse(ImportStudents::canAccess());
    }

    public function test_non_administrators_cannot_mount_the_livewire_component(): void
    {
        Livewire::actingAs(User::factory()->teacher()->create())
            ->test(ImportStudents::class)
            ->assertForbidden();
    }

    public function test_uploads_are_only_accepted_for_the_file_field(): void
    {
        $file = [['name' => 'schueler.csv', 'size' => 10, 'type' => 'text/csv']];
        $page = Livewire::actingAs(User::factory()->admin()->create())->test(ImportStudents::class);

        $page->call('_startUpload', 'data.file', $file, false)->assertOk();
        $page->call('_startUpload', 'imported', $file, false)->assertForbidden();
    }

    public function test_a_file_is_previewed_first_and_imported_only_after_confirming(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $adminCount = User::count();

        $page = Livewire::test(ImportStudents::class)
            ->fillForm(['file' => $this->upload("Nachname;Vorname;Klasse\nMüller;Anna;5a\nÖztürk;Mehmet;5b\n;Leer;5a\n")])
            ->call('createPreview')
            ->assertHasNoFormErrors()
            ->assertSet('imported', false)
            ->assertSee('Das ist nur eine Vorschau')
            ->assertSee('Wird angelegt')
            ->assertSee('anna.mueller')
            ->assertSee('Nachname fehlt.')
            ->assertSee('5a, 5b')
            ->assertSee('Import ausführen')
            ->assertDontSee('Startpasswort');

        $this->assertSame($adminCount, User::count());
        $this->assertDatabaseCount(SchoolClass::class, 0);

        $page->callAction('import')
            ->assertSet('imported', true)
            ->assertSee('Der Import ist abgeschlossen.')
            ->assertSee('Die Startpasswörter siehst du nur jetzt.')
            ->assertSee('Zugangsdaten als CSV herunterladen')
            ->assertSee('Startpasswort');

        $this->assertSame(2, User::withRole(Role::Student)->count());
        $this->assertDatabaseCount(SchoolClass::class, 2);

        $rows = $page->get('outcome')['rows'];
        $anna = User::where('username', 'anna.mueller')->sole();
        $this->assertSame('created', $rows[0]['status']);
        $this->assertTrue(password_verify($rows[0]['password'], $anna->password));
        $this->assertSame('skipped', $rows[2]['status']);
        $this->assertNull($rows[2]['password']);
    }

    public function test_the_temporary_upload_is_deleted_after_the_import(): void
    {
        $page = Livewire::actingAs(User::factory()->admin()->create())
            ->test(ImportStudents::class)
            ->fillForm(['file' => $this->upload("Nachname;Vorname;Klasse\nMüller;Anna;5a\n")])
            ->call('createPreview');

        // Livewire keeps uploads on a faked disk of its own while testing, next to a .json file with the client's file name and size.
        $uploadedFiles = fn (): array => array_filter(
            Storage::disk('tmp-for-tests')->allFiles(),
            fn (string $path): bool => ! str_ends_with($path, '.json'),
        );

        $this->assertNotEmpty($uploadedFiles());

        $page->callAction('import')->assertSet('imported', true);

        $this->assertSame([], $uploadedFiles());
    }

    public function test_the_credentials_of_new_accounts_can_be_downloaded_as_csv(): void
    {
        $page = Livewire::actingAs(User::factory()->admin()->create())
            ->test(ImportStudents::class)
            ->fillForm(['file' => $this->upload("Nachname;Vorname;Klasse\nMüller;Anna;5a\n")])
            ->call('createPreview')
            ->callAction('import');

        $password = $page->get('outcome')['rows'][0]['password'];

        $page->call('downloadCredentials')
            ->assertFileDownloaded(content: "\xEF\xBB\xBFName;Klasse;Benutzername;Passwort\r\n\"Anna Müller\";5a;anna.mueller;{$password}\r\n");
    }

    public function test_credentials_cannot_be_downloaded_before_an_import_ran(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ImportStudents::class)
            ->fillForm(['file' => $this->upload("Nachname;Vorname;Klasse\nMüller;Anna;5a\n")])
            ->call('createPreview')
            ->call('downloadCredentials')
            ->assertForbidden();
    }

    public function test_an_unusable_file_is_reported_and_nothing_is_previewed(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ImportStudents::class)
            ->fillForm(['file' => $this->upload("Nachname;Schule\nMüller;X\n")])
            ->call('createPreview')
            ->assertNotified('Die Datei lässt sich nicht importieren.')
            ->assertSet('outcome', null);
    }

    public function test_a_file_is_required_and_other_file_types_are_rejected(): void
    {
        $page = Livewire::actingAs(User::factory()->admin()->create())->test(ImportStudents::class);

        $page->call('createPreview')->assertHasFormErrors(['file' => 'required']);

        $page->fillForm(['file' => UploadedFile::fake()->createWithContent('schueler.png', "\x89PNG\r\n\x1a\n".str_repeat("\0", 32))])
            ->call('createPreview')
            ->assertHasFormErrors(['file'])
            ->assertSet('outcome', null);
    }

    public function test_starting_over_returns_to_the_upload_step(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ImportStudents::class)
            ->fillForm(['file' => $this->upload("Nachname;Vorname;Klasse\nMüller;Anna;5a\n")])
            ->call('createPreview')
            ->assertNotSet('outcome', null)
            ->call('startOver')
            ->assertSet('outcome', null)
            ->assertSet('imported', false)
            ->assertSee('Vorschau erstellen');
    }

    private function upload(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('schueler.csv', $content);
    }
}
