<?php

namespace Tests\Feature\Import;

use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use App\Support\StudentImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class StudentImportTest extends TestCase
{
    use RefreshDatabase;

    private StudentImport $import;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->import = new StudentImport;
        $this->school = School::factory()->create();
    }

    public function test_parser_reads_semicolons_a_bom_and_flexible_german_headers(): void
    {
        $parsed = $this->import->parse("\xEF\xBB\xBFName;Vorname;Klasse;Benutzername;E-Mail\r\n Müller ; Anna ;5a;Anna.M;anna@example.org\r\n\r\n;;;\r\nÖztürk;Mehmet;5b;;\r\n");

        $this->assertNull($parsed['problem']);
        $this->assertSame([
            ['line' => 2, 'lastName' => 'Müller', 'firstName' => 'Anna', 'class' => '5a', 'username' => 'anna.m', 'email' => 'anna@example.org'],
            ['line' => 5, 'lastName' => 'Öztürk', 'firstName' => 'Mehmet', 'class' => '5b', 'username' => '', 'email' => ''],
        ], $parsed['rows']);
    }

    public function test_parser_reads_commas_with_english_headers_in_any_order(): void
    {
        $parsed = $this->import->parse("class,firstname,lastname,username\n6c,\"Lea, Marie\",Schmidt,lea\n");

        $this->assertNull($parsed['problem']);
        $this->assertSame(
            ['line' => 2, 'lastName' => 'Schmidt', 'firstName' => 'Lea, Marie', 'class' => '6c', 'username' => 'lea', 'email' => ''],
            $parsed['rows'][0],
        );
    }

    public function test_parser_reads_tab_separated_files(): void
    {
        $parsed = $this->import->parse("Nachname\tVorname\tKlasse\nWeiß\tJule\t7a\n");

        $this->assertNull($parsed['problem']);
        $this->assertSame('Weiß', $parsed['rows'][0]['lastName']);
        $this->assertSame('7a', $parsed['rows'][0]['class']);
    }

    public function test_parser_converts_windows_1252_umlauts(): void
    {
        $content = mb_convert_encoding("Nachname;Vorname;Klasse\nGrößer;Jörg;8ä\n", 'Windows-1252', 'UTF-8');

        $this->assertFalse(mb_check_encoding($content, 'UTF-8'));

        $parsed = $this->import->parse($content);

        $this->assertNull($parsed['problem']);
        $this->assertSame(['Größer', 'Jörg', '8ä'], [$parsed['rows'][0]['lastName'], $parsed['rows'][0]['firstName'], $parsed['rows'][0]['class']]);
    }

    public function test_parser_reports_unusable_files_instead_of_guessing(): void
    {
        $this->assertStringContainsString('First name, Class', $this->import->parse("Nachname;Schule\nMüller;X\n")['problem']);
        $this->assertSame('The file contains no rows.', $this->import->parse(" \n\n")['problem']);
        $this->assertStringContainsString('no students', $this->import->parse("Nachname;Vorname;Klasse\n")['problem']);

        $tooMany = "Nachname;Vorname;Klasse\n".str_repeat("Müller;Anna;5a\n", StudentImport::MAX_ROWS + 1);
        $this->assertStringContainsString('at most', $this->import->parse($tooMany)['problem']);
    }

    public function test_import_creates_classes_and_students_who_can_log_in_with_their_initial_password(): void
    {
        $outcome = $this->importCsv("Nachname;Vorname;Klasse\nGrößer;Jörg;5a\nMüller;Anna;5a\nÖztürk;Mehmet;5b\n");

        $this->assertNull($outcome['problem']);
        $this->assertSame(['5a', '5b'], $outcome['newClasses']);
        $this->assertSame(['created', 'created', 'created'], array_column($outcome['rows'], 'status'));
        $this->assertSame(['joerg.groesser', 'anna.mueller', 'mehmet.oeztuerk'], array_column($outcome['rows'], 'username'));
        $this->assertDatabaseCount(SchoolClass::class, 2);

        foreach ($outcome['rows'] as $row) {
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Za-km-z2-9]{12}$/', $row['password']);

            $student = User::where('username', $row['username'])->sole();
            $this->assertSame(Role::Student, $student->role);
            $this->assertSame($row['class'], $student->schoolClass->name);
            $this->assertNotSame($row['password'], $student->password);
            $this->assertTrue(Hash::check($row['password'], $student->password));
            $this->assertTrue(Auth::attempt(['username' => $row['username'], 'password' => $row['password']]));
            Auth::logout();
        }

        $this->assertSame('Jörg Größer', User::where('username', 'joerg.groesser')->sole()->name);
        $this->assertSame(3, count(array_unique(array_column($outcome['rows'], 'password'))));
    }

    public function test_the_preview_says_what_will_happen_and_the_import_what_happened(): void
    {
        $student = User::factory()->student($this->classIn($this->school, '5a'))->create(['name' => 'Anna Mueller', 'username' => 'anna.mueller']);
        $csv = "Nachname;Vorname;Klasse;Benutzername\nMüller;Anna;6a;anna.mueller\nSchmidt;Ben;5a;\n";

        $preview = $this->previewCsv($csv);
        $import = $this->importCsv($csv);

        $this->assertSame(['Will be updated: Name and Class.', 'Account will be created.'], array_column($preview['rows'], 'message'));
        $this->assertSame(['Name and Class updated.', 'Account created.'], array_column($import['rows'], 'message'));
        $this->assertSame($student->id, User::where('username', 'anna.mueller')->value('id'));
    }

    public function test_generated_usernames_are_unique_even_for_the_same_name(): void
    {
        User::factory()->teacher()->create(['username' => 'anna.schmidt']);

        $outcome = $this->importCsv("Nachname;Vorname;Klasse\nSchmidt;Anna;5a\nSchmidt;Anna;5a\n");

        $this->assertSame(['anna.schmidt2', 'anna.schmidt3'], array_column($outcome['rows'], 'username'));
        $this->assertSame(['created', 'created'], array_column($outcome['rows'], 'status'));
    }

    public function test_an_existing_student_is_updated_not_duplicated(): void
    {
        $oldClass = $this->classIn($this->school, '5a');
        $student = User::factory()->student($oldClass)->create(['name' => 'Anna Mueller', 'username' => 'anna.mueller']);
        $passwordHash = $student->password;

        $outcome = $this->importCsv("Nachname;Vorname;Klasse;Benutzername\nMüller;Anna;6a;anna.mueller\n");

        $this->assertSame(['updated'], array_column($outcome['rows'], 'status'));
        $this->assertSame('Name and Class updated.', $outcome['rows'][0]['message']);
        $this->assertNull($outcome['rows'][0]['password']);
        $this->assertSame(['6a'], $outcome['newClasses']);

        $student->refresh();
        $this->assertSame('Anna Müller', $student->name);
        $this->assertSame('6a', $student->schoolClass->name);
        $this->assertSame($passwordHash, $student->password);
        $this->assertSame(1, User::where('username', 'anna.mueller')->count());
        $this->assertSame(1, User::withRole(Role::Student)->count());
    }

    public function test_importing_the_same_list_again_does_not_create_duplicates(): void
    {
        $csv = "Nachname;Vorname;Klasse\nMüller;Anna;5a\nSchmidt;Lea;5a\nSchmidt;Lea;5a\n";

        $this->importCsv($csv);
        $userCount = User::count();

        $second = $this->importCsv($csv);

        $this->assertSame(['skipped', 'skipped', 'skipped'], array_column($second['rows'], 'status'));
        $this->assertSame([], $second['newClasses']);
        $this->assertSame($userCount, User::count());
    }

    public function test_an_account_that_is_not_a_student_is_reported_and_left_untouched(): void
    {
        $teacher = User::factory()->teacher()->for($this->school)->create(['name' => 'Frau Krause', 'username' => 'krause']);
        $admin = User::factory()->admin()->for($this->school)->create(['username' => 'chef']);
        $hash = $teacher->password;

        $outcome = $this->importCsv("Nachname;Vorname;Klasse;Benutzername\nKrause;Petra;5a;krause\nBoss;Bob;5a;chef\nMüller;Anna;5a;anna\n");

        $this->assertSame(['skipped', 'skipped', 'created'], array_column($outcome['rows'], 'status'));
        $this->assertStringContainsString('Teacher', $outcome['rows'][0]['message']);
        $this->assertStringContainsString('School administration', $outcome['rows'][1]['message']);

        $teacher->refresh();
        $this->assertSame('Frau Krause', $teacher->name);
        $this->assertSame(Role::Teacher, $teacher->role);
        $this->assertNull($teacher->school_class_id);
        $this->assertSame($hash, $teacher->password);
        $this->assertNull($admin->refresh()->school_class_id);
        $this->assertSame('anna', User::where('name', 'Anna Müller')->sole()->username);
    }

    public function test_faulty_rows_are_reported_while_valid_rows_still_import(): void
    {
        User::factory()->create(['email' => 'taken@example.org']);

        $outcome = $this->importCsv(implode("\n", [
            'Nachname;Vorname;Klasse;Benutzername;E-Mail',
            'Müller;;5a;;',
            'Schmidt;Lea;;;',
            'Meier;Tom;5a;;kein-email',
            'Koch;Ida;5a;Ida Koch;',
            'Wolf;Max;5a;;taken@example.org',
            '***;???;5a;;',
            'Bauer;Eva;5a;eva;eva@example.org',
            'Bauer;Eva;5a;eva;',
            'Fuchs;Uwe;5a;;eva@example.org',
        ]));

        $this->assertSame(
            ['skipped', 'skipped', 'skipped', 'skipped', 'skipped', 'skipped', 'created', 'skipped', 'skipped'],
            array_column($outcome['rows'], 'status'),
        );
        $this->assertSame('First name is missing.', $outcome['rows'][0]['message']);
        $this->assertSame('Class is missing.', $outcome['rows'][1]['message']);
        $this->assertStringContainsString('email address is not valid', $outcome['rows'][2]['message']);
        $this->assertStringContainsString('username may only contain', $outcome['rows'][3]['message']);
        $this->assertStringContainsString('email address already belongs', $outcome['rows'][4]['message']);
        $this->assertStringContainsString('username cannot be created', $outcome['rows'][5]['message']);
        $this->assertStringContainsString('several times', $outcome['rows'][7]['message']);
        $this->assertStringContainsString('email address already belongs', $outcome['rows'][8]['message']);
        $this->assertSame(['5a'], $outcome['newClasses']);
        $this->assertSame(1, User::where('username', 'eva')->count());
    }

    public function test_a_failing_database_write_rolls_the_whole_import_back(): void
    {
        User::creating(function (User $user): void {
            if ($user->username === 'lea.schmidt') {
                throw new RuntimeException('Datenbank weg.');
            }
        });

        try {
            $this->importCsv("Nachname;Vorname;Klasse\nMüller;Anna;5a\nSchmidt;Lea;5a\n");
            $this->fail('The exception should have been thrown.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount(User::class, 0);
            $this->assertDatabaseCount(SchoolClass::class, 0);
        }
    }

    public function test_preview_predicts_the_import_without_writing_anything(): void
    {
        User::factory()->teacher()->create(['username' => 'krause']);
        $csv = "Nachname;Vorname;Klasse;Benutzername\nMüller;Anna;5a;\nMüller;Anna;5a;\nKrause;Petra;5a;krause\n";
        $usersBefore = User::count();

        $preview = $this->previewCsv($csv);

        $this->assertSame($usersBefore, User::count());
        $this->assertDatabaseCount(SchoolClass::class, 0);
        $this->assertSame(['5a'], $preview['newClasses']);
        $this->assertSame([null, null, null], array_column($preview['rows'], 'password'));

        $import = $this->importCsv($csv);

        $this->assertSame(array_column($import['rows'], 'status'), array_column($preview['rows'], 'status'));
        $this->assertSame(array_column($import['rows'], 'username'), array_column($preview['rows'], 'username'));
        // Only the tense differs: the preview says what will happen, the import what happened (see the test below).
        $skipped = fn (array $rows): array => array_column(array_filter($rows, fn (array $row): bool => $row['status'] === 'skipped'), 'message');
        $this->assertSame($skipped($import['rows']), $skipped($preview['rows']));
        $this->assertSame($import['newClasses'], $preview['newClasses']);
        $this->assertSame(['anna.mueller', 'anna.mueller2'], array_slice(array_column($preview['rows'], 'username'), 0, 2));
    }

    public function test_classes_and_students_are_created_in_the_given_school(): void
    {
        $otherSchool = School::factory()->create();

        $outcome = $this->importCsv("Nachname;Vorname;Klasse\nMüller;Anna;5a\nÖztürk;Mehmet;5b\n");

        $this->assertSame(['created', 'created'], array_column($outcome['rows'], 'status'));
        $this->assertSame([$this->school->id, $this->school->id], SchoolClass::orderBy('name')->pluck('school_id')->all());
        $this->assertSame([$this->school->id, $this->school->id], User::withRole(Role::Student)->pluck('school_id')->all());
        $this->assertSame(0, $otherSchool->schoolClasses()->count() + $otherSchool->users()->count());

        foreach (User::withRole(Role::Student)->get() as $student) {
            $this->assertSame($student->school_id, $student->schoolClass->school_id);
        }
    }

    public function test_a_username_of_another_school_is_reported_without_saying_who_uses_it(): void
    {
        $otherSchool = School::factory()->create(['name' => 'Geheimschule Nord']);
        $foreignClass = $this->classIn($otherSchool, '6b');
        $foreignStudent = User::factory()->student($foreignClass)->create(['name' => 'Fremde Anna', 'username' => 'anna']);
        $foreignTeacher = User::factory()->teacher()->for($otherSchool)->create(['username' => 'krause']);
        $operator = User::factory()->operator()->create(['username' => 'plattform']);

        $csv = "Nachname;Vorname;Klasse;Benutzername\nMüller;Anna;5a;anna\nKrause;Petra;5a;krause\nBoss;Bob;5a;plattform\n";
        $outcome = $this->importCsv($csv);

        $this->assertSame(['skipped', 'skipped', 'skipped'], array_column($outcome['rows'], 'status'));
        $this->assertSame([], $outcome['newClasses']);
        $this->assertSame(0, $this->school->schoolClasses()->count());

        foreach ($outcome['rows'] as $row) {
            $this->assertSame('The username is already taken. Choose a different one in the file.', $row['message']);
            $this->assertNull($row['password']);
        }

        $foreignStudent->refresh();
        $this->assertSame('Fremde Anna', $foreignStudent->name);
        $this->assertSame($foreignClass->id, $foreignStudent->school_class_id);
        $this->assertSame($otherSchool->id, $foreignStudent->school_id);
        $this->assertSame(Role::Teacher, $foreignTeacher->refresh()->role);
        $this->assertNull($operator->refresh()->school_id);

        $this->assertSame($outcome['rows'], $this->previewCsv($csv)['rows']);
    }

    public function test_an_email_address_of_another_school_is_reported_without_saying_who_uses_it(): void
    {
        User::factory()->create(['email' => 'anna@example.org']);

        $outcome = $this->importCsv("Nachname;Vorname;Klasse;E-Mail\nMüller;Anna;5a;anna@example.org\n");

        $this->assertSame('skipped', $outcome['rows'][0]['status']);
        $this->assertSame('The email address already belongs to another account.', $outcome['rows'][0]['message']);
    }

    public function test_generated_usernames_avoid_the_usernames_of_other_schools(): void
    {
        User::factory()->student($this->classIn(School::factory()->create(), '6b'))->create(['username' => 'anna.mueller']);

        $outcome = $this->importCsv("Nachname;Vorname;Klasse\nMüller;Anna;5a\n");

        $this->assertSame(['created'], array_column($outcome['rows'], 'status'));
        $this->assertSame('anna.mueller2', $outcome['rows'][0]['username']);
    }

    public function test_only_students_of_the_own_school_are_updated(): void
    {
        $ownAnna = User::factory()->student($this->classIn($this->school, '5a'))->create(['name' => 'Anna Mueller', 'username' => 'anna.mueller']);
        $foreignAnna = User::factory()->student($this->classIn(School::factory()->create(), '6b'))->create(['name' => 'Anna Mueller', 'username' => 'anna.fremd']);

        $outcome = $this->importCsv("Nachname;Vorname;Klasse;Benutzername\nMüller;Anna;5a;anna.mueller\nMüller;Anna;5a;anna.fremd\n");

        $this->assertSame(['updated', 'skipped'], array_column($outcome['rows'], 'status'));
        $this->assertSame('Anna Müller', $ownAnna->refresh()->name);
        $this->assertSame('Anna Mueller', $foreignAnna->refresh()->name);
    }

    public function test_the_same_class_name_in_two_schools_stays_separate(): void
    {
        $this->skipUnlessClassNamesAreUniquePerSchool();

        $foreignClass = $this->classIn(School::factory()->create(), '5a');
        $foreignAnna = User::factory()->student($foreignClass)->create(['name' => 'Anna Müller', 'username' => 'anna.fremd']);

        $outcome = $this->importCsv("Nachname;Vorname;Klasse\nMüller;Anna;5a\n");

        // Without a username, only a student of the own school counts as the same person.
        $this->assertSame(['created'], array_column($outcome['rows'], 'status'));
        $this->assertSame(['5a'], $outcome['newClasses']);

        $ownClass = $this->school->schoolClasses()->where('name', '5a')->sole();
        $this->assertNotSame($foreignClass->id, $ownClass->id);
        $this->assertSame($ownClass->id, User::where('username', $outcome['rows'][0]['username'])->sole()->school_class_id);
        $this->assertSame($foreignClass->id, $foreignAnna->refresh()->school_class_id);
    }

    public function test_credentials_csv_holds_only_new_accounts_and_cannot_run_as_a_formula(): void
    {
        $outcome = $this->importCsv("Nachname;Vorname;Klasse;Benutzername\nMüller;Anna;5a;anna\nSchmidt;=Lea;5a;\n");

        $csv = $this->import->credentialsCsv($outcome['rows']);
        $lines = explode("\r\n", rtrim($csv));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertSame("\xEF\xBB\xBFName;Class;Username;Password", $lines[0]);
        $this->assertCount(3, $lines);
        $this->assertSame("\"Anna Müller\";5a;anna;{$outcome['rows'][0]['password']}", $lines[1]);
        $this->assertStringStartsWith("\"'=Lea Schmidt\";5a;lea.schmidt;", $lines[2]);

        $skippedOnly = $this->import->credentialsCsv($this->importCsv("Nachname;Vorname;Klasse\nMüller;Anna;5a\n")['rows']);
        $this->assertSame("\xEF\xBB\xBFName;Class;Username;Password\r\n", $skippedOnly);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, newClasses: list<string>, problem: string|null}
     */
    private function importCsv(string $csv): array
    {
        return $this->import->import($csv, $this->school);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, newClasses: list<string>, problem: string|null}
     */
    private function previewCsv(string $csv): array
    {
        return $this->import->preview($csv, $this->school);
    }

    private function classIn(School $school, string $name): SchoolClass
    {
        return SchoolClass::factory()->for($school)->create(['name' => $name, 'teacher_id' => null]);
    }

    /**
     * school_classes.name is still unique for the whole table until a migration makes it unique per school.
     */
    private function skipUnlessClassNamesAreUniquePerSchool(): void
    {
        $globalNameIndex = collect(Schema::getIndexes('school_classes'))
            ->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['name']);

        if ($globalNameIndex) {
            $this->markTestSkipped('school_classes.name is globally unique: replace the index by unique(school_id, name).');
        }
    }
}
