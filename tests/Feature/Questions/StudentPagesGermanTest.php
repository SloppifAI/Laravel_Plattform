<?php

namespace Tests\Feature\Questions;

use App\Filament\Pages\ExplainIt;
use App\Filament\Pages\MyQuestions;
use App\Filament\Pages\QuestionInbox;
use App\Filament\Pages\WorkOnHomework;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\StudentQuestion;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The suite runs in English; the platform default for real is German. These smoke tests switch it back and check that
 * the student pages and the teacher's question inbox really follow the language of the user.
 */
class StudentPagesGermanTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $schoolClass = SchoolClass::factory()->create();
        $this->student = User::factory()->student($schoolClass)->create(['name' => 'Mia Hoffmann']);
        $this->lesson = Lesson::factory()->for($schoolClass)->publishedFor([$this->student])->create(['topic' => 'Fotosynthese', 'due_on' => today()->addDay()]);
        Question::factory()->for($this->lesson)->create(['prompt' => 'Was braucht eine Pflanze?', 'position' => 1]);
    }

    private function speakGerman(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        app()->setLocale('de');
    }

    public function test_the_start_page_and_explain_it_render_in_german_when_german_is_the_language(): void
    {
        $this->speakGerman();

        $this->actingAs($this->student)
            ->get('/')
            ->assertOk()
            ->assertSee(['Hallo Mia!', '1 offene Hausaufgabe', 'Morgen fällig', 'Als Nächstes fällig', 'Dein Verständnis', 'Thema Tuto erklären'])
            ->assertDontSee(['Hello Mia!', 'Due tomorrow']);

        $this->actingAs($this->student)
            ->get('/erklaer-es-mir')
            ->assertOk()
            ->assertSee(['Erklär’s mir', 'Erklär’s Tuto!', 'Such dir ein Thema aus.', 'Oder ein eigenes Thema', 'Deine Erklärungen', 'Noch keine Erklärungen. Leg einfach los!'])
            ->assertDontSee(['Explain it to me', 'Pick a topic.']);

        $session = ExplanationSession::factory()->for($this->student, 'student')->for($this->lesson)->create();

        Livewire::actingAs($this->student)
            ->test(ExplainIt::class)
            ->call('openSession', $session->id)
            ->assertSee(['Frage an meine Lehrkraft', 'Gespräch löschen', 'Deine Erklärung', 'Enter schickt ab.', 'Erklärung beenden'])
            ->assertDontSee('Question for my teacher')
            ->callAction('askTeacher', ['question' => 'Warum ist Chlorophyll grün?'])
            ->assertNotified('Deine Frage ist bei deiner Lehrkraft');
    }

    public function test_the_homework_page_renders_in_german_when_german_is_the_language(): void
    {
        $this->speakGerman();

        $this->actingAs($this->student)
            ->get(WorkOnHomework::getUrl(['lesson' => $this->lesson]))
            ->assertOk()
            ->assertSeeInOrder(['Zusammenfassung der Stunde', 'Was braucht eine Pflanze?', 'In deinen Worten', 'Zusammenfassung in deinen Worten', 'Abgeben und prüfen lassen'])
            ->assertSee(['Einfache Sprache', 'Du kannst nur einmal abgeben.', 'Mindestens 80 Zeichen'])
            ->assertDontSee(['Summary of the lesson', 'Plain language']);
    }

    public function test_the_question_pages_render_in_german_when_german_is_the_language(): void
    {
        $this->speakGerman();
        $question = StudentQuestion::factory()->for($this->student, 'student')->for($this->lesson)->create();

        Livewire::actingAs($this->student)
            ->test(MyQuestions::class)
            ->assertSee(['Deine Frage', 'Antwort', 'Wartet auf Antwort', 'Gefragt am'])
            ->assertDontSee(['Waiting for answer', 'Asked on']);

        $this->assertSame('Meine Rückfragen', MyQuestions::getNavigationLabel());
        $this->assertSame('Rückfragen', QuestionInbox::getNavigationLabel());

        Livewire::actingAs($this->lesson->teacher)
            ->test(QuestionInbox::class)
            ->assertSee(['Fragen deiner Schüler:innen zu deinen Stunden.', 'Offen', 'Noch nicht beantwortet', 'Beantworten'])
            ->assertDontSee(['Open', 'Not answered yet'])
            ->callAction(TestAction::make('answer')->table($question), ['answer' => 'Weil Chlorophyll rotes Licht schluckt.'])
            ->assertNotified('Antwort geschickt');
    }

    public function test_the_choice_of_the_user_beats_the_german_platform_default_on_the_student_pages(): void
    {
        $this->speakGerman();
        $this->student->update(['locale' => 'en']);

        $this->actingAs($this->student)
            ->get('/')
            ->assertOk()
            ->assertSee(['Hello Mia!', 'Due tomorrow'])
            ->assertDontSee('Hallo Mia!');

        $this->actingAs($this->student)
            ->get(MyQuestions::getUrl())
            ->assertOk()
            ->assertSee('My questions for the teacher')
            ->assertDontSee('Meine Rückfragen');
    }
}
