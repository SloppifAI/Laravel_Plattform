<?php

namespace Tests\Feature\DataRights;

use App\Http\Controllers\Api\V1\TokenController;
use App\MessageAuthor;
use App\Models\Answer;
use App\Models\ClassBookEntry;
use App\Models\ClassSubstitution;
use App\Models\ConsentForm;
use App\Models\ExplanationMessage;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Models\Question;
use App\Models\RecordingConsent;
use App\Models\SchoolClass;
use App\Models\StudentQuestion;
use App\Models\Submission;
use App\Models\User;
use App\Support\Notify;
use App\Support\PersonalDataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PersonalDataExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The texts are asserted in German, the platform's default language (phpunit.xml runs the suite in English).
        config(['lernplattform.locale.default' => 'de']);
        app()->setLocale('de');
    }

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
            'sprache' => null,
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

    public function test_student_export_holds_language_questions_consent_and_tuto_mikro_attendance(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $student = User::factory()->student($schoolClass)->create(['locale' => 'en']);
        $classmate = User::factory()->student($schoolClass)->create(['name' => 'Mia Klassenkameradin']);
        $teacher = User::factory()->teacher()->for($schoolClass->school)->create(['name' => 'Frau Antwortende']);
        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->publishedFor([$student, $classmate])->create(['subject' => 'Biologie', 'topic' => 'Zellen']);
        StudentQuestion::factory()->for($student, 'student')->for($lesson)->answered('Schau auf Seite 12.')->create(['question' => 'Was ist ein Zellkern?']);
        StudentQuestion::factory()->for($classmate, 'student')->for($lesson)->create(['question' => 'Frage der Mitschülerin']);
        RecordingConsent::factory()->for($student, 'student')->agreedTo(['audio', 'transcript'])->create([
            'guardian_name' => 'Maria Muster',
            'source' => RecordingConsent::SOURCE_PAPER,
            'recorded_by' => $teacher->id,
            'token_hash' => str_repeat('f', 64),
        ]);
        LessonRecording::factory()->for($lesson)->presentStudents([$student, $classmate])->finished()->create();
        LessonRecording::factory()->for($lesson)->presentStudents([$classmate])->create();

        $export = PersonalDataExport::for($student);

        $this->assertSame('English', $export['konto']['sprache']);
        $this->assertSame([[
            'fach' => 'Biologie',
            'thema' => 'Zellen',
            'frage' => 'Was ist ein Zellkern?',
            'antwort' => 'Schau auf Seite 12.',
        ]], array_map(fn (array $question): array => Arr::only($question, ['fach', 'thema', 'frage', 'antwort']), $export['rueckfragen']));
        $this->assertSame('Maria Muster', $export['einwilligung_tuto_mikro']['erziehungsberechtigte_person']);
        $this->assertSame('auf Papier', $export['einwilligung_tuto_mikro']['art']);
        $this->assertSame([ConsentForm::PURPOSES['audio'], ConsentForm::PURPOSES['transcript']], $export['einwilligung_tuto_mikro']['zwecke']);
        $this->assertCount(1, $export['tuto_mikro_anwesenheit'], 'Only the session the student was marked present in.');
        $this->assertSame('Zellen', $export['tuto_mikro_anwesenheit'][0]['thema']);

        $json = json_encode($export, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach (['Mia Klassenkameradin', 'Frage der Mitschülerin', 'Frau Antwortende', str_repeat('f', 64), 'token_hash'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_the_consent_reaches_others_only_if_they_look_after_the_consents_of_the_class(): void
    {
        $student = User::factory()->student()->create();
        RecordingConsent::factory()->for($student, 'student')->create(['guardian_name' => 'Maria Muster']);
        $admin = User::factory()->admin()->for($student->school)->create();
        $otherTeacher = User::factory()->teacher()->for($student->school)->create();

        $this->assertSame('Maria Muster', PersonalDataExport::for($student, $admin)['einwilligung_tuto_mikro']['erziehungsberechtigte_person']);
        $this->assertNull(PersonalDataExport::for($student, $otherTeacher)['einwilligung_tuto_mikro']);
    }

    public function test_teacher_export_holds_mikro_sessions_classes_substitutions_and_answers_without_student_data(): void
    {
        $teacher = User::factory()->teacher()->create();
        $ledClass = SchoolClass::factory()->for($teacher->school)->create(['name' => '8a', 'teacher_id' => $teacher->id]);
        $assignedClass = SchoolClass::factory()->for($teacher->school)->create(['name' => '9c']);
        $assignedClass->teachers()->attach($teacher);
        $coveredClass = SchoolClass::factory()->for($teacher->school)->create(['name' => '5d']);
        ClassSubstitution::factory()->for($coveredClass)->create(['substitute_id' => $teacher->id, 'note' => 'Bitte Kapitel 3']);
        $students = User::factory()->count(2)->student($ledClass)->sequence(['name' => 'Ida Schuelerin'], ['name' => 'Ben Schueler'])->create();
        $lesson = Lesson::factory()->for($ledClass)->for($teacher, 'teacher')->publishedFor($students)->create(['topic' => 'Bruchrechnung']);
        LessonRecording::factory()->for($lesson)->presentStudents($students)->finished(600)->create([
            'purposes' => ['audio', 'transcript', 'ai_analysis'],
            'audio_sent_to_ai' => true,
        ]);
        StudentQuestion::factory()->for($students[0], 'student')->for($lesson)->answered('Erst kürzen, dann rechnen.')->create(['question' => 'Wie kürze ich?']);
        RecordingConsent::factory()->for($students[1], 'student')->create(['recorded_by' => $teacher->id, 'guardian_name' => 'Maria Muster']);

        $export = PersonalDataExport::for($teacher);

        $this->assertSame(['8a'], $export['klassenleitung']);
        $this->assertSame(['9c'], $export['zugeordnete_klassen']);
        $this->assertSame([['klasse' => '5d', 'hinweis' => 'Bitte Kapitel 3']], array_map(fn (array $substitution): array => Arr::only($substitution, ['klasse', 'hinweis']), $export['vertretungen']));
        $this->assertCount(1, $export['tuto_mikro_sitzungen']);
        $this->assertSame(2, $export['tuto_mikro_sitzungen'][0]['anzahl_anwesende_schueler']);
        $this->assertSame(600, $export['tuto_mikro_sitzungen'][0]['dauer_sekunden']);
        $this->assertTrue($export['tuto_mikro_sitzungen'][0]['ton_an_ki_anbieter']);
        $this->assertSame(array_values(ConsentForm::PURPOSES_SHORT), $export['tuto_mikro_sitzungen'][0]['gepruefte_zwecke']);
        $this->assertSame('Erst kürzen, dann rechnen.', $export['beantwortete_rueckfragen'][0]['antwort']);
        $this->assertSame(1, $export['erfasste_einwilligungserklaerungen']);

        $json = json_encode($export, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach (['Ida Schuelerin', 'Ben Schueler', 'Wie kürze ich?', 'Maria Muster', 'present_student_ids'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }

        $forAdmin = PersonalDataExport::for($teacher, User::factory()->admin()->for($teacher->school)->create());

        $this->assertSame([], $forAdmin['tuto_mikro_sitzungen'], 'The school administration does not see Tuto-Mikro sessions.');
    }

    public function test_admin_export_holds_what_the_administration_switched_on_for_its_school(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Sabine Leitung']);
        $school = $admin->school;
        $school->forceFill(['ai_enabled_at' => now(), 'ai_enabled_by' => $admin->id, 'recordings_enabled_at' => now(), 'recordings_enabled_by' => $admin->id])->save();
        $school->acceptDpa($admin);
        ConsentForm::factory()->for($school)->create(['created_by' => $admin->id, 'title' => 'Einwilligung zum Tuto-Mikro']);
        $schoolClass = SchoolClass::factory()->for($school)->create(['name' => '6b']);
        ClassSubstitution::factory()->for($schoolClass)->create([
            'granted_by' => $admin->id,
            'substitute_id' => User::factory()->teacher()->for($school)->create(['name' => 'Herr Vertretung'])->id,
        ]);

        $export = PersonalDataExport::for($admin);

        $this->assertNotNull($export['schuleinstellungen']['ki_funktionen_eingeschaltet_am']);
        $this->assertNotNull($export['schuleinstellungen']['tuto_mikro_freigeschaltet_am']);
        $this->assertSame([config('lernplattform.dpa.version')], array_column($export['schuleinstellungen']['avv_annahmen'], 'version'));
        $this->assertSame(['Einwilligung zum Tuto-Mikro'], array_column($export['schuleinstellungen']['einwilligungstexte'], 'titel'));
        $this->assertSame(['6b'], array_column($export['eingerichtete_vertretungen'], 'klasse'));
        $this->assertStringNotContainsString('Herr Vertretung', json_encode($export, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function test_an_administration_export_holds_the_lessons_answers_and_klassenbuch_entries_of_the_administration(): void
    {
        $admin = User::factory()->admin()->create();
        $schoolClass = SchoolClass::factory()->for($admin->school)->create(['name' => '7b', 'teacher_id' => null]);
        $student = User::factory()->student($schoolClass)->create();
        $lesson = Lesson::factory()->for($schoolClass)->for($admin, 'teacher')->create(['topic' => 'Stunde der Administration', 'notes' => 'Notizen der Admin-Stunde']);
        StudentQuestion::factory()->for($student, 'student')->for($lesson)->answered('Antwort der Schuladministration')->create();
        ClassBookEntry::factory()->for($schoolClass, 'schoolClass')->create(['teacher_id' => $admin->id, 'topic' => 'Eintrag der Administration']);

        $export = PersonalDataExport::for($admin);

        $this->assertSame(['Stunde der Administration'], array_column($export['unterrichtsstunden'], 'thema'));
        $this->assertSame('Notizen der Admin-Stunde', $export['unterrichtsstunden'][0]['notizen']);
        $this->assertSame(['Antwort der Schuladministration'], array_column($export['beantwortete_rueckfragen'], 'antwort'));
        $this->assertSame(['Eintrag der Administration'], array_column($export['klassenbuch_eintraege'], 'thema'));
        $this->assertStringNotContainsString($student->name, json_encode($export, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function test_the_platform_operator_gets_the_account_and_the_agreements_of_an_administration_but_nothing_from_inside_the_school(): void
    {
        $operator = User::factory()->operator()->create();
        $admin = User::factory()->admin()->create();
        $school = $admin->school;
        $school->forceFill(['ai_enabled_at' => now(), 'ai_enabled_by' => $admin->id])->save();
        $school->acceptDpa($admin);
        ConsentForm::factory()->for($school)->create(['created_by' => $admin->id, 'title' => 'Einwilligung der Schule']);
        $schoolClass = SchoolClass::factory()->for($school)->create(['name' => 'Geheimklasse 7b', 'teacher_id' => null]);
        ClassSubstitution::factory()->for($schoolClass)->create(['granted_by' => $admin->id]);
        RecordingConsent::factory()->create(['recorded_by' => $admin->id]);
        $lesson = Lesson::factory()->for($schoolClass)->for($admin, 'teacher')->create(['topic' => 'Geheimes Unterrichtsthema']);
        Notify::submissionReceived(Submission::factory()->for($lesson)->create());
        $this->assertSame(1, $admin->notifications()->count(), 'The administration has a bell entry that names the lesson.');

        $forOperator = PersonalDataExport::for($admin, $operator);

        $this->assertSame(['hinweis', 'erstellt_am', 'konto', 'schuleinstellungen'], array_keys($forOperator));
        $this->assertSame(['avv_annahmen'], array_keys($forOperator['schuleinstellungen']));
        $this->assertSame([config('lernplattform.dpa.version')], array_column($forOperator['schuleinstellungen']['avv_annahmen'], 'version'));

        $json = json_encode($forOperator, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach (['Geheimklasse 7b', 'Geheimes Unterrichtsthema', 'Einwilligung der Schule'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }

        $own = PersonalDataExport::for($admin);

        $this->assertCount(1, $own['benachrichtigungen']);
        $this->assertSame(['Geheimes Unterrichtsthema'], array_column($own['unterrichtsstunden'], 'thema'));
        $this->assertSame(['Geheimklasse 7b'], array_column($own['eingerichtete_vertretungen'], 'klasse'));
    }

    public function test_the_optional_sections_reach_others_only_if_they_may_see_them(): void
    {
        $teacher = User::factory()->teacher()->create();
        $schoolClass = SchoolClass::factory()->for($teacher->school)->create(['name' => 'Klasse der Leitung', 'teacher_id' => $teacher->id]);
        ClassSubstitution::factory()->for($schoolClass)->create();
        RecordingConsent::factory()->for(User::factory()->student($schoolClass), 'student')->create(['recorded_by' => $teacher->id]);
        $admin = User::factory()->admin()->for($teacher->school)->create();
        $teacher->school->forceFill(['ai_enabled_at' => now(), 'ai_enabled_by' => $admin->id])->save();
        $colleague = User::factory()->teacher()->for($teacher->school)->create();

        $forAdmin = PersonalDataExport::for($teacher, $admin);

        $this->assertSame(['Klasse der Leitung'], array_column($forAdmin['eingerichtete_vertretungen'], 'klasse'));
        $this->assertSame(1, $forAdmin['erfasste_einwilligungserklaerungen']);
        $this->assertArrayHasKey('schuleinstellungen', PersonalDataExport::for($admin, $admin));

        $forColleague = PersonalDataExport::for($teacher, $colleague);

        $this->assertArrayNotHasKey('eingerichtete_vertretungen', $forColleague);
        $this->assertArrayNotHasKey('erfasste_einwilligungserklaerungen', $forColleague);
        $this->assertStringNotContainsString('Klasse der Leitung', json_encode(Arr::except($forColleague, 'klassenleitung'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('schuleinstellungen', PersonalDataExport::for($admin, $colleague));
    }

    public function test_the_bell_is_part_of_the_own_export_only(): void
    {
        $student = User::factory()->student()->create();
        $admin = User::factory()->admin()->for($student->school)->create();
        $lesson = Lesson::factory()->for($student->schoolClass)->publishedFor([$student])->create();
        Notify::homeworkReleased($lesson, [$student]);
        $this->assertSame(1, $student->notifications()->count());
        $student->notifications()->update(['read_at' => now()]);

        $this->assertCount(1, PersonalDataExport::for($student)['benachrichtigungen']);
        $this->assertCount(1, PersonalDataExport::for($student, $student)['benachrichtigungen']);
        $this->assertArrayNotHasKey('benachrichtigungen', PersonalDataExport::for($student, $admin));
    }

    public function test_the_export_lists_the_app_accesses_but_never_the_token_or_its_hash(): void
    {
        $teacher = User::factory()->teacher()->create();
        $other = User::factory()->teacher()->for($teacher->school)->create();
        $other->createToken('Tablet einer Kollegin', TokenController::ABILITIES);

        $this->travelTo('2026-10-24 10:00:00');
        $used = $teacher->createToken('iPhone von Frau Schulze', TokenController::ABILITIES, now()->addDays(180));
        $unused = $teacher->createToken('Altes Tablet', ['lessons:read']);
        $this->travelTo('2026-10-25 08:30:00');
        $used->accessToken->forceFill(['last_used_at' => now()])->save();

        $export = PersonalDataExport::for($teacher);

        $this->assertSame([
            [
                'name' => 'iPhone von Frau Schulze',
                'verbunden_am' => now()->subDay()->setTime(10, 0)->toIso8601String(),
                'zuletzt_genutzt_am' => now()->toIso8601String(),
                'gueltig_bis' => now()->subDay()->setTime(10, 0)->addDays(180)->toIso8601String(),
                'berechtigungen' => ['lessons:read', 'lessons:create', 'lessons:summary', 'roster:read'],
            ],
            [
                'name' => 'Altes Tablet',
                'verbunden_am' => now()->subDay()->setTime(10, 0)->toIso8601String(),
                'zuletzt_genutzt_am' => null,
                'gueltig_bis' => null,
                'berechtigungen' => ['lessons:read'],
            ],
        ], $export['verbundene_apps']);

        $json = json_encode($export, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach ([$used, $unused] as $created) {
            $this->assertStringNotContainsString($created->plainTextToken, $json);
            $this->assertStringNotContainsString($created->accessToken->token, $json, 'Not even the hash of the token.');
        }
        $this->assertStringNotContainsString('Tablet einer Kollegin', $json);
        $this->assertStringContainsString('Zugangsschlüssel verbundener Apps', $export['hinweis']);
    }

    public function test_the_app_accesses_are_part_of_the_own_export_only_and_only_when_there_are_any(): void
    {
        $admin = User::factory()->admin()->create();
        $teacher = User::factory()->teacher()->for($admin->school)->create();
        $colleague = User::factory()->teacher()->for($admin->school)->create();

        $this->assertArrayNotHasKey('verbundene_apps', PersonalDataExport::for($teacher));
        $this->assertArrayNotHasKey('verbundene_apps', PersonalDataExport::for($admin));

        $teacher->createToken('Tablet', TokenController::ABILITIES);
        $admin->createToken('Handy der Schulleitung', TokenController::ABILITIES);

        $this->assertSame(['Tablet'], array_column(PersonalDataExport::for($teacher)['verbundene_apps'], 'name'));
        $this->assertSame(['Handy der Schulleitung'], array_column(PersonalDataExport::for($admin)['verbundene_apps'], 'name'));
        $this->assertArrayNotHasKey('verbundene_apps', PersonalDataExport::for($teacher, $admin), 'Nobody but its owner reads the list.');
        $this->assertArrayNotHasKey('verbundene_apps', PersonalDataExport::for($teacher, $colleague));
    }

    public function test_the_enum_labels_stay_german_when_the_person_reads_the_interface_in_english(): void
    {
        app()->setLocale('en');
        $teacher = User::factory()->teacher()->create();
        $schoolClass = SchoolClass::factory()->for($teacher->school)->create(['teacher_id' => $teacher->id]);
        ClassBookEntry::factory()->for($schoolClass, 'schoolClass')->create(['teacher_id' => $teacher->id]);
        ClassBookEntry::factory()->for($schoolClass, 'schoolClass')->confirmed()->create(['teacher_id' => $teacher->id]);
        $student = User::factory()->student($schoolClass)->create();
        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->publishedFor([$student])->create();
        $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated()->create();
        Answer::factory()->for($submission)->for(Question::factory()->for($lesson)->create())->create(['level' => 'plus']);

        $teacherExport = PersonalDataExport::for($teacher);
        $studentExport = PersonalDataExport::for($student);

        $this->assertSame('Lehrkraft', $teacherExport['konto']['rolle']);
        $this->assertEqualsCanonicalizing(['Entwurf', 'Bestätigt'], array_column($teacherExport['klassenbuch_eintraege'], 'status'));
        $this->assertSame('Schüler:in', $studentExport['konto']['rolle']);
        $this->assertSame('Profi', $studentExport['abgaben'][0]['antworten'][0]['stufe']);
        $this->assertSame('en', app()->getLocale());
    }
}
