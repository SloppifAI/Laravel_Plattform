<?php

namespace Tests\Feature\StudentProgress;

use App\Models\Competency;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\LearningInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class StudentCompetenciesTest extends TestCase
{
    use CreatesLearningResults;
    use RefreshDatabase;

    public function test_competencies_are_sorted_into_strengths_almost_and_practice_at_the_level_boundaries(): void
    {
        $student = User::factory()->student(SchoolClass::factory()->create())->create();
        $homework = $this->evaluatedHomework($student, 'Photosynthese');

        foreach (['A' => 75, 'B' => 74, 'C' => 50, 'D' => 49, 'E' => 20] as $name => $score) {
            $this->answer($homework, Competency::factory()->create(['name' => $name]), $score);
        }

        $result = LearningInsights::studentCompetencies($student);
        $names = fn (Collection $group): array => $group->map(fn (array $row): string => $row['competency']->name)->all();

        $this->assertSame(['A'], $names($result['strengths']));
        $this->assertSame(['B', 'C'], $names($result['almost']));
        $this->assertSame(['E', 'D'], $names($result['practice']));
        $this->assertSame([20, 49], $result['practice']->pluck('average')->all());
    }

    public function test_practice_lists_the_lessons_where_it_showed_and_their_gaps(): void
    {
        $student = User::factory()->student(SchoolClass::factory()->create())->create();
        $competency = Competency::factory()->create();

        $first = $this->evaluatedHomework($student, 'Zellaufbau', '2026-10-01', ['Lücke A', 'Lücke B']);
        $second = $this->evaluatedHomework($student, 'Fotosynthese', '2026-10-10', ['Lücke B', 'Lücke C']);
        $solved = $this->evaluatedHomework($student, 'Atmung', '2026-10-15', ['Lücke D']);
        $withdrawn = $this->evaluatedHomework($student, 'Verdauung', '2026-10-20', ['Lücke E']);
        $withdrawn->lesson->students()->detach($student);

        foreach ([[$first, 20], [$second, 30], [$solved, 90], [$withdrawn, 10]] as [$submission, $score]) {
            $this->answer($submission, $competency, $score);
        }

        $practice = LearningInsights::studentCompetencies($student)['practice'];

        $this->assertCount(1, $practice);
        $this->assertSame(38, $practice[0]['average']);
        // Newest lesson first, only lessons with weak answers that are still delegated to the student.
        $this->assertSame([$second->lesson_id, $first->lesson_id], $practice[0]['lessons']->modelKeys());
        $this->assertSame(['Lücke B', 'Lücke C', 'Lücke A'], $practice[0]['gaps']);
    }

    public function test_answers_of_other_students_never_count(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        [$student, $classmate] = User::factory()->student($schoolClass)->count(2)->create();
        $shared = Competency::factory()->create();
        $classmateOnly = Competency::factory()->create();

        $this->answer($this->evaluatedHomework($student, 'Photosynthese'), $shared, 90);
        $classmateHomework = $this->evaluatedHomework($classmate, 'Zellaufbau', gaps: ['Fremde Lücke']);
        $this->answer($classmateHomework, $shared, 5);
        $this->answer($classmateHomework, $classmateOnly, 5);

        $result = LearningInsights::studentCompetencies($student);

        $this->assertSame([90], $result['strengths']->pluck('average')->all());
        $this->assertCount(0, $result['almost']);
        $this->assertCount(0, $result['practice']);
    }

    public function test_without_evaluated_answers_every_group_is_empty(): void
    {
        $student = User::factory()->student(SchoolClass::factory()->create())->create();

        $result = LearningInsights::studentCompetencies($student);

        $this->assertCount(0, $result['strengths']);
        $this->assertCount(0, $result['almost']);
        $this->assertCount(0, $result['practice']);
    }

    public function test_understanding_trend_compares_the_newer_with_the_older_half(): void
    {
        $this->assertNull(LearningInsights::understandingTrend(collect()));
        $this->assertNull(LearningInsights::understandingTrend(collect([80])));

        $this->assertSame('up', LearningInsights::understandingTrend(collect([40, 45])));
        $this->assertSame('down', LearningInsights::understandingTrend(collect([80, 60, 50, 40])));
        $this->assertSame('steady', LearningInsights::understandingTrend(collect([60, 64])));
        $this->assertSame('steady', LearningInsights::understandingTrend(collect([64, 60])));
        $this->assertSame('up', LearningInsights::understandingTrend(collect([50, 20, 55, 80, 90])));
    }

    public function test_understanding_trend_splits_and_rounds_like_the_parent_report(): void
    {
        // With an odd count the middle score belongs to the newer half: 40 against 70 is +30.
        $this->assertSame('up', LearningInsights::understandingTrend(collect([40, 80, 60])));
        $this->assertSame('steady', LearningInsights::understandingTrend(collect([60, 64, 62])));
        // 60 against 64.5 is +4.5, which the parent report rounds to the threshold of 5.
        $this->assertSame('up', LearningInsights::understandingTrend(collect([60, 64, 65])));
        $this->assertSame('down', LearningInsights::understandingTrend(collect([65, 61, 60])));
    }

    public function test_evaluated_submissions_follow_the_lesson_order_and_skip_withdrawn_unpublished_and_unevaluated_homework(): void
    {
        $student = User::factory()->student(SchoolClass::factory()->create())->create();
        $classmate = User::factory()->student($student->schoolClass)->create();

        $later = $this->evaluatedHomework($student, 'Später', '2026-10-20');
        $earlier = $this->evaluatedHomework($student, 'Früher', '2026-10-01');
        $this->evaluatedHomework($student, 'Zurückgezogen', '2026-10-05')->lesson->students()->detach($student);
        $this->evaluatedHomework($student, 'Entwurf', '2026-10-06')->lesson->update(['published_at' => null]);
        $this->evaluatedHomework($student, 'Ausstehend', '2026-10-07')->update(['evaluated_at' => null, 'understanding_score' => null]);
        $this->evaluatedHomework($classmate, 'Mitschülerin', '2026-10-08');

        $this->assertSame(
            [$earlier->getKey(), $later->getKey()],
            LearningInsights::evaluatedSubmissions($student)->modelKeys(),
        );
    }

    public function test_the_lowest_level_reads_as_noch_ueben_in_the_warning_colour_for_students(): void
    {
        $this->assertSame('Keep practising', LearningInsights::studentLevel(49)['label']);
        $this->assertSame('warning', LearningInsights::studentLevel(0)['color']);
        $this->assertSame('Partly understood', LearningInsights::studentLevel(50)['label']);
        $this->assertSame('Well understood', LearningInsights::studentLevel(75)['label']);
        $this->assertSame('success', LearningInsights::studentLevel(100)['color']);
    }
}
