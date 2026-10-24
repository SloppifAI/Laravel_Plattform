<?php

namespace Tests\Feature\Questions;

use App\Models\Lesson;
use App\Models\StudentQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class StudentQuestionRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'lernplattform.retention.school_years_kept' => 1,
            'lernplattform.retention.school_year_starts' => '08-01',
            'lernplattform.retention.one_year_rule_from' => null,
        ]);
        $this->travelTo('2026-08-01');
    }

    public function test_questions_to_the_teacher_are_deleted_with_their_expired_lesson_and_counted_beforehand(): void
    {
        $expired = Lesson::factory()->create(['held_on' => '2026-07-31', 'created_at' => '2026-07-31', 'updated_at' => '2026-07-31']);
        $current = Lesson::factory()->create(['held_on' => '2026-08-01']);
        $expiredQuestion = StudentQuestion::factory()->for($expired)->create();
        $currentQuestion = StudentQuestion::factory()->for($current)->create();

        $this->assertSame(0, Artisan::call('lernplattform:prune-learning-data', ['--dry-run' => true]));
        $this->assertStringContainsString('Rückfragen', Artisan::output());
        $this->assertModelExists($expiredQuestion);

        $this->artisan('lernplattform:prune-learning-data')->assertSuccessful();

        $this->assertModelMissing($expiredQuestion);
        $this->assertModelExists($currentQuestion);
    }
}
