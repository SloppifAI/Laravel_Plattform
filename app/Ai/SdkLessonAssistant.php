<?php

namespace App\Ai;

use App\Ai\Agents\ExplanationAssessor;
use App\Ai\Agents\FollowUpAsker;
use App\Ai\Agents\HomeworkDrafter;
use App\Ai\Agents\LessonAgent;
use App\Ai\Agents\PracticeTaskDrafter;
use App\Ai\Agents\QuestionDifferentiator;
use App\Ai\Agents\SubmissionEvaluator;
use App\Ai\Agents\SummaryReviser;
use App\Ai\Agents\TextSimplifier;
use App\Filament\Pages\ExplainIt;
use App\MessageAuthor;
use App\MistakeType;
use App\Models\Competency;
use App\Models\ExplanationMessage;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\QuestionLevel;
use App\QuestionType;
use App\Support\Pseudonymizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

/**
 * LessonAssistant backed by the Laravel AI SDK (provider and model from `config('lernplattform.ai.*')`, Gemini by default).
 *
 * Reached only through SchoolAwareLessonAssistant, i.e. for schools that opted in (School::aiEnabled()). Privacy: every
 * free text (notes, summaries, answers, conversation) passes the Pseudonymizer with the class roster first, and no
 * username, e-mail or user id is sent; question ids are. Worksheets go as photos and PDFs, which cannot be pseudonymised.
 * Whatever the AI returns is validated and clamped; provider failures become a RuntimeException with a German message.
 */
class SdkLessonAssistant implements LessonAssistant
{
    public function draftHomework(Lesson $lesson): array
    {
        $pseudonymizer = $this->pseudonymizer($lesson->schoolClass);
        $competencies = Competency::query()->forSubject((string) $lesson->subject)->orderBy('id')->get();
        $competencyList = $competencies->isEmpty()
            ? 'Keine hinterlegt – setze competency_id überall auf null.'
            : $competencies->map(fn (Competency $competency): string => "- ID {$competency->id}: {$competency->area} – {$competency->name}")->implode("\n");
        // The teacher's own and edited questions stay next to the draft (HomeworkDraft), so the AI must not repeat them.
        $ownQuestions = $lesson->questions()->where('ai_drafted', false)->pluck('prompt')
            ->map(fn (string $prompt): string => '- '.$pseudonymizer->apply($prompt))
            ->implode("\n");

        // A summary the lesson has stays (HomeworkDraft::keepsSummary()), so the AI writes none and the questions build on it.
        $withSummary = blank($lesson->summary);

        $result = $this->askForData(
            new HomeworkDrafter($withSummary),
            $this->lessonContext($lesson, $pseudonymizer)."\n\nKompetenzen aus dem Referenzrahmen:\n".$competencyList
                .($ownQuestions === '' ? '' : "\n\nFragen, die die Lehrkraft schon hat und behält (nicht wiederholen):\n".$ownQuestions),
            $this->worksheets($lesson),
        );

        return [
            'summary' => $withSummary ? $this->summary($result) : (string) $lesson->summary,
            'questions' => array_map(
                fn (array $question): array => [...$question, 'competency_id' => $this->knownId($question['competency_id'], $competencies->modelKeys())],
                $this->questions($result, HomeworkDrafter::QUESTION_COUNT, 'Die KI hat keine Fragen geliefert. Bitte versuche es erneut.'),
            ),
        ];
    }

    public function draftPracticeTask(Competency $competency, string $subject, Collection $sourceLessons, array $gaps, ?MistakeType $mistakeType = null): array
    {
        $sourceLessons = $sourceLessons->values();
        $pseudonymizers = $sourceLessons->map(fn (Lesson $lesson): Pseudonymizer => $this->pseudonymizer($lesson->schoolClass));

        // The hints come from earlier AI evaluations of pseudonymised texts; they pass every roster of the lessons nevertheless.
        $gapList = $gaps === []
            ? 'Keine konkreten Hinweise vorhanden.'
            : collect($gaps)->map(fn (string $gap): string => '- '.$pseudonymizers->reduce(fn (string $text, Pseudonymizer $pseudonymizer): string => $pseudonymizer->apply($text), $gap))->implode("\n");

        $lessons = $sourceLessons->map(fn (Lesson $lesson, int $index): string => implode("\n", array_filter([
            'Thema: '.$pseudonymizers[$index]->apply((string) $lesson->topic),
            'Datum der Stunde: '.($lesson->held_on?->format('d.m.Y') ?? 'unbekannt'),
            // The caller withholds the notes of lessons the teacher may not view.
            blank($lesson->notes) ? null : "Notizen der Lehrkraft:\n".$pseudonymizers[$index]->apply($lesson->notes),
        ])))->implode("\n\n---\n\n");

        $result = $this->askForData(new PracticeTaskDrafter, implode("\n\n", array_filter([
            "Fach: {$subject}",
            "Kompetenz: {$competency->area} – {$competency->name}\nBeschreibung: ".($competency->description ?: '(keine)'),
            "Hinweise der KI auf Lücken (anonym):\n".$gapList,
            $mistakeType === null ? null : "Häufigste Fehlerart in der Gruppe: {$this->germanLabel($mistakeType)}. {$mistakeType->practiceGuidance()}",
            "Stunden, in denen sich die Lücke gezeigt hat:\n\n".($lessons ?: '(keine)'),
        ])));

        return [
            'summary' => $this->summary($result),
            'questions' => array_map(
                fn (array $question): array => [...$question, 'competency_id' => $competency->getKey()],
                $this->questions($result, PracticeTaskDrafter::MAX_QUESTIONS, 'Die KI hat keine Übungsfragen geliefert. Bitte versuche es erneut.'),
            ),
        ];
    }

