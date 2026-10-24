<?php

namespace Tests\Feature\PracticeTasks;

use App\Ai\Agents\PracticeTaskDrafter;
use App\Ai\LessonAssistant;
use App\Filament\Pages\LearningProgress;
use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\QuestionType;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Feature\Ai\EnablesSchoolAi;
use Tests\Feature\StudentProgress\CreatesLearningResults;
use Tests\TestCase;

/**
 * "Förderaufgabe erstellen" on the Förderbedarf part of "Lernstand & Förderbedarf".
 */
class PracticeTaskCreationTest extends TestCase
{
    use CreatesLearningResults;
    use EnablesSchoolAi;
    use RefreshDatabase;

    private const string NOTES = <<<'NOTES'
        Tafelbild: Fotosynthese
        - Pflanzen bauen mit Lichtenergie aus Kohlenstoffdioxid und Wasser Glucose auf.
        - Der grüne Farbstoff Chlorophyll in den Chloroplasten fängt das Licht ein.
        - Versuch: Wir haben eine Wasserpest unter eine Lampe gestellt und die aufsteigenden Sauerstoffbläschen gezählt.
        NOTES;

    private SchoolClass $schoolClass;

    private User $teacher;

    private User $mia;

    private User $ben;

    private User $lena;

    private Competency $competency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolClass = SchoolClass::factory()->create(['name' => '7b']);
        $this->teacher = $this->schoolClass->teacher;
        $this->mia = User::factory()->student($this->schoolClass)->create(['name' => 'Mia Hoffmann']);
        $this->ben = User::factory()->student($this->schoolClass)->create(['name' => 'Ben Schulz']);
        $this->lena = User::factory()->student($this->schoolClass)->create(['name' => 'Lena Wagner']);
        $this->competency = Competency::factory()->create([
            'subject' => 'Biologie',
            'area' => 'Erkenntnisgewinnung',
            'name' => 'Versuche auswerten',
            'description' => 'Beobachtungen aus Versuchen beschreiben und deuten',
        ]);
        $strength = Competency::factory()->create(['subject' => 'Biologie', 'name' => 'Fachwissen anwenden']);

