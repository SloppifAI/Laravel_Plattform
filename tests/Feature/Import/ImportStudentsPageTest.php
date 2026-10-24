<?php

namespace Tests\Feature\Import;

use App\Filament\Pages\ImportStudents;
use App\Models\School;
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
        $this->actingAs(User::factory()->operator()->create())->get(self::PAGE_URL)->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())
            ->get(self::PAGE_URL)
            ->assertOk()
            ->assertSee('Import student list')
            ->assertSee('Create preview');
    }

    public function test_the_page_is_only_listed_in_the_navigation_for_administrators(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->assertTrue(ImportStudents::canAccess());

        $this->actingAs(User::factory()->teacher()->create());
        $this->assertFalse(ImportStudents::canAccess());

        $this->actingAs(User::factory()->operator()->create());
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

    public function test_the_confirmation_announces_what_will_happen_before_anything_is_imported(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ImportStudents::class)
            ->fillForm(['file' => $this->upload("Nachname;Vorname;Klasse\nMüller;Anna;5a\nÖztürk;Mehmet;5b\n;Leer;5a\n")])
            ->call('createPreview')
            ->mountAction('import')
            ->assertMountedActionModalSee('This will create 2 accounts, update 0 and skip 1 rows.');
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
            ->assertSee('This is only a preview')
            ->assertSee('Will be created')
            ->assertSee('anna.mueller')
            ->assertSee('Last name is missing.')
            ->assertSee('5a, 5b')
            ->assertSee('Run import')
            ->assertDontSee('Initial password');

        $this->assertSame($adminCount, User::count());
        $this->assertDatabaseCount(SchoolClass::class, 0);

        $page->callAction('import')
            ->assertSet('imported', true)
            ->assertSee('The import is complete.')
            ->assertSee('You only see the initial passwords now.')
            ->assertSee('Download login details as CSV')
            ->assertSee('Initial password');

        $this->assertSame(2, User::withRole(Role::Student)->count());
        $this->assertDatabaseCount(SchoolClass::class, 2);

        $rows = $page->get('outcome')['rows'];
        $anna = User::where('username', 'anna.mueller')->sole();
        $this->assertSame('created', $rows[0]['status']);
        $this->assertTrue(password_verify($rows[0]['password'], $anna->password));
        $this->assertSame('skipped', $rows[2]['status']);
        $this->assertNull($rows[2]['password']);
    }

    public function test_operators_cannot_mount_the_livewire_component(): void
    {
        Livewire::actingAs(User::factory()->operator()->create())
            ->test(ImportStudents::class)
            ->assertForbidden();
    }

    public function test_the_import_creates_everything_in_the_school_of_the_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $otherSchool = School::factory()->create();

        Livewire::actingAs($admin)
            ->test(ImportStudents::class)
            ->fillForm(['file' => $this->upload("Nachname;Vorname;Klasse\nMüller;Anna;5a\n")])
            ->call('createPreview')
            ->callAction('import')
            ->assertSet('imported', true);

        $anna = User::where('username', 'anna.mueller')->sole();
        $this->assertSame($admin->school_id, $anna->school_id);
        $this->assertSame($admin->school_id, $anna->schoolClass->school_id);
        $this->assertSame(0, $otherSchool->users()->count() + $otherSchool->schoolClasses()->count());
    }

    public function test_a_username_of_another_school_shows_up_as_a_plain_error_row(): void
    {
        $otherSchool = School::factory()->create(['name' => 'Geheimschule Nord']);
        User::factory()->teacher()->for($otherSchool)->create(['username' => 'krause']);
        $admin = User::factory()->admin()->create();

        $page = Livewire::actingAs($admin)
            ->test(ImportStudents::class)
            ->fillForm(['file' => $this->upload("Nachname;Vorname;Klasse;Benutzername\nKrause;Petra;5a;krause\n")])
            ->call('createPreview')
            ->assertSee('The username is already taken.')
            ->assertDontSee('Geheimschule Nord')
            ->assertDontSee('Teacher');

        $this->assertSame('skipped', $page->get('outcome')['rows'][0]['status']);
        $this->assertSame(0, SchoolClass::count());
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
            ->assertFileDownloaded(content: "\xEF\xBB\xBFName;Class;Username;Password\r\n\"Anna Müller\";5a;anna.mueller;{$password}\r\n");
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
            ->assertNotified('The file cannot be imported.')
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
            ->assertSee('Create preview');
    }

    private function upload(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('schueler.csv', $content);
    }
}
