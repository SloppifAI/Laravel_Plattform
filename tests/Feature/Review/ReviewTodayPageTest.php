<?php

namespace Tests\Feature\Review;

use App\Filament\Pages\ReviewToday;
use App\Filament\Widgets\StudentLearningWidget;
use App\Models\Answer;
use App\Models\Lesson;
use App\Models\ReviewItem;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\QuestionLevel;
use App\QuestionType;
use App\Support\ReviewSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The page "Heute wiederholen" and the card on the student's start page: the queue (AC-3), the solution that stays out of
 * the page until it is asked for (AC-4), who may see what (AC-5) and the separation of schools (AC-9).
 */
class ReviewTodayPageTest extends TestCase
{
    use CreatesReviewFixtures;
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-20 10:00:00');
        $this->student = $this->newStudent();
    }

    // ---- AC-3: the queue ---------------------------------------------------------------------------------------------

    public function test_the_page_shows_at_most_the_daily_limit_oldest_first_and_only_the_own_questions(): void
    {
        $this->assertSame(5, config('lernplattform.review.daily_limit'));

        // Seven due questions, created out of order; the two youngest must wait.
        $dueDates = ['2026-10-18', '2026-10-12', '2026-10-19', '2026-10-15', '2026-10-20', '2026-10-17', '2026-10-13'];
        $items = collect($dueDates)->map(fn (string $date, int $index): ReviewItem => $this->itemFor($this->student, ['prompt' => 'Prompt-Nummer-'.($index + 1).'-Ende'], ['due_on' => $date]));

        // Questions of a classmate, in the same lesson and due long before, are none of this student's business.
        $classmate = User::factory()->student($this->student->schoolClass)->create();
        $this->itemFor($classmate, ['prompt' => 'Frage-des-Mitschuelers'], ['due_on' => '2026-10-01']);
        // Not due yet.
        $this->itemFor($this->student, ['prompt' => 'Prompt-noch-nicht-faellig'], ['due_on' => '2026-10-21']);

        $oldestFirst = $items->sortBy(fn (ReviewItem $item): string => $item->due_on->toDateString())->values();
        $queue = ReviewSchedule::todaysQueue($this->student);

        $this->assertSame($oldestFirst->take(5)->pluck('id')->all(), $queue->pluck('id')->all());
        $this->assertSame(5, ReviewSchedule::todaysCount($this->student));

        $page = Livewire::actingAs($this->student)->test(ReviewToday::class);

        $page->assertSee('Prompt-Nummer-2-Ende')
            ->assertSee('Question 1 of 5')
            ->assertDontSee('Prompt-Nummer-1-Ende')
            ->assertDontSee('Frage-des-Mitschuelers')
            ->assertDontSee('Prompt-noch-nicht-faellig');

        // One at a time, in the order of the due dates, and then the day is done although two questions are due still.
        foreach ($oldestFirst->take(5) as $index => $item) {
            $page->assertSee($item->question->prompt)->assertSee('Question '.($index + 1).' of 5');

            foreach ($oldestFirst->take(5)->forget($index) as $other) {
                $page->assertDontSee($other->question->prompt);
            }

            $page->call('reveal', $item->id)->call('rate', $item->id, 'knew');
        }

        $page->assertSee('Done for today')
            ->assertSee('You revised 5 questions today.')
            ->assertDontSee('Prompt-Nummer-1-Ende')
            ->assertDontSee('Prompt-Nummer-7-Ende');
        $this->assertSame(0, ReviewSchedule::todaysCount($this->student));

        // Tomorrow the two that waited are there, and the one that was not due yet.
        $this->travelTo('2026-10-21 08:00:00');

        $this->assertSame(
            ['Prompt-Nummer-3-Ende', 'Prompt-Nummer-5-Ende', 'Prompt-noch-nicht-faellig'],
            ReviewSchedule::todaysQueue($this->student)->map(fn (ReviewItem $item): string => $item->question->prompt)->all(),
        );
    }

    public function test_a_student_without_anything_due_sees_that_nothing_is_to_revise(): void
    {
        $this->itemFor($this->student, ['prompt' => 'Prompt-morgen'], ['due_on' => '2026-10-21']);

        Livewire::actingAs($this->student)->test(ReviewToday::class)
            ->assertSee('Nothing to revise today')
            ->assertDontSee('Prompt-morgen')
            ->assertDontSee('Show solution');
    }

    public function test_a_reflection_question_is_never_offered(): void
    {
        $this->itemFor($this->student, ['type' => QuestionType::Reflection, 'prompt' => 'Reflexionsfrage-hier']);
        $kept = $this->itemFor($this->student, ['prompt' => 'Verstaendnisfrage-hier']);

        $this->assertSame([$kept->id], ReviewSchedule::todaysQueue($this->student)->pluck('id')->all());

        Livewire::actingAs($this->student)->test(ReviewToday::class)
            ->assertSee('Verstaendnisfrage-hier')
            ->assertDontSee('Reflexionsfrage-hier');
    }

    public function test_the_question_is_shown_in_the_level_the_student_answered_it_in(): void
    {
        $lesson = Lesson::factory()->for($this->student->schoolClass)->publishedFor([$this->student])->create();
        $item = $this->itemFor($this->student, ['prompt' => 'Standardtext-der-Frage', 'prompt_basis' => 'Einstiegstext-der-Frage', 'prompt_plus' => 'Profitext-der-Frage'], lesson: $lesson);
        $submission = Submission::factory()->for($lesson)->for($this->student, 'student')->evaluated()->create();
        Answer::factory()->for($submission)->for($item->question)->create(['level' => QuestionLevel::Plus->value]);

        $this->assertSame('Profitext-der-Frage', $item->askedPrompt());

        Livewire::actingAs($this->student)->test(ReviewToday::class)
            ->assertSee('Profitext-der-Frage')
            ->assertDontSee('Standardtext-der-Frage')
            ->assertDontSee('Einstiegstext-der-Frage');
    }

    // ---- AC-4: the solution stays out of the page until it is asked for ---------------------------------------------

    public function test_the_solution_is_neither_on_the_page_nor_in_the_livewire_snapshot_before_it_is_shown(): void
    {
        $item = $this->itemFor($this->student, ['prompt' => 'Was ist Chlorophyll?', 'expected_answer' => 'LOESUNG-GEHEIM-4711']);
        $this->actingAs($this->student);

        // The whole page, with the snapshot Livewire embeds in it.
        $this->get(ReviewToday::getUrl())
            ->assertOk()
            ->assertSee('Was ist Chlorophyll?')
            ->assertSee('Show solution')
            ->assertDontSee('LOESUNG-GEHEIM-4711')
            ->assertDontSee('Knew it straight away');

        $page = Livewire::test(ReviewToday::class);

        $page->assertDontSee('LOESUNG-GEHEIM-4711');
        $this->assertStringNotContainsString('LOESUNG-GEHEIM-4711', json_encode($page->snapshot));
        $this->assertStringNotContainsString('LOESUNG-GEHEIM-4711', $page->html());

        $page->call('reveal', $item->id)
            ->assertSee('LOESUNG-GEHEIM-4711')
            ->assertSee(['Knew it straight away', 'With effort', 'Did not know it'])
            ->assertDontSee('Show solution');

        // Even then only the number of the question travels in the state, never its text or its solution.
        $this->assertStringNotContainsString('LOESUNG-GEHEIM-4711', json_encode($page->snapshot));
        $this->assertStringNotContainsString('Was ist Chlorophyll?', json_encode($page->snapshot));
        $this->assertSame($item->id, $page->get('revealedItemId'));
    }

    public function test_the_own_answer_field_is_not_bound_to_the_server(): void
    {
        $this->itemFor($this->student, ['prompt' => 'Was ist Chlorophyll?']);

        $html = Livewire::actingAs($this->student)->test(ReviewToday::class)->html();

        $this->assertStringContainsString('<textarea', $html);
        $this->assertStringContainsString('Your answer (just for you)', $html);
        $this->assertStringContainsString('x-model="own"', $html);
        $this->assertStringNotContainsString('wire:model', $html);
    }

    public function test_a_rating_needs_the_solution_to_be_shown_first_and_the_right_question(): void
    {
        $first = $this->itemFor($this->student, ['prompt' => 'Frage-eins'], ['due_on' => '2026-10-10']);
        $second = $this->itemFor($this->student, ['prompt' => 'Frage-zwei'], ['due_on' => '2026-10-11']);
        $page = Livewire::actingAs($this->student)->test(ReviewToday::class);

        // Without "Lösung zeigen" a rating counts for nothing, and neither does a wrong question or rating.
        $page->call('rate', $first->id, 'knew')
            ->call('reveal', $second->id)
            ->assertDontSee('Knew it straight away')
            ->call('reveal', $first->id)
            ->call('rate', $second->id, 'knew')
            ->call('rate', $first->id, 'brilliant');

        $this->assertSame([1, 0, null], [$first->fresh()->box, $first->fresh()->knew_count, $first->fresh()->last_reviewed_at]);
        $this->assertSame(1, $second->fresh()->box);

        // The one shown, rated for real: the next question follows, with its solution hidden again.
        $page->call('reveal', $first->id)
            ->call('rate', $first->id, 'knew')
            ->assertSee('Frage-zwei')
            ->assertDontSee('Frage-eins')
            ->assertDontSee('Knew it straight away');

        $this->assertSame([2, 1, '2026-10-23'], [$first->fresh()->box, $first->fresh()->knew_count, $first->fresh()->due_on->toDateString()]);
    }

    public function test_a_question_rated_in_another_tab_is_not_rated_twice(): void
    {
        $item = $this->itemFor($this->student, ['prompt' => 'Frage-eins'], ['due_on' => '2026-10-10']);
        $this->itemFor($this->student, ['prompt' => 'Frage-zwei'], ['due_on' => '2026-10-19']);
        $tabOne = Livewire::actingAs($this->student)->test(ReviewToday::class);
        $tabTwo = Livewire::actingAs($this->student)->test(ReviewToday::class);

        $tabOne->call('reveal', $item->id);
        $tabTwo->call('reveal', $item->id);
        $tabOne->call('rate', $item->id, 'forgot');
        $tabTwo->call('rate', $item->id, 'knew');

        $item->refresh();
        $this->assertSame([1, 0, 1], [$item->box, $item->knew_count, $item->forgot_count]);
    }

    // ---- AC-5: private to the student -------------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function everybodyButTheStudent(): array
    {
        return [
            'teacher of the class' => ['teacher'],
            'school administration' => ['admin'],
            'platform operator' => ['operator'],
            'classmate' => ['classmate'],
        ];
    }

    private function somebodyElse(string $who): User
    {
        return match ($who) {
            'teacher' => $this->student->schoolClass->teacher,
            'admin' => User::factory()->admin()->for($this->student->school)->create(),
            'operator' => User::factory()->operator()->create(),
            'classmate' => User::factory()->student($this->student->schoolClass)->create(),
        };
    }

    #[DataProvider('everybodyButTheStudent')]
    public function test_nobody_but_the_student_opens_the_page_or_sees_the_items(string $who): void
    {
        $item = $this->itemFor($this->student, ['prompt' => 'Frage-des-Schuelers']);
        $other = $this->somebodyElse($who);

        $this->actingAs($other);

        $this->assertSame($other->isStudent(), ReviewToday::canAccess());
        $this->assertFalse(Gate::forUser($other)->allows('view', $item));
        $this->assertFalse(Gate::forUser($other)->allows('rate', $item));
        $this->assertFalse(Gate::forUser($other)->allows('update', $item));
        $this->assertFalse(Gate::forUser($other)->allows('delete', $item));

        if ($other->isStudent()) {
            // A classmate gets his own, empty page, and cannot reach the item by its number.
            $this->get(ReviewToday::getUrl())->assertOk()->assertDontSee('Frage-des-Schuelers');

            Livewire::actingAs($other)->test(ReviewToday::class)
                ->call('reveal', $item->id)
                ->call('rate', $item->id, 'forgot')
                ->assertSee('Nothing to revise today')
                ->assertDontSee('Frage-des-Schuelers');
        } else {
            $this->get(ReviewToday::getUrl())->assertForbidden();
        }

        $this->assertSame([1, 0, 0], [$item->fresh()->box, $item->fresh()->knew_count, $item->fresh()->forgot_count]);
        $this->assertNull($item->fresh()->last_reviewed_at);
    }

    public function test_only_the_student_may_view_or_rate_an_item_and_nobody_may_change_it_by_hand(): void
    {
        $item = $this->itemFor($this->student);

        $this->assertTrue(Gate::forUser($this->student)->allows('view', $item));
        $this->assertTrue(Gate::forUser($this->student)->allows('rate', $item));
        $this->assertTrue(Gate::forUser($this->student)->allows('viewAny', ReviewItem::class));

        foreach (['create' => ReviewItem::class, 'update' => $item, 'delete' => $item] as $ability => $subject) {
            $this->assertFalse(Gate::forUser($this->student)->allows($ability, $subject), $ability);
        }

        $this->assertFalse(Gate::forUser($this->student->schoolClass->teacher)->allows('viewAny', ReviewItem::class));
    }

    public function test_students_find_the_page_in_the_menu(): void
    {
        $this->itemFor($this->student);

        $this->actingAs($this->student)->get('/')->assertOk()->assertSee(ReviewToday::getUrl(), false)->assertSee('Revise today');
    }

    // ---- AC-9: schools stay apart -----------------------------------------------------------------------------------

    public function test_after_a_change_of_school_no_question_of_the_old_school_is_offered(): void
    {
        $oldItem = $this->itemFor($this->student, ['prompt' => 'Frage-der-alten-Schule']);

        $this->assertSame(1, ReviewSchedule::todaysCount($this->student));

        // The student moves to a class of another school: the rows stay, but nothing of the old school is shown any more.
        $newClass = SchoolClass::factory()->create();
        $this->student->forceFill(['school_class_id' => $newClass->id, 'school_id' => $newClass->school_id])->save();
        $moved = $this->student->fresh();

        $this->assertNotSame($oldItem->question->lesson->schoolClass->school_id, $moved->school_id);
        $this->assertSame(0, ReviewSchedule::todaysCount($moved));
        $this->assertCount(0, ReviewSchedule::todaysQueue($moved));

        Livewire::actingAs($moved)->test(ReviewToday::class)
            ->assertSee('Nothing to revise today')
            ->assertDontSee('Frage-der-alten-Schule');
        Livewire::actingAs($moved)->test(StudentLearningWidget::class)->assertDontSee('Revise today');

        $this->assertModelExists($oldItem);
    }

    public function test_a_lesson_of_another_school_in_the_assignments_is_not_offered(): void
    {
        $foreignLesson = Lesson::factory()->for(SchoolClass::factory()->create())->publishedFor([$this->student])->create();
        $this->itemFor($this->student, ['prompt' => 'Frage-aus-fremder-Schule'], lesson: $foreignLesson);

        $this->assertNotSame($this->student->school_id, $foreignLesson->schoolClass->school_id);
        $this->assertSame(0, ReviewSchedule::todaysCount($this->student));

        Livewire::actingAs($this->student)->test(ReviewToday::class)->assertDontSee('Frage-aus-fremder-Schule');
    }

    public function test_a_student_of_school_a_never_sees_the_questions_of_school_b(): void
    {
        $studentOfB = $this->newStudent();
        $this->itemFor($studentOfB, ['prompt' => 'Frage-aus-Schule-B']);
        $own = $this->itemFor($this->student, ['prompt' => 'Frage-aus-Schule-A']);

        $this->assertNotSame($this->student->school_id, $studentOfB->school_id);
        $this->assertSame([$own->id], ReviewSchedule::todaysQueue($this->student)->pluck('id')->all());

        Livewire::actingAs($this->student)->test(ReviewToday::class)
            ->assertSee('Frage-aus-Schule-A')
            ->assertDontSee('Frage-aus-Schule-B');
    }

    public function test_questions_of_homework_that_was_taken_back_or_is_no_longer_assigned_are_not_offered(): void
    {
        $lesson = Lesson::factory()->for($this->student->schoolClass)->publishedFor([$this->student])->create();
        $item = $this->itemFor($this->student, ['prompt' => 'Frage-der-Stunde'], lesson: $lesson);

        $this->assertSame(1, ReviewSchedule::todaysCount($this->student));

        $lesson->update(['published_at' => null]);
        $this->assertSame(0, ReviewSchedule::todaysCount($this->student), 'taken back');

        $lesson->update(['published_at' => now()]);
        $this->assertSame(1, ReviewSchedule::todaysCount($this->student));

        $lesson->students()->detach($this->student);
        $this->assertSame(0, ReviewSchedule::todaysCount($this->student), 'no longer assigned');
        $this->assertModelExists($item);
    }

    // ---- The card on the start page ---------------------------------------------------------------------------------

    public function test_the_start_page_shows_a_card_with_the_number_of_questions_that_are_due(): void
    {
        foreach (range(1, 3) as $number) {
            $this->itemFor($this->student, ['prompt' => "Frage-{$number}"]);
        }

        Livewire::actingAs($this->student)->test(StudentLearningWidget::class)
            ->assertSee('Revise today: 3 questions')
            ->assertSee('Revise now')
            ->assertSeeHtml('href="'.ReviewToday::getUrl().'"');
    }

    public function test_the_card_counts_at_most_the_daily_limit_and_is_gone_when_nothing_is_due_or_the_limit_is_reached(): void
    {
        Livewire::actingAs($this->student)->test(StudentLearningWidget::class)
            ->assertSee('Hello')
            ->assertDontSee('Revise today')
            ->assertDontSeeHtml(ReviewToday::getUrl());

        foreach (range(1, 7) as $number) {
            $this->itemFor($this->student, ['prompt' => "Frage-{$number}"]);
        }

        Livewire::actingAs($this->student)->test(StudentLearningWidget::class)->assertSee('Revise today: 5 questions');

        $page = Livewire::actingAs($this->student)->test(ReviewToday::class);

        foreach (range(1, 5) as $ignored) {
            $item = ReviewSchedule::todaysQueue($this->student)->first();
            $page->call('reveal', $item->id)->call('rate', $item->id, 'hard');
        }

        Livewire::actingAs($this->student)->test(StudentLearningWidget::class)->assertDontSee('Revise today');
    }

    public function test_the_card_stays_away_for_teachers(): void
    {
        $this->itemFor($this->student);

        $this->assertSame(1, ReviewSchedule::todaysCount($this->student));
        $this->actingAs($this->student->schoolClass->teacher)->get('/')->assertOk()->assertDontSee('Revise today');
    }
}
