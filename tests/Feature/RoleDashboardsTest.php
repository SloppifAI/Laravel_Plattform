<?php

namespace Tests\Feature;

use App\Filament\Pages\LessonStarter;
use App\Filament\Resources\Competencies\CompetencyResource;
use App\Filament\Resources\Schools\SchoolResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\AdminShortcutsWidget;
use App\Filament\Widgets\AdminStatsWidget;
use App\Filament\Widgets\OperatorSchoolsWidget;
use App\Filament\Widgets\OperatorShortcutsWidget;
use App\Filament\Widgets\OperatorStatsWidget;
use App\Filament\Widgets\TeacherLessonsWidget;
use App\Filament\Widgets\TeacherStatsWidget;
use App\Models\Answer;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\Role;
use App\Support\LessonReview;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The start pages of administration, teachers and operator: every number comes from the viewer's own school,
 * and the operator sees platform-wide aggregates but no people and no lessons.
 */
class RoleDashboardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_figures_count_only_the_own_school(): void
    {
        $schoolA = $this->schoolWithLessons(students: 3, published: 2, drafts: 1, submissions: 2);
        User::factory()->teacher()->for($schoolA['school'])->create();

        $schoolB = $this->schoolWithLessons(students: 5, published: 3, drafts: 1, submissions: 4, classes: 2);
        User::factory()->admin()->for($schoolB['school'])->create();

        $this->actingAs($schoolA['admin']);
        $statsA = $this->statsOf(AdminStatsWidget::class);

        $this->assertSame(
            ['Schüler:innen' => 3, 'Lehrkräfte' => 2, 'Administration' => 1, 'Klassen' => 1, 'Unterrichtsstunden' => 3, 'Abgaben diese Woche' => 2],
            array_map(fn (array $stat): mixed => $stat[0], $statsA),
        );
        $this->assertSame('2 davon mit freigegebener Hausaufgabe', $statsA['Unterrichtsstunden'][1]);

        $this->actingAs($schoolB['admin']);
        $statsB = $this->statsOf(AdminStatsWidget::class);

        $this->assertSame(
            ['Schüler:innen' => 5, 'Lehrkräfte' => 1, 'Administration' => 2, 'Klassen' => 2, 'Unterrichtsstunden' => 4, 'Abgaben diese Woche' => 4],
            array_map(fn (array $stat): mixed => $stat[0], $statsB),
        );
        $this->assertSame('3 davon mit freigegebener Hausaufgabe', $statsB['Unterrichtsstunden'][1]);
    }

    public function test_admins_open_their_start_page_and_the_other_school_stays_out_of_it(): void
    {
        $schoolA = $this->schoolWithLessons(students: 2, published: 1, drafts: 0, submissions: 0);
        $schoolB = $this->schoolWithLessons(students: 2, published: 1, drafts: 0, submissions: 0);
        $schoolB['school']->update(['name' => 'Nebenan-Schule Fremdhausen']);

        $this->actingAs($schoolA['admin'])
            ->get('/')
            ->assertOk()
            ->assertSee('Abgaben diese Woche')
            ->assertSee('Benutzer und Klassen pflegen, das Kompetenzraster einsehen.')
            ->assertSee(UserResource::getUrl())
            ->assertDontSee('Nebenan-Schule Fremdhausen')
            ->assertDontSee('Neueste Registrierungen')
            ->assertDontSee('Deine letzten Unterrichtsstunden');
    }

    public function test_teacher_widgets_only_use_the_teachers_own_lessons(): void
    {
        $schoolA = School::factory()->create();
        $teacher = User::factory()->teacher()->for($schoolA)->create();
        $colleague = User::factory()->teacher()->for($schoolA)->create();
        $classA = SchoolClass::factory()->create(['teacher_id' => $teacher->id]);
        $weakStudent = User::factory()->student($classA)->create();
        User::factory()->student($classA)->create();

        $ownLesson = Lesson::factory()->for($classA)->for($teacher, 'teacher')->publishedFor()->create(['topic' => 'Bruchrechnung']);
        Lesson::factory()->for($classA)->for($colleague, 'teacher')->publishedFor()->create(['topic' => 'Stunde der Kollegin']);
        $this->weakAnswer($ownLesson, $weakStudent);

        $classB = SchoolClass::factory()->create();
        $strangers = User::factory()->student($classB)->count(4)->create();
        $strangerLesson = Lesson::factory()->for($classB)->publishedFor()->create(['topic' => 'Stunde einer anderen Schule']);
        $strangers->each(fn (User $stranger) => $this->weakAnswer($strangerLesson, $stranger));

        $this->actingAs($teacher);

        $stats = array_map(fn (array $stat): mixed => $stat[0], $this->statsOf(TeacherStatsWidget::class));
        $this->assertSame('1 / 2', $stats['Abgaben offener Hausaufgaben']);
        $this->assertSame(1, $stats['Schüler:innen mit Förderbedarf']);

        Livewire::test(TeacherLessonsWidget::class)
            ->assertSee('Bruchrechnung')
            ->assertDontSee('Stunde der Kollegin')
            ->assertDontSee('Stunde einer anderen Schule');
    }

    public function test_teacher_stats_do_not_count_foerderaufgaben_as_homework(): void
    {
        $teacher = User::factory()->teacher()->create();
        $schoolClass = SchoolClass::factory()->create(['teacher_id' => $teacher->id]);
        [$first, $second] = User::factory()->student($schoolClass)->count(2)->create();
        $competency = Competency::factory()->create();

        $homework = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->publishedFor()->create(['topic' => 'Bruchrechnung', 'published_at' => now()->subDays(2)]);
        Submission::factory()->for($homework)->for($first, 'student')->evaluated(80)->create();

        // Newer than the homework, but delegated to one student for one competency.
        $practiceTask = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->publishedFor([$second])->create([
            'topic' => 'Förderaufgabe: Brüche kürzen',
            'published_at' => now(),
            'practice_competency_id' => $competency->id,
        ]);
        Submission::factory()->for($practiceTask)->for($second, 'student')->evaluated(20)->create();

        $this->actingAs($teacher);

        $stats = $this->statsOf(TeacherStatsWidget::class);

        $this->assertSame('1 / 2', $stats['Abgaben offener Hausaufgaben'][0]);
        $this->assertSame('aus 1 laufenden Hausaufgabe · Förderaufgaben: 1 / 1', $stats['Abgaben offener Hausaufgaben'][1]);
        $this->assertSame('80 %', $stats['Ø Verständnis der letzten Hausaufgabe'][0]);
        $this->assertStringContainsString('Bruchrechnung', $stats['Ø Verständnis der letzten Hausaufgabe'][1]);
    }

    public function test_teacher_stats_without_ordinary_homework_ignore_a_foerderaufgabe(): void
    {
        $teacher = User::factory()->teacher()->create();
        $schoolClass = SchoolClass::factory()->create(['teacher_id' => $teacher->id]);
        $student = User::factory()->student($schoolClass)->create();

        $practiceTask = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->publishedFor([$student])->create(['practice_competency_id' => Competency::factory()->create()->id]);
        Submission::factory()->for($practiceTask)->for($student, 'student')->evaluated(20)->create();

        $this->actingAs($teacher);

        $stats = $this->statsOf(TeacherStatsWidget::class);

        $this->assertSame(['–', 'Noch keine Hausaufgabe freigegeben'], $stats['Ø Verständnis der letzten Hausaufgabe']);
        $this->assertSame(['0 / 0', 'Gerade läuft keine Hausaufgabe · Förderaufgaben: 1 / 1'], $stats['Abgaben offener Hausaufgaben']);
    }

    public function test_the_teacher_widget_links_lessons_with_enough_evaluated_hand_ins_to_the_starter_board(): void
    {
        $teacher = User::factory()->teacher()->create();
        $schoolClass = SchoolClass::factory()->create(['teacher_id' => $teacher->id]);
        $students = User::factory()->student($schoolClass)->count(LessonReview::MIN_GROUP)->create();

        $reviewable = $this->lessonWithEvaluatedHandIns($teacher, $schoolClass, $students, 'Reviewbare Stunde');
        $tooFew = $this->lessonWithEvaluatedHandIns($teacher, $schoolClass, $students->take(LessonReview::MIN_GROUP - 1), 'Stunde mit wenig Abgaben');
        $practice = $this->lessonWithEvaluatedHandIns($teacher, $schoolClass, $students, 'Förderstunde');
        $practice->forceFill(['practice_competency_id' => Competency::factory()->create()->id])->save();

        $this->actingAs($teacher);

        Livewire::test(TeacherLessonsWidget::class)
            ->assertSee('Einstieg nächste Stunde')
            ->assertSee(LessonStarter::getUrl(['lesson' => $reviewable]))
            ->assertDontSee(LessonStarter::getUrl(['lesson' => $tooFew]))
            ->assertDontSee(LessonStarter::getUrl(['lesson' => $practice]));
    }

    public function test_the_operator_start_page_shows_aggregates_and_no_personal_data(): void
    {
        $operator = User::factory()->operator()->create();
        $school = School::factory()->create(['name' => 'Sonnenschule Testhausen']);
        School::factory()->blocked()->create(['name' => 'Mondschule Beispielstadt']);

        User::factory()->admin()->for($school)->create(['name' => 'Agathe Adminname']);
        $teacher = User::factory()->teacher()->for($school)->create(['name' => 'Torsten Lehrername']);
        $schoolClass = SchoolClass::factory()->create(['teacher_id' => $teacher->id, 'name' => 'Klasse Geheim']);
        User::factory()->student($schoolClass)->count(2)->create(['name' => 'Sabine Schülername']);
        Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->publishedFor()->create(['topic' => 'Geheimthema Bruchrechnung']);

        $this->actingAs($operator)
            ->get('/')
            ->assertOk()
            ->assertSee('Überblick über alle Schulen der Plattform.')
            ->assertSee('Neueste Registrierungen')
            ->assertSee('Sonnenschule Testhausen')
            ->assertSee('Mondschule Beispielstadt')
            ->assertSee('Gesperrt')
            ->assertSee('Aktiv')
            ->assertDontSee('Agathe Adminname')
            ->assertDontSee('Torsten Lehrername')
            ->assertDontSee('Sabine Schülername')
            ->assertDontSee('Klasse Geheim')
            ->assertDontSee('Geheimthema Bruchrechnung')
            ->assertDontSee('Deine letzten Unterrichtsstunden')
            ->assertDontSee('Abgaben diese Woche');

        $stats = $this->statsOf(OperatorStatsWidget::class);

        $this->assertSame(
            ['Schulen' => 2, 'Schuladministrationen' => 1, 'Lehrkräfte' => 1, 'Schüler:innen' => 2, 'Klassen' => 1],
            array_map(fn (array $stat): mixed => $stat[0], $stats),
        );
        $this->assertSame('1 aktiv, 1 gesperrt', $stats['Schulen'][1]);
    }

    public function test_the_operator_sees_the_five_newest_registrations_first(): void
    {
        $this->actingAs(User::factory()->operator()->create());

        foreach (['Erste', 'Zweite', 'Dritte', 'Vierte', 'Fünfte', 'Sechste', 'Siebte'] as $index => $name) {
            School::factory()->create(['name' => "{$name} Schule", 'created_at' => now()->subDays(10 - $index)]);
        }

        Livewire::test(OperatorSchoolsWidget::class)
            ->assertSeeInOrder(['Siebte Schule', 'Sechste Schule', 'Fünfte Schule', 'Vierte Schule', 'Dritte Schule'])
            ->assertDontSee('Zweite Schule')
            ->assertDontSee('Erste Schule');
    }

    public function test_the_operator_start_page_without_schools_invites_the_first_registration(): void
    {
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(OperatorSchoolsWidget::class)->assertSee('Noch keine Schule registriert.');
        $this->assertSame(0, $this->statsOf(OperatorStatsWidget::class)['Schulen'][0]);
    }

    public function test_the_operator_shortcuts_lead_to_the_schools_the_administration_accounts_and_the_competency_framework(): void
    {
        $response = $this->actingAs(User::factory()->operator()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('Plattform betreuen')
            ->assertSee(UserResource::getUrl())
            ->assertSee(CompetencyResource::getUrl());

        if (class_exists(SchoolResource::class)) {
            $response->assertSee(SchoolResource::getUrl());
        }
    }

    public function test_every_widget_is_only_visible_to_its_own_role(): void
    {
        $widgets = [
            AdminStatsWidget::class => Role::Admin,
            AdminShortcutsWidget::class => Role::Admin,
            TeacherStatsWidget::class => Role::Teacher,
            TeacherLessonsWidget::class => Role::Teacher,
            OperatorStatsWidget::class => Role::Operator,
            OperatorSchoolsWidget::class => Role::Operator,
            OperatorShortcutsWidget::class => Role::Operator,
        ];

        foreach (Role::cases() as $role) {
            $this->actingAs(User::factory()->{$role->value}()->create());

            foreach ($widgets as $widget => $widgetRole) {
                $this->assertSame($widgetRole === $role, $widget::canView(), "{$widget} for {$role->value}");
            }
        }
    }

    /**
     * A school with an administration, one teacher who leads the classes, students, lessons and hand-ins.
     *
     * @return array{school: School, admin: User, teacher: User, schoolClass: SchoolClass}
     */
    private function schoolWithLessons(int $students, int $published, int $drafts, int $submissions, int $classes = 1): array
    {
        $school = School::factory()->create();
        $admin = User::factory()->admin()->for($school)->create();
        $teacher = User::factory()->teacher()->for($school)->create();
        $schoolClass = SchoolClass::factory()->create(['teacher_id' => $teacher->id]);

        SchoolClass::factory()->count($classes - 1)->create(['teacher_id' => $teacher->id]);

        $pupils = User::factory()->student($schoolClass)->count($students)->create();
        $publishedLessons = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->publishedFor()->count($published)->create();
        Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->count($drafts)->create();

        $pupils->take($submissions)->each(
            fn (User $pupil) => Submission::factory()->for($publishedLessons->first())->for($pupil, 'student')->create(),
        );

        return ['school' => $school, 'admin' => $admin, 'teacher' => $teacher, 'schoolClass' => $schoolClass];
    }

    /**
     * A lesson of the teacher with one evaluated hand-in per given student.
     *
     * @param  iterable<User>  $students
     */
    private function lessonWithEvaluatedHandIns(User $teacher, SchoolClass $schoolClass, iterable $students, string $topic): Lesson
    {
        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->publishedFor()->create(['topic' => $topic]);

        foreach ($students as $student) {
            Submission::factory()->for($lesson)->for($student, 'student')->evaluated(40)->create();
        }

        return $lesson;
    }

    private function weakAnswer(Lesson $lesson, User $student): void
    {
        $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated(40)->create();
        $question = Question::factory()->for($lesson)->for(Competency::factory())->create();
        Answer::factory()->for($submission)->for($question)->create(['score' => 30]);
    }

    /**
     * The stats a widget renders, by label: value and description.
     *
     * @param  class-string  $widget
     * @return array<string, array{0: mixed, 1: string}>
     */
    private function statsOf(string $widget): array
    {
        $component = Livewire::test($widget)->instance();
        $stats = (new ReflectionMethod($component, 'getStats'))->invoke($component);

        return collect($stats)
            ->mapWithKeys(fn (Stat $stat): array => [(string) $stat->getLabel() => [$stat->getValue(), (string) $stat->getDescription()]])
            ->all();
    }
}
