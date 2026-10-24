<?php

namespace Tests\Feature\DataRights;

use App\MessageAuthor;
use App\Models\Answer;
use App\Models\ExplanationMessage;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\Support\PersonalDataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PersonalDataExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_export_contains_the_own_work_but_no_secrets_and_no_expectations(): void
    {
        $schoolClass = SchoolClass::factory()->create(['name' => '7b']);
        $student = User::factory()->student($schoolClass)->create([
            'name' => 'Lena Muster',
            'username' => 'lena.muster',
            'email' => 'lena@example.test',
            'remember_token' => 'geheimes-merk-token',
        ]);
        $classmate = User::factory()->student($schoolClass)->create(['name' => 'Mia Klassenkameradin', 'username' => 'mia.klassenkameradin']);
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor()->create([
            'subject' => 'Biologie',
            'topic' => 'Zellen',
            'held_on' => '2026-09-10',
        ]);
        $question = Question::factory()->for($lesson)->create([
            'prompt' => 'Was macht der Zellkern?',
            'expected_answer' => 'ERWARTUNGSHORIZONT-GEHEIM',
            'position' => 1,
        ]);

        $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated(80)->create([
            'summary' => 'Meine eigene Zusammenfassung',
            'feedback' => 'Das hast du gut erklärt.',
            'gaps' => ['Mitose'],
        ]);
        Answer::factory()->for($submission)->for($question)->create(['answer' => 'Er steuert die Zelle.', 'score' => 70, 'feedback' => 'Fast.']);

        $classmateSubmission = Submission::factory()->for($lesson)->for($classmate, 'student')->evaluated()->create(['summary' => 'Zusammenfassung der Mitschülerin']);
        Answer::factory()->for($classmateSubmission)->for($question)->create(['answer' => 'Antwort der Mitschülerin']);

        $session = ExplanationSession::factory()->for($student, 'student')->for($lesson)->finished(65)->create(['topic' => 'Zellteilung']);
        ExplanationMessage::factory()->for($session, 'session')->create(['author' => MessageAuthor::Student, 'content' => 'Zellen teilen sich.']);
        ExplanationMessage::factory()->for($session, 'session')->create(['author' => MessageAuthor::Tutor, 'content' => 'Und wie genau?']);
        $classmateSession = ExplanationSession::factory()->for($classmate, 'student')->create();
        ExplanationMessage::factory()->for($classmateSession, 'session')->create(['content' => 'Nachricht der Mitschülerin']);

        $export = PersonalDataExport::for($student);

        $this->assertSame([
            'name' => 'Lena Muster',
            'benutzername' => 'lena.muster',
            'email' => 'lena@example.test',
            'rolle' => 'Schüler:in',
            'schule' => $schoolClass->school->name,
            'klasse' => '7b',
        ], collect($export['konto'])->except('registriert_am')->all());

        $this->assertCount(1, $export['abgaben']);
        $this->assertSame('Biologie', $export['abgaben'][0]['fach']);
        $this->assertSame('2026-09-10', $export['abgaben'][0]['unterricht_am']);
        $this->assertSame('Meine eigene Zusammenfassung', $export['abgaben'][0]['zusammenfassung']);
        $this->assertSame(80, $export['abgaben'][0]['verstaendnis_punkte']);
        $this->assertSame(['Mitose'], $export['abgaben'][0]['luecken']);
        $this->assertSame(
            [['frage' => 'Was macht der Zellkern?', 'stufe' => null, 'antwort' => 'Er steuert die Zelle.', 'punkte' => 70, 'rueckmeldung' => 'Fast.']],
            $export['abgaben'][0]['antworten'],
        );

        $this->assertCount(1, $export['zugewiesene_hausaufgaben']);
        $this->assertCount(1, $export['erklaer_mir_gespraeche']);
        $this->assertSame('Zellteilung', $export['erklaer_mir_gespraeche'][0]['thema']);
        $this->assertSame(
            [['Schüler:in', 'Zellen teilen sich.'], ['Tuto (KI-Tutor)', 'Und wie genau?']],
            collect($export['erklaer_mir_gespraeche'][0]['nachrichten'])->map(fn (array $message): array => [$message['von'], $message['text']])->all(),
        );

        $json = json_encode($export, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach ([$student->password, 'geheimes-merk-token', 'remember_token', 'password', 'ERWARTUNGSHORIZONT-GEHEIM', 'expected_answer', 'Mitschülerin', $classmate->name, $classmate->username] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_teacher_export_contains_the_own_lessons_with_counts_but_no_student_content(): void
    {
        $teacher = User::factory()->teacher()->create(['name' => 'Frau Schmidt']);
        $schoolClass = SchoolClass::factory()->create(['name' => '8a', 'teacher_id' => $teacher->id]);
        $student = User::factory()->student($schoolClass)->create(['name' => 'Ida Schuelerin', 'username' => 'ida.schuelerin']);
        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->publishedFor()->create([
            'subject' => 'Geschichte',
            'topic' => 'Die Weimarer Republik',
            'notes' => 'Meine Stichpunkte zur Stunde',
            'summary' => 'Meine Zusammenfassung für die Klasse',
            'attachments' => ['worksheets/a.pdf', 'worksheets/b.pdf'],
        ]);
        $question = Question::factory()->for($lesson)->create(['expected_answer' => 'ERWARTUNGSHORIZONT-GEHEIM']);
        Question::factory()->for($lesson)->create();
        $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated()->create(['summary' => 'Text der Schülerin', 'feedback' => 'KI-Feedback an die Schülerin']);
        Answer::factory()->for($submission)->for($question)->create(['answer' => 'Antwort der Schülerin']);
        ExplanationSession::factory()->for($student, 'student')->for($lesson)->create(['topic' => 'Thema der Schülerin']);
        Lesson::factory()->create(['topic' => 'Stunde einer anderen Lehrkraft']);

        $export = PersonalDataExport::for($teacher);

        $this->assertSame('Lehrkraft', $export['konto']['rolle']);
        $this->assertSame(['8a'], $export['klassenleitung']);
        $this->assertArrayNotHasKey('abgaben', $export);
        $this->assertArrayNotHasKey('erklaer_mir_gespraeche', $export);
        $this->assertCount(1, $export['unterrichtsstunden']);
        $this->assertEquals([
            'fach' => 'Geschichte',
            'thema' => 'Die Weimarer Republik',
            'klasse' => '8a',
            'notizen' => 'Meine Stichpunkte zur Stunde',
            'zusammenfassung' => 'Meine Zusammenfassung für die Klasse',
            'anzahl_arbeitsblaetter' => 2,
            'anzahl_fragen' => 2,
            'anzahl_zugewiesene_schueler' => 1,
            'anzahl_abgaben' => 1,
            'ist_foerderaufgabe' => false,
        ], collect($export['unterrichtsstunden'][0])->only([
            'fach', 'thema', 'klasse', 'notizen', 'zusammenfassung', 'anzahl_arbeitsblaetter', 'anzahl_fragen',
            'anzahl_zugewiesene_schueler', 'anzahl_abgaben', 'ist_foerderaufgabe',
        ])->all());

        $json = json_encode($export, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach (['Schülerin', 'ERWARTUNGSHORIZONT-GEHEIM', $student->name, $student->username, 'Stunde einer anderen Lehrkraft', $teacher->password] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_unpublished_assignments_are_not_exported_to_the_student(): void
    {
        $student = User::factory()->student()->create();
        $draft = Lesson::factory()->for($student->schoolClass)->create(['subject' => 'Mathe', 'topic' => 'Förderaufgabe: Brüche addieren']);
        $draft->students()->attach($student);
        Lesson::factory()->for($student->schoolClass)->publishedFor([$student])->create(['topic' => 'Veröffentlichtes Thema']);

        $this->assertFalse($student->can('view', $draft));

        $export = PersonalDataExport::for($student);

        $this->assertSame(['Veröffentlichtes Thema'], array_column($export['zugewiesene_hausaufgaben'], 'thema'));
        $this->assertStringNotContainsString('Brüche addieren', json_encode($export, JSON_UNESCAPED_UNICODE));
    }

    public function test_an_export_requested_by_somebody_else_holds_only_what_that_person_may_see(): void
    {
        $student = User::factory()->student()->create();
        $admin = User::factory()->admin()->for($student->school)->create();
        $lesson = Lesson::factory()->for($student->schoolClass)->publishedFor([$student])->create();
        Submission::factory()->for($lesson)->for($student, 'student')->create(['summary' => 'Abgabe zur Stunde']);
        $lessonSession = ExplanationSession::factory()->for($student, 'student')->for($lesson)->create(['topic' => 'Gespräch zur Stunde']);
        ExplanationMessage::factory()->for($lessonSession, 'session')->create(['content' => 'Nachricht zur Stunde']);
        $privateSession = ExplanationSession::factory()->for($student, 'student')->create(['topic' => 'Privates Thema']);
        ExplanationMessage::factory()->for($privateSession, 'session')->create(['content' => 'Private Nachricht']);

        $this->assertTrue($admin->can('view', $student));
        $this->assertFalse($admin->can('view', $privateSession));

        $forAdmin = PersonalDataExport::for($student, $admin);

        $this->assertSame(['Gespräch zur Stunde'], array_column($forAdmin['erklaer_mir_gespraeche'], 'thema'));
        $this->assertCount(1, $forAdmin['abgaben']);
        $this->assertStringNotContainsString('Private', json_encode($forAdmin, JSON_UNESCAPED_UNICODE));

        // The student's own export, with or without naming the viewer, is complete.
        foreach ([PersonalDataExport::for($student), PersonalDataExport::for($student, $student)] as $own) {
            $this->assertSame(['Gespräch zur Stunde', 'Privates Thema'], array_column($own['erklaer_mir_gespraeche'], 'thema'));
        }
    }

    public function test_a_teacher_export_requested_by_a_colleague_leaves_out_lessons_the_colleague_may_not_see(): void
    {
        $teacher = User::factory()->teacher()->create();
        Lesson::factory()->for($teacher, 'teacher')->for(SchoolClass::factory()->for($teacher->school), 'schoolClass')->create(['topic' => 'Meine Stunde']);
        $colleague = User::factory()->teacher()->for($teacher->school)->create();

        $this->assertCount(1, PersonalDataExport::for($teacher)['unterrichtsstunden']);
        $this->assertSame([], PersonalDataExport::for($teacher, $colleague)['unterrichtsstunden']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rolesWithoutLearningData(): array
    {
        return [
            'admin' => ['admin'],
            'operator' => ['operator'],
        ];
    }

    #[DataProvider('rolesWithoutLearningData')]
    public function test_admins_and_operators_get_their_account_data_only(string $role): void
    {
        $user = User::factory()->{$role}()->create(['name' => 'Konto Test']);
        // Learning data of the school must not leak into the export of an administrator.
        Submission::factory()->evaluated()->create(['summary' => 'Text einer Schülerin']);

        $export = PersonalDataExport::for($user);

        $this->assertSame(['hinweis', 'erstellt_am', 'konto'], array_keys($export));
        $this->assertSame('Konto Test', $export['konto']['name']);
        $this->assertSame($user->role->getLabel(), $export['konto']['rolle']);
        $this->assertSame($user->school?->name, $export['konto']['schule']);
        $this->assertNull($export['konto']['klasse']);
        $this->assertStringNotContainsString('Text einer Schülerin', json_encode($export, JSON_UNESCAPED_UNICODE));
    }
}
