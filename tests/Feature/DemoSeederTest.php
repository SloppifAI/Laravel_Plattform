<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\ClassBook;
use App\Filament\Pages\LearningProgress;
use App\Filament\Pages\MyHomework;
use App\Filament\Pages\MyQuestions;
use App\Filament\Pages\ParentReport;
use App\Filament\Pages\QuestionInbox;
use App\Filament\Pages\Substitutions;
use App\Filament\Pages\WorkOnHomework;
use App\Filament\Resources\SchoolClasses\Schemas\SubstitutionForm;
use App\Models\ClassBookEntry;
use App\Models\ClassSubstitution;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\StudentQuestion;
use App\Models\User;
use App\QuestionLevel;
use App\Role;
use App\Support\LearningInsights;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private const array TABLES = ['schools', 'users', 'school_classes', 'competencies', 'lessons', 'questions', 'lesson_user', 'submissions', 'answers', 'explanation_sessions', 'explanation_messages', 'class_book_entries', 'class_substitutions', 'student_questions', 'notifications'];

    protected function setUp(): void
    {
        parent::setUp();

        // The demo texts and the pages that show them are German; the suite runs in English wherever a text is translated,
        // and a bell text is stored in the recipient's language when it is sent.
        config(['lernplattform.locale.default' => 'de']);
        app()->setLocale('de');
    }

    public function test_seeding_twice_creates_no_duplicates(): void
    {
        $this->seed(DatabaseSeeder::class);
        $countsAfterFirstRun = $this->rowCounts();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($countsAfterFirstRun, $this->rowCounts());
        $this->assertSame(2, $countsAfterFirstRun['schools']);
        $this->assertSame(21, $countsAfterFirstRun['users']);
        $this->assertSame(3, $countsAfterFirstRun['school_classes']);
        $this->assertSame(6, $countsAfterFirstRun['lessons']);
        $this->assertSame(2, $countsAfterFirstRun['class_book_entries']);
        $this->assertSame(1, $countsAfterFirstRun['class_substitutions']);
        $this->assertSame(2, $countsAfterFirstRun['student_questions']);
        $this->assertSame(4, $countsAfterFirstRun['notifications']);
    }

    public function test_the_demo_schools_always_have_the_current_dpa_accepted_once(): void
    {
        config(['lernplattform.dpa.final' => false]);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        config(['lernplattform.dpa.final' => true]);

        School::all()->each(function (School $school): void {
            $this->assertTrue($school->hasAcceptedCurrentDpa());
            $this->assertFalse($school->mustAcceptDpa());
            $this->assertSame(1, $school->dpaAcceptances()->count());
        });
    }

    public function test_the_demo_logins_exist_with_their_roles(): void
    {
        $existingAccount = User::factory()->admin()->create(['username' => 'hackathon']);

        $this->seed(DatabaseSeeder::class);

        $roles = [
            'hackathon' => Role::Operator,
            'schulleitung' => Role::Admin,
            'lehrer' => Role::Teacher,
            'weber' => Role::Teacher,
            'lindner' => Role::Teacher,
            'schueler' => Role::Student,
            'gym-admin' => Role::Admin,
            'gym-lehrer' => Role::Teacher,
            'gym-schueler1' => Role::Student,
        ];

        foreach ($roles as $username => $role) {
            $user = User::query()->where('username', $username)->sole();

            $this->assertSame($role, $user->role, $username);
            $this->assertTrue(Hash::check('hackathon2026', $user->password), $username);
        }

        $this->assertSame($existingAccount->id, User::query()->where('username', 'hackathon')->value('id'));

        $mia = User::query()->where('username', 'schueler')->sole();
        $this->assertSame('Emilian', $mia->name);
        $this->assertSame('Frau Schulze', $mia->schoolClass->teacher->name);
        $this->assertSame('7b', $mia->schoolClass->name);
        $this->assertSame('lehrer', $mia->schoolClass->teacher->username);
        $this->assertSame('Petra Krause', User::query()->where('username', 'schulleitung')->value('name'));
    }

    public function test_every_demo_account_of_the_login_page_exists_with_the_listed_role(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (Login::DEMO_ACCOUNTS as $username => $role) {
            $this->assertSame($role, User::query()->where('username', $username)->sole()->role, $username);
        }
    }

    public function test_there_are_two_demo_schools_and_the_operator_belongs_to_none(): void
    {
        $this->seed(DatabaseSeeder::class);

        $lernpark = School::query()->where('name', 'Gesamtschule am Lernpark')->sole();
        $this->assertSame(['Gesamtschule', 'Fulda', 'Hessen', '4711'], [$lernpark->school_type, $lernpark->city, $lernpark->state, $lernpark->school_number]);

        $gymnasium = School::query()->where('name', 'Gymnasium Musterstadt')->sole();
        $this->assertSame(['Gymnasium', 'Musterstadt', 'Niedersachsen'], [$gymnasium->school_type, $gymnasium->city, $gymnasium->state]);

        $this->assertSame(['hackathon'], User::query()->whereNull('school_id')->pluck('username')->all());
        $this->assertTrue(User::query()->where('username', 'hackathon')->sole()->isOperator());

        $this->assertSame($lernpark->id, User::query()->where('username', 'schulleitung')->value('school_id'));
        $this->assertSame($gymnasium->id, User::query()->where('username', 'gym-admin')->value('school_id'));
        $this->assertNull($lernpark->blocked_at);
        $this->assertNull($gymnasium->blocked_at);
    }

    public function test_every_demo_user_class_and_lesson_belongs_to_the_right_school(): void
    {
        $this->seed(DatabaseSeeder::class);

        $lernpark = School::query()->where('name', 'Gesamtschule am Lernpark')->sole();
        $gymnasium = School::query()->where('name', 'Gymnasium Musterstadt')->sole();

        $this->assertSame(['7a', '7b'], $lernpark->schoolClasses()->orderBy('name')->pluck('name')->all());
        $this->assertSame(['8c'], $gymnasium->schoolClasses()->pluck('name')->all());
        $this->assertSame(['gym-admin', 'gym-lehrer', 'gym-schueler1', 'gym-schueler2'], $gymnasium->users()->orderBy('username')->pluck('username')->all());
        $this->assertSame(16, $lernpark->users()->count());

        foreach (User::query()->whereNotNull('school_class_id')->with('schoolClass')->get() as $student) {
            $this->assertSame($student->schoolClass->school_id, $student->school_id, $student->username);
        }

        foreach (SchoolClass::query()->with('teacher')->get() as $schoolClass) {
            $this->assertSame($schoolClass->school_id, $schoolClass->teacher->school_id, $schoolClass->name);
        }

        foreach (Lesson::query()->with(['schoolClass', 'teacher', 'students'])->get() as $lesson) {
            $this->assertSame($lesson->schoolClass->school_id, $lesson->teacher->school_id, $lesson->topic);
            $this->assertEmpty($lesson->students->where('school_id', '!=', $lesson->schoolClass->school_id), $lesson->topic);
        }

        $this->assertSame(['Lineare Funktionen'], Lesson::query()->whereRelation('schoolClass', 'school_id', $gymnasium->id)->pluck('topic')->all());
        $this->assertSame(5, Lesson::query()->whereRelation('schoolClass', 'school_id', $lernpark->id)->count());
    }

    public function test_every_demo_class_has_its_own_join_code(): void
    {
        $this->seed(DatabaseSeeder::class);
        $codes = SchoolClass::query()->pluck('join_code');

        $this->assertCount(3, $codes);
        $this->assertCount(3, $codes->filter()->unique());
    }

    public function test_accounts_and_classes_from_before_the_schools_existed_move_into_the_demo_school(): void
    {
        $teacher = User::factory()->teacher()->create(['username' => 'lehrer', 'school_id' => null]);
        $schoolClass = SchoolClass::factory()->create(['name' => '7b', 'teacher_id' => $teacher->id, 'school_id' => null]);
        $mia = User::factory()->student($schoolClass)->create(['username' => 'schueler', 'school_id' => null]);
        $joinCode = $schoolClass->join_code;

        $this->seed(DatabaseSeeder::class);

        $lernpark = School::query()->where('name', 'Gesamtschule am Lernpark')->sole();

        $this->assertSame($lernpark->id, $teacher->refresh()->school_id);
        $this->assertSame($lernpark->id, $mia->refresh()->school_id);
        $this->assertSame($lernpark->id, $schoolClass->refresh()->school_id);
        $this->assertSame($joinCode, $schoolClass->join_code);
        $this->assertSame(1, SchoolClass::query()->where('name', '7b')->count());
        $this->assertSame($schoolClass->id, $mia->school_class_id);
    }

    public function test_the_demo_lessons_cover_every_state(): void
    {
        $this->seed(DatabaseSeeder::class);
        $mia = User::query()->where('username', 'schueler')->sole();

        $photosynthesis = Lesson::query()->where('topic', 'Fotosynthese')->sole();
        $this->assertCount(8, $photosynthesis->students);
        $this->assertCount(4, $photosynthesis->questions);
        $this->assertTrue($photosynthesis->submissions()->where('student_id', $mia->id)->whereNotNull('evaluated_at')->exists());

        $pythagoras = Lesson::query()->where('topic', 'Satz des Pythagoras')->sole();
        $this->assertTrue($pythagoras->students->contains($mia));
        $this->assertFalse($pythagoras->submissions()->where('student_id', $mia->id)->exists());

        $draft = Lesson::query()->where('topic', 'Ursachen der Französischen Revolution')->sole();
        $this->assertFalse($draft->isPublished());
        $this->assertCount(0, $draft->questions);

        $remedial = Lesson::query()->where('topic', 'Flächeninhalt von Dreiecken (Förderaufgabe)')->sole();
        $this->assertFalse($remedial->isPracticeTask());
        $this->assertSame(['ben.krueger', 'noah.wagner', 'schueler'], $remedial->students->pluck('username')->sort()->values()->all());

        $this->assertSame(1, $mia->explanationSessions()->whereNotNull('finished_at')->count());
        $this->assertSame(1, $mia->explanationSessions()->whereNull('finished_at')->count());

        $linear = Lesson::query()->where('topic', 'Lineare Funktionen')->sole();
        $this->assertTrue($linear->isPublished());
        $this->assertSame(['gym-schueler1', 'gym-schueler2'], $linear->students->pluck('username')->sort()->values()->all());
        $this->assertCount(2, $linear->questions);
        $this->assertNotContains(null, $linear->questions->pluck('competency_id')->all());
    }

    public function test_the_demo_foerderaufgabe_goes_to_exactly_the_students_who_need_support_in_its_competency(): void
    {
        $this->seed(DatabaseSeeder::class);
        $mia = User::query()->where('username', 'schueler')->sole();

        $task = Lesson::query()->whereNotNull('practice_competency_id')->sole();
        $this->assertSame('Probleme mathematisch lösen', $task->practiceCompetency->name);
        $this->assertSame('7b', $task->schoolClass->name);
        $this->assertSame('lehrer', $task->teacher->username);
        $this->assertTrue($task->isPublished());
        $this->assertTrue($task->due_on->isFuture());
        $this->assertSame(0, $task->submissions()->count());

        $this->assertCount(3, $task->questions);
        $this->assertSame([$task->practice_competency_id], $task->questions->pluck('competency_id')->unique()->all());

        $studentIds = User::query()->withRole(Role::Student)->pluck('id')->all();
        $support = LearningInsights::competencySupport(LearningInsights::supportNeeds(LearningInsights::scoredAnswers($studentIds)), $task->practice_competency_id);

        $this->assertEqualsCanonicalizing($support['student_ids'], $task->students->modelKeys());
        $this->assertSame(['ben.krueger', 'schueler'], $task->students->pluck('username')->sort()->values()->all());

        $practiceRow = LearningInsights::studentCompetencies($mia)['practice']->firstWhere('competency.id', $task->practice_competency_id);
        $this->assertTrue($practiceRow['practice_task']->is($task));

        $this->actingAs($mia);

        Livewire::test(MyHomework::class)->assertCanSeeTableRecords([$task])->assertSee('Förderaufgabe');
        $this->get(WorkOnHomework::getUrl(['lesson' => $task]))->assertOk()->assertSee('Förderaufgabe zu „Probleme mathematisch lösen“');
    }

    public function test_the_insight_pages_render_the_demo_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        $teacher = User::query()->where('username', 'lehrer')->sole();
        $mia = User::query()->where('username', 'schueler')->sole();

        $this->actingAs($teacher)
            ->get(LearningProgress::getUrl())
            ->assertOk()
            ->assertSee(['Ben Krüger', 'Probleme mathematisch lösen', 'Eigenes Lernen reflektieren', 'Satz des Pythagoras']);

        $this->actingAs($teacher)
            ->get(ParentReport::getUrl(['student' => $mia]))
            ->assertOk()
            ->assertSee(['Steigend', 'Offen', 'Noch nicht abgeschlossen', 'Probleme mathematisch lösen (Mathematik): 38 Punkte']);
    }

    public function test_class_7b_has_a_confirmed_and_a_draft_klassenbuch_entry_with_one_absence(): void
    {
        $this->seed(DatabaseSeeder::class);
        $anna = $this->account('lehrer');

        $entries = ClassBookEntry::query()->get()->keyBy('topic');
        $this->assertCount(2, $entries);

        $confirmed = $entries['Fotosynthese'];
        $this->assertSame('7b', $confirmed->schoolClass->name);
        $this->assertSame($anna->id, $confirmed->teacher_id);
        $this->assertTrue($confirmed->isConfirmed());
        $this->assertTrue($confirmed->isComplete());
        $this->assertSame([], $confirmed->absent_student_ids);

        $draft = $entries['Satz des Pythagoras'];
        $this->assertFalse($draft->isConfirmed());
        $this->assertTrue($draft->isComplete());
        $this->assertSame(['Emma Fischer'], $draft->absentStudents()->pluck('name')->all());

        Livewire::actingAs($anna)
            ->test(ClassBook::class)
            ->assertCanSeeTableRecords($entries->values())
            ->assertSee('Emma Fischer');

        $draft->forceFill(['content' => 'Von Hand geändert.'])->save();
        $this->seed(DatabaseSeeder::class);

        $this->assertSame('Von Hand geändert.', $draft->refresh()->content);
    }

    public function test_sabine_lindner_covers_7b_for_a_few_days_from_today(): void
    {
        $this->seed(DatabaseSeeder::class);

        $substitution = ClassSubstitution::query()->sole();

        $this->assertSame('lindner', $substitution->substitute->username);
        $this->assertSame('7b', $substitution->schoolClass->name);
        $this->assertSame('lehrer', $substitution->grantedBy->username);
        $this->assertTrue($substitution->isActive());
        $this->assertTrue($substitution->starts_on->isToday());
        $this->assertSame(3, (int) $substitution->starts_on->diffInDays($substitution->ends_on));
        $this->assertNotEmpty($substitution->note);

        Livewire::actingAs($this->account('lindner'))->test(Substitutions::class)->assertCanSeeTableRecords([$substitution]);
        Livewire::actingAs($this->account('lehrer'))->test(Substitutions::class)->assertCanSeeTableRecords([$substitution]);
    }

    /**
     * The demo shows only what the form can produce: its substitute options leave out the Klassenleitung and every
     * assigned teacher, so the demo substitute may not be one of them (Thomas Weber is, and stays that).
     */
    public function test_the_demo_substitute_is_a_teacher_the_form_would_offer_for_7b(): void
    {
        $this->seed(DatabaseSeeder::class);

        $class7b = SchoolClass::query()->where('name', '7b')->sole();
        $lindner = $this->account('lindner');

        $this->assertFalse($class7b->teachers()->whereKey($lindner->id)->exists());
        $this->assertNotSame($lindner->id, $class7b->teacher_id);
        $this->assertSame([$lindner->id], array_keys(SubstitutionForm::substituteOptions($class7b)));
        $this->assertTrue($class7b->isTaughtBy($lindner));
    }

    public function test_a_running_demo_vertretung_is_left_alone_and_one_that_ran_out_is_renewed(): void
    {
        $this->seed(DatabaseSeeder::class);
        $substitution = ClassSubstitution::query()->sole();

        $substitution->forceFill(['ends_on' => today()->addDay()])->save();
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue($substitution->refresh()->ends_on->isTomorrow());

        $substitution->forceFill(['starts_on' => today()->subDays(5), 'ends_on' => today()->subDay()])->save();
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, ClassSubstitution::query()->count());
        $this->assertTrue($substitution->refresh()->isActive());
    }

    public function test_mia_has_an_open_and_an_answered_rueckfrage(): void
    {
        $this->seed(DatabaseSeeder::class);
        $mia = $this->account('schueler');
        $anna = $this->account('lehrer');

        $questions = StudentQuestion::query()->with(['lesson', 'explanationSession'])->get();
        $this->assertSame([$mia->id], $questions->pluck('student_id')->unique()->all());

        $open = $questions->filter->isOpen()->sole();
        $this->assertSame('Satz des Pythagoras', $open->lesson->topic);
        $this->assertSame($open->lesson->topic, $open->explanationSession->topic);

        $answered = $questions->reject->isOpen()->sole();
        $this->assertSame('Fotosynthese', $answered->lesson->topic);
        $this->assertSame($anna->id, $answered->answered_by);
        $this->assertTrue($answered->hasUnreadAnswer());
        $this->assertNotEmpty($answered->answer);

        Livewire::actingAs($anna)->test(QuestionInbox::class)->assertCanSeeTableRecords($questions);
        Livewire::actingAs($mia)->test(MyQuestions::class)->assertCanSeeTableRecords($questions);
    }

    public function test_the_questions_of_pythagoras_come_in_three_levels_and_those_of_other_lessons_do_not(): void
    {
        $this->seed(DatabaseSeeder::class);

        $pythagoras = Lesson::query()->where('topic', 'Satz des Pythagoras')->sole();
        $this->assertCount(3, $pythagoras->questions);

        foreach ($pythagoras->questions as $question) {
            $this->assertSame([QuestionLevel::Basis, QuestionLevel::Standard, QuestionLevel::Plus], $question->availableLevels());
        }

        $this->assertFalse(Question::query()->whereNot('lesson_id', $pythagoras->id)->get()->contains->hasLevels());

        $this->actingAs($this->account('schueler'))
            ->get(WorkOnHomework::getUrl(['lesson' => $pythagoras]))
            ->assertOk()
            ->assertSee(['Einstieg', 'Profi']);
    }

    public function test_the_demo_teacher_and_mia_find_entries_in_their_bell_and_nobody_else_does(): void
    {
        $this->seed(DatabaseSeeder::class);

        $titles = fn (string $username): array => $this->account($username)->notifications->pluck('data.title')->sort()->values()->all();

        $this->assertSame([
            '3 neue Abgaben zu „Satz des Pythagoras“',
            'Neue Frage zu „Satz des Pythagoras“',
        ], $titles('lehrer'));
        $this->assertSame([
            'Deine Frage zu „Fotosynthese“ wurde beantwortet',
            'Neue Hausaufgabe: „Satz des Pythagoras“',
        ], $titles('schueler'));
        $this->assertSame(4, DB::table('notifications')->count());
    }

    public function test_seeders_do_not_depend_on_dev_packages(): void
    {
        foreach (glob(database_path('seeders/*.php')) as $seeder) {
            $this->assertDoesNotMatchRegularExpression('/::factory\(|\bfake\(|Faker/', file_get_contents($seeder), basename($seeder));
        }
    }

    private function account(string $username): User
    {
        return User::query()->where('username', $username)->sole();
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
