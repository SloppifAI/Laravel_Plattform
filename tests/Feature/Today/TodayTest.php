<?php

namespace Tests\Feature\Today;

use App\Ai\LessonAssistant;
use App\Filament\NavigationGroup;
use App\Filament\Pages\Today;
use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\QuestionType;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Feature\Ai\EnablesSchoolAi;
use Tests\TestCase;

/**
 * "Heute": the teacher's own lessons of the last school days with draft and release at hand, one by one or all at once.
 */
class TodayTest extends TestCase
{
    use EnablesSchoolAi;
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday: the window starts on Monday.
        $this->travelTo('2026-10-21 10:00:00');
        $this->teacher = User::factory()->teacher()->create();
    }

    public function test_only_teachers_open_the_page(): void
    {
        $this->actingAs($this->teacher)->get(Today::getUrl())->assertOk()->assertSee('No class assigned', false);

        foreach ([User::factory()->student(), User::factory()->admin(), User::factory()->operator()] as $user) {
            $this->actingAs($user->create())->get(Today::getUrl())->assertForbidden();
        }

        auth()->logout();
        $this->get(Today::getUrl())->assertRedirect();
    }

    public function test_it_is_the_first_entry_of_the_teaching_group(): void
    {
        $this->assertSame('heute', Today::getSlug());
        $this->assertSame(NavigationGroup::Unterricht, Today::getNavigationGroup());
        $this->assertLessThan(LessonResource::getNavigationSort(), Today::getNavigationSort());
        $this->assertStringEndsWith('/heute', Today::getUrl());
    }

    public function test_it_lists_the_own_lessons_of_today_and_the_last_two_school_days(): void
    {
        $today = $this->lesson('2026-10-21');
        $monday = $this->lesson('2026-10-19');
        $sunday = $this->lesson('2026-10-18');
        $lastFriday = $this->lesson('2026-10-16');
        $colleagues = Lesson::factory()->state(['held_on' => '2026-10-21'])->create();
        $practice = $this->lesson('2026-10-21', ['practice_competency_id' => Competency::factory()->create()->id]);
        $tomorrow = $this->lesson('2026-10-22');
        $this->actingAs($this->teacher);

        Livewire::test(Today::class)
            ->assertCanSeeTableRecords([$today, $monday])
            ->assertCanNotSeeTableRecords([$sunday, $lastFriday, $colleagues, $practice, $tomorrow]);
    }

    public function test_the_window_skips_the_weekend_when_counting_school_days(): void
    {
        $this->travelTo('2026-10-26 08:00:00'); // Monday: Monday, Friday and Thursday count.
        $thursday = $this->lesson('2026-10-22');
        $wednesday = $this->lesson('2026-10-21');
        $this->actingAs($this->teacher);

        Livewire::test(Today::class)
            ->assertCanSeeTableRecords([$thursday])
            ->assertCanNotSeeTableRecords([$wednesday]);
    }

    public function test_a_lesson_held_later_in_the_day_is_still_listed_today(): void
    {
        $lesson = $this->lesson('2026-10-21 15:30:00');
        $this->actingAs($this->teacher);

        Livewire::test(Today::class)->assertCanSeeTableRecords([$lesson]);
    }

    public function test_it_shows_status_draft_and_hand_ins(): void
    {
        $schoolClass = SchoolClass::factory()->for($this->teacher, 'teacher')->create();
        $students = User::factory()->student($schoolClass)->count(2)->create();
        $waiting = $this->lesson('2026-10-21', [], questions: 3);
        $empty = $this->lesson('2026-10-20');
        $released = $this->lesson('2026-10-19', [], questions: 1, class: $schoolClass);
        $released->update(['published_at' => now(), 'due_on' => today()->addDays(5)]);
        $released->students()->sync($students->modelKeys());
        Submission::factory()->for($released)->for($students->first(), 'student')->create();
        $this->actingAs($this->teacher);

        Livewire::test(Today::class)
            ->assertTableColumnStateSet('status', 'Waiting for release', $waiting)
            ->assertTableColumnStateSet('status', 'Released', $released)
            ->assertTableColumnStateSet('questions_count', '3 questions', $waiting)
            ->assertTableColumnStateSet('questions_count', 'No draft yet', $empty)
            ->assertTableColumnStateSet('questions_count', '1 question', $released)
            ->assertTableColumnStateSet('submissions_count', '1 / 2', $released)
            ->assertTableColumnStateSet('submissions_count', null, $waiting)
            ->assertSee(['Waiting for release', 'Released', 'Release in 30 seconds']);
    }

    public function test_it_shows_an_empty_state_without_lessons(): void
    {
        SchoolClass::factory()->for($this->teacher, 'teacher')->create();
        $this->actingAs($this->teacher);

        Livewire::test(Today::class)
            ->assertSee('No lesson in the last school days')
            ->assertSee('Add lesson')
            ->assertDontSee('Release in 30 seconds')
            ->assertActionHidden('releaseAll');
    }

    public function test_the_draft_replaces_the_ai_questions_and_says_it_is_a_demo_for_schools_without_ai(): void
    {
        $lesson = $this->lesson('2026-10-21', ['subject' => 'Biologie']);
        Question::factory()->for($lesson)->aiDrafted()->create();
        $competency = Competency::factory()->create(['subject' => 'Biologie']);
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->shouldReceive('draftHomework')->once()->andReturn([
            'summary' => 'Pflanzen bauen mit Licht Glucose auf.',
            'questions' => [
                ['type' => QuestionType::Comprehension, 'prompt' => 'Was braucht die Fotosynthese?', 'expected_answer' => 'Licht', 'competency_id' => $competency->id],
                ['type' => QuestionType::Reflection, 'prompt' => 'Was zeigte die Wasserpest?', 'expected_answer' => 'Sauerstoff', 'competency_id' => 9999],
            ],
        ]));
        $this->actingAs($this->teacher);

        Livewire::test(Today::class)
            ->callAction(TestAction::make('draftHomework')->table($lesson))
            ->assertNotified(Notification::make()
                ->success()
                ->title('Homework created')
                ->body('Demo mode – the AI connection is set up but not active. Summary and questions are sample drafts, please check and adjust them.')
                ->persistent())
            ->assertTableColumnStateSet('questions_count', '2 questions', $lesson);

        $this->assertSame('Pflanzen bauen mit Licht Glucose auf.', $lesson->refresh()->summary);
        $this->assertSame(
            [['prompt' => 'Was braucht die Fotosynthese?', 'competency_id' => $competency->id], ['prompt' => 'Was zeigte die Wasserpest?', 'competency_id' => null]],
            $lesson->questions()->get(['prompt', 'competency_id'])->toArray(),
        );
    }

    public function test_the_confirmation_names_the_own_questions_that_stay(): void
    {
        $lesson = $this->lesson('2026-10-21', [], questions: 2);
        Question::factory()->for($lesson)->aiDrafted()->create();
        $this->actingAs($this->teacher);

        Livewire::test(Today::class)
            ->mountAction(TestAction::make('draftHomework')->table($lesson))
            ->assertMountedActionModalSee('The summary and the AI questions are replaced by a new AI draft. 2 questions of your own or edited by you are kept.');
    }

    public function test_the_draft_of_a_school_with_ai_carries_no_demo_hint(): void
    {
        $this->preventRealAiCalls();
        $this->enableAiFor($this->teacher->school);
        $lesson = $this->lesson('2026-10-21');
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->shouldReceive('draftHomework')->once()->andReturn([
            'summary' => 'Zusammenfassung',
            'questions' => [['type' => QuestionType::Comprehension, 'prompt' => 'Frage?', 'expected_answer' => 'Antwort', 'competency_id' => null]],
        ]));
        $this->actingAs($this->teacher);

        Livewire::test(Today::class)
            ->callAction(TestAction::make('draftHomework')->table($lesson))
            ->assertNotified(Notification::make()
                ->success()
                ->title('Homework created')
                ->body('Check the summary and the questions before you release the homework.')
                ->persistent());
    }

    public function test_a_failing_assistant_keeps_the_questions(): void
    {
        $lesson = $this->lesson('2026-10-21', [], questions: 1);
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->shouldReceive('draftHomework')->andThrow(new RuntimeException('API down')));
        $this->actingAs($this->teacher);

        Livewire::test(Today::class)
            ->callAction(TestAction::make('draftHomework')->table($lesson))
            ->assertNotified('AI draft failed');

        $this->assertSame(1, $lesson->questions()->count());
    }

    public function test_the_draft_is_not_offered_for_a_released_lesson(): void
    {
        $lesson = $this->lesson('2026-10-21', [], questions: 1);
        $lesson->update(['published_at' => now(), 'due_on' => today()->addWeek()]);
        $this->actingAs($this->teacher);

        Livewire::test(Today::class)
            ->assertActionHidden(TestAction::make('draftHomework')->table($lesson))
            ->assertActionHidden(TestAction::make('release')->table($lesson));
    }

    public function test_releasing_one_lesson_defaults_to_the_whole_class_and_seven_days(): void
    {
        $lesson = $this->lesson('2026-10-21', [], questions: 2);
        $students = User::factory()->student($lesson->schoolClass)->count(2)->create();
        $this->actingAs($this->teacher);

        Livewire::test(Today::class)
            ->mountAction(TestAction::make('release')->table($lesson))
            ->assertSchemaStateSet(['due_on' => today()->addDays(7)->toDateString()])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified('Homework released')
            ->assertTableColumnStateSet('status', 'Released', $lesson);

        $lesson->refresh();
        $this->assertTrue($lesson->isPublished());
        $this->assertTrue($lesson->due_on->isSameDay(today()->addDays(7)));
        $this->assertEqualsCanonicalizing($students->modelKeys(), $lesson->students()->pluck('users.id')->all());
    }

    public function test_a_lesson_without_questions_cannot_be_released(): void
    {
        $lesson = $this->lesson('2026-10-21');
        User::factory()->student($lesson->schoolClass)->create();
        $this->actingAs($this->teacher);

        Livewire::test(Today::class)
            ->assertActionDisabled(TestAction::make('release')->table($lesson))
            ->callAction(TestAction::make('release')->table($lesson), data: ['due_on' => today()->addDays(3)->toDateString()]);

        $this->assertFalse($lesson->refresh()->isPublished());
    }

    public function test_a_class_without_students_is_not_released(): void
    {
        $lesson = $this->lesson('2026-10-21', [], questions: 1);
        $this->actingAs($this->teacher);

        Livewire::test(Today::class)
            ->callAction(TestAction::make('release')->table($lesson), data: ['due_on' => today()->addDays(3)->toDateString()])
            ->assertNotified('Nothing released');

        $this->assertFalse($lesson->refresh()->isPublished());
    }

    public function test_a_lesson_in_a_class_the_teacher_no_longer_teaches_cannot_be_released(): void
    {
        $lesson = $this->lesson('2026-10-21', [], questions: 1);
        User::factory()->student($lesson->schoolClass)->create();
        $lesson->schoolClass->update(['teacher_id' => User::factory()->teacher()->for($this->teacher->school)->create()->id]);
        $this->actingAs($this->teacher);

        Livewire::test(Today::class)
            ->assertCanSeeTableRecords([$lesson])
            ->assertActionHidden(TestAction::make('release')->table($lesson))
            ->assertActionVisible(TestAction::make('draftHomework')->table($lesson))
            ->assertActionHidden('releaseAll');

        $this->assertFalse($lesson->refresh()->isPublished());
    }

    public function test_release_all_releases_only_the_lessons_that_are_ready_and_allowed(): void
    {
        $ready = $this->lesson('2026-10-21', [], questions: 2);
        $readyStudents = User::factory()->student($ready->schoolClass)->count(2)->create();
        $secondReady = $this->lesson('2026-10-20', [], questions: 1);
        User::factory()->student($secondReady->schoolClass)->create();

        $withoutQuestions = $this->lesson('2026-10-21');
        User::factory()->student($withoutQuestions->schoolClass)->create();

        $alreadyReleased = $this->lesson('2026-10-20', [], questions: 1);
        $alreadyReleased->update(['published_at' => now()->subDay(), 'due_on' => today()->addDays(2)]);
        $releasedStudent = User::factory()->student($alreadyReleased->schoolClass)->create();
        $alreadyReleased->students()->attach($releasedStudent);

        $lostClass = $this->lesson('2026-10-21', [], questions: 1);
        $lostStudent = User::factory()->student($lostClass->schoolClass)->create();
        $lostClass->schoolClass->update(['teacher_id' => User::factory()->teacher()->for($this->teacher->school)->create()->id]);

        $emptyClass = $this->lesson('2026-10-21', [], questions: 1);

        $tooOld = $this->lesson('2026-10-14', [], questions: 1);
        User::factory()->student($tooOld->schoolClass)->create();

        $colleagues = Lesson::factory()->has(Question::factory())->state(['held_on' => '2026-10-21'])->create();
        $colleaguesStudent = User::factory()->student($colleagues->schoolClass)->create();

        $this->actingAs($this->teacher);

        Livewire::test(Today::class)
            ->assertActionVisible('releaseAll')
            ->mountAction('releaseAll')
            ->assertMountedActionModalSee('2 homework assignments with questions are waiting for release')
            ->assertSchemaStateSet(['due_on' => today()->addDays(7)->toDateString()])
            ->setActionData(['due_on' => today()->addDays(4)->toDateString()])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified('2 homework assignments released')
            ->assertActionHidden('releaseAll');

        foreach ([$ready, $secondReady] as $lesson) {
            $this->assertTrue($lesson->refresh()->isPublished());
            $this->assertTrue($lesson->due_on->isSameDay(today()->addDays(4)));
        }
        $this->assertEqualsCanonicalizing($readyStudents->modelKeys(), $ready->students()->pluck('users.id')->all());

        foreach ([$withoutQuestions, $lostClass, $emptyClass, $tooOld, $colleagues] as $untouched) {
            $this->assertFalse($untouched->refresh()->isPublished());
            $this->assertSame(0, $untouched->students()->count());
        }
        $this->assertTrue($alreadyReleased->refresh()->due_on->isSameDay(today()->addDays(2)));
        $this->assertSame([$releasedStudent->id], $alreadyReleased->students()->pluck('users.id')->all());
        $this->assertFalse($lostClass->students()->whereKey($lostStudent->id)->exists());
        $this->assertFalse($colleagues->students()->whereKey($colleaguesStudent->id)->exists());
    }

    public function test_release_all_is_hidden_when_nothing_is_ready(): void
    {
        $this->lesson('2026-10-21');
        $this->actingAs($this->teacher);

        Livewire::test(Today::class)->assertActionHidden('releaseAll');
    }

    /**
     * A lesson of the signed-in teacher in a class they lead (a new one unless `$class` is given), with questions.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function lesson(string $heldOn, array $attributes = [], int $questions = 0, ?SchoolClass $class = null): Lesson
    {
        $class ??= SchoolClass::factory()->for($this->teacher, 'teacher')->create();

        return Lesson::factory()
            ->for($class)
            ->for($this->teacher, 'teacher')
            ->has(Question::factory()->count($questions))
            ->create(['held_on' => $heldOn, ...$attributes]);
    }
}
