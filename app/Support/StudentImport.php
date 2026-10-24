<?php

namespace App\Support;

use App\Models\School;
use App\Models\User;
use App\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports student lists that school software (SchILD-NRW, WebUntis, IServ, ...) exports as CSV into one school.
 *
 * preview() answers exactly what import() would do without touching the database, import() does it
 * inside one transaction. Faulty rows never abort the run: they are reported as skipped.
 *
 * Classes and students are created in, and matched within, the given school only. Usernames and e-mail
 * addresses are unique across all schools, so a clash with another school is reported without saying whose it is.
 *
 * @phpstan-type ParsedRow array{line: int, lastName: string, firstName: string, class: string, username: string, email: string}
 * @phpstan-type ResultRow array{line: int, name: string, class: string, username: string, status: 'created'|'updated'|'skipped', message: string, password: string|null}
 * @phpstan-type Outcome array{rows: list<ResultRow>, newClasses: list<string>, problem: string|null}
 */
class StudentImport
{
    /**
     * Every initial password is hashed with bcrypt, which takes about 0.2 s per student.
     */
    public const int MAX_ROWS = 300;

    /**
     * Normalised header names (lowercase, letters and digits only) that are understood for each column.
     *
     * @var array<string, list<string>>
     */
    private const array COLUMN_ALIASES = [
        'lastName' => ['nachname', 'familienname', 'name', 'lastname', 'surname', 'familyname'],
        'firstName' => ['vorname', 'rufname', 'firstname', 'givenname'],
        'class' => ['klasse', 'klassenname', 'class', 'classname'],
        'username' => ['benutzername', 'benutzer', 'username', 'user', 'login', 'account'],
        'email' => ['email', 'emailadresse', 'mail'],
    ];

    /**
     * The German names of the columns every file needs, shown (translated) in the messages that name a missing one.
     *
     * @var array<string, string>
     */
    private const array REQUIRED_COLUMNS = ['lastName' => 'Nachname', 'firstName' => 'Vorname', 'class' => 'Klasse'];

    /**
     * Without the characters that look alike (0/O, 1/l/I).
     */
    private const string PASSWORD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    private const int PASSWORD_LENGTH = 12;

    private School $school;

    /**
     * @var list<string>
     */
    private array $seenUsernames = [];

    /**
     * @var list<string>
     */
    private array $seenEmails = [];

    /**
     * @var array<string, int>
     */
    private array $classIds = [];

    /**
     * @var list<string>
     */
    private array $newClasses = [];

    /**
     * Reads the rows of an exported list: UTF-8 (with or without BOM) or Windows-1252, delimited by ; , or tab.
     *
     * @return array{rows: list<ParsedRow>, problem: string|null}
     */
    public function parse(string $content): array
    {
        $content = $this->toUtf8($content);
        $delimiter = $this->detectDelimiter($content);

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $columns = null;
        $rows = [];
        $line = 0;

        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;
            $cells = array_map($this->trim(...), $cells);

            if (implode('', $cells) === '') {
                continue;
            }

            if ($columns === null) {
                $columns = $this->mapColumns($cells);
                $missing = array_diff_key(self::REQUIRED_COLUMNS, $columns);

                if ($missing !== []) {
                    return ['rows' => [], 'problem' => __('In der ersten Zeile fehlen die Spalten :missing. Gefunden wurde: :found. Erwartet werden Nachname, Vorname und Klasse, optional Benutzername und E-Mail.', [
                        'missing' => implode(', ', array_map(__(...), $missing)),
                        'found' => implode(', ', array_filter($cells)),
                    ])];
                }

                continue;
            }

            $row = ['line' => $line];

            foreach (array_keys(self::COLUMN_ALIASES) as $field) {
                $row[$field] = isset($columns[$field]) ? ($cells[$columns[$field]] ?? '') : '';
            }

            $row['username'] = mb_strtolower($row['username']);
            $rows[] = $row;
        }

        fclose($stream);

