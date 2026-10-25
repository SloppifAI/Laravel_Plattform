<?php

namespace Tests\Feature\Review;

use App\Models\ReviewItem;
use App\Models\User;
use App\QuestionType;
use App\Support\ReviewSchedule;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewDemoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_student_has_questions_to_revise_and_seeding_again_adds_none(): void
    {
        $this->seed(DatabaseSeeder::class);

        $emilian = User::query()->where('username', 'schueler')->sole();
        $count = ReviewItem::query()->count();

        $this->assertGreaterThan(0, ReviewSchedule::todaysCount($emilian));
        $this->assertSame(0, ReviewItem::query()->whereHas('question', fn ($question) => $question->where('type', QuestionType::Reflection))->count());
        $this->assertSame(0, ReviewItem::query()->whereNotIn('student_id', User::query()->where('role', 'student')->select('id'))->count());

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($count, ReviewItem::query()->count());
    }
}
