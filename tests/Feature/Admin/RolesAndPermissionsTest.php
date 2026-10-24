<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\AiSettings;
use App\Filament\Pages\RolesAndPermissions;
use App\Filament\Pages\Today;
use App\Filament\Resources\SchoolClasses\Schemas\SubstitutionForm;
use App\Models\ClassBookEntry;
use App\Models\ClassSubstitution;
use App\Models\ConsentForm;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Models\RecordingConsent;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\StudentQuestion;
use App\Models\User;
use App\Role;
use App\Support\LearningInsights;
use App\Support\PersonalDataExport;
use App\Support\RecordingConsentGate;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The newer rows of the permission matrix (Heute, Klassenbuch, Vertretung, Rückfragen, Tuto-Mikro, KI) say what the
 * policies and pages allow today. AdministrationAccessTest keeps the older rows in step.
 */
class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The page texts are German; the suite runs in English wherever a text is translated.
        config(['lernplattform.locale.default' => 'de']);
        app()->setLocale('de');
    }

    public function test_every_row_names_a_known_level_for_every_role(): void
    {
        $levels = ['all', 'school', 'admins', 'own', 'class', 'read', 'none'];

        foreach (RolesAndPermissions::matrix() as $capability => $row) {
            $this->assertNotEmpty($row['ability'], $capability);
            $this->assertNotEmpty($row['detail'], $capability);

            foreach (Role::cases() as $role) {
                $this->assertContains($row[$role->value], $levels, "{$capability} for {$role->value}");
            }
        }
    }

    public function test_the_page_lists_what_a_vertretung_grants_and_every_newer_area(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->operator()->create()] as $user) {
            $this->actingAs($user)
                ->get(RolesAndPermissions::getUrl())
                ->assertOk()
                ->assertSee(ClassSubstitution::grants())
                ->assertSee('höchstens '.ClassSubstitution::maxDays().' Tage')
                ->assertSee([
                    'Nur eigene Klassen',
                    'Heute: Hausaufgaben prüfen und freigeben',
                    'Klassenbuch führen',
                    'Vertretung einrichten',
                    'Rückfragen an die Lehrkraft',
                    'Stufen und Einfache Sprache bei Hausaufgaben',
                    'Benachrichtigungen erhalten (Glocke)',
                    'Tuto-Mikro im Unterricht einschalten',
                    'Einwilligungen der Eltern erfassen',
                    'Tuto-Mikro für die Schule freischalten',
                    'KI-Funktionen der Schule einschalten',
                ]);
        }
    }

    /**
     * Rows with a policy or a page behind them: a role has a level other than "Nein" exactly where the code lets it in.
     * "Stufen und Einfache Sprache" and "Benachrichtigungen" have no policy (students choose for themselves, the bell is
     * filled by Notify) and are left out.
     */
    public function test_the_newer_rows_agree_with_the_policies_and_pages(): void
    {
        $abilities = [
            'today' => fn (): bool => Today::canAccess(),
            'classbook' => fn (User $user): bool => $user->can('viewAny', ClassBookEntry::class),
            'substitutions' => fn (User $user): bool => $user->can('create', ClassSubstitution::class),
            'questions' => fn (User $user): bool => $user->can('viewAny', StudentQuestion::class),
            'consents' => fn (User $user): bool => $user->isStudent()
                ? $user->can('confirmOwn', RecordingConsent::query()->make()->forceFill(['student_id' => $user->getKey()]))
                : $user->can('viewAny', RecordingConsent::class),
            'mikro_settings' => fn (User $user): bool => $user->can('viewAny', ConsentForm::class),
            'ai' => fn (): bool => AiSettings::canAccess(),
        ];
        $matrix = RolesAndPermissions::matrix();

        foreach (Role::cases() as $role) {
            // Fetched again, so the user and the unsaved consent below compare on the same connection.
            $user = ($role === Role::Operator ? User::factory()->operator()->create() : User::factory()->create(['role' => $role]))->fresh();
            $this->actingAs($user);

            /** @var Closure(User): bool $mayDoIt */
            foreach ($abilities as $capability => $mayDoIt) {
                $this->assertSame($matrix[$capability][$role->value] !== 'none', $mayDoIt($user), "{$role->getLabel()}: {$matrix[$capability]['ability']}");
            }
        }
    }

    public function test_a_vertretung_is_set_up_by_the_klassenleitung_or_the_administration_of_the_school_only(): void
    {
        $school = School::factory()->create();
        $leader = User::factory()->teacher()->for($school)->create();
        $assigned = User::factory()->teacher()->for($school)->create();
        $schoolClass = SchoolClass::factory()->create(['school_id' => $school->id, 'teacher_id' => $leader->id]);
        $schoolClass->teachers()->attach($assigned);

        $canSetUp = fn (User $user): bool => $user->can('createFor', [ClassSubstitution::class, $schoolClass]);

        $this->assertTrue($canSetUp($leader));
        $this->assertTrue($canSetUp(User::factory()->admin()->for($school)->create()));
        $this->assertFalse($canSetUp($assigned));
        $this->assertFalse($canSetUp(User::factory()->admin()->create()));
        $this->assertFalse($canSetUp(User::factory()->student($schoolClass)->create()));
        $this->assertFalse($canSetUp(User::factory()->operator()->create()));
    }

    /**
     * ClassSubstitution::grants() is the text the page and the forms show; this is what it promises, checked against
     * the code that decides.
     */
    public function test_what_a_vertretung_grants_is_what_the_substitute_gets_for_the_time_it_lasts(): void
    {
        $school = School::factory()->create();
        $schoolClass = SchoolClass::factory()->create(['school_id' => $school->id, 'teacher_id' => User::factory()->teacher()->for($school)->create()->id]);
        $student = User::factory()->student($schoolClass)->create();
        $substitute = User::factory()->teacher()->for($school)->create();

        $granted = fn (): array => [
            'Stunden anlegen' => $substitute->can('createFor', [Lesson::class, $schoolClass]),
            'Lernstände der Klasse sehen' => LearningInsights::canView($substitute, $student),
            'Klassenbuch lesen und exportieren' => $substitute->can('accessClass', [ClassBookEntry::class, $schoolClass]),
            'Einwilligungen der Eltern erfassen' => $substitute->can('manageClass', [RecordingConsent::class, $schoolClass]),
            'Tuto-Mikro nutzen' => RecordingConsentGate::teachesClass($substitute, $schoolClass),
        ];

        $this->assertSame([false], array_values(array_unique($granted())));

        $substitution = ClassSubstitution::factory()->create(['school_class_id' => $schoolClass->id, 'substitute_id' => $substitute->id]);

        $this->assertSame([true], array_values(array_unique($granted())));

        $substitution->end();

        $this->assertSame([false], array_values(array_unique($granted())));
    }

    public function test_tuto_mikro_belongs_to_the_teacher_of_the_lesson_alone(): void
    {
        $school = School::factory()->create();
        $teacher = User::factory()->teacher()->for($school)->create();
        $schoolClass = SchoolClass::factory()->create(['school_id' => $school->id, 'teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->create();

        $canStart = fn (User $user): bool => $user->can('create', [LessonRecording::class, $lesson]);

        $this->assertTrue($canStart($teacher));
        $this->assertFalse($canStart(User::factory()->teacher()->for($school)->create()));
        $this->assertFalse($canStart(User::factory()->admin()->for($school)->create()));
        $this->assertFalse($canStart(User::factory()->student($schoolClass)->create()));
        $this->assertFalse($canStart(User::factory()->operator()->create()));
    }

    /**
     * The row says students never see the entries, only their own Fehltage in their own data export; this is the code
     * behind both halves.
     */
    public function test_the_klassenbuch_row_matches_what_a_student_gets_to_see_of_the_absences(): void
    {
        $school = School::factory()->create();
        $schoolClass = SchoolClass::factory()->create(['school_id' => $school->id]);
        $student = User::factory()->student($schoolClass)->create();
        $classmate = User::factory()->student($schoolClass)->create();
        $entry = ClassBookEntry::factory()->for($schoolClass)->absent([$student, $classmate])->create();

        $this->assertFalse($student->can('viewAny', ClassBookEntry::class));
        $this->assertFalse($student->can('view', $entry));

        $export = PersonalDataExport::for($student);

        $this->assertCount(1, $export['klassenbuch_fehlzeiten']);
        $this->assertSame(['datum', 'fach', 'klasse', 'status'], array_keys($export['klassenbuch_fehlzeiten'][0]));
        $this->assertStringContainsString('Datenauskunft', RolesAndPermissions::matrix()['classbook']['detail']);
        $this->assertStringContainsString('nur die eigenen', RolesAndPermissions::matrix()['classbook']['detail']);
    }

    public function test_talks_on_a_free_topic_stay_with_the_student_for_teachers_and_the_administration(): void
    {
        $school = School::factory()->create();
        $teacher = User::factory()->teacher()->for($school)->create();
        $schoolClass = SchoolClass::factory()->create(['school_id' => $school->id, 'teacher_id' => $teacher->id]);
        $student = User::factory()->student($schoolClass)->create();
        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->create();
        $freeTopic = ExplanationSession::factory()->for($student, 'student')->create();
        $aboutLesson = ExplanationSession::factory()->for($student, 'student')->create(['lesson_id' => $lesson->id]);
        $admin = User::factory()->admin()->for($school)->create();

        foreach ([$teacher, $admin] as $user) {
            $this->assertFalse($user->can('view', $freeTopic));
            $this->assertTrue($user->can('view', $aboutLesson));
        }

        $this->assertTrue($student->can('view', $freeTopic));
        $this->assertStringContainsString('frei gewählten Thema', RolesAndPermissions::matrix()['results']['detail']);
    }

    /**
     * "Laufende und geplante Vertretungen ... zusammen höchstens N Tage" and "die die Klasse nicht ohnehin unterrichtet":
     * a period that is over counts for nothing, the Klassenleitung and the assigned teachers are not offered.
     */
    public function test_the_vertretung_row_counts_only_running_and_planned_cover_and_names_the_teachers_offered(): void
    {
        $school = School::factory()->create();
        $leader = User::factory()->teacher()->for($school)->create();
        $assigned = User::factory()->teacher()->for($school)->create();
        $other = User::factory()->teacher()->for($school)->create();
        User::factory()->teacher()->create();
        $schoolClass = SchoolClass::factory()->create(['school_id' => $school->id, 'teacher_id' => $leader->id]);
        $schoolClass->teachers()->attach($assigned);

        ClassSubstitution::factory()->create([
            'school_class_id' => $schoolClass->id,
            'substitute_id' => $other->id,
            'starts_on' => today()->subDays(100),
            'ends_on' => today()->subDays(10),
        ]);

        $this->assertSame(0, ClassSubstitution::daysPlanned($schoolClass, $other->id));
        $this->assertSame([$other->id], array_keys(SubstitutionForm::substituteOptions($schoolClass)));

        $detail = RolesAndPermissions::matrix()['substitutions']['detail'];

        $this->assertStringContainsString('Laufende und geplante Vertretungen derselben Lehrkraft in einer Klasse dürfen zusammen höchstens '.ClassSubstitution::maxDays().' Tage dauern', $detail);
        $this->assertStringContainsString('die die Klasse nicht ohnehin unterrichtet', $detail);
    }

    public function test_the_vertretung_row_is_one_sentence_in_the_language_of_the_user(): void
    {
        app()->setLocale('en');

        $detail = RolesAndPermissions::matrix()['substitutions']['detail'];

        $this->assertStringContainsString(ClassSubstitution::grants(), $detail);
        $this->assertStringContainsString('It ends by itself afterwards', $detail);
        $this->assertStringNotContainsString('Danach endet das von selbst', $detail);
        $this->assertStringNotContainsString(':grants', $detail);
        $this->assertStringNotContainsString(':days', $detail);
    }
}