    public function evaluateSubmission(Submission $submission): array
    {
        $lesson = $submission->lesson;
        $pseudonymizer = $this->pseudonymizer($lesson->schoolClass, $submission->student);
        $answers = $submission->answers()->get()->keyBy('question_id');
        // The student saw the text of the level they chose; the Erwartungshorizont is the same for every level.
        $questions = $lesson->questions->map(function (Question $question) use ($answers, $pseudonymizer): string {
            $level = $answers->get($question->id)?->level === null ? null : $question->levelFor($answers->get($question->id)->level);

            return implode("\n", [
                "Frage-ID {$question->id} ({$this->germanLabel($question->type)}".($level === null ? '' : ", Stufe: {$this->germanLabel($level)}").'): '.$pseudonymizer->apply($question->promptFor($level ?? QuestionLevel::Standard)),
                'Erwartungshorizont: '.$pseudonymizer->apply((string) $question->expected_answer),
                'Antwort: '.(filled($answers->get($question->id)?->answer) ? $pseudonymizer->apply($answers->get($question->id)->answer) : '(keine Antwort)'),
            ]);
        })->implode("\n\n");

        $result = $this->askForData(
            new SubmissionEvaluator,
            $this->lessonContext($lesson, $pseudonymizer)
                ."\n\nEigene Zusammenfassung der Schülerin oder des Schülers:\n".(filled($submission->summary) ? $pseudonymizer->apply($submission->summary) : '(keine Zusammenfassung)')
                ."\n\nFragen und Antworten:\n".$questions,
            $this->worksheets($lesson),
        );

        $evaluatedAnswers = [];

        foreach (is_array($result['answers'] ?? null) ? $result['answers'] : [] as $answer) {
            $questionId = is_array($answer) && is_numeric($answer['question_id'] ?? null) ? (int) $answer['question_id'] : null;

            if ($questionId !== null && $answers->has($questionId)) {
                $evaluatedAnswers[$questionId] = ['score' => $this->clamp($answer['score'] ?? 0), 'feedback' => $this->text($answer['feedback'] ?? null)];
                // Optional and only a hint: an unknown type is dropped, the score stands either way.
                $mistakeType = MistakeType::tryFrom($this->text($answer['mistake_type'] ?? null));

                if ($mistakeType !== null) {
                    $evaluatedAnswers[$questionId] += ['mistake_type' => $mistakeType, 'mistake_confidence' => $this->clamp($answer['mistake_confidence'] ?? 0)];
                }
            }
        }

        return [
            'understanding_score' => $this->clamp($result['understanding_score'] ?? 0),
            'feedback' => $this->text($result['feedback'] ?? null),
            'gaps' => array_slice(array_values(array_filter(array_map($this->text(...), is_array($result['gaps'] ?? null) ? $result['gaps'] : []))), 0, SubmissionEvaluator::MAX_GAPS),
            'answers' => $evaluatedAnswers,
        ];
    }

    /**
     * Sends only subject, topic, the teacher's summary (students read it anyway) and the question: neither the
     * Erwartungshorizont nor the lesson notes, so the AI cannot give the answer away in a hint.
     */
    public function differentiateQuestion(Question $question): array
    {
        $lesson = $question->lesson;
        $pseudonymizer = $this->pseudonymizer($lesson->schoolClass);

        $result = $this->askForData(new QuestionDifferentiator, implode("\n", array_filter([
            'Fach: '.$pseudonymizer->apply((string) $lesson->subject),
            'Thema: '.$pseudonymizer->apply((string) $lesson->topic),
            $lesson->summary ? "Zusammenfassung der Lehrkraft:\n".$pseudonymizer->apply($lesson->summary) : null,
            "Art der Frage: {$this->germanLabel($question->type)}",
            "Frage:\n".$pseudonymizer->apply($question->prompt),
        ])));

        $basis = $this->text($result['basis'] ?? null);
        $plus = $this->text($result['plus'] ?? null);

        if ($basis === '' || $plus === '') {
            throw new RuntimeException('Die KI hat keine Stufen geliefert. Bitte versuche es erneut.');
        }

        return ['basis' => $basis, 'plus' => $plus];
    }

