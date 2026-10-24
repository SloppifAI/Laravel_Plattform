<?php

namespace Tests\Feature\Differentiation;

use App\Ai\LessonAssistant;
use App\Filament\Pages\WorkOnHomework;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Submission;
use App\Models\User;
use App\QuestionLevel;
use App\QuestionType;
use App\Support\LessonRelease;
use Filament\Forms\Components\ToggleButtons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Feature\Ai\EnablesSchoolAi;
use Tests\TestCase;

/**
 * The student's side: the level chosen per question (Einstieg, Standard, Profi) and the switch "Einfache Sprache".
 */
class StudentLevelsAndSimpleLanguageTest extends TestCase
{
    use EnablesSchoolAi;
    use RefreshDatabase;

    private const string SECRET = 'GEHEIMER ERWARTUNGSHORIZONT';

    private const string OWN_ANSWER = 'MEINE EIGENE ANTWORT mit genug Worten, damit sie nichts mit dem Lehrertext gemeinsam hat';

    private User $student;

    private Lesson $lesson;

    private Question $leveled;

    private Question $basisOnly;

    private Question $plain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->student()->create();
        $this->lesson = Lesson::factory()->publishedFor([$this->student])->create(['summary' => 'Offizielle Zusammenfassung der Lehrkraft über die Fotosynthese.']);
        $this->leveled = Question::factory()->for($this->lesson)->create([
            'type' => QuestionType::Comprehension,
            'prompt' => 'Standardfrage: Was braucht eine Pflanze?',
            'prompt_basis' => 'Einstiegsfrage: Was braucht eine Pflanze? Tipp: Denk an Licht.',
            'prompt_plus' => 'Profifrage: Was braucht eine Pflanze und warum?',
            'expected_answer' => self::SECRET.' EINS',
            'position' => 1,
        ]);
        $this->basisOnly = Question::factory()->for($this->lesson)->create([
            'type' => QuestionType::Application,
            'prompt' => 'Zweite Standardfrage: Wo wächst Wasserpest?',
            'prompt_basis' => 'Zweite Einstiegsfrage: Wo wächst Wasserpest? Tipp: Denk an einen Teich.',
            'expected_answer' => self::SECRET.' ZWEI',
            'position' => 2,
        ]);
        $this->plain = Question::factory()->for($this->lesson)->create([
            'type' => QuestionType::Reflection,
            'prompt' => 'Dritte Frage ohne Stufen: Was hat dich überrascht?',
            'expected_answer' => self::SECRET.' DREI',
            'position' => 3,
        ]);