        return match (true) {
            $columns === null => ['rows' => [], 'problem' => __('Die Datei enthält keine Zeilen.')],
            $rows === [] => ['rows' => [], 'problem' => __('Die Datei enthält nur die Kopfzeile, aber keine Schüler:innen.')],
            count($rows) > self::MAX_ROWS => ['rows' => [], 'problem' => __('Die Datei enthält :count Schüler:innen. Pro Import sind höchstens :max möglich, teile die Datei zum Beispiel nach Klassen auf.', ['count' => count($rows), 'max' => self::MAX_ROWS])],
            default => ['rows' => $rows, 'problem' => null],
        };
    }

    /**
     * What an import would do. Writes nothing, so the result has no passwords.
     *
     * @return Outcome
     */
    public function preview(string $content, School $school): array
    {
        return $this->process($content, $school, commit: false);
    }

    /**
     * Creates the missing classes and students and updates known students, all or nothing.
     *
     * @return Outcome
     */
    public function import(string $content, School $school): array
    {
        set_time_limit(300);

        return $this->process($content, $school, commit: true);
    }

    /**
     * The new accounts of an import result as a CSV for Excel: semicolon-separated, UTF-8 with BOM.
     *
     * @param  list<ResultRow>  $rows
     */
    public function credentialsCsv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");

        $lines = [[__('Name'), __('Klasse'), __('Benutzername'), __('Passwort')]];

        foreach ($rows as $row) {
            if ($row['password'] !== null) {
                $lines[] = [$row['name'], $row['class'], $row['username'], $row['password']];
            }
        }

        foreach ($lines as $line) {
            fputcsv($stream, array_map($this->neutralizeFormula(...), $line), ';', '"', '', "\r\n");
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * @return Outcome
     */
    private function process(string $content, School $school, bool $commit): array
    {
        $parsed = $this->parse($content);

        if ($parsed['problem'] !== null) {
            return ['rows' => [], 'newClasses' => [], 'problem' => $parsed['problem']];
        }

        $this->school = $school;
        $this->seenUsernames = $this->seenEmails = $this->classIds = $this->newClasses = [];

        $apply = fn (): array => array_map(fn (array $row): array => $this->applyRow($row, $commit), $parsed['rows']);

        return [
            'rows' => $commit ? DB::transaction($apply) : $apply(),
            'newClasses' => $this->newClasses,
            'problem' => null,
        ];
    }

    /**
     * @param  ParsedRow  $row
     * @return ResultRow
     */
    private function applyRow(array $row, bool $commit): array
    {
        if (($problem = $this->problemWith($row)) !== null) {
            return $this->outcome($row, 'skipped', $problem);
        }

        $name = $this->fullName($row);

        if ($row['username'] !== '') {
            if (in_array($row['username'], $this->seenUsernames, true)) {
                return $this->outcome($row, 'skipped', __('Der Benutzername kommt in der Datei mehrfach vor, nur die erste Zeile zählt.'));
            }

            $existing = User::where('username', $row['username'])->first();

            if ($existing !== null && ! $existing->isMemberOf($this->school)) {
                // Usernames are unique across schools; which school (or role) holds it is not revealed.
                return $this->outcome($row, 'skipped', __('Der Benutzername ist schon vergeben. Wähle in der Datei einen anderen.'));
            }

            if ($existing !== null && ! $existing->isStudent()) {
                return $this->outcome($row, 'skipped', __('Der Benutzername gehört zu einem Konto mit der Rolle :role. Es bleibt unverändert.', ['role' => $existing->role->getLabel()]));
            }
        } else {
            // Without a username the same name in the same class is the same person, which keeps a repeated import from creating duplicates.
            $existing = $this->school->users()
                ->withRole(Role::Student)
                ->where('name', $name)
                ->whereHas('schoolClass', fn ($query) => $query->where('name', $row['class']))
                ->whereNotIn('username', $this->seenUsernames)
                ->orderBy('id')
                ->first();
        }

        return $existing !== null
            ? $this->updateStudent($row, $existing, $commit)
            : $this->createStudent($row, $commit);
    }

    /**
     * @param  ParsedRow  $row
     * @return ResultRow
     */
    private function updateStudent(array $row, User $student, bool $commit): array
    {
        $this->seenUsernames[] = mb_strtolower($student->username);

        $student->fill(['name' => $this->fullName($row), 'school_class_id' => $this->schoolClassId($row['class'], $commit)]);

        $changes = collect($student->getDirty())
            ->keys()
            ->map(fn (string $attribute): string => $attribute === 'name' ? __('Name') : __('Klasse'))
            ->join(' '.__('und').' ');

        if ($changes === '') {
            return $this->outcome($row, 'skipped', __('Ist schon mit diesen Angaben vorhanden.'), $student->username);
        }

        if ($commit) {
            $student->save();
        }

        return $this->outcome($row, 'updated', __(':changes aktualisiert.', ['changes' => $changes]), $student->username);
    }

    /**
     * @param  ParsedRow  $row
     * @return ResultRow
     */
    private function createStudent(array $row, bool $commit): array
    {
        $username = $row['username'] !== '' ? $row['username'] : $this->generateUsername($row);

        if ($username === null) {
            return $this->outcome($row, 'skipped', __('Aus dem Namen lässt sich kein Benutzername bilden. Trag in der Datei einen Benutzernamen ein.'));
        }

        if ($row['email'] !== '' && $this->emailIsTaken($row['email'])) {
            return $this->outcome($row, 'skipped', __('Die E-Mail-Adresse gehört schon zu einem anderen Konto.'));
        }

        $password = $this->generatePassword();
        $classId = $this->schoolClassId($row['class'], $commit);

        if ($commit) {
            $this->school->users()->create([
                'name' => $this->fullName($row),
                'username' => $username,
                'email' => $row['email'] !== '' ? $row['email'] : null,
                'password' => $password,
                'role' => Role::Student,
                'school_class_id' => $classId,
            ]);
        }

        $this->seenUsernames[] = $username;

        if ($row['email'] !== '') {
            $this->seenEmails[] = mb_strtolower($row['email']);
        }

        return $this->outcome($row, 'created', __('Konto angelegt.'), $username, $commit ? $password : null);
    }

    /**
     * @param  ParsedRow  $row
     */
    private function problemWith(array $row): ?string
    {
        $problems = [];

        foreach (self::REQUIRED_COLUMNS as $field => $label) {
            if ($row[$field] === '') {
                $problems[] = __(':column fehlt.', ['column' => __($label)]);
            }
        }

        if (mb_strlen($this->fullName($row)) > 255 || mb_strlen($row['class']) > 255) {
            $problems[] = __('Name oder Klasse sind länger als 255 Zeichen.');
        }

        if ($row['email'] !== '' && filter_var($row['email'], FILTER_VALIDATE_EMAIL) === false) {
            $problems[] = __('Die E-Mail-Adresse ist ungültig.');
        }

        if ($row['username'] !== '' && preg_match('/^[a-z0-9._@-]{1,255}$/', $row['username']) !== 1) {
            $problems[] = __('Der Benutzername darf nur Buchstaben ohne Umlaute, Ziffern und . _ - @ enthalten.');
        }

        return $problems === [] ? null : implode(' ', $problems);
    }

    /**
     * @param  ParsedRow  $row
     * @param  'created'|'updated'|'skipped'  $status
     * @return ResultRow
     */
    private function outcome(array $row, string $status, string $message, ?string $username = null, ?string $password = null): array
    {
        return [
            'line' => $row['line'],
            'name' => $this->fullName($row),
            'class' => $row['class'],
            'username' => $username ?? $row['username'],
            'status' => $status,
            'message' => $message,
            'password' => $password,
        ];
    }

    /**
     * @param  ParsedRow  $row
     */
    private function fullName(array $row): string
    {
        return trim("{$row['firstName']} {$row['lastName']}");
    }

    /**
     * Looks the class up by name within the school. A missing class is created when committing and only announced otherwise,
     * then 0 stands for the class that does not exist yet.
     */
    private function schoolClassId(string $name, bool $commit): int
    {
        if (! array_key_exists($name, $this->classIds)) {
            $schoolClass = $this->school->schoolClasses()->where('name', $name)->first();

            if ($schoolClass === null) {
                $this->newClasses[] = $name;
                $schoolClass = $commit ? $this->school->schoolClasses()->create(['name' => $name]) : null;
            }

            $this->classIds[$name] = $schoolClass?->getKey() ?? 0;
        }

        return $this->classIds[$name];
    }

    /**
     * vorname.nachname in lowercase, with a number appended when the name is already taken.
     *
     * @param  ParsedRow  $row
     */
    private function generateUsername(array $row): ?string
    {
        $firstName = $this->transliterate($row['firstName']);
        $lastName = $this->transliterate($row['lastName']);

        if ($firstName === '' || $lastName === '') {
            return null;
        }

        $base = "{$firstName}.{$lastName}";
        $username = $base;

        for ($suffix = 2; $this->usernameIsTaken($username); $suffix++) {
            $username = $base.$suffix;
        }

        return $username;
    }

    private function transliterate(string $name): string
    {
        $name = strtr(mb_strtolower($name), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return preg_replace('/[^a-z0-9]/', '', Str::ascii($name));
    }

    private function usernameIsTaken(string $username): bool
    {
        return in_array($username, $this->seenUsernames, true) || User::where('username', $username)->exists();
    }

    private function emailIsTaken(string $email): bool
    {
        return in_array(mb_strtolower($email), $this->seenEmails, true) || User::where('email', $email)->exists();
    }

    private function generatePassword(): string
    {
        $password = '';

        for ($position = 0; $position < self::PASSWORD_LENGTH; $position++) {
            $password .= self::PASSWORD_ALPHABET[random_int(0, strlen(self::PASSWORD_ALPHABET) - 1)];
        }

        return $password;
    }

    private function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    /**
     * The delimiter that occurs most often in the header line; semicolon on a tie, as German Excel writes it.
     */
    private function detectDelimiter(string $content): string
    {
        $header = preg_split('/\R/u', trim($content), 2)[0];
        $counts = [';' => substr_count($header, ';'), ',' => substr_count($header, ','), "\t" => substr_count($header, "\t")];

        arsort($counts);

        return array_key_first($counts);
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, int> column key => index of the first header with a known name
     */
    private function mapColumns(array $headers): array
    {
        $columns = [];

        foreach ($headers as $index => $header) {
            $normalised = preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($header)));

            foreach (self::COLUMN_ALIASES as $field => $aliases) {
                if (in_array($normalised, $aliases, true) && ! isset($columns[$field])) {
                    $columns[$field] = $index;
                }
            }
        }

        return $columns;
    }

    /**
     * Trims whitespace including the non-breaking spaces that Excel exports like to contain.
     */
    private function trim(?string $value): string
    {
        return preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', (string) $value) ?? '';
    }

    /**
     * Stops spreadsheet software from running a cell that starts like a formula.
     */
    private function neutralizeFormula(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'{$value}" : $value;
    }
}
