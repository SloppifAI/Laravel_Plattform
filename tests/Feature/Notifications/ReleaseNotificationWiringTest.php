<?php

namespace Tests\Feature\Notifications;

use App\Ai\LessonAssistant;
use App\Filament\Pages\Today;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\User;
use App\QuestionType;
use App\Support\LessonRelease;
use App\Support\PracticeTasks;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Every way homework goes out ends in LessonRelease (lesson page, "Heute" one by one and all at once) or, for a
 * Förderaufgabe made with a due date, in PracticeTasks: the students reached get one bell entry each, once per real
 * release and never for a withdrawal or a release that reached nobody.
 */
class ReleaseNotificationWiringTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, so "Heute" lists the lessons held today.
        $this->travelTo('2026-10-21 10:00:00');
        $this->schoolClass = SchoolClass::factory()->create();
        $this->teacher = $this->schoolClass->teacher;
    }

    public function test_releasing_on_the_lesson_page_notifies_every_student_of_the_class_once(): void
    {
        $students = User::factory()->student($this->schoolClass)->count(3)->create();
        $otherClass = User::factory()->student(SchoolClass::factory()->for($this->schoolClass->school)->create())->create();
        $lesson = $this->draft(['topic' => 'Fotosynthese']);

        Livewire::actingAs($this->teacher)->test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->callAction('delegate', data: ['due_on' => today()->addDays(3)->toDateString(), 'audience' => 'class'])
            ->assertNotified('Homework released');

        foreach ($students as $student) {
            $this->assertSame('New homework: “Fotosynthese”', $student->notifications()->sole()->data['title']);
        }
        $this->assertSame(0, $otherClass->notifications()->count());
        $this->assertSame(0, $this->teacher->notifications()->count());
    }

    public function test_releasing_to_chosen_students_notifies_only_them(): void
    {
        [$first, $second, $third] = User::factory()->student($this->schoolClass)->count(3)->create();
        $lesson = $this->draft();

        Livewire::actingAs($this->teacher)->test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->callAction('delegate', data: ['due_on' => today()->addDays(3)->toDateString(), 'audience' => 'selected', 'students' => [$first->id, $third->id]]);

        $this->assertSame(1, $first->notifications()->count());
        $this->assertSame(1, $third->notifications()->count());
        $this->assertSame(0, $second->notifications()->count());
    }

    public function test_a_practice_task_released_from_its_draft_says_so_to_the_chosen_students(): void
    {
        [$chosen, $left] = User::factory()->student($this->schoolClass)->count(2)->create();
        $task = $this->draft(['topic' => 'Förderaufgabe: Brüche', 'practice_competency_id' => Competency::factory()->create()->id]);
        $task->students()->sync([$chosen->id]);

        Livewire::actingAs($this->teacher)->test(EditLesson::class, ['record' => $task->getRouteKey()])
            ->callAction('delegate', data: ['due_on' => today()->addDays(3)->toDateString(), 'audience' => 'selected', 'students' => [$chosen->id]]);

        $this->assertSame('New practice task: “Förderaufgabe: Brüche”', $chosen->notifications()->sole()->data['title']);
        $this->assertSame(0, $left->notifications()->count());
    }

    public function test_a_practice_task_made_with_a_due_date_notifies_its_students_and_a_draft_nobody(): void
    {
        [$chosen, $other] = User::factory()->student($this->schoolClass)->count(2)->create();
        $competency = Competency::factory()->create();
        $this->mock(LessonAssistant::class, fn ($mock) => $mock->shouldReceive('draftPracticeTask')->twice()->andReturn([
            'summary' => 'Übe die Brüche.',
            'questions' => [['type' => QuestionType::Comprehension, 'prompt' => 'Was ist ein Bruch?', 'expected_answer' => 'Ein Teil eines Ganzen']],
        ]));
        $support = ['student_ids' => [], 'lesson_ids' => []];

        $draft = PracticeTasks::create($this->teacher, $this->schoolClass, $competency, $support, [$chosen->id]);

        $this->assertFalse($draft->isPublished());
        $this->assertSame(0, $chosen->notifications()->count());

        $published = PracticeTasks::create($this->teacher, $this->schoolClass, $competency, $support, [$chosen->id], today()->addDays(5));

        $this->assertTrue($published->isPublished());
        $this->assertStringStartsWith('New practice task', $chosen->notifications()->sole()->data['title']);
        $this->assertSame(0, $other->notifications()->count());
    }

    public function test_releasing_one_lesson_on_heute_notifies_the_class_once(): void
    {
        $students = User::factory()->student($this->schoolClass)->count(2)->create();
        $lesson = $this->draft(['held_on' => today()]);

        Livewire::actingAs($this->teacher)->test(Today::class)
            ->callAction(TestAction::make('release')->table($lesson), data: ['due_on' => today()->addDays(3)->toDateString()])
            ->assertNotified('Homework released');

        foreach ($students as $student) {
            $this->assertSame(1, $student->notifications()->count());
        }
    }

    public function test_releasing_all_on_heute_notifies_the_students_of_each_lesson_once(): void
    {
        $firstStudent = User::factory()->student($this->schoolClass)->create();
        $secondClass = SchoolClass::factory()->for($this->teacher, 'teacher')->create();
        $secondStudent = User::factory()->student($secondClass)->create();
        $this->draft(['held_on' => today(), 'topic' => 'Zellen']);
        $this->draft(['held_on' => today(), 'topic' => 'Atmung'], $secondClass);
        $notReady = $this->draft(['held_on' => today()]);
        $notReady->questions()->delete();

        Livewire::actingAs($this->teacher)->test(Today::class)
            ->callAction('releaseAll', data: ['due_on' => today()->addDays(3)->toDateString()])
            ->assertNotified('2 homework assignments released');

        $this->assertSame('New homework: “Zellen”', $firstStudent->notifications()->sole()->data['title']);
        $this->assertSame('New homework: “Atmung”', $secondStudent->notifications()->sole()->data['title']);
    }

    public function test_a_release_that_does_nothing_notifies_nobody(): void
    {
        $lesson = $this->draft();

        $this->assertSame(0, LessonRelease::release($lesson, $this->teacher), 'class without students');
        $this->assertSame(0, $this->bellEntries());

        $student = User::factory()->student($this->schoolClass)->create();
        $this->assertSame(0, LessonRelease::release($lesson, $this->teacher, null, []), 'nobody chosen');
        $this->assertSame(0, LessonRelease::release($lesson, $this->teacher, null, [User::factory()->student()->create()->id]), 'a student of another class');
        $this->assertSame(0, $this->bellEntries());

        $this->assertSame(1, LessonRelease::release($lesson, $this->teacher));
        $this->assertSame(1, $student->notifications()->count());

        $this->assertSame(0, LessonRelease::release($lesson->refresh(), $this->teacher), 'released already');
        $this->assertSame(1, $this->bellEntries());
    }

    public function test_withdrawing_notifies_nobody_and_a_new_release_is_told_again(): void
    {
        $student = User::factory()->student($this->schoolClass)->create();
        $lesson = $this->draft();
        LessonRelease::release($lesson, $this->teacher);

        Livewire::actingAs($this->teacher)->test(EditLesson::class, ['record' => $lesson->getRouteKey()])->callAction('withdraw');

        $this->assertFalse($lesson->refresh()->isPublished());
        $this->assertSame(1, $this->bellEntries());

        LessonRelease::release($lesson, $this->teacher);

        $this->assertSame(2, $student->notifications()->count());
    }

    public function test_two_releases_running_at_once_notify_the_students_only_once(): void
    {
        $student = User::factory()->student($this->schoolClass)->create();
        $first = $this->draft();
        // The second tab loaded the lesson before the first release went through.
        $second = Lesson::query()->findOrFail($first->getKey());

        $this->assertSame(1, LessonRelease::release($first, $this->teacher, today()->addDays(3)));
        $this->assertSame(0, LessonRelease::release($second, $this->teacher, today()->addDays(10)));

        $this->assertSame(1, $student->notifications()->count());
        $this->assertTrue($first->refresh()->due_on->isSameDay(today()->addDays(3)));
        $this->assertSame([$student->id], $first->students()->pluck('users.id')->all());
    }

    public function test_a_failing_bell_does_not_turn_a_release_into_an_error(): void
    {
        $student = User::factory()->student($this->schoolClass)->create();
        $lesson = $this->draft();
        Event::listen(NotificationSending::class, fn () => throw new RuntimeException('bell down'));

        $this->assertSame(1, LessonRelease::release($lesson, $this->teacher));

        $this->assertTrue($lesson->refresh()->isPublished());
        $this->assertSame([$student->id], $lesson->students()->pluck('users.id')->all());
        $this->assertSame(0, $student->notifications()->count());
    }

    public function test_a_failing_bell_does_not_make_a_published_practice_task_look_like_it_failed(): void
    {
        $student = User::factory()->student($this->schoolClass)->create();
        $this->mock(LessonAssistant::class, fn ($mock) => $mock->shouldReceive('draftPracticeTask')->once()->andReturn([
            'summary' => 'Übe die Brüche.',
            'questions' => [['type' => QuestionType::Comprehension, 'prompt' => 'Was ist ein Bruch?', 'expected_answer' => 'Ein Teil eines Ganzen']],
        ]));
        Event::listen(NotificationSending::class, fn () => throw new RuntimeException('bell down'));

        $task = PracticeTasks::create($this->teacher, $this->schoolClass, Competency::factory()->create(), ['student_ids' => [], 'lesson_ids' => []], [$student->id], today()->addDays(5));

        $this->assertTrue($task->isPublished());
        $this->assertSame(1, Lesson::query()->whereNotNull('practice_competency_id')->count());
    }

    public function test_a_release_by_someone_who_may_not_publish_notifies_nobody(): void
    {
        User::factory()->student($this->schoolClass)->create();
        $lesson = $this->draft();

        try {
            LessonRelease::release($lesson, User::factory()->teacher()->for($this->schoolClass->school)->create());
            $this->fail('A colleague released the lesson.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $this->bellEntries());
        }
    }

    /**
     * A draft with one question, held by the Klassenleitung in the given class.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function draft(array $attributes = [], ?SchoolClass $schoolClass = null): Lesson
    {
        return Lesson::factory()
            ->for($schoolClass ?? $this->schoolClass)
            ->for($this->teacher, 'teacher')
            ->has(Question::factory())
            ->create($attributes);
    }

    private function bellEntries(): int
    {
        return DB::table('notifications')->count();
    }
}