    public function reviseSummary(Lesson $lesson): string
    {
        $summary = trim($this->ask(new SummaryReviser, $this->lessonContext($lesson, $this->pseudonymizer($lesson->schoolClass)), $this->worksheets($lesson))->text);

        if ($summary === '') {
            throw new RuntimeException('Die KI hat keine Zusammenfassung geliefert. Bitte versuche es erneut.');
        }

        return $summary;
    }

    public function simplify(string $text, Lesson $lesson): string
    {
        $pseudonymizer = $this->pseudonymizer($lesson->schoolClass);

        $simple = trim($this->ask(new TextSimplifier, implode("\n\n", [
            'Fach: '.$pseudonymizer->apply((string) $lesson->subject)."\nThema: ".$pseudonymizer->apply((string) $lesson->topic),
            "Text, den du in Einfacher Sprache umschreibst:\n".$pseudonymizer->apply($text),
        ]))->text);

        if ($simple === '') {
            throw new RuntimeException('Die KI hat keinen Text in Einfacher Sprache geliefert. Bitte versuche es erneut.');
        }

        return $simple;
    }

    public function askFollowUp(ExplanationSession $session): string
    {
        $question = trim($this->ask(new FollowUpAsker, $this->explanationContext($session)."\n\nStelle jetzt deine nächste Rückfrage.")->text);

        if ($question === '') {
            throw new RuntimeException('Die KI hat keine Rückfrage geliefert. Bitte versuche es erneut.');
        }

        return $question;
    }

    public function assessExplanation(ExplanationSession $session): array
    {
        $result = $this->askForData(new ExplanationAssessor, $this->explanationContext($session));

        return [
            'understanding_score' => $this->clamp($result['understanding_score'] ?? 0),
            'feedback' => $this->text($result['feedback'] ?? null),
        ];
    }

    /**
     * Prompts the agent and maps the provider's failures to a RuntimeException the callers can show.
     *
     * @param  list<Image|Document>  $attachments
     */
    private function ask(LessonAgent $agent, string $prompt, array $attachments = []): AgentResponse
    {
        try {
            return $agent->prompt($prompt, $attachments);
        } catch (RateLimitedException|ProviderOverloadedException $exception) {
            throw new RuntimeException('Die KI ist gerade überlastet. Bitte versuche es in ein paar Minuten erneut.', previous: $exception);
        } catch (InsufficientCreditsException $exception) {
            throw new RuntimeException('Das Guthaben beim KI-Anbieter ist aufgebraucht. Bitte wende dich an die Betreuung der Plattform.', previous: $exception);
        } catch (AiException|RequestException|ConnectionException $exception) {
            throw new RuntimeException('Die KI ist gerade nicht erreichbar. Bitte versuche es erneut.', previous: $exception);
        }
    }

