<?php

namespace Tests\Feature\ExplanationSessions;

use App\Filament\Resources\ExplanationSessions\ExplanationSessionResource;
use App\Filament\Resources\ExplanationSessions\Pages\ListExplanationSessions;
use App\Filament\Resources\ExplanationSessions\Pages\ViewExplanationSession;
use App\MessageAuthor;
use App\Models\ExplanationMessage;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExplanationSessionResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_sees_only_sessions_of_own_lessons(): void
    {
        $teacher = User::factory()->teacher()->create();
        $own = ExplanationSession::factory()->for(Lesson::factory()->for($teacher, 'teacher'))->create();
        $foreign = ExplanationSession::factory()->for(Lesson::factory())->create();
        $withoutLesson = ExplanationSession::factory()->create();

        Livewire::actingAs($teacher)
            ->test(ListExplanationSessions::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign, $withoutLesson]);
    }

    public function test_admin_sees_all_sessions_including_those_without_lesson(): void
    {
        $sessions = collect([
            ExplanationSession::factory()->for(Lesson::factory())->create(),
            ExplanationSession::factory()->for(Lesson::factory())->create(),
            ExplanationSession::factory()->create(),
        ]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListExplanationSessions::class)
            ->assertCanSeeTableRecords($sessions);
    }

    public function test_students_cannot_open_the_resource(): void
    {
        $student = User::factory()->student()->create();
        $ownSession = ExplanationSession::factory()->for($student, 'student')->for(Lesson::factory())->create();

        $this->actingAs($student)
            ->get(ExplanationSessionResource::getUrl('index'))
            ->assertForbidden();

        $this->actingAs($student)
            ->get(ExplanationSessionResource::getUrl('view', ['record' => $ownSession]))
            ->assertNotFound();
    }

    public function test_the_resource_is_only_accessible_for_teachers_and_admins(): void
    {
        $this->actingAs(User::factory()->student()->create());
        $this->assertFalse(ExplanationSessionResource::canAccess());

        $this->actingAs(User::factory()->teacher()->create());
        $this->assertTrue(ExplanationSessionResource::canAccess());

        $this->actingAs(User::factory()->admin()->create());
        $this->assertTrue(ExplanationSessionResource::canAccess());
    }

    public function test_view_page_shows_the_transcript_in_order_with_author_labels(): void
    {
        $teacher = User::factory()->teacher()->create();
        $session = ExplanationSession::factory()->for(Lesson::factory()->for($teacher, 'teacher'))->create();
        ExplanationMessage::factory()->for($session, 'session')->create(['author' => MessageAuthor::Tutor, 'content' => 'Erste Frage der KI']);
        ExplanationMessage::factory()->for($session, 'session')->create(['author' => MessageAuthor::Student, 'content' => 'Zweite Antwort der Schülerin']);
        ExplanationMessage::factory()->for($session, 'session')->create(['author' => MessageAuthor::Tutor, 'content' => 'Dritte Nachfrage der KI']);

        Livewire::actingAs($teacher)
            ->test(ViewExplanationSession::class, ['record' => $session->getRouteKey()])
            ->assertOk()
            ->assertSeeInOrder(['Tuto', 'Erste Frage der KI', 'Schüler:in', 'Zweite Antwort der Schülerin', 'Tuto', 'Dritte Nachfrage der KI']);
    }

    public function test_view_page_shows_student_topic_level_and_feedback_of_a_finished_session(): void
    {
        $teacher = User::factory()->teacher()->create();
        $student = User::factory()->student(SchoolClass::factory()->create(['name' => '7b']))->create(['name' => 'Mia Beispiel']);
        $lesson = Lesson::factory()->for($teacher, 'teacher')->create(['subject' => 'Biologie', 'topic' => 'Die Zelle']);
        $session = ExplanationSession::factory()->for($student, 'student')->for($lesson)->finished(82)
            ->create(['topic' => 'Zellkern erklärt', 'feedback' => 'Sehr anschaulich erklärt.']);

        Livewire::actingAs($teacher)
            ->test(ViewExplanationSession::class, ['record' => $session->getRouteKey()])
            ->assertSeeInOrder(['Mia Beispiel', '7b', 'Zellkern erklärt', 'Biologie: Die Zelle', 'Beendet', '82 % · Gut verstanden', 'Sehr anschaulich erklärt.']);
    }

    public function test_view_page_of_a_running_session_says_that_there_is_no_result_yet(): void
    {
        $teacher = User::factory()->teacher()->create();
        $session = ExplanationSession::factory()->for(Lesson::factory()->for($teacher, 'teacher'))->create();

        Livewire::actingAs($teacher)
            ->test(ViewExplanationSession::class, ['record' => $session->getRouteKey()])
            ->assertSee('Läuft')
            ->assertSee('Noch nicht ausgewertet')
            ->assertSee('Es gibt noch keine Nachrichten in dieser Sitzung.');
    }

    public function test_teacher_cannot_open_sessions_of_other_teachers_or_without_lesson(): void
    {
        $teacher = User::factory()->teacher()->create();
        $foreign = ExplanationSession::factory()->for(Lesson::factory())->create();
        $withoutLesson = ExplanationSession::factory()->create();

        $this->actingAs($teacher)
            ->get(ExplanationSessionResource::getUrl('view', ['record' => $foreign]))
            ->assertNotFound();

        $this->actingAs($teacher)
            ->get(ExplanationSessionResource::getUrl('view', ['record' => $withoutLesson]))
            ->assertNotFound();
    }

    public function test_teacher_opens_a_session_of_an_own_lesson_over_http(): void
    {
        $teacher = User::factory()->teacher()->create();
        $session = ExplanationSession::factory()->for(Lesson::factory()->for($teacher, 'teacher'))->create(['topic' => 'Fotosynthese']);

        $this->actingAs($teacher)
            ->get(ExplanationSessionResource::getUrl('view', ['record' => $session]))
            ->assertOk()
            ->assertSee('Fotosynthese');
    }

    public function test_admin_opens_a_session_without_lesson(): void
    {
        $session = ExplanationSession::factory()->create(['topic' => 'Bruchrechnen']);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ViewExplanationSession::class, ['record' => $session->getRouteKey()])
            ->assertOk()
            ->assertSee('Bruchrechnen')
            ->assertSee('Ohne Unterrichtsstunde');
    }

    public function test_table_shows_status_and_understanding_level(): void
    {
        $teacher = User::factory()->teacher()->create();
        $lesson = Lesson::factory()->for($teacher, 'teacher')->create();
        $finished = ExplanationSession::factory()->for($lesson)->finished(40)->create();
        $running = ExplanationSession::factory()->for($lesson)->create();

        Livewire::actingAs($teacher)
            ->test(ListExplanationSessions::class)
            ->assertTableColumnFormattedStateSet('status', 'Beendet', $finished)
            ->assertTableColumnFormattedStateSet('understanding_score', '40 % · Förderbedarf', $finished)
            ->assertTableColumnFormattedStateSet('status', 'Läuft', $running)
            ->assertTableColumnStateSet('understanding_score', null, $running);
    }

    public function test_table_lists_the_newest_session_first(): void
    {
        $teacher = User::factory()->teacher()->create();
        $lesson = Lesson::factory()->for($teacher, 'teacher')->create();
        $oldest = ExplanationSession::factory()->for($lesson)->create(['created_at' => now()->subDays(3)]);
        $newest = ExplanationSession::factory()->for($lesson)->create(['created_at' => now()->subHour()]);
        $middle = ExplanationSession::factory()->for($lesson)->create(['created_at' => now()->subDay()]);

        Livewire::actingAs($teacher)
            ->test(ListExplanationSessions::class)
            ->assertCanSeeTableRecords([$newest, $middle, $oldest], inOrder: true);
    }

    public function test_table_filters_by_lesson_and_status(): void
    {
        $teacher = User::factory()->teacher()->create();
        $lessonA = Lesson::factory()->for($teacher, 'teacher')->create();
        $lessonB = Lesson::factory()->for($teacher, 'teacher')->create();
        $finishedA = ExplanationSession::factory()->for($lessonA)->finished()->create();
        $runningA = ExplanationSession::factory()->for($lessonA)->create();
        $finishedB = ExplanationSession::factory()->for($lessonB)->finished()->create();

        $list = Livewire::actingAs($teacher)->test(ListExplanationSessions::class);

        $list->filterTable('lesson_id', $lessonA->id)
            ->assertCanSeeTableRecords([$finishedA, $runningA])
            ->assertCanNotSeeTableRecords([$finishedB]);

        $list->removeTableFilters()
            ->filterTable('finished_at', true)
            ->assertCanSeeTableRecords([$finishedA, $finishedB])
            ->assertCanNotSeeTableRecords([$runningA]);

        $list->removeTableFilters()
            ->filterTable('finished_at', false)
            ->assertCanSeeTableRecords([$runningA])
            ->assertCanNotSeeTableRecords([$finishedA, $finishedB]);
    }

    public function test_lesson_filter_does_not_offer_lessons_of_other_teachers(): void
    {
        $teacher = User::factory()->teacher()->create();
        $own = Lesson::factory()->for($teacher, 'teacher')->create();
        $foreign = Lesson::factory()->create();

        $options = Livewire::actingAs($teacher)
            ->test(ListExplanationSessions::class)
            ->instance()
            ->getTable()
            ->getFilter('lesson_id')
            ->getOptions();

        $this->assertSame([$own->id], array_keys($options));
        $this->assertArrayNotHasKey($foreign->id, $options);
    }

    public function test_the_resource_is_read_only(): void
    {
        $this->assertSame(['index', 'view'], array_keys(ExplanationSessionResource::getPages()));

        $teacher = User::factory()->teacher()->create();
        $session = ExplanationSession::factory()->for(Lesson::factory()->for($teacher, 'teacher'))->create();

        Livewire::actingAs($teacher)
            ->test(ListExplanationSessions::class)
            ->assertTableActionExists('view', record: $session)
            ->assertTableActionDoesNotExist('edit', record: $session)
            ->assertTableActionDoesNotExist('delete', record: $session)
            ->assertActionDoesNotExist('create');
    }

    public function test_admin_cannot_delete_a_session_from_the_resource(): void
    {
        $session = ExplanationSession::factory()->for(Lesson::factory())->create();

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ViewExplanationSession::class, ['record' => $session->getRouteKey()])
            ->assertActionDoesNotExist('delete')
            ->assertActionDoesNotExist('edit');
    }
}
