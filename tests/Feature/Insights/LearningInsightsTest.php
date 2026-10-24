<?php

namespace Tests\Feature\Insights;

use App\Models\Answer;
use App\Models\Competency;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\Support\LearningInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_teachers_see_classes_they_lead_or_are_assigned_to_and_admins_those_of_their_school(): void
    {
        $ledClass = SchoolClass::factory()->create();
        $school = $ledClass->school;
        $teacher = $ledClass->teacher;
        $taughtClass = $this->classIn($school);
        $colleagueClass = $this->classIn($school);
        $otherSchoolClass = SchoolClass::factory()->create();
        $taughtClass->teachers()->attach($teacher);
        Lesson::factory()->for($taughtClass)->for($teacher, 'teacher')->publishedFor()->create();
        // Neither a draft nor a published lesson in a colleague's class grants insight into it, only the assignment does.
        Lesson::factory()->for($colleagueClass)->for($teacher, 'teacher')->create();
        Lesson::factory()->for($colleagueClass)->for($teacher, 'teacher')->publishedFor()->create();

        $ledStudent = User::factory()->student($ledClass)->create();
        $taughtStudent = User::factory()->student($taughtClass)->create();
        $colleagueStudent = User::factory()->student($colleagueClass)->create();
        $otherSchoolStudent = User::factory()->student($otherSchoolClass)->create();
        $studentWithoutClass = User::factory()->for($school)->create();

        $this->assertEqualsCanonicalizing([$ledClass->id, $taughtClass->id], LearningInsights::visibleClasses($teacher)->pluck('id')->all());
        $this->assertTrue(LearningInsights::canView($teacher, $ledStudent));
        $this->assertTrue(LearningInsights::canView($teacher, $taughtStudent));
        $this->assertFalse(LearningInsights::canView($teacher, $colleagueStudent));
        $this->assertFalse(LearningInsights::canView($teacher, $otherSchoolStudent));
        $this->assertFalse(LearningInsights::canView($teacher, $teacher));

        $admin = User::factory()->admin()->for($school)->create();
        $this->assertEqualsCanonicalizing([$ledClass->id, $taughtClass->id, $colleagueClass->id], LearningInsights::visibleClasses($admin)->pluck('id')->all());
        $this->assertTrue(LearningInsights::canView($admin, $colleagueStudent));
        $this->assertTrue(LearningInsights::canView($admin, $studentWithoutClass));
        $this->assertFalse(LearningInsights::canView($admin, $otherSchoolStudent));
        $this->assertFalse(LearningInsights::canView($admin, $admin));

        $this->assertSame(0, LearningInsights::visibleClasses($ledStudent)->count());
        $this->assertFalse(LearningInsights::canView($ledStudent, $ledStudent));
    }

    public function test_admins_of_another_school_and_operators_see_no_classes_and_no_students(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $student = User::factory()->student($schoolClass)->create();
        Lesson::factory()->for($schoolClass)->publishedFor([$student])->create();

        $foreignAdmin = User::factory()->admin()->create();
        $operator = User::factory()->operator()->create();
        $foreignTeacher = User::factory()->teacher()->create();
        Lesson::factory()->for($this->classIn($foreignTeacher->school))->for($foreignTeacher, 'teacher')->create();

        foreach ([$foreignAdmin, $operator, $foreignTeacher] as $viewer) {
            $this->assertNotContains($schoolClass->id, LearningInsights::visibleClasses($viewer)->pluck('id')->all());
            $this->assertFalse(LearningInsights::canView($viewer, $student));
            $this->assertSame(0, LearningInsights::visibleStudents($viewer)->whereKey($student->id)->count());
        }

        $this->assertSame(0, LearningInsights::visibleClasses($operator)->count());
        $this->assertSame(0, LearningInsights::visibleStudents($operator)->count());
    }

    public function test_only_sessions_about_a_lesson_are_visible_to_the_viewer_the_policy_allows(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $teacher = $schoolClass->teacher;
        $student = User::factory()->student($schoolClass)->create();
        $ownLesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->publishedFor([$student])->create();
        $colleagueLesson = Lesson::factory()->for($schoolClass)->publishedFor([$student])->create();

        $aboutOwnLesson = ExplanationSession::factory()->for($student, 'student')->create(['lesson_id' => $ownLesson->id, 'topic' => 'Fotosynthese']);
        $aboutColleagueLesson = ExplanationSession::factory()->for($student, 'student')->create(['lesson_id' => $colleagueLesson->id, 'topic' => 'Zellaufbau']);
        $freeTopic = ExplanationSession::factory()->for($student, 'student')->create(['lesson_id' => null, 'topic' => 'Mein Hobby Dinosaurier']);

        $admin = User::factory()->admin()->for($schoolClass->school)->create();
        $foreignAdmin = User::factory()->admin()->create();

        $this->assertSame([$aboutOwnLesson->id], LearningInsights::visibleExplanationSessions($teacher, $student)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$aboutOwnLesson->id, $aboutColleagueLesson->id], LearningInsights::visibleExplanationSessions($admin, $student)->pluck('id')->all());
        $this->assertTrue(LearningInsights::visibleExplanationSessions($foreignAdmin, $student)->isEmpty());
        $this->assertNotContains($freeTopic->id, LearningInsights::visibleExplanationSessions($admin, $student)->pluck('id')->all());
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

    private function classIn(School $school): SchoolClass
    {
        return SchoolClass::factory()->for(User::factory()->teacher()->for($school)->create(), 'teacher')->create();
    }

    private function answer(User $student, Lesson $lesson, ?Competency $competency, ?int $score): void
    {
        $submission = Submission::query()->firstOrCreate(['lesson_id' => $lesson->id, 'student_id' => $student->id], ['summary' => 'Zusammenfassung']);
        $question = Question::factory()->for($lesson)->create(['competency_id' => $competency?->id]);

        Answer::factory()->for($submission)->for($question)->create(['score' => $score]);
    }
}
