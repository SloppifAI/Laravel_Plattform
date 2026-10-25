<?php

namespace Tests\Feature\Lessons;

use App\Filament\Pages\LessonStarter;
use App\Filament\Pages\Today;
use App\Filament\Resources\Lessons\LessonResource;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Filament\Resources\Lessons\RelationManagers\QuestionsRelationManager;
use App\Filament\Resources\Lessons\RelationManagers\SubmissionsRelationManager;
use App\Filament\Widgets\TeacherLessonsWidget;
use App\Filament\Widgets\TeacherStatsWidget;
use App\Models\Answer;
use App\Models\ClassSubstitution;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\StudentQuestion;
use App\Models\Submission;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\ViewAction;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * What the students handed in on the lesson page is for those who may read the lesson's results (LessonPolicy::viewResults).
 * A substitute whose period is over keeps the lesson as a draft but no longer sees hand-ins, answers or evaluations.
 */
class LessonResultsAccessTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private User $leader;

    private User $substitute;

    private Lesson $lesson;

    private Submission $submission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolClass = SchoolClass::factory()->create();
        $this->leader = $this->schoolClass->teacher;
        $this->substitute = User::factory()->teacher()->for($this->schoolClass->school)->create();
        $student = User::factory()->student($this->schoolClass)->create(['name' => 'Mia Hoffmann']);
        User::factory()->student($this->schoolClass)->create(['name' => 'Ben Schneider']);

        $this->lesson = Lesson::factory()->for($this->schoolClass)->for($this->substitute, 'teacher')->has(Question::factory())->publishedFor()->create();
        $this->submission = Submission::factory()->for($this->lesson)->for($student, 'student')->evaluated(80)->create();
        Answer::factory()->for($this->submission)->create(['answer' => 'Licht und Wasser']);
    }

    public function test_a_substitute_in_the_period_reads_the_hand_ins_of_their_lesson(): void
    {
        $this->substitution(ended: false);

        $this->openSubmissions($this->substitute)
            ->assertOk()
            ->assertCanSeeTableRecords([$this->submission])
            ->assertSee('Not handed in yet (1): Ben Schneider')
            ->mountAction(TestAction::make(ViewAction::class)->table($this->submission))
            ->assertMountedActionModalSee('Licht und Wasser');

        $this->actingAs($this->substitute)->get(LessonResource::getUrl('edit', ['record' => $this->lesson]))->assertOk();
        $this->assertContains(SubmissionsRelationManager::class, $this->relationManagersOf($this->substitute));
    }

    public function test_an_expired_substitute_cannot_open_the_hand_ins_of_their_lesson(): void
    {
        $this->substitution(ended: true);

        $this->openSubmissions($this->substitute)->assertForbidden();
    }

    public function test_an_expired_substitute_keeps_the_lesson_page_but_it_shows_no_results(): void
    {
        $this->substitution(ended: true);

        $this->actingAs($this->substitute)
            ->get(LessonResource::getUrl('edit', ['record' => $this->lesson]))
            ->assertOk()
            ->assertDontSee('Mia Hoffmann')
            ->assertDontSee('Ben Schneider')
            ->assertDontSee('Not handed in yet');

        $managers = $this->relationManagersOf($this->substitute);
        $this->assertContains(QuestionsRelationManager::class, $managers);
        $this->assertNotContains(SubmissionsRelationManager::class, $managers);

        // The lesson stays theirs: only the class's results are gone, and with them the right to delete the lesson.
        Livewire::actingAs($this->substitute)->test(EditLesson::class, ['record' => $this->lesson->getRouteKey()])
            ->assertOk()
            ->assertActionHidden('starter')
            ->assertActionHidden('delete');
    }

    public function test_an_expired_substitute_cannot_delete_a_lesson_that_holds_hand_ins(): void
    {
        $this->substitution(ended: true);

        $this->assertFalse($this->substitute->can('delete', $this->lesson));

        Livewire::actingAs($this->substitute)->test(EditLesson::class, ['record' => $this->lesson->getRouteKey()])
            ->assertActionHidden('delete');

        Livewire::actingAs($this->substitute)->test(ListLessons::class)
            ->callTableBulkAction('delete', [$this->lesson]);

        $this->assertModelExists($this->lesson);
        $this->assertModelExists($this->submission);
    }

    public function test_an_expired_substitute_cannot_delete_a_lesson_that_holds_student_questions(): void
    {
        $this->submission->delete();
        StudentQuestion::factory()->create(['lesson_id' => $this->lesson->id, 'student_id' => $this->submission->student_id]);
        $this->substitution(ended: true);

        $this->assertFalse($this->substitute->can('delete', $this->lesson));

        Livewire::actingAs($this->substitute)->test(ListLessons::class)
            ->callTableBulkAction('delete', [$this->lesson]);

        $this->assertModelExists($this->lesson);
        $this->assertSame(1, StudentQuestion::query()->count());
    }

    public function test_an_expired_substitute_deletes_a_lesson_without_student_data(): void
    {
        $this->submission->delete();
        $this->substitution(ended: true);

        $this->assertTrue($this->substitute->can('delete', $this->lesson));

        Livewire::actingAs($this->substitute)->test(EditLesson::class, ['record' => $this->lesson->getRouteKey()])
            ->assertActionVisible('delete')
            ->callAction('delete')
            ->assertNotified();

        $this->assertModelMissing($this->lesson);
    }

    public function test_the_lessons_teacher_a_substitute_in_the_period_and_the_administration_delete_a_lesson_with_hand_ins(): void
    {
        $admin = User::factory()->admin()->for($this->schoolClass->school)->create();
        $this->substitution(ended: false);

        $this->assertTrue($this->substitute->can('delete', $this->lesson));
        $this->assertTrue($admin->can('delete', $this->lesson));

        $this->lesson->update(['teacher_id' => $this->leader->id]);
        $this->assertTrue($this->leader->can('delete', $this->lesson));
        $this->assertFalse($this->substitute->can('delete', $this->lesson));

        Livewire::actingAs($this->leader)->test(ListLessons::class)
            ->callTableBulkAction('delete', [$this->lesson])
            ->assertNotified();

        $this->assertModelMissing($this->lesson);
        $this->assertModelMissing($this->submission);
    }

    public function test_an_expired_substitute_cannot_open_the_starter_board_either(): void
    {
        $this->substitution(ended: true);

        $this->actingAs($this->substitute)->get(LessonStarter::getUrl(['lesson' => $this->lesson]))->assertForbidden();
    }

    public function test_the_lesson_list_shows_no_hand_in_count_and_no_average_of_an_ended_substitution(): void
    {
        // A Förderaufgabe for one student: the "average" would be that student's own score.
        $mia = $this->schoolClass->students()->where('name', 'Mia Hoffmann')->sole();
        $task = Lesson::factory()->for($this->schoolClass)->for($this->substitute, 'teacher')->publishedFor([$mia])
            ->create(['practice_competency_id' => Competency::factory()->create()->id, 'topic' => 'Förderaufgabe: Brüche']);
        Submission::factory()->for($task)->for($mia, 'student')->evaluated(23)->create();

        $this->substitution(ended: false);

        Livewire::actingAs($this->substitute)->test(ListLessons::class)
            ->assertSee('Förderaufgabe: Brüche')
            ->assertSee('23 %')
            ->assertSee('1 / 1');

        ClassSubstitution::query()->delete();
        $this->substitution(ended: true);

        Livewire::actingAs($this->substitute)->test(ListLessons::class)
            ->assertSee('Förderaufgabe: Brüche')
            ->assertCanSeeTableRecords([$task, $this->lesson])
            ->assertDontSee('23 %')
            ->assertDontSee('80 %')
            ->assertDontSee('1 / 1')
            ->assertDontSee('1 / 2');
    }

    public function test_heute_shows_no_hand_in_count_of_an_ended_substitution(): void
    {
        // A Wednesday: "Heute" lists the lessons held today.
        $this->travelTo('2026-10-21 10:00:00');
        $this->lesson->update(['held_on' => today()]);

        $this->substitution(ended: false);

        Livewire::actingAs($this->substitute)->test(Today::class)->assertCanSeeTableRecords([$this->lesson])->assertSee('1 / 2');

        ClassSubstitution::query()->delete();
        $this->substitution(ended: true);

        Livewire::actingAs($this->substitute)->test(Today::class)->assertCanSeeTableRecords([$this->lesson])->assertDontSee('1 / 2');
    }

    public function test_the_dashboard_widgets_leave_out_the_results_of_an_ended_substitution(): void
    {
        // Their own class, where they still teach and may read the results: the one the figures keep.
        $ownClass = SchoolClass::factory()->for($this->substitute, 'teacher')->create();
        $own = Lesson::factory()->for($ownClass)->for($this->substitute, 'teacher')->has(Question::factory())->publishedFor([User::factory()->student($ownClass)->create()])->create(['topic' => 'Eigene Stunde']);
        // The newest released homework is the one of the substitution.
        $this->lesson->update(['published_at' => now()->addMinute()]);

        $this->substitution(ended: false);

        $stats = $this->statsOf();
        $this->assertSame('1 / 3', $stats['Submissions for open homework'][0]);
        $this->assertSame('80 %', $stats['Avg. comprehension of the last homework'][0]);
        Livewire::actingAs($this->substitute)->test(TeacherLessonsWidget::class)->assertSee('1 / 2 handed in');

        ClassSubstitution::query()->delete();
        $this->substitution(ended: true);

        $stats = $this->statsOf();
        $this->assertSame('0 / 1', $stats['Submissions for open homework'][0]);
        $this->assertSame('–', $stats['Avg. comprehension of the last homework'][0]);
        $this->assertStringContainsString('Eigene Stunde', $stats['Avg. comprehension of the last homework'][1]);

        Livewire::actingAs($this->substitute)->test(TeacherLessonsWidget::class)
            ->assertSee($this->lesson->topic)
            ->assertSee($own->topic)
            ->assertSee('0 / 1 handed in')
            ->assertDontSee('1 / 2 handed in');
    }

    public function test_the_lessons_teacher_and_the_administration_read_the_results(): void
    {
        $admin = User::factory()->admin()->for($this->schoolClass->school)->create();
        $this->lesson->update(['teacher_id' => $this->leader->id]);

        foreach ([$this->leader, $admin] as $user) {
            $this->openSubmissions($user)->assertOk()->assertCanSeeTableRecords([$this->submission]);
        }

        Livewire::actingAs($this->leader)->test(EditLesson::class, ['record' => $this->lesson->getRouteKey()])->assertActionVisible('starter');
        $this->actingAs($this->leader)->get(LessonStarter::getUrl(['lesson' => $this->lesson]))->assertOk();
    }

    public function test_a_colleague_who_did_not_hold_the_lesson_gets_nothing(): void
    {
        $colleague = User::factory()->teacher()->for($this->schoolClass->school)->create();

        $this->openSubmissions($colleague)->assertForbidden();
    }

    private function openSubmissions(User $user)
    {
        return Livewire::actingAs($user)->test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class]);
    }

    /**
     * @return list<class-string>
     */
    private function relationManagersOf(User $user): array
    {
        return array_values(Livewire::actingAs($user)->test(EditLesson::class, ['record' => $this->lesson->getRouteKey()])->instance()->getRelationManagers());
    }

    /**
     * @return array<string, array{0: mixed, 1: string}> label => value and description
     */
    private function statsOf(): array
    {
        $component = Livewire::actingAs($this->substitute)->test(TeacherStatsWidget::class)->instance();

        return collect((new ReflectionMethod($component, 'getStats'))->invoke($component))
            ->mapWithKeys(fn (Stat $stat): array => [(string) $stat->getLabel() => [$stat->getValue(), (string) $stat->getDescription()]])
            ->all();
    }

    private function substitution(bool $ended): void
    {
        $factory = ClassSubstitution::factory()->for($this->schoolClass);

        ($ended ? $factory->ended() : $factory)->create(['substitute_id' => $this->substitute->id]);
    }
}
