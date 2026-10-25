<?php

namespace Tests\Feature\Lessons;

use App\Filament\Pages\Today;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Filament\Resources\Lessons\RelationManagers\QuestionsRelationManager;
use App\Filament\Resources\Lessons\RelationManagers\SubmissionsRelationManager;
use App\Filament\Widgets\TeacherLessonsWidget;
use App\Filament\Widgets\TeacherStatsWidget;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The lesson pages in both languages: the list, the lesson page with its two relation managers, "Heute" and the two
 * widgets of the teacher's start page. The tests of the single features assert the English texts; this one checks that
 * the German ones are still there.
 */
class LessonsLanguageTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday: "Heute" lists the lessons held today.
        $this->travelTo('2026-10-21 10:00:00');
        $this->teacher = User::factory()->teacher()->create();
        $schoolClass = SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => '7b']);
        User::factory()->student($schoolClass)->create();
        $this->lesson = Lesson::factory()->for($schoolClass)->for($this->teacher, 'teacher')->has(Question::factory()->count(2))->create([
            'held_on' => '2026-10-21',
            'topic' => 'Fotosynthese',
            'summary' => 'Pflanzen bauen mit Licht Glucose auf.',
        ]);
        Lesson::factory()->for($schoolClass)->for($this->teacher, 'teacher')->publishedFor()->create([
            'held_on' => '2026-10-20',
            'practice_competency_id' => Competency::factory()->create()->id,
        ]);
        $this->actingAs($this->teacher);
    }

    private function germanIsTheDefault(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        app()->setLocale('de');
    }

    public function test_the_lesson_list_renders_in_english(): void
    {
        Livewire::test(ListLessons::class)
            ->assertSee(['Lessons', 'Add lesson', 'Class codes', 'Waiting for release', 'Released', 'Practice task', 'Submitted'])
            ->assertDontSee(['Stunde erfassen', 'Klassencodes', 'Wartet auf Freigabe', 'Förderaufgabe'])
            ->mountAction('classCodes')
            ->assertMountedActionModalSee(['Register on this page:', 'Class code', 'Close']);
    }

    public function test_the_lesson_page_renders_in_english(): void
    {
        Livewire::test(EditLesson::class, ['record' => $this->lesson->getRouteKey()])
            ->assertSee(['Held on', 'Subject', 'Topic', 'Content of the lesson', 'Notes', 'Worksheets', 'Summary', 'Draft – waiting for release', 'Submissions'])
            ->assertActionHasLabel('draftHomework', 'Create homework with AI')
            ->assertActionHasLabel('delegate', 'Release')
            ->mountAction('delegate')
            ->assertMountedActionModalSee(['Release homework', 'Due by', 'For whom?', 'Whole class 7b', 'Selected students'])
            ->assertDontSee(['Gehalten am', 'Inhalt der Stunde', 'Arbeitsblätter']);
    }

    public function test_the_relation_managers_render_in_english(): void
    {
        Livewire::test(QuestionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->assertSee(['No.', 'Type', 'Question', 'Levels', 'Competency', 'Origin', 'Create levels with AI'])
            ->mountAction(TestAction::make('differentiateAll')->table())
            ->assertMountedActionModalSee(['Create levels for all questions?', 'Starter and Pro levels']);

        Livewire::test(SubmissionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->assertSee(['Student', 'Handed in on', 'The homework has not been released yet.'])
            ->assertDontSee(['Abgegeben am', 'Die Hausaufgabe ist noch nicht freigegeben.']);
    }

    public function test_today_and_the_teacher_widgets_render_in_english(): void
    {
        $this->lesson->update(['published_at' => null]);

        Livewire::test(Today::class)
            ->assertSee(['Today', 'Release in 30 seconds', '1 lesson is waiting for release', 'Create homework with AI', 'Release all that are ready'])
            ->assertSee('2 questions')
            ->assertDontSee(['Freigabe in 30 Sekunden', 'Alle bereiten freigeben']);

        Livewire::test(TeacherLessonsWidget::class)
            ->assertSee(['Your latest lessons', 'Today: release homework', 'Learning progress & support needs', 'New lesson', 'Fotosynthese', 'Waiting for release', 'Released, due', 'class 7b'])
            ->assertDontSee(['Deine letzten Unterrichtsstunden', 'Klasse 7b']);

        Livewire::test(TeacherStatsWidget::class)
            ->assertSee(['Submissions for open homework', 'Avg. comprehension of the last homework', 'Students with support needs', 'No homework is running right now'])
            ->assertDontSee(['Abgaben offener Hausaufgaben', 'Schüler:innen mit Förderbedarf']);
    }

    public function test_the_dates_of_the_list_and_the_lesson_page_follow_the_language(): void
    {
        $released = Lesson::query()->whereNotNull('published_at')->firstOrFail();

        Livewire::test(ListLessons::class)->assertSee('21 Oct 2026')->assertDontSee('21.10.2026');
        Livewire::test(EditLesson::class, ['record' => $released->getRouteKey()])->assertSee('due by 28 Oct 2026')->assertDontSee('28.10.2026');

        $this->germanIsTheDefault();

        Livewire::test(ListLessons::class)->assertSee('21.10.2026');
        Livewire::test(EditLesson::class, ['record' => $released->getRouteKey()])->assertSee('Abgabe bis 28.10.2026');
    }

    public function test_the_lesson_pages_render_in_german(): void
    {
        $this->germanIsTheDefault();

        Livewire::test(ListLessons::class)
            ->assertSee(['Unterrichtsstunden', 'Stunde erfassen', 'Klassencodes', 'Wartet auf Freigabe', 'Freigegeben', 'Förderaufgabe', 'Abgegeben'])
            ->assertDontSee(['Add lesson', 'Class codes']);

        Livewire::test(EditLesson::class, ['record' => $this->lesson->getRouteKey()])
            ->assertSee(['Gehalten am', 'Inhalt der Stunde', 'Arbeitsblätter', 'Zusammenfassung', 'Entwurf – wartet auf Freigabe', 'Abgaben'])
            ->assertActionHasLabel('draftHomework', 'Hausaufgabe mit KI erstellen')
            ->assertActionHasLabel('delegate', 'Freigeben')
            ->assertDontSee(['Held on', 'Content of the lesson']);

        // The hidden label of the summary editor is read out by screen readers.
        $this->assertDoesNotMatchRegularExpression('/>\s*Summary\s*</', Livewire::test(EditLesson::class, ['record' => $this->lesson->getRouteKey()])->html());

        Livewire::test(QuestionsRelationManager::class, ['ownerRecord' => $this->lesson, 'pageClass' => EditLesson::class])
            ->assertSee(['Nr.', 'Stufen', 'Kompetenz', 'Herkunft', 'Stufen mit KI erstellen']);

        Livewire::test(Today::class)
            ->assertSee(['Heute', 'Freigabe in 30 Sekunden', '1 Stunde wartet auf Freigabe', '2 Fragen', 'Alle bereiten freigeben']);

        Livewire::test(TeacherLessonsWidget::class)
            ->assertSee(['Deine letzten Unterrichtsstunden', 'Heute: Hausaufgaben freigeben', 'Neue Unterrichtsstunde', 'Freigegeben, fällig', 'Klasse 7b']);

        Livewire::test(TeacherStatsWidget::class)
            ->assertSee(['Abgaben offener Hausaufgaben', 'Schüler:innen mit Förderbedarf']);
    }
}