        $miaHomework = $this->evaluatedHomework($this->mia, 'Fotosynthese', '2026-10-01', ['Gehe noch einmal auf „Chlorophyll“ ein.']);
        $miaHomework->lesson->update(['notes' => self::NOTES, 'teacher_id' => $this->teacher->id]);
        $this->answer($miaHomework, $this->competency, 30);
        $this->answer($miaHomework, $strength, 80);
        $this->answer($this->evaluatedHomework($this->ben, 'Zellaufbau', '2026-10-05'), $this->competency, 40);
        $this->answer($this->evaluatedHomework($this->lena, 'Atmung', '2026-10-10'), $this->competency, 90);
    }

    public function test_the_button_is_only_offered_for_competencies_with_support_needs(): void
    {
        $page = Livewire::actingAs($this->teacher)->test(LearningProgress::class)->assertOk();

        // Mia and Ben are below the threshold in one competency, nobody in the other one.
        // One button, and it names its competency for screen readers.
        $this->assertSame(1, substr_count($page->html(), 'aria-label="Förderaufgabe erstellen zu '));
        $page->assertSeeHtml('aria-label="Förderaufgabe erstellen zu Versuche auswerten"');
        $page->assertSee('Versuche auswerten')->assertSee('2 Schüler:innen unter 50 Punkten');

        $cleanClass = SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => '8a']);
        $clean = User::factory()->student($cleanClass)->create();
        $this->answer($this->evaluatedHomework($clean, 'Wetter'), $this->competency, 95);

        Livewire::actingAs($this->teacher)->test(LearningProgress::class)
            ->set('schoolClassId', $cleanClass->id)
            ->assertSee('Derzeit liegt bei niemandem in dieser Klasse eine Kompetenz unter der Förderschwelle.')
            ->assertDontSee('Förderaufgabe erstellen');
    }

    public function test_the_action_refuses_a_competency_without_support_needs_in_the_class(): void
    {
        $withoutNeed = Competency::query()->where('name', 'Fachwissen anwenden')->firstOrFail();

        Livewire::actingAs($this->teacher)->test(LearningProgress::class)
            ->mountAction('createPracticeTask', ['competency' => $withoutNeed->id])
            ->assertForbidden();

        $this->assertSame(0, Lesson::query()->whereNotNull('practice_competency_id')->count());
    }

    public function test_it_creates_a_draft_with_questions_mapped_to_the_competency_and_the_preselected_students(): void
    {
        $page = Livewire::actingAs($this->teacher)->test(LearningProgress::class)
            ->mountAction('createPracticeTask', ['competency' => $this->competency->id])
            ->assertActionMounted('createPracticeTask');

        $mounted = $page->instance()->mountedActions[0]['data'];
        $this->assertEqualsCanonicalizing([$this->mia->id, $this->ben->id], $mounted['students']);
        $this->assertFalse($mounted['distribute']);

        $page->callMountedAction()->assertHasNoActionErrors();

        $lesson = Lesson::query()->where('practice_competency_id', $this->competency->id)->sole();

        $page->assertRedirect(LessonResource::getUrl('edit', ['record' => $lesson]))
            ->assertNotified('Förderaufgabe als Entwurf angelegt');

        $this->assertTrue($lesson->isPracticeTask());
        $this->assertSame('Förderaufgabe: Versuche auswerten', $lesson->topic);
        $this->assertSame('Biologie', $lesson->subject);
        $this->assertTrue($lesson->teacher->is($this->teacher));
        $this->assertTrue($lesson->schoolClass->is($this->schoolClass));
        $this->assertTrue($lesson->held_on->isToday());
        $this->assertFalse($lesson->isPublished());
        $this->assertNull($lesson->due_on);
        $this->assertNotEmpty($lesson->summary);
        $this->assertStringContainsString('Beobachtungen aus Versuchen beschreiben und deuten', $lesson->notes);
        $this->assertStringContainsString('Fotosynthese', $lesson->notes);
        $this->assertEqualsCanonicalizing([$this->mia->id, $this->ben->id], $lesson->students()->pluck('users.id')->all());

        $questions = $lesson->questions;
        $this->assertGreaterThanOrEqual(3, $questions->count());
        $this->assertLessThanOrEqual(4, $questions->count());
        $this->assertSame(range(1, $questions->count()), $questions->pluck('position')->all());
        $this->assertSame([$this->competency->id], $questions->pluck('competency_id')->unique()->values()->all());
        $this->assertSame(1, $questions->where('type', QuestionType::Application)->count());
        // The gap the AI named in Mia's homework is practised first.
        $this->assertStringContainsString('„Chlorophyll“', $questions->first()->prompt);
        $this->assertSame(0, Lesson::assignedTo($this->mia)->where('practice_competency_id', $this->competency->id)->count());
    }

    public function test_direct_distribution_publishes_to_exactly_the_chosen_students(): void
    {
        $dueOn = today()->addDays(10);

        Livewire::actingAs($this->teacher)->test(LearningProgress::class)
            ->callAction('createPracticeTask', data: [
                'students' => [$this->mia->id],
                'distribute' => true,
                'due_on' => $dueOn->toDateString(),
            ], arguments: ['competency' => $this->competency->id])
            ->assertHasNoActionErrors()
            ->assertNoRedirect()
            ->assertNotified('Förderaufgabe freigegeben');

        $lesson = Lesson::query()->where('practice_competency_id', $this->competency->id)->sole();

        $this->assertTrue($lesson->isPublished());
        $this->assertTrue($lesson->due_on->isSameDay($dueOn));
        $this->assertSame([$this->mia->id], $lesson->students()->pluck('users.id')->all());
        $this->assertSame([$lesson->id], Lesson::assignedTo($this->mia)->where('practice_competency_id', $this->competency->id)->pluck('id')->all());
        $this->assertSame(0, Lesson::assignedTo($this->ben)->where('practice_competency_id', $this->competency->id)->count());
        $this->assertSame(0, Lesson::assignedTo($this->lena)->where('practice_competency_id', $this->competency->id)->count());

        Livewire::actingAs($this->teacher)->test(LearningProgress::class)
            ->assertSeeInOrder(['Förderaufgabe vom '.$lesson->held_on->format('d.m.'), '0 von 1 abgegeben']);
    }

    public function test_a_due_date_in_the_past_is_rejected_for_direct_distribution(): void
    {
        Livewire::actingAs($this->teacher)->test(LearningProgress::class)
            ->callAction('createPracticeTask', data: [
                'students' => [$this->mia->id],
                'distribute' => true,
                'due_on' => today()->subDay()->toDateString(),
            ], arguments: ['competency' => $this->competency->id])
            ->assertHasActionErrors(['due_on']);

        $this->assertSame(0, Lesson::query()->whereNotNull('practice_competency_id')->count());
    }

    public function test_students_of_another_class_or_school_cannot_be_chosen(): void
    {
        $otherClassInSchool = SchoolClass::factory()->for(User::factory()->teacher()->for($this->schoolClass->school)->create(), 'teacher')->create();
        $foreignSchoolStudent = User::factory()->student(SchoolClass::factory()->create())->create();
        $classmateOfOtherClass = User::factory()->student($otherClassInSchool)->create();

        foreach ([$classmateOfOtherClass, $foreignSchoolStudent] as $forged) {
            Livewire::actingAs($this->teacher)->test(LearningProgress::class)
                ->callAction('createPracticeTask', data: [
                    'students' => [$this->mia->id, $forged->id],
                    'distribute' => true,
                    'due_on' => today()->addWeek()->toDateString(),
                ], arguments: ['competency' => $this->competency->id])
                ->assertHasActionErrors(['students.1']);

            $this->assertSame(0, Lesson::query()->whereNotNull('practice_competency_id')->count());
            $this->assertSame(0, $forged->assignedLessons()->count());
        }
    }

    public function test_teachers_of_other_schools_and_classes_and_students_and_operators_cannot_trigger_it(): void
    {
        $colleagueWithOwnClass = SchoolClass::factory()->for(User::factory()->teacher()->for($this->schoolClass->school)->create(), 'teacher')->create();
        $withoutClass = [User::factory()->teacher()->create(), User::factory()->admin()->create()];

        foreach ([...$withoutClass, $colleagueWithOwnClass->teacher] as $viewer) {
            Livewire::actingAs($viewer)->test(LearningProgress::class)
                ->set('schoolClassId', $this->schoolClass->id)
                ->assertForbidden();
        }

        // A throwaway draft lesson in the class is no reason to see it.
        Lesson::factory()->for($this->schoolClass)->for($colleagueWithOwnClass->teacher, 'teacher')->create();

        foreach ([...$withoutClass, $colleagueWithOwnClass->teacher] as $viewer) {
            Livewire::actingAs($viewer)->test(LearningProgress::class)
                ->set('schoolClassId', $this->schoolClass->id)
                ->assertForbidden();
        }

        // Without a class of their own there is nothing to mount the action for.
        foreach ($withoutClass as $viewer) {
            Livewire::actingAs($viewer)->test(LearningProgress::class)
                ->call('mountAction', 'createPracticeTask', ['competency' => $this->competency->id])
                ->assertActionNotMounted();
        }

        // A colleague's own class has no support need in that competency.
        Livewire::actingAs($colleagueWithOwnClass->teacher)->test(LearningProgress::class)
            ->call('mountAction', 'createPracticeTask', ['competency' => $this->competency->id])
            ->assertForbidden();

        foreach ([$this->mia, User::factory()->operator()->create()] as $viewer) {
            Livewire::actingAs($viewer)->test(LearningProgress::class)->assertForbidden();
        }

        $this->assertSame(0, Lesson::query()->whereNotNull('practice_competency_id')->count());
    }

    public function test_admins_of_the_school_can_create_one_for_any_class_of_their_school(): void
    {
        $admin = User::factory()->admin()->for($this->schoolClass->school)->create();

        Livewire::actingAs($admin)->test(LearningProgress::class)
            ->assertSet('schoolClassId', $this->schoolClass->id)
            ->callAction('createPracticeTask', data: ['students' => [$this->ben->id], 'distribute' => false], arguments: ['competency' => $this->competency->id])
            ->assertHasNoActionErrors();

        $lesson = Lesson::query()->where('practice_competency_id', $this->competency->id)->sole();
        $this->assertTrue($lesson->teacher->is($admin));
        $this->assertTrue($admin->can('update', $lesson));
    }

    public function test_an_assistant_failure_creates_nothing_and_says_so_with_a_hint_on_the_demo_mode(): void
    {
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->shouldReceive('draftPracticeTask')->once()->andThrow(new RuntimeException('KI nicht erreichbar')));
        $questionCount = Question::query()->count();

        Livewire::actingAs($this->teacher)->test(LearningProgress::class)
            ->callAction('createPracticeTask', data: ['students' => [$this->mia->id], 'distribute' => false], arguments: ['competency' => $this->competency->id])
            ->assertNoRedirect()
            // The modal stays open, so the teacher keeps the selection.
            ->assertActionHalted('createPracticeTask');

        $this->assertSame(0, Lesson::query()->whereNotNull('practice_competency_id')->count());
        $this->assertSame($questionCount, Question::query()->count());

        $notification = $this->sentNotifications()->sole();
        $this->assertSame('KI-Entwurf fehlgeschlagen', $notification['title']);
        $this->assertSame('danger', $notification['status']);
        $this->assertStringContainsString('Es wurde nichts angelegt', $notification['body']);
        $this->assertStringContainsString('Demo-Modus', $notification['body']);
    }

    public function test_a_failing_ai_request_creates_nothing_and_does_not_blame_the_demo_mode(): void
    {
        $this->preventRealAiCalls();
        $this->enableAiFor($this->teacher->school);
        PracticeTaskDrafter::fake(fn () => throw ProviderOverloadedException::forProvider('gemini'));

        Livewire::actingAs($this->teacher)->test(LearningProgress::class)
            ->callAction('createPracticeTask', data: ['students' => [$this->mia->id], 'distribute' => false], arguments: ['competency' => $this->competency->id])
            ->assertNoRedirect()
            ->assertActionHalted('createPracticeTask');

        $this->assertSame(0, Lesson::query()->whereNotNull('practice_competency_id')->count());

        $notification = $this->sentNotifications()->sole();
        $this->assertSame('KI-Entwurf fehlgeschlagen', $notification['title']);
        $this->assertStringNotContainsString('Demo-Modus', $notification['body']);
    }

    public function test_with_the_ai_enabled_the_draft_is_stored_and_no_student_names_leave_the_platform(): void
    {
        $this->preventRealAiCalls();
        $this->enableAiFor($this->teacher->school);
        PracticeTaskDrafter::fake([[
            'summary' => 'Hier übst du, Versuche auszuwerten.',
            'questions' => [
                ['type' => 'comprehension', 'prompt' => 'Was zeigt der Versuch mit der Wasserpest?', 'expected_answer' => 'Sauerstoff entsteht.'],
                ['type' => 'application', 'prompt' => 'Plane einen eigenen Versuch.', 'expected_answer' => 'Eine Variable ändern.'],
                ['type' => 'comprehension', 'prompt' => 'Warum braucht die Pflanze Licht?', 'expected_answer' => 'Für die Fotosynthese.'],
            ],
        ]]);

        Livewire::actingAs($this->teacher)->test(LearningProgress::class)
            ->callAction('createPracticeTask', data: ['students' => [$this->mia->id, $this->ben->id], 'distribute' => false], arguments: ['competency' => $this->competency->id])
            ->assertHasNoActionErrors();

        $lesson = Lesson::query()->where('practice_competency_id', $this->competency->id)->sole();
        $this->assertSame('Hier übst du, Versuche auszuwerten.', $lesson->summary);
        $this->assertSame(['Was zeigt der Versuch mit der Wasserpest?', 'Plane einen eigenen Versuch.', 'Warum braucht die Pflanze Licht?'], $lesson->questions->pluck('prompt')->all());
        $this->assertSame([$this->competency->id], $lesson->questions->pluck('competency_id')->unique()->values()->all());

        PracticeTaskDrafter::assertPromptedTimes(1);
        PracticeTaskDrafter::assertPrompted(function (AgentPrompt $prompt): bool {
            $body = $prompt->prompt;

            foreach ([$this->mia, $this->ben, $this->lena] as $student) {
                $this->assertStringNotContainsString($student->name, $body);
                $this->assertStringNotContainsString($student->username, $body);
                $this->assertStringNotContainsString($student->email, $body);
            }

            $this->assertStringContainsString('Versuche auswerten', $body);
            $this->assertStringContainsString('Chlorophyll', $body);

            return true;
        });
    }

    public function test_existing_foerderaufgaben_show_next_to_the_button_without_other_teachers_drafts(): void
    {
        $published = Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')->publishedFor([$this->mia, $this->ben])
            ->create(['practice_competency_id' => $this->competency->id, 'held_on' => '2026-10-20', 'created_at' => '2026-10-20 08:30:00']);
        Submission::factory()->for($published)->for($this->mia, 'student')->create();
        $ownDraft = Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')
            ->create(['practice_competency_id' => $this->competency->id, 'held_on' => '2026-10-22', 'created_at' => '2026-10-22 14:05:00']);
        // Two drafts of one day stay apart by their time.
        Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')
            ->create(['practice_competency_id' => $this->competency->id, 'held_on' => '2026-10-22', 'created_at' => '2026-10-22 16:40:00']);
        $colleaguesDraft = Lesson::factory()->for($this->schoolClass)
            ->create(['practice_competency_id' => $this->competency->id, 'held_on' => '2026-10-23']);
        $ordinaryHomework = Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')->create(['held_on' => '2026-10-21']);

        $page = Livewire::actingAs($this->teacher)->test(LearningProgress::class);

        $page->assertSeeInOrder(['Förderaufgabe vom 22.10., 16:40 Uhr', 'Wartet auf Freigabe', 'Förderaufgabe vom 22.10., 14:05 Uhr', 'Wartet auf Freigabe', 'Förderaufgabe vom 20.10., 08:30 Uhr', '1 von 2 abgegeben'])
            ->assertSee(LessonResource::getUrl('edit', ['record' => $published]), false)
            ->assertSee(LessonResource::getUrl('edit', ['record' => $ownDraft]), false)
            ->assertDontSee('Förderaufgabe vom 23.10.')
            ->assertDontSee(LessonResource::getUrl('edit', ['record' => $colleaguesDraft]), false)
            ->assertDontSee(LessonResource::getUrl('edit', ['record' => $ordinaryHomework]), false);
    }

    public function test_deleting_the_competency_turns_the_task_into_an_ordinary_homework(): void
    {
        $lesson = Lesson::factory()->for($this->schoolClass)->create(['practice_competency_id' => $this->competency->id]);

        $this->assertTrue($lesson->isPracticeTask());
        $this->assertTrue($lesson->practiceCompetency->is($this->competency));

        $this->competency->delete();

        $this->assertFalse($lesson->refresh()->isPracticeTask());
    }

    public function test_the_assistant_gets_the_competency_the_weak_lessons_and_the_gaps_of_the_weak_students_only(): void
    {
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->shouldReceive('draftPracticeTask')->once()
            ->withArgs(function (Competency $competency, string $subject, $sourceLessons, array $gaps): bool {
                $this->assertTrue($competency->is($this->competency));
                $this->assertSame('Biologie', $subject);
                $this->assertEqualsCanonicalizing(['Fotosynthese', 'Zellaufbau'], $sourceLessons->pluck('topic')->all());
                $this->assertContains('Gehe noch einmal auf „Chlorophyll“ ein.', $gaps);

                return true;
            })
            ->andReturn([
                'summary' => 'Übe das Auswerten.',
                'questions' => [['type' => QuestionType::Comprehension, 'prompt' => 'Frage?', 'expected_answer' => 'Antwort.', 'competency_id' => 999999]],
            ]));

        Livewire::actingAs($this->teacher)->test(LearningProgress::class)
            ->callAction('createPracticeTask', data: ['students' => [$this->mia->id], 'distribute' => false], arguments: ['competency' => $this->competency->id])
            ->assertHasNoActionErrors();

        // AI output is untrusted: an unknown competency in the answer does not matter, the chosen one is stored.
        $this->assertSame([$this->competency->id], Question::query()->where('prompt', 'Frage?')->pluck('competency_id')->all());
    }

    public function test_a_colleagues_lesson_notes_never_reach_the_draft_but_its_topic_does(): void
    {
        $colleagueLesson = Lesson::query()->where('topic', 'Zellaufbau')->sole();
        $colleagueLesson->update(['notes' => 'GEHEIME NOTIZ von Kollegin']);
        $this->assertFalse($this->teacher->can('view', $colleagueLesson));

        $received = [];
        $this->mock(LessonAssistant::class, function (MockInterface $mock) use (&$received): void {
            $mock->shouldReceive('draftPracticeTask')->once()
                ->andReturnUsing(function (Competency $competency, string $subject, Collection $sourceLessons) use (&$received): array {
                    $received = $sourceLessons->pluck('notes', 'topic')->all();

                    return $this->draftFor($competency);
                });
        });

        Livewire::actingAs($this->teacher)->test(LearningProgress::class)
            ->callAction('createPracticeTask', data: ['students' => [$this->mia->id, $this->ben->id], 'distribute' => false], arguments: ['competency' => $this->competency->id])
            ->assertHasNoActionErrors();

        // Own lesson: notes and topic. Colleague's lesson: the topic only, in the draft and in the stored task.
        $this->assertEquals(['Fotosynthese' => self::NOTES, 'Zellaufbau' => null], $received);

        $notes = Lesson::query()->where('practice_competency_id', $this->competency->id)->sole()->notes;
        $this->assertStringContainsString('Tafelbild: Fotosynthese', $notes);
        $this->assertStringContainsString('Aus der Stunde „Zellaufbau“', $notes);
        $this->assertStringNotContainsString('GEHEIME NOTIZ', $notes);
        $this->assertSame('GEHEIME NOTIZ von Kollegin', $colleagueLesson->refresh()->notes);
    }

    public function test_admins_of_the_school_may_use_the_notes_of_every_lesson_of_their_school(): void
    {
        Lesson::query()->where('topic', 'Zellaufbau')->sole()->update(['notes' => 'Notiz der Kollegin']);
        $admin = User::factory()->admin()->for($this->schoolClass->school)->create();

        Livewire::actingAs($admin)->test(LearningProgress::class)
            ->callAction('createPracticeTask', data: ['students' => [$this->ben->id], 'distribute' => false], arguments: ['competency' => $this->competency->id])
            ->assertHasNoActionErrors();

        $this->assertStringContainsString('Notiz der Kollegin', Lesson::query()->where('practice_competency_id', $this->competency->id)->sole()->notes);
    }

    public function test_an_earlier_foerderaufgabe_is_no_source_for_the_next_one(): void
    {
        $firstTask = Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')->publishedFor([$this->mia])
            ->create(['practice_competency_id' => $this->competency->id, 'topic' => 'Förderaufgabe: Versuche auswerten', 'subject' => 'Biologie', 'notes' => 'Förderaufgabe zur Kompetenz „Versuche auswerten“.']);
        $handIn = Submission::factory()->for($firstTask)->for($this->mia, 'student')->evaluated(20)
            ->create(['gaps' => ['Gehe noch einmal auf „Förderaufgabe“ ein.', 'Gehe noch einmal auf „Kompetenz“ ein.']]);
        $this->answer($handIn, $this->competency, 20);

        $received = null;
        $this->mock(LessonAssistant::class, function (MockInterface $mock) use (&$received): void {
            $mock->shouldReceive('draftPracticeTask')->once()
                ->andReturnUsing(function (Competency $competency, string $subject, Collection $sourceLessons, array $gaps) use (&$received): array {
                    $received = ['topics' => $sourceLessons->pluck('topic')->all(), 'gaps' => $gaps];

                    return $this->draftFor($competency);
                });
        });

        Livewire::actingAs($this->teacher)->test(LearningProgress::class)
            ->callAction('createPracticeTask', data: ['students' => [$this->mia->id], 'distribute' => false], arguments: ['competency' => $this->competency->id])
            ->assertHasNoActionErrors();

        $this->assertEqualsCanonicalizing(['Fotosynthese', 'Zellaufbau'], $received['topics']);
        $this->assertSame(['Gehe noch einmal auf „Chlorophyll“ ein.'], $received['gaps']);

        $secondTask = Lesson::query()->where('practice_competency_id', $this->competency->id)->whereKeyNot($firstTask->id)->sole();
        $this->assertStringNotContainsString('Aus der Stunde „Förderaufgabe', $secondTask->notes);
        $this->assertSame(1, substr_count($secondTask->notes, 'Förderaufgabe zur Kompetenz'));
    }

    public function test_students_with_an_open_foerderaufgabe_are_not_preselected_again_and_the_button_offers_another_one(): void
    {
        $openTask = Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')->publishedFor([$this->mia])
            ->create(['practice_competency_id' => $this->competency->id]);
        // A draft is not open: nobody sees it yet.
        Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')
            ->create(['practice_competency_id' => $this->competency->id])->students()->sync([$this->ben->id]);

        $page = Livewire::actingAs($this->teacher)->test(LearningProgress::class)
            ->assertSeeHtml('aria-label="Weitere Förderaufgabe erstellen zu Versuche auswerten"')
            ->mountAction('createPracticeTask', ['competency' => $this->competency->id])
            ->assertSchemaComponentExists('students', checkComponentUsing: fn (CheckboxList $field): bool => $field->getDescriptions() === [
                $this->mia->id => 'hat bereits eine offene Förderaufgabe zu dieser Kompetenz',
            ]);

        $this->assertEquals([$this->ben->id], $page->instance()->mountedActions[0]['data']['students']);

        // Once Mia handed in, the task is no longer open for her.
        Submission::factory()->for($openTask)->for($this->mia, 'student')->create();

        $mounted = Livewire::actingAs($this->teacher)->test(LearningProgress::class)
            ->mountAction('createPracticeTask', ['competency' => $this->competency->id])
            ->assertSchemaComponentExists('students', checkComponentUsing: fn (CheckboxList $field): bool => $field->getDescriptions() === [])
            ->instance()->mountedActions[0]['data'];
        $this->assertEqualsCanonicalizing([$this->mia->id, $this->ben->id], $mounted['students']);
    }

    public function test_the_demo_hint_on_example_questions_belongs_to_drafts_and_the_toggle_warns_about_it(): void
    {
        $arguments = ['competency' => $this->competency->id];

        Livewire::actingAs($this->teacher)->test(LearningProgress::class)
            ->mountAction('createPracticeTask', $arguments)
            ->assertSchemaComponentExists('distribute', checkComponentUsing: fn (Toggle $toggle): bool => str_contains($toggle->toHtml(), 'besser erst als Entwurf prüfen'));

        Livewire::actingAs($this->teacher)->test(LearningProgress::class)
            ->callAction('createPracticeTask', data: ['students' => [$this->mia->id], 'distribute' => true, 'due_on' => today()->addWeek()->toDateString()], arguments: $arguments);
        $distributed = $this->sentNotifications()->sole();
        $this->assertSame('Förderaufgabe freigegeben', $distributed['title']);
        $this->assertStringContainsString('Demo-Modus', $distributed['body']);
        $this->assertStringNotContainsString('bitte prüfe', $distributed['body']);

        Livewire::actingAs($this->teacher)->test(LearningProgress::class)
            ->callAction('createPracticeTask', data: ['students' => [$this->ben->id], 'distribute' => false], arguments: $arguments);
        $this->assertStringContainsString('bitte prüfe und passe sie an', $this->sentNotifications()->sole()['body']);

        $this->preventRealAiCalls();
        $this->enableAiFor($this->teacher->school);

        Livewire::actingAs($this->teacher)->test(LearningProgress::class)
            ->mountAction('createPracticeTask', $arguments)
            ->assertSchemaComponentExists('distribute', checkComponentUsing: fn (Toggle $toggle): bool => str_contains($toggle->toHtml(), 'Aus: Du prüfst') && ! str_contains($toggle->toHtml(), 'Demo-Modus'));
    }

    public function test_the_class_average_table_lists_ordinary_homework_only(): void
    {
        $task = Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')->publishedFor([$this->mia])
            ->create(['practice_competency_id' => $this->competency->id, 'topic' => 'Förderaufgabe: Versuche auswerten']);
        Submission::factory()->for($task)->for($this->mia, 'student')->evaluated(85)->create();

        Livewire::actingAs($this->teacher)->test(LearningProgress::class)
            ->assertSee('Atmung')
            ->assertDontSee('Förderaufgabe: Versuche auswerten');
    }

    /**
     * @return array{summary: string, questions: list<array{type: QuestionType, prompt: string, expected_answer: string, competency_id: int}>}
     */
    private function draftFor(Competency $competency): array
    {
        return [
            'summary' => 'Übe das Auswerten.',
            'questions' => [['type' => QuestionType::Comprehension, 'prompt' => 'Frage?', 'expected_answer' => 'Antwort.', 'competency_id' => $competency->id]],
        ];
    }

    /**
     * The notifications sent so far, as the browser would receive them.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function sentNotifications(): Collection
    {
        app(NotificationMailbox::class)->flush();

        $component = new Notifications;
        $component->mount();
        $component->pullNotificationsFromSession();

        return $component->notifications->map(fn (Notification $notification): array => $notification->toArray())->values();
    }
}