        $this->actingAs($this->student);
    }

    private function page(): Testable
    {
        return Livewire::test(WorkOnHomework::class, ['lesson' => $this->lesson]);
    }

    /**
     * @param  array<int, string>  $levels
     * @return array<string, mixed>
     */
    private function formData(array $levels = []): array
    {
        return [
            'levels' => $levels,
            'answers' => [
                $this->leveled->id => self::OWN_ANSWER.' eins',
                $this->basisOnly->id => self::OWN_ANSWER.' zwei',
                $this->plain->id => self::OWN_ANSWER.' drei',
            ],
            'summary' => 'Ich habe gelernt, dass Pflanzen aus Licht, Wasser und Luft ihren eigenen Zucker herstellen und dabei Sauerstoff abgeben.',
        ];
    }

    /**
     * @return array<int, array{score: int, feedback: string}>
     */
    private function evaluatedAnswers(): array
    {
        return collect([$this->leveled, $this->basisOnly, $this->plain])->mapWithKeys(fn (Question $question): array => [$question->id => ['score' => 80, 'feedback' => 'Gut.']])->all();
    }

    public function test_only_questions_with_variants_offer_levels_and_only_those_they_have(): void
    {
        $this->page()
            ->assertFormFieldVisible("levels.{$this->leveled->id}")
            ->assertFormFieldVisible("levels.{$this->basisOnly->id}")
            ->assertFormFieldHidden("levels.{$this->plain->id}")
            ->assertFormFieldExists("levels.{$this->leveled->id}", fn (ToggleButtons $field): bool => $field->getOptions() === ['basis' => 'Einstieg', 'standard' => 'Standard', 'plus' => 'Profi'])
            ->assertFormFieldExists("levels.{$this->basisOnly->id}", fn (ToggleButtons $field): bool => $field->getOptions() === ['basis' => 'Einstieg', 'standard' => 'Standard'])
            ->assertSet("data.levels.{$this->leveled->id}", 'standard')
            ->assertSee('Standardfrage: Was braucht eine Pflanze?')
            ->assertDontSee('Einstiegsfrage')
            ->assertDontSee('Profifrage');
    }

    public function test_the_chosen_level_shows_its_text_and_is_stored_on_the_answers_and_passed_to_the_evaluator(): void
    {
        $seen = [];
        $this->mock(LessonAssistant::class, function (MockInterface $mock) use (&$seen): void {
            $mock->expects('evaluateSubmission')
                ->withArgs(function (Submission $submission) use (&$seen): bool {
                    $seen = $submission->answers->pluck('level', 'question_id')->all();

                    return true;
                })
                ->andReturn(['understanding_score' => 80, 'feedback' => 'Gut.', 'gaps' => [], 'answers' => $this->evaluatedAnswers()]);
        });

        $this->page()
            ->fillForm($this->formData([$this->leveled->id => 'plus', $this->basisOnly->id => 'basis']))
            ->assertSee('Profifrage: Was braucht eine Pflanze und warum?')
            ->assertDontSee('Standardfrage: Was braucht eine Pflanze?')
            ->assertSee('Zweite Einstiegsfrage: Wo wächst Wasserpest?')
            ->call('submit')
            ->assertHasNoFormErrors();

        $expected = [$this->leveled->id => 'plus', $this->basisOnly->id => 'basis', $this->plain->id => null];
        $this->assertSame($expected, $seen);
        $this->assertSame($expected, $this->student->submissions()->first()->answers()->pluck('level', 'question_id')->all());
    }

    public function test_a_question_with_variants_that_was_left_on_its_default_is_stored_as_standard(): void
    {
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('evaluateSubmission')
            ->andReturn(['understanding_score' => 80, 'feedback' => 'Gut.', 'gaps' => [], 'answers' => []]));
        $data = $this->formData();
        unset($data['levels']);

        $this->page()->fillForm($data)->call('submit')->assertHasNoFormErrors();

        $this->assertSame(
            [$this->leveled->id => 'standard', $this->basisOnly->id => 'standard', $this->plain->id => null],
            $this->student->submissions()->first()->answers()->pluck('level', 'question_id')->all(),
        );
    }

    public function test_a_level_the_question_does_not_have_is_refused(): void
    {
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->shouldNotReceive('evaluateSubmission'));

        $this->page()
            ->fillForm($this->formData([$this->basisOnly->id => 'plus']))
            ->call('submit')
            ->assertHasFormErrors(["levels.{$this->basisOnly->id}"]);

        $this->assertSame(0, Submission::count());
    }

    public function test_levels_resolve_to_the_levels_a_question_has(): void
    {
        $this->assertSame(QuestionLevel::Plus, $this->leveled->levelFor('plus'));
        $this->assertSame(QuestionLevel::Standard, $this->basisOnly->levelFor('plus'));
        $this->assertSame(QuestionLevel::Standard, $this->plain->levelFor(QuestionLevel::Basis));
        $this->assertSame(QuestionLevel::Standard, $this->leveled->levelFor('expert'));
        $this->assertSame(QuestionLevel::Standard, $this->leveled->levelFor(null));
        $this->assertFalse($this->plain->hasLevels());
    }

    public function test_the_result_shows_the_text_and_the_level_the_student_answered(): void
    {
        $submission = Submission::factory()->for($this->lesson)->for($this->student, 'student')->evaluated(80)->create();
        $submission->answers()->create(['question_id' => $this->leveled->id, 'answer' => 'Antwort eins', 'level' => 'basis', 'score' => 70, 'feedback' => 'Okay.']);
        $submission->answers()->create(['question_id' => $this->basisOnly->id, 'answer' => 'Antwort zwei', 'level' => 'standard']);
        $submission->answers()->create(['question_id' => $this->plain->id, 'answer' => 'Antwort drei']);

        $this->page()
            ->assertSee('Stufe Einstieg')
            ->assertSee('Einstiegsfrage: Was braucht eine Pflanze? Tipp: Denk an Licht.')
            ->assertDontSee('Standardfrage: Was braucht eine Pflanze?')
            ->assertSee('Zweite Standardfrage: Wo wächst Wasserpest?')
            ->assertDontSee('Stufe Standard')
            ->assertDontSee('Stufe Profi');
    }

    public function test_the_expected_answer_is_never_rendered(): void
    {
        $this->page()->assertDontSee(self::SECRET);

        $this->page()->set('simpleLanguage', true)->fillForm($this->formData([$this->leveled->id => 'plus']))->assertDontSee(self::SECRET);

        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('evaluateSubmission')
            ->andReturn(['understanding_score' => 80, 'feedback' => 'Gut.', 'gaps' => [], 'answers' => $this->evaluatedAnswers()]));

        $this->page()->fillForm($this->formData())->call('submit');
        $this->get(WorkOnHomework::getUrl(['lesson' => $this->lesson]))->assertOk()->assertDontSee(self::SECRET);
    }

    public function test_the_switch_is_remembered_in_the_browser_per_student_and_starts_off(): void
    {
        $this->get(WorkOnHomework::getUrl(['lesson' => $this->lesson]))
            ->assertOk()
            ->assertSee('role="switch"', false)
            ->assertSee("tuto-einfache-sprache-{$this->student->id}", false)
            ->assertSee('Einfache Sprache')
            ->assertSee('Deine eigenen Antworten bleiben, wie du sie schreibst.');

        $this->page()->assertSet('simpleLanguage', false);
    }

    public function test_the_switch_shows_cached_simple_texts_and_asks_the_assistant_once_per_text(): void
    {
        $sent = [];
        $this->mock(LessonAssistant::class, function (MockInterface $mock) use (&$sent): void {
            $mock->shouldReceive('simplify')
                ->times(4)
                ->andReturnUsing(function (string $text, Lesson $lesson) use (&$sent): string {
                    $sent[] = $text;
                    $this->assertTrue($lesson->is($this->lesson));

                    return "Einfach: {$text}";
                });
        });

        $this->page()
            ->assertDontSee('Einfach: ')
            ->fillForm($this->formData())
            ->set('simpleLanguage', true)
            ->assertSee('Einfach: Offizielle Zusammenfassung der Lehrkraft über die Fotosynthese.')
            ->assertSee('Einfach: Standardfrage: Was braucht eine Pflanze?')
            ->assertSee('Einfach: Zweite Standardfrage: Wo wächst Wasserpest?')
            ->assertSee('Einfach: Dritte Frage ohne Stufen: Was hat dich überrascht?')
            // Switching off and on again, or answering the next question, does not ask again.
            ->set('simpleLanguage', false)
            ->assertDontSee('Einfach: ')
            ->set('simpleLanguage', true)
            ->assertSee('Einfach: Offizielle Zusammenfassung')
            ->set("data.answers.{$this->plain->id}", 'Noch eine Antwort');

        $this->assertCount(4, $sent);

        // Another student gets the cached copies.
        $other = User::factory()->student()->create();
        $this->lesson->students()->attach($other);
        $this->actingAs($other);

        $this->page()->set('simpleLanguage', true)->assertSee('Einfach: Standardfrage: Was braucht eine Pflanze?');

        $this->assertSame('Einfach: Offizielle Zusammenfassung der Lehrkraft über die Fotosynthese.', $this->lesson->refresh()->summary_simple);
        $this->assertSame('Einfach: Standardfrage: Was braucht eine Pflanze?', $this->leveled->refresh()->prompt_simple);
        $this->assertNull($this->leveled->prompt_basis_simple);
    }

    public function test_the_assistant_never_gets_what_the_student_wrote(): void
    {
        $sent = [];
        $this->mock(LessonAssistant::class, function (MockInterface $mock) use (&$sent): void {
            $mock->shouldReceive('simplify')->andReturnUsing(function (string $text) use (&$sent): string {
                $sent[] = $text;

                return "Einfach: {$text}";
            });
            $mock->shouldReceive('evaluateSubmission')->andReturn(['understanding_score' => 80, 'feedback' => 'Gut.', 'gaps' => [], 'answers' => $this->evaluatedAnswers()]);
        });

        $this->page()
            ->fillForm($this->formData())
            ->set('simpleLanguage', true)
            ->call('submit')
            ->assertHasNoFormErrors();

        $this->assertNotEmpty($sent);
        $this->assertSame([], array_values(array_filter($sent, fn (string $text): bool => str_contains($text, 'MEINE EIGENE') || str_contains($text, 'Ich habe gelernt'))));
        $this->assertSame([], array_values(array_filter($sent, fn (string $text): bool => str_contains($text, self::SECRET))));
    }

    public function test_the_simple_text_of_the_chosen_level_is_the_one_shown_and_cached(): void
    {
        $sent = [];
        $this->mock(LessonAssistant::class, function (MockInterface $mock) use (&$sent): void {
            $mock->shouldReceive('simplify')->andReturnUsing(function (string $text) use (&$sent): string {
                $sent[] = $text;

                return "Einfach: {$text}";
            });
        });

        $this->page()
            ->set('simpleLanguage', true)
            ->fillForm($this->formData([$this->leveled->id => 'basis']))
            ->assertSee('Einfach: Einstiegsfrage: Was braucht eine Pflanze? Tipp: Denk an Licht.');

        $this->assertContains('Einstiegsfrage: Was braucht eine Pflanze? Tipp: Denk an Licht.', $sent);
        $this->assertSame('Einfach: Einstiegsfrage: Was braucht eine Pflanze? Tipp: Denk an Licht.', $this->leveled->refresh()->prompt_basis_simple);
        // The Profi level was never shown, so it was never simplified.
        $this->assertNull($this->leveled->prompt_plus_simple);
    }

    public function test_a_correction_made_while_the_assistant_works_is_not_overwritten_by_the_stale_simple_copy(): void
    {
        $this->mock(LessonAssistant::class, function (MockInterface $mock): void {
            $mock->shouldReceive('simplify')->andReturnUsing(function (string $text): string {
                // The teacher saves a corrected summary and a corrected question while the AI call is still running.
                Lesson::find($this->lesson->id)->update(['summary' => 'Korrigierte Zusammenfassung.']);
                Question::find($this->plain->id)->update(['prompt' => 'Korrigierte dritte Frage?']);

                return "EINFACH: {$text}";
            });
        });

        $this->page()
            ->set('simpleLanguage', true)
            ->assertSee('EINFACH: Offizielle Zusammenfassung der Lehrkraft über die Fotosynthese.');

        $this->lesson->refresh();
        $this->assertSame('Korrigierte Zusammenfassung.', $this->lesson->summary);
        $this->assertNull($this->lesson->summary_simple);
        $this->assertSame('Korrigierte dritte Frage?', $this->plain->refresh()->prompt);
        $this->assertNull($this->plain->prompt_simple);
        // The texts nobody changed are cached as usual.
        $this->assertSame('EINFACH: Standardfrage: Was braucht eine Pflanze?', $this->leveled->refresh()->prompt_simple);
    }

    public function test_a_student_whose_access_was_taken_back_cannot_use_an_open_page_any_more(): void
    {
        $this->mock(LessonAssistant::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('simplify');
            $mock->shouldNotReceive('evaluateSubmission');
        });

        $switchPage = $this->page();
        $submitPage = $this->page();

        LessonRelease::withdraw($this->lesson);
        $this->lesson->update(['summary' => 'Entwurf der Lehrkraft, noch nicht für Schüler:innen.']);
        $this->assertFalse($this->student->can('view', $this->lesson->refresh()));

        $switchPage->set('simpleLanguage', true)->assertForbidden();
        $submitPage->call('submit')->assertForbidden();

        $this->assertNull($this->lesson->refresh()->summary_simple);
        $this->assertNull($this->leveled->refresh()->prompt_simple);
        $this->assertSame(0, Submission::count());
    }

    public function test_when_the_assistant_fails_the_original_texts_stay_and_the_switch_goes_off(): void
    {
        $this->mock(LessonAssistant::class, fn (MockInterface $mock) => $mock->expects('simplify')->once()->andThrow(new RuntimeException('Die KI ist gerade überlastet.')));

        $this->page()
            ->set('simpleLanguage', true)
            ->assertSet('simpleLanguage', false)
            ->assertNotified('Einfache Sprache ist gerade nicht verfügbar')
            ->assertSee('Offizielle Zusammenfassung der Lehrkraft über die Fotosynthese.')
            ->assertSee('Standardfrage: Was braucht eine Pflanze?');

        $this->assertNull($this->lesson->refresh()->summary_simple);
    }

    public function test_the_offline_demo_writes_the_simple_texts_and_says_so(): void
    {
        $this->lesson->update(['summary' => 'Pflanzen brauchen Licht, z. B. von der Sonne. Außerdem brauchen sie Wasser.']);

        $this->page()
            ->set('simpleLanguage', true)
            ->assertSee('Pflanzen brauchen Licht, zum Beispiel von der Sonne. Auch brauchen sie Wasser.')
            ->assertSee('Demo-Modus: Die einfache Fassung entsteht ohne KI und ist nur ein Beispiel.')
            ->assertDontSee('schreibt eine KI');

        $this->assertSame('Pflanzen brauchen Licht, zum Beispiel von der Sonne. Auch brauchen sie Wasser.', $this->lesson->refresh()->summary_simple);
    }

    public function test_with_the_ai_on_the_student_learns_what_the_ai_sees(): void
    {
        $this->preventRealAiCalls();
        $this->enableAiFor($this->lesson->schoolClass->school);

        $this->page()
            ->assertSee('Die einfache Fassung schreibt eine KI. Sie sieht nur die Texte deiner Lehrkraft, nie deine Antworten.')
            ->assertDontSee('Demo-Modus');
    }

    public function test_the_switch_is_also_there_once_the_homework_was_handed_in(): void
    {
        $submission = Submission::factory()->for($this->lesson)->for($this->student, 'student')->evaluated(80)->create();
        $submission->answers()->create(['question_id' => $this->plain->id, 'answer' => 'Antwort drei']);

        $this->page()
            ->assertSee('role="switch"', false)
            ->set('simpleLanguage', true)
            ->assertSee('Dritte Frage ohne Stufen: Was hat dich überrascht?');
    }
}
