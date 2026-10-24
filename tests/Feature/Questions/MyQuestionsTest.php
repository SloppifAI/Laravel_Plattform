<?php

namespace Tests\Feature\Questions;

use App\Filament\Pages\MyQuestions;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\StudentQuestion;
use App\Models\User;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyQuestionsTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private User $student;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolClass = SchoolClass::factory()->create();
        $this->student = User::factory()->student($this->schoolClass)->create();
        $this->lesson = Lesson::factory()->for($this->schoolClass)->publishedFor()->create();
    }

    public function test_only_students_open_the_page(): void
    {
        $this->actingAs($this->student)->get('/meine-rueckfragen')->assertOk()->assertSee('Meine Rückfragen');
        $this->actingAs($this->lesson->teacher)->get('/meine-rueckfragen')->assertForbidden();
        $this->actingAs(User::factory()->admin()->for($this->schoolClass->school)->create())->get('/meine-rueckfragen')->assertForbidden();
        $this->actingAs(User::factory()->operator()->create())->get('/meine-rueckfragen')->assertForbidden();
    }

    public function test_a_student_sees_only_their_own_questions_never_those_of_classmates(): void
    {
        $own = StudentQuestion::factory()->for($this->student, 'student')->for($this->lesson)->create();
        $classmate = StudentQuestion::factory()
            ->for(User::factory()->student($this->schoolClass), 'student')
            ->for($this->lesson)
            ->answered('Geheime Antwort an die Klassenkameradin.')
            ->create();

        Livewire::actingAs($this->student)
            ->test(MyQuestions::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$classmate])
            ->assertSee($own->question)
            ->assertDontSee($classmate->question)
            ->assertDontSee('Geheime Antwort');
    }

    public function test_answers_the_teacher_and_the_state_of_each_question_are_shown(): void
    {
        $open = StudentQuestion::factory()->for($this->student, 'student')->for($this->lesson)->create();
        $answered = StudentQuestion::factory()->for($this->student, 'student')->for($this->lesson)->answered('Weil Chlorophyll rotes Licht schluckt.')->create();
        $markedDone = StudentQuestion::factory()->for($this->student, 'student')->for($this->lesson)->create(['answered_at' => now(), 'answered_by' => $this->lesson->teacher_id]);
        $read = StudentQuestion::factory()->for($this->student, 'student')->for($this->lesson)->answered('Das war schon klar.')->read()->create();

        Livewire::actingAs($this->student)
            ->test(MyQuestions::class)
            ->assertSee([
                'Wartet auf Antwort',
                'Weil Chlorophyll rotes Licht schluckt.',
                $this->lesson->teacher->name,
                'Neue Antwort',
                'Erledigt',
                'Deine Lehrkraft hat die Frage im Unterricht besprochen oder erledigt.',
                'Gelesen',
            ])
            ->assertActionHidden(TestAction::make('markAsRead')->table($open))
            ->assertActionVisible(TestAction::make('markAsRead')->table($answered))
            ->assertActionVisible(TestAction::make('markAsRead')->table($markedDone))
            ->assertActionHidden(TestAction::make('markAsRead')->table($read));
    }

    public function test_new_answers_come_first(): void
    {
        $oldRead = StudentQuestion::factory()->for($this->student, 'student')->for($this->lesson)->answered()->read()->create();
        $oldUnread = StudentQuestion::factory()->for($this->student, 'student')->for($this->lesson)->answered()->create();
        $newest = StudentQuestion::factory()->for($this->student, 'student')->for($this->lesson)->create();

        Livewire::actingAs($this->student)
            ->test(MyQuestions::class)
            ->assertCanSeeTableRecords([$oldUnread, $newest, $oldRead], inOrder: true);
    }

    public function test_marking_an_answer_as_read_clears_the_badge(): void
    {
        $answered = StudentQuestion::factory()->for($this->student, 'student')->for($this->lesson)->answered()->create();
        StudentQuestion::factory()->for($this->student, 'student')->for($this->lesson)->answered()->create();
        StudentQuestion::factory()->for($this->student, 'student')->for($this->lesson)->create();
        StudentQuestion::factory()->for($this->student, 'student')->for($this->lesson)->answered()->read()->create();
        StudentQuestion::factory()->for(User::factory()->student($this->schoolClass), 'student')->for($this->lesson)->answered()->create();

        $this->actingAs($this->student);
        $this->assertSame('2', MyQuestions::getNavigationBadge());

        Livewire::test(MyQuestions::class)->callAction(TestAction::make('markAsRead')->table($answered));

        $this->assertNotNull($answered->refresh()->read_at);
        $this->assertSame('1', MyQuestions::getNavigationBadge());
    }

    public function test_a_classmates_answer_cannot_be_marked_as_read(): void
    {
        $classmates = StudentQuestion::factory()
            ->for(User::factory()->student($this->schoolClass), 'student')
            ->for($this->lesson)
            ->answered()
            ->create();

        $page = Livewire::actingAs($this->student)->test(MyQuestions::class);

        $this->assertFalse($this->student->can('markAsRead', $classmates));
        $this->assertFalse($this->lesson->teacher->can('markAsRead', $classmates));

        try {
            $page->callAction(TestAction::make('markAsRead')->table($classmates));
            $this->fail('The question of a classmate must not be part of the table.');
        } catch (ActionNotResolvableException) {
            $this->assertNull($classmates->refresh()->read_at);
        }
    }

    public function test_the_empty_state_points_to_explain_it(): void
    {
        Livewire::actingAs($this->student)
            ->test(MyQuestions::class)
            ->assertSee('Du hast noch keine Rückfragen gestellt')
            ->assertSee('Zu Erklär’s mir');

        $this->assertNull(MyQuestions::getNavigationBadge());
    }
}
