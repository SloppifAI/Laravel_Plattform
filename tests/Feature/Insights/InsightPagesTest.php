<?php

namespace Tests\Feature\Insights;

use App\Filament\Pages\LearningProgress;
use App\Filament\Pages\ParentReport;
use App\Models\Answer;
use App\Models\Competency;
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

        $this->schoolClass = SchoolClass::factory()->create(['name' => '7b']);
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
        $foreignClass = SchoolClass::factory()->create(['name' => '9c']);

        Livewire::actingAs($this->teacher)
            ->test(LearningProgress::class)
            ->assertOk()
            ->assertSet('schoolClassId', $this->schoolClass->id)
            ->assertDontSee('9c')
            ->assertSee(['Mia Hoffmann', 'Erkenntnisgewinnung', 'Probleme mathematisch lösen', 'gezeigt in:', 'Fotosynthese am'])
            ->assertSee(ParentReport::getUrl(['student' => $this->student]))
            ->set('schoolClassId', $foreignClass->id)
            ->assertForbidden();
    }

    public function test_the_report_renders_for_admins_and_the_allowed_teacher(): void
    {
        foreach ([User::factory()->admin()->create(), $this->teacher] as $viewer) {
            $this->actingAs($viewer)
                ->get(ParentReport::getUrl(['student' => $this->student]))
                ->assertOk()
                ->assertSee('Elterngespräch: Mia Hoffmann')
                ->assertSee('keine Noten')
                ->assertSeeInOrder(['Gesprächsgrundlage', 'Erkenntnisgewinnung (Biologie): 90 Punkte', 'Probleme mathematisch lösen (Mathematik): 30 Punkte, gezeigt in', 'Fotosynthese', 'Abgabequote 50 %'])
                ->assertSee('Nicht abgegeben');
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
}
