<?php

namespace Tests\Feature\Insights;

use App\Models\Answer;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\Support\LearningInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_teachers_see_classes_they_lead_or_teach_in_and_admins_see_everyone(): void
    {
        $ledClass = SchoolClass::factory()->create();
        $taughtClass = SchoolClass::factory()->create();
        $foreignClass = SchoolClass::factory()->create();
        $teacher = $ledClass->teacher;
        Lesson::factory()->for($taughtClass)->for($teacher, 'teacher')->create();

        $ledStudent = User::factory()->student($ledClass)->create();
        $taughtStudent = User::factory()->student($taughtClass)->create();
        $foreignStudent = User::factory()->student($foreignClass)->create();
        $studentWithoutClass = User::factory()->create();

        $this->assertEqualsCanonicalizing([$ledClass->id, $taughtClass->id], LearningInsights::visibleClasses($teacher)->pluck('id')->all());
        $this->assertTrue(LearningInsights::canView($teacher, $ledStudent));
        $this->assertTrue(LearningInsights::canView($teacher, $taughtStudent));
        $this->assertFalse(LearningInsights::canView($teacher, $foreignStudent));
        $this->assertFalse(LearningInsights::canView($teacher, $teacher));

        $admin = User::factory()->admin()->create();
        $this->assertSame(3, LearningInsights::visibleClasses($admin)->count());
        $this->assertTrue(LearningInsights::canView($admin, $foreignStudent));
        $this->assertTrue(LearningInsights::canView($admin, $studentWithoutClass));

        $this->assertSame(0, LearningInsights::visibleClasses($ledStudent)->count());
        $this->assertFalse(LearningInsights::canView($ledStudent, $ledStudent));
    }

    public function test_competency_averages_and_support_needs_use_only_evaluated_mapped_answers(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        [$mia, $ben] = User::factory()->student($schoolClass)->count(2)->create();
        [$biology, $mathematics] = Lesson::factory()->for($schoolClass)->count(2)->create();
        [$argumentation, $modelling] = Competency::factory()->count(2)->create();

        $this->answer($mia, $biology, $argumentation, 80);
        $this->answer($mia, $mathematics, $argumentation, 61);
        $this->answer($mia, $biology, $modelling, 40);
        $this->answer($mia, $mathematics, $modelling, null);
        $this->answer($mia, $mathematics, null, 0);

        $this->answer($ben, $biology, $argumentation, 49);
        $this->answer($ben, $mathematics, $argumentation, 50);
        $this->answer($ben, $biology, $modelling, 60);
        $this->answer($ben, $mathematics, $modelling, 30);

        $this->answer(User::factory()->student()->create(), $biology, $argumentation, 10);

        $answers = LearningInsights::scoredAnswers([$mia->id, $ben->id]);

        $this->assertEquals([
            $mia->id => [$argumentation->id => 71, $modelling->id => 40],
            $ben->id => [$argumentation->id => 50, $modelling->id => 45],
        ], LearningInsights::competencyAverages($answers));

        $this->assertEquals([
            $mia->id => [['competency_id' => $modelling->id, 'average' => 40, 'lesson_ids' => [$biology->id]]],
            $ben->id => [['competency_id' => $modelling->id, 'average' => 45, 'lesson_ids' => [$mathematics->id]]],
        ], LearningInsights::supportNeeds($answers));
    }

    private function answer(User $student, Lesson $lesson, ?Competency $competency, ?int $score): void
    {
        $submission = Submission::query()->firstOrCreate(['lesson_id' => $lesson->id, 'student_id' => $student->id], ['summary' => 'Zusammenfassung']);
        $question = Question::factory()->for($lesson)->create(['competency_id' => $competency?->id]);

        Answer::factory()->for($submission)->for($question)->create(['score' => $score]);
    }
}
