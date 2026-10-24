<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\HomeworkDrafter;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\QuestionType;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Ai\Exceptions\AiException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Hausaufgabe mit KI erstellen" end to end, without a mocked assistant: the school decides whether the real AI or the
 * demo logic drafts, and the notice says which one it was.
 */
class LessonDraftWithAiTest extends TestCase
{
    use EnablesSchoolAi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preventRealAiCalls();
    }

    private function lessonIn(School $school): Lesson
    {
        $teacher = User::factory()->teacher()->for($school)->create();

        return Lesson::factory()
            ->for(SchoolClass::factory()->for($school)->for($teacher, 'teacher')->create())
            ->for($teacher, 'teacher')
            ->create(['subject' => 'Biologie', 'topic' => 'Fotosynthese', 'notes' => "Pflanzen bauen mit Lichtenergie Glucose auf.\nVersuch: Wasserpest unter der Lampe, Sauerstoffbläschen gezählt."]);
    }

    private function draft(Lesson $lesson): void
    {
        $this->actingAs($lesson->teacher);

        Livewire::test(EditLesson::class, ['record' => $lesson->getRouteKey()])->callAction('draftHomework');
    }

    public function test_a_school_without_opt_in_gets_the_demo_draft_and_the_notice_says_so(): void
    {
        HomeworkDrafter::fake()->preventStrayPrompts();
        $lesson = $this->lessonIn(School::factory()->create());

        $this->draft($lesson);

        $this->assertSame(
            [QuestionType::Comprehension, QuestionType::Comprehension, QuestionType::Application, QuestionType::Application, QuestionType::Reflection],
            $lesson->questions()->pluck('type')->all(),
        );
        HomeworkDrafter::assertNeverPrompted();

        $notification = $this->sentNotifications()->sole();
        $this->assertSame('Homework created', $notification['title']);
        $this->assertStringContainsString('Demo mode', $notification['body']);
    }

    public function test_a_school_with_opt_in_gets_the_ai_draft_without_the_demo_notice(): void
    {
        $school = $this->enableAiFor(School::factory()->create());
        $question = fn (string $type, string $prompt): array => ['type' => $type, 'prompt' => $prompt, 'expected_answer' => 'Antwort.', 'competency_id' => null];
        HomeworkDrafter::fake([[
            'summary' => 'Pflanzen bauen mit Licht Glucose auf.',
            'questions' => [
                $question('comprehension', 'Was ist Chlorophyll?'),
                $question('comprehension', 'Wozu braucht die Pflanze Licht?'),
                $question('application', 'Warum stehen Pflanzen am Fenster?'),
                $question('application', 'Was passiert im Dunkeln?'),
                $question('reflection', 'Was hat dich am Versuch überrascht?'),
            ],
        ]]);
        $lesson = $this->lessonIn($school);

        $this->draft($lesson);

        $this->assertSame('Pflanzen bauen mit Licht Glucose auf.', $lesson->refresh()->summary);
        $this->assertSame(
            ['Was ist Chlorophyll?', 'Wozu braucht die Pflanze Licht?', 'Warum stehen Pflanzen am Fenster?', 'Was passiert im Dunkeln?', 'Was hat dich am Versuch überrascht?'],
            $lesson->questions()->pluck('prompt')->all(),
        );
        HomeworkDrafter::assertPromptedTimes(1);

        $notification = $this->sentNotifications()->sole();
        $this->assertSame('Homework created', $notification['title']);
        $this->assertStringNotContainsString('Demo mode', $notification['body']);
    }

    public function test_a_failing_ai_keeps_the_questions_and_does_not_fall_back_to_the_demo_logic(): void
    {
        $school = $this->enableAiFor(School::factory()->create());
        HomeworkDrafter::fake(fn () => throw new AiException('Gemini Error: [503] überlastet'));
        $lesson = $this->lessonIn($school);
        Question::factory()->for($lesson)->create(['prompt' => 'Alte Frage']);

        $this->draft($lesson);

        $this->assertSame(['Alte Frage'], $lesson->questions()->pluck('prompt')->all());
        $this->assertNull($lesson->refresh()->summary);
        $this->assertSame('AI draft failed', $this->sentNotifications()->sole()['title']);
    }

    /**
     * The notifications sent so far, as the browser would receive them.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function sentNotifications(): Collection
    {
        app(NotificationMailbox::class)->flush();

        $component = new Notifications;
        $component->mount();
        $component->pullNotificationsFromSession();

        return $component->notifications->map(fn (Notification $notification): array => $notification->toArray())->values();
    }
}