    /**
     * @param  list<Image|Document>  $attachments
     * @return array<string, mixed>
     */
    private function askForData(LessonAgent $agent, string $prompt, array $attachments = []): array
    {
        $response = $this->ask($agent, $prompt, $attachments);
        $data = $response instanceof StructuredAgentResponse ? $response->toArray() : [];

        if ($data === []) {
            throw new RuntimeException('Die Antwort der KI konnte nicht gelesen werden. Bitte versuche es erneut.');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function summary(array $result): string
    {
        return $this->text($result['summary'] ?? null) ?: throw new RuntimeException('Die KI hat keine Zusammenfassung geliefert. Bitte versuche es erneut.');
    }

    /**
     * The usable questions of a structured answer (known type, non-empty prompt), at most `$limit`.
     *
     * @param  array<string, mixed>  $result
     * @return list<array{type: QuestionType, prompt: string, expected_answer: string, competency_id: mixed}>
     */
    private function questions(array $result, int $limit, string $noneMessage): array
    {
        $questions = [];

        foreach (is_array($result['questions'] ?? null) ? $result['questions'] : [] as $question) {
            $type = is_array($question) ? QuestionType::tryFrom($this->text($question['type'] ?? null)) : null;
            $prompt = is_array($question) ? $this->text($question['prompt'] ?? null) : '';

            if ($type !== null && $prompt !== '') {
                $questions[] = ['type' => $type, 'prompt' => $prompt, 'expected_answer' => $this->text($question['expected_answer'] ?? null), 'competency_id' => $question['competency_id'] ?? null];
            }
        }

        return $questions === [] ? throw new RuntimeException($noneMessage) : array_slice($questions, 0, $limit);
    }

    /**
     * The class's students (labelled in the order of their ids) and every teacher who has to do with the class: the
     * Klassenleitung, the assigned teachers and whoever held a lesson in it. A Förderaufgabe may belong to the
     * administration while its notes quote the teachers of the source lessons, and a text may name a colleague.
     * The student of the hand-in or conversation is added when they are not in the class (any more), so their name
     * never leaves the platform either.
     */
    private function pseudonymizer(?SchoolClass $schoolClass, ?User $student = null): Pseudonymizer
    {
        $names = $schoolClass?->students()->orderBy('id')->pluck('name')->all() ?? [];

        if ($student !== null && ! in_array($student->name, $names, true)) {
            $names[] = $student->name;
        }

        return Pseudonymizer::forPeople($names, $schoolClass === null ? [] : User::query()
            ->whereKey($schoolClass->lessons()->pluck('teacher_id')->push($schoolClass->teacher_id)->merge($schoolClass->teachers()->pluck('users.id'))->filter()->unique()->all())
            ->pluck('name')
            ->all());
    }

    private function lessonContext(Lesson $lesson, Pseudonymizer $pseudonymizer): string
    {
        return implode("\n", array_filter([
            'Fach: '.$pseudonymizer->apply((string) $lesson->subject),
            'Thema: '.$pseudonymizer->apply((string) $lesson->topic),
            'Datum der Stunde: '.($lesson->held_on?->format('d.m.Y') ?? 'unbekannt'),
            $lesson->summary ? "Zusammenfassung der Lehrkraft:\n".$pseudonymizer->apply($lesson->summary) : null,
            "Notizen der Lehrkraft (Tafelbild, Verlauf, Notizen aus der Stunde):\n".$pseudonymizer->apply((string) $lesson->notes),
        ]));
    }

    /**
     * The conversation so far, labelled by role only, plus the lesson as background for the tutor.
     */
    private function explanationContext(ExplanationSession $session): string
    {
        $lesson = $session->lesson;
        $schoolClass = $lesson?->schoolClass ?? $session->student?->schoolClass;
        $pseudonymizer = $this->pseudonymizer($schoolClass, $session->student);

        $conversation = $session->messages()->get()
            ->map(fn (ExplanationMessage $message): string => ($message->author === MessageAuthor::Student ? 'Schüler:in' : ExplainIt::TUTOR_NAME.' (du)').': '.$pseudonymizer->apply($message->content))
            ->implode("\n\n");

        return implode("\n\n", array_filter([
            'Thema der Erklärung: '.$pseudonymizer->apply((string) $session->topic),
            $lesson ? "Inhalte der zugehörigen Stunde (Hintergrund, nicht verraten):\n".$this->lessonContext($lesson, $pseudonymizer) : null,
            "Bisheriges Gespräch:\n".($conversation ?: '(noch keine Nachrichten)'),
        ]));
    }

    /**
     * Photos and PDFs the teacher attached to the lesson; other file types and missing files are skipped.
     *
     * @return list<Image|Document>
     */
    private function worksheets(Lesson $lesson): array
    {
        $disk = Storage::disk('local');
        $files = [];

        foreach ($lesson->attachments ?? [] as $path) {
            if (! $disk->exists($path)) {
                continue;
            }

            $mimeType = $disk->mimeType($path);

            if (in_array($mimeType, Lesson::WORKSHEET_IMAGE_TYPES, true)) {
                $files[] = Image::fromStorage($path, 'local');
            } elseif ($mimeType === 'application/pdf') {
                $files[] = Document::fromStorage($path, 'local');
            }
        }

        return $files;
    }

    /**
     * @param  list<int>  $knownIds
     */
    private function knownId(mixed $id, array $knownIds): ?int
    {
        return is_numeric($id) && in_array((int) $id, $knownIds, true) ? (int) $id : null;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * The prompts are German whatever language the interface speaks, so the labels of the enums in them are too.
     */
    private function germanLabel(QuestionType|QuestionLevel|MistakeType $case): string
    {
        $locale = app()->getLocale();
        app()->setLocale('de');

        try {
            return $case->getLabel();
        } finally {
            app()->setLocale($locale);
        }
    }

    private function clamp(mixed $score): int
    {
        return max(0, min(100, (int) (is_numeric($score) ? $score : 0)));
    }
}
