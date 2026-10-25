<?php

namespace Tests\Feature\Insights;

use App\Filament\Pages\LearningProgress;
use App\Filament\Pages\ParentReport;
use App\Models\Answer;
use App\Models\Competency;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InsightPagesTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private User $teacher;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolClass = SchoolClass::factory()->create(['name' => 'Klasse 7b']);
        $this->teacher = $this->schoolClass->teacher;
        $this->student = User::factory()->student($this->schoolClass)->create(['name' => 'Mia Hoffmann']);

        $strength = Competency::factory()->create(['subject' => 'Biologie', 'name' => 'Erkenntnisgewinnung']);
        $weakness = Competency::factory()->create(['subject' => 'Mathematik', 'name' => 'Probleme mathematisch lösen']);

        $lesson = Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')
            ->publishedFor([$this->student])
            ->create(['topic' => 'Fotosynthese', 'due_on' => today()->subDay()]);
        Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')
            ->publishedFor([$this->student])
            ->create(['topic' => 'Satz des Pythagoras', 'due_on' => today()->subDay()]);

        $submission = Submission::factory()->for($lesson)->for($this->student, 'student')->evaluated(66)->create();
        Answer::factory()->for($submission)->for(Question::factory()->for($lesson)->for($strength))->create(['score' => 90]);
        Answer::factory()->for($submission)->for(Question::factory()->for($lesson)->for($weakness))->create(['score' => 30]);
    }

    public function test_teachers_see_the_matrix_and_support_needs_of_their_class_only(): void
    {
        $foreignClass = SchoolClass::factory()->create(['name' => 'Klasse 9c']);

        Livewire::actingAs($this->teacher)
            ->test(LearningProgress::class)
            ->assertOk()
            ->assertSet('schoolClassId', $this->schoolClass->id)
            ->assertDontSee('Klasse 9c')
            ->assertSee(['Mia Hoffmann', 'Erkenntnisgewinnung', 'Probleme mathematisch lösen', 'shown in:', 'Fotosynthese on'])
            ->assertSee(ParentReport::getUrl(['student' => $this->student]))
            ->set('schoolClassId', $foreignClass->id)
            ->assertForbidden();
    }

    public function test_the_report_renders_for_admins_and_the_allowed_teacher(): void
    {
        foreach ([User::factory()->admin()->for($this->schoolClass->school)->create(), $this->teacher] as $viewer) {
            $this->actingAs($viewer)
                ->get(ParentReport::getUrl(['student' => $this->student]))
                ->assertOk()
                ->assertSee('Parent meeting: Mia Hoffmann')
                ->assertSee('not grades')
                ->assertSeeInOrder(['Basis for the conversation', 'Erkenntnisgewinnung (Biologie): 90 points', 'Probleme mathematisch lösen (Mathematik): 30 points, shown in', 'Fotosynthese', 'Submission rate 50%'])
                ->assertSee('Not submitted');
        }
    }

    public function test_others_are_forbidden(): void
    {
        $reportUrl = ParentReport::getUrl(['student' => $this->student]);

        $this->actingAs(User::factory()->teacher()->create())->get($reportUrl)->assertForbidden();
        $this->actingAs($this->student)->get($reportUrl)->assertForbidden();
        $this->actingAs($this->student)->get(LearningProgress::getUrl())->assertForbidden();
        $this->actingAs($this->teacher)->get(LearningProgress::getUrl(['klasse' => SchoolClass::factory()->create()->id]))->assertForbidden();
    }

    public function test_an_admin_of_another_school_is_forbidden_and_only_sees_the_classes_of_their_own_school(): void
    {
        $foreignAdmin = User::factory()->admin()->create();
        $ownClass = SchoolClass::factory()->for(User::factory()->teacher()->for($foreignAdmin->school)->create(), 'teacher')->create(['name' => 'Klasse 5a']);

        $this->actingAs($foreignAdmin)->get(ParentReport::getUrl(['student' => $this->student]))->assertForbidden();
        $this->actingAs($foreignAdmin)->get(LearningProgress::getUrl(['klasse' => $this->schoolClass->id]))->assertForbidden();

        Livewire::actingAs($foreignAdmin)
            ->test(LearningProgress::class)
            ->assertOk()
            ->assertSet('schoolClassId', $ownClass->id)
            ->assertSee('Klasse 5a')
            ->assertDontSee('Klasse 7b')
            ->assertDontSee('Mia Hoffmann')
            ->set('schoolClassId', $this->schoolClass->id)
            ->assertForbidden();
    }

    public function test_platform_operators_see_no_learning_data(): void
    {
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)->get(LearningProgress::getUrl())->assertForbidden();
        $this->actingAs($operator)->get(ParentReport::getUrl(['student' => $this->student]))->assertForbidden();
    }

    public function test_the_report_lists_sessions_about_lessons_but_never_private_free_topic_sessions(): void
    {
        $lesson = Lesson::query()->where('topic', 'Fotosynthese')->firstOrFail();
        ExplanationSession::factory()->for($this->student, 'student')->finished(70)->create(['lesson_id' => $lesson->id, 'topic' => 'Fotosynthese erklärt']);
        ExplanationSession::factory()->for($this->student, 'student')->finished(30)->create(['lesson_id' => null, 'topic' => 'Mein geheimes Hobby']);

        foreach ([$this->teacher, User::factory()->admin()->for($this->schoolClass->school)->create()] as $viewer) {
            $this->actingAs($viewer)
                ->get(ParentReport::getUrl(['student' => $this->student]))
                ->assertOk()
                ->assertSee('Fotosynthese erklärt')
                ->assertDontSee('Mein geheimes Hobby')
                ->assertSee('Conversations about freely chosen topics are private');
        }
    }
}
