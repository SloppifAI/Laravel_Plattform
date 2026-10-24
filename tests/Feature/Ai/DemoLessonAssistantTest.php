<?php

namespace Tests\Feature\Ai;

use App\Ai\DemoLessonAssistant;
use App\Ai\LessonAssistant;
use App\MessageAuthor;
use App\Models\Competency;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Submission;
use App\QuestionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoLessonAssistantTest extends TestCase
{
    use RefreshDatabase;

    private const string NOTES = <<<'NOTES'
        Tafelbild: Fotosynthese
        - Pflanzen bauen mit Lichtenergie aus Kohlenstoffdioxid und Wasser Glucose auf.
        - Dabei wird Sauerstoff als Nebenprodukt frei.
        - Der grüne Farbstoff Chlorophyll in den Chloroplasten fängt das Licht ein.
        - Versuch: Wir haben eine Wasserpest unter eine Lampe gestellt und die aufsteigenden Sauerstoffbläschen gezählt.
        - Je heller das Licht, desto mehr Bläschen haben wir beobachtet.
        - Die Glucose nutzt die Pflanze als Energiespeicher und zum Aufbau von Stärke.
        NOTES;

    public function test_it_is_the_assistant_used_by_default(): void
    {
        $this->assertInstanceOf(DemoLessonAssistant::class, app(LessonAssistant::class));
    }

    public function test_it_drafts_a_summary_and_four_questions_mapped_to_the_subjects_competencies(): void
    {
        $lesson = $this->photosynthesisLesson();
        $biology = Competency::factory()->create(['subject' => 'Biologie']);
        $crossSubject = Competency::factory()->create(['subject' => null]);
        Competency::factory()->create(['subject' => 'Mathematik']);

        $draft = app(DemoLessonAssistant::class)->draftHomework($lesson);

        $this->assertStringContainsString('„Fotosynthese“', $draft['summary']);
        $this->assertStringNotContainsString('- ', $draft['summary']);
        $this->assertStringNotContainsString('Tafelbild', $draft['summary']);
        $sentenceCount = preg_match_all('/[.!?](?=\s+\p{Lu}|$)/u', $draft['summary']);
        $this->assertGreaterThanOrEqual(3, $sentenceCount);
        $this->assertLessThanOrEqual(5, $sentenceCount);

        $this->assertSame(
            [QuestionType::Comprehension, QuestionType::Comprehension, QuestionType::Application, QuestionType::Reflection],
            array_column($draft['questions'], 'type'),
        );
        $this->assertStringContainsString('am 21.10.', $draft['questions'][3]['prompt']);
        $this->assertStringContainsString('Wasserpest', $draft['questions'][3]['prompt']);

        foreach ($draft['questions'] as $question) {
            $this->assertNotSame('', $question['prompt']);
            $this->assertNotSame('', $question['expected_answer']);
        }

        $this->assertEqualsCanonicalizing(
            [$biology->id, $crossSubject->id],
            array_values(array_unique(array_column($draft['questions'], 'competency_id'))),
        );
        $this->assertEquals($draft, app(DemoLessonAssistant::class)->draftHomework($lesson));
    }

    public function test_questions_stay_unmapped_without_competencies(): void
    {
        $draft = app(DemoLessonAssistant::class)->draftHomework($this->photosynthesisLesson());

        $this->assertSame([null, null, null, null], array_column($draft['questions'], 'competency_id'));
    }

    public function test_a_submission_covering_the_lesson_scores_clearly_higher_than_an_off_topic_one(): void
    {
        $lesson = $this->photosynthesisLesson();
        $assistant = app(DemoLessonAssistant::class);

        foreach ($assistant->draftHomework($lesson)['questions'] as $position => $question) {
            $lesson->questions()->create([...$question, 'position' => $position]);
        }

        $onTopic = $this->submit(
            $lesson,
            'Bei der Fotosynthese bauen Pflanzen mit Licht aus Kohlenstoffdioxid und Wasser Glucose auf. Das Chlorophyll in den Chloroplasten fängt das Licht ein und dabei entsteht Sauerstoff. Im Versuch mit der Wasserpest unter der Lampe sind mehr Bläschen aufgestiegen, je heller das Licht war.',
            fn (Question $question): string => $question->type === QuestionType::Reflection
                ? 'Mich hat überrascht, dass die Wasserpest unter der hellen Lampe so viele Sauerstoffbläschen gebildet hat. Ich hätte nicht gedacht, dass man die Fotosynthese so direkt sehen kann, weil Sauerstoff ja unsichtbar ist und die Pflanze nur Licht bekommt.'
                : $question->expected_answer,
        );
        $offTopic = $this->submit(
            $lesson,
            'Am Wochenende war ich beim Fußball und wir haben zwei zu eins gewonnen. Danach gab es Pizza bei meinem Freund.',
            fn (Question $question): string => 'Keine Ahnung.',
        );

        $good = $assistant->evaluateSubmission($onTopic);
        $poor = $assistant->evaluateSubmission($offTopic);

        $this->assertGreaterThanOrEqual(75, $good['understanding_score']);
        $this->assertLessThan(50, $poor['understanding_score']);
        $this->assertGreaterThan($poor['understanding_score'] + 30, $good['understanding_score']);
        $this->assertEquals($good, $assistant->evaluateSubmission($onTopic));

        foreach ([$good, $poor] as $evaluation) {
            $this->assertEqualsCanonicalizing($lesson->questions->modelKeys(), array_keys($evaluation['answers']));
            $this->assertNotSame('', $evaluation['feedback']);
            $this->assertLessThanOrEqual(3, count($evaluation['gaps']));

            foreach ($evaluation['answers'] as $answer) {
                $this->assertGreaterThanOrEqual(0, $answer['score']);
                $this->assertLessThanOrEqual(100, $answer['score']);
                $this->assertNotSame('', $answer['feedback']);
            }
        }

        $this->assertCount(3, $poor['gaps']);
        $this->assertStringStartsWith('Gehe noch einmal auf „', $poor['gaps'][0]);
    }

    public function test_the_tutor_asks_about_a_key_term_the_student_has_not_mentioned_yet(): void
    {
        $session = ExplanationSession::factory()->for($this->photosynthesisLesson())->create(['topic' => 'Fotosynthese']);
        $session->messages()->create(['author' => MessageAuthor::Student, 'content' => 'Bei der Fotosynthese brauchen Pflanzen Licht.']);
        $assistant = app(DemoLessonAssistant::class);

        $first = $assistant->askFollowUp($session);

        $this->assertSame(1, preg_match('/„([^“]+)“/u', $first, $match));
        $askedTerm = $match[1];
        $this->assertStringContainsString($askedTerm, self::NOTES);
        $this->assertStringNotContainsStringIgnoringCase($askedTerm, 'Bei der Fotosynthese brauchen Pflanzen Licht.');

        $session->messages()->create(['author' => MessageAuthor::Tutor, 'content' => $first]);
        $session->messages()->create(['author' => MessageAuthor::Student, 'content' => "{$askedTerm} gehört auch dazu, weil die Pflanze es braucht."]);

        $second = $assistant->askFollowUp($session);

        $this->assertNotSame($first, $second);
        $this->assertStringNotContainsString("„{$askedTerm}“", $second);
    }

    public function test_the_tutor_never_repeats_its_previous_question(): void
    {
        $session = ExplanationSession::factory()->create(['topic' => 'Fotosynthese']);
        $session->messages()->create(['author' => MessageAuthor::Student, 'content' => 'Fotosynthese ist, wenn Pflanzen aus Licht Zucker machen.']);
        $assistant = app(DemoLessonAssistant::class);
        $previous = null;

        for ($round = 0; $round < 8; $round++) {
            $question = $assistant->askFollowUp($session);

            $this->assertNotSame($previous, $question);
            $this->assertStringEndsWith('?', $question);

            $session->messages()->create(['author' => MessageAuthor::Tutor, 'content' => $question]);
            $session->messages()->create(['author' => MessageAuthor::Student, 'content' => 'Das ist so, weil Pflanzen Energie brauchen.']);
            $previous = $question;
        }
    }

    public function test_a_thorough_explanation_is_assessed_higher_than_a_brief_one(): void
    {
        $lesson = $this->photosynthesisLesson();
        $thorough = ExplanationSession::factory()->for($lesson)->create(['topic' => 'Fotosynthese']);
        $brief = ExplanationSession::factory()->for($lesson)->create(['topic' => 'Fotosynthese']);

        foreach ([
            'Bei der Fotosynthese bauen Pflanzen aus Kohlenstoffdioxid und Wasser Glucose auf. Dafür brauchen sie Licht, weil das die Energie liefert.',
            'Das Licht fängt das Chlorophyll ein, das ist der grüne Farbstoff in den Chloroplasten. Deshalb sind Blätter grün.',
            'Dabei entsteht Sauerstoff. Zum Beispiel haben wir bei der Wasserpest unter der Lampe Bläschen gezählt, und je heller das Licht war, desto mehr Bläschen gab es.',
        ] as $explanation) {
            $thorough->messages()->create(['author' => MessageAuthor::Student, 'content' => $explanation]);
        }

        $brief->messages()->create(['author' => MessageAuthor::Student, 'content' => 'Pflanzen sind grün.']);

        $assistant = app(DemoLessonAssistant::class);
        $good = $assistant->assessExplanation($thorough);
        $poor = $assistant->assessExplanation($brief);

        $this->assertGreaterThan($poor['understanding_score'] + 30, $good['understanding_score']);
        $this->assertLessThanOrEqual(100, $good['understanding_score']);
        $this->assertGreaterThanOrEqual(0, $poor['understanding_score']);
        $this->assertStringContainsString('Gut erklärt hast du', $good['feedback']);
        $this->assertStringContainsString('Noch nicht erklärt hast du', $poor['feedback']);
    }

    private function photosynthesisLesson(): Lesson
    {
        return Lesson::factory()->create([
            'subject' => 'Biologie',
            'topic' => 'Fotosynthese',
            'held_on' => '2026-10-21',
            'notes' => self::NOTES,
        ]);
    }

    /**
     * @param  callable(Question): string  $answerFor
     */
    private function submit(Lesson $lesson, string $summary, callable $answerFor): Submission
    {
        $submission = Submission::factory()->for($lesson)->create(['summary' => $summary]);

        foreach ($lesson->questions as $question) {
            $submission->answers()->create(['question_id' => $question->id, 'answer' => $answerFor($question)]);
        }

        return $submission;
    }
}
