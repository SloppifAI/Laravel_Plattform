<?php

namespace Tests\Feature\Insights;

use App\Filament\Pages\LearningProgress;
use App\Filament\Pages\LessonStarter;
use App\Filament\Pages\MyHomework;
use App\Filament\Pages\MyLearningProgress;
use App\Filament\Pages\ParentReport;
use App\Filament\Resources\ExplanationSessions\ExplanationSessionResource;
use App\Filament\Resources\ExplanationSessions\Pages\ListExplanationSessions;
use App\Filament\Resources\ExplanationSessions\Pages\ViewExplanationSession;
use App\Models\Competency;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\StudentProgress\CreatesLearningResults;
use Tests\TestCase;

/**
 * The suite runs in English; the platform default for real is German. These smoke tests switch it back and check
 * that the insight and student-progress pages (and their English counterparts) really follow the language of the user.
 */
class InsightsLanguageTest extends TestCase
{
    use CreatesLearningResults;
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private User $teacher;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->schoolClass = SchoolClass::factory()->create(['name' => '7b']);
        $this->teacher = $this->schoolClass->teacher;
        $this->student = User::factory()->student($this->schoolClass)->create(['name' => 'Mia Hoffmann']);

        $homework = $this->evaluatedHomework($this->student, 'Fotosynthese', '2026-10-01', ['Lichtreaktion'], 40);
        $this->answer($homework, Competency::factory()->create(['subject' => 'Biologie', 'name' => 'Erkenntnisgewinnung']), 30);
    }

    private function speakGerman(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        app()->setLocale('de');
    }

    public function test_the_teacher_pages_render_in_german_when_german_is_the_language(): void
    {
        $this->speakGerman();

        $this->actingAs($this->teacher)
            ->get(LearningProgress::getUrl())
            ->assertOk()
            ->assertSee(['Lernstand & Förderbedarf', 'Kompetenzmatrix', 'Förderaufgaben auf Knopfdruck', 'Bericht für das Elterngespräch', 'gezeigt in:', 'Fotosynthese am'])
            ->assertDontSee(['Competency matrix', 'shown in:']);

        $this->actingAs($this->teacher)
            ->get(ParentReport::getUrl(['student' => $this->student]))
            ->assertOk()
            ->assertSee(['Elterngespräch: Mia Hoffmann', 'keine Noten', 'Gesprächsgrundlage', 'Abgabequote', '„Erklär’s mir“'])
            ->assertDontSee(['Parent meeting', 'not grades']);
    }

    public function test_the_student_pages_render_in_german_when_german_is_the_language(): void
    {
        $this->speakGerman();

        $this->actingAs($this->student)
            ->get(MyLearningProgress::getUrl())
            ->assertOk()
            ->assertSee(['Mein Lernstand', 'Wo stehst du gerade?', 'keine Noten', 'Hier lohnt sich Üben', 'Noch üben', 'Thema Tuto erklären'])
            ->assertDontSee(['My learning progress', 'Keep practising']);

        $this->actingAs($this->student)
            ->get(MyHomework::getUrl())
            ->assertOk()
            ->assertSee('Meine Hausaufgaben')
            ->assertDontSee('My homework');
    }

    public function test_the_lesson_starter_and_the_session_pages_render_in_german_when_german_is_the_language(): void
    {
        $this->speakGerman();
        $lesson = Lesson::query()->where('topic', 'Fotosynthese')->firstOrFail();
        $lesson->forceFill(['teacher_id' => $this->teacher->id])->save();
        $session = ExplanationSession::factory()->for($lesson)->for($this->student, 'student')->create(['topic' => 'Zellkern erklärt']);

        $this->actingAs($this->teacher)
            ->get(LessonStarter::getUrl(['lesson' => $lesson]))
            ->assertOk()
            ->assertSee(['Einstieg für die nächste Stunde', 'Noch zu wenige Abgaben für einen Klassen-Einstieg', 'Vollbild', 'Drucken', 'lang="de"'], false)
            ->assertDontSee('Starter for the next lesson');

        Livewire::actingAs($this->teacher)
            ->test(ListExplanationSessions::class)
            ->assertSee(['Erklär-Sitzungen', 'Läuft', 'Unterrichtsstunde']);

        Livewire::actingAs($this->teacher)
            ->test(ViewExplanationSession::class, ['record' => $session->getRouteKey()])
            ->assertSee(['Gesprächsverlauf', 'Es gibt noch keine Nachrichten in dieser Sitzung.'])
            // The label for screen readers is German as well.
            ->assertDontSee('Messages');

        $this->assertSame('Erklär-Sitzungen', ExplanationSessionResource::getNavigationLabel());
    }

    public function test_the_lesson_starter_marks_its_language_for_the_projector(): void
    {
        $lesson = Lesson::query()->where('topic', 'Fotosynthese')->firstOrFail();
        $lesson->forceFill(['teacher_id' => $this->teacher->id])->save();

        $this->actingAs($this->teacher)
            ->get(LessonStarter::getUrl(['lesson' => $lesson]))
            ->assertOk()
            ->assertSee(['Starter for the next lesson', 'Too few submissions for a class starter yet', 'lang="en"'], false);
    }

    public function test_the_choice_of_the_user_beats_the_platform_default_on_every_page(): void
    {
        $this->speakGerman();
        $this->student->update(['locale' => 'en']);

        $this->actingAs($this->student)
            ->get(MyLearningProgress::getUrl())
            ->assertOk()
            ->assertSee(['My learning progress', 'Where do you stand right now?', 'Keep practising'])
            ->assertDontSee('Wo stehst du gerade?');
    }
}
