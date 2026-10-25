<?php

namespace App\Ai;

use App\MessageAuthor;
use App\MistakeType;
use App\Models\Competency;
use App\Models\ExplanationMessage;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Submission;
use App\QuestionType;
use App\UnderstandingLevel;
use Illuminate\Support\Collection;

/**
 * Offline stand-in for the AI, used for every school that has not switched the AI on (see SchoolAwareLessonAssistant).
 *
 * Deterministic text heuristics: it ranks the key terms of the teacher's notes and measures how many of them
 * a student's own text covers. No API call, no database writes. In "Erklär's mir" it speaks as the curious
 * classmate ExplainIt::TUTOR_NAME, in the first person. A homework draft has five questions, like the real AI's:
 * two comprehension, two application and one reflection question.
 */
class DemoLessonAssistant implements LessonAssistant
{
    /**
     * German words that carry no lesson content, plus classroom words that are never the subject matter
     * (a Förderaufgabe's notes name the Förderaufgabe and the Kompetenz in their header).
     */
    public const array STOPWORDS = [
        'aber', 'alle', 'allem', 'allen', 'aller', 'alles', 'also', 'andere', 'anderen', 'anderer', 'anderes', 'anschließend',
        'auch', 'außerdem', 'beim', 'bevor', 'beide', 'beiden', 'bereits', 'besonders', 'bitte', 'bzw', 'dabei', 'dadurch',
        'dafür', 'dagegen', 'daher', 'damit', 'danach', 'dann', 'daran', 'darauf', 'daraus', 'darin', 'darum', 'darüber',
        'dass', 'davon', 'dazu', 'dein', 'deine', 'deiner', 'denen', 'denn', 'deren', 'derselbe', 'dessen', 'deshalb',
        'desto', 'dich', 'diese', 'diesem', 'diesen', 'dieser', 'dieses', 'doch', 'dort', 'durch', 'eben', 'eigentlich',
        'eine', 'einem', 'einen', 'einer', 'eines', 'einige', 'einigen', 'einmal', 'etwa', 'etwas', 'euch', 'euer', 'eure',
        'erst', 'erste', 'ersten', 'fast', 'ganz', 'geht', 'gemacht', 'genau', 'gegen', 'gibt', 'ging', 'gleich', 'haben',
        'habe', 'habt', 'hast', 'hatte', 'hatten', 'heute', 'hier', 'hierbei', 'ihre', 'ihren', 'ihrer', 'immer', 'jede',
        'jedem', 'jeden', 'jeder', 'jedes', 'jetzt', 'kann', 'kaum', 'kein', 'keine', 'keinen', 'können', 'könnte', 'konnte',
        'konnten', 'lässt', 'machen', 'macht', 'mehr', 'meine', 'meinem', 'meinen', 'meiner', 'mich', 'mittels', 'muss',
        'müssen', 'nach', 'neben', 'nicht', 'noch', 'oder', 'ohne', 'schließlich', 'schon', 'sehr', 'sein', 'seine',
        'seinem', 'seinen', 'seiner', 'selbst', 'sich', 'sind', 'sodass', 'soll', 'sollen', 'sollte', 'somit', 'sondern',
        'sowie', 'später', 'statt', 'über', 'unter', 'viel', 'viele', 'vielen', 'vom', 'von', 'warum', 'weil', 'welche',
        'welchem', 'welchen', 'welcher', 'welches', 'wenn', 'werden', 'wieder', 'wird', 'wurde', 'wurden', 'während',
        'wichtig', 'wichtige', 'wichtigen', 'wichtigste', 'zuerst', 'zunächst', 'zwischen', 'zusammen', 'beispiel',
        'beispielsweise', 'arbeitsblatt', 'arbeitsblätter', 'aufgabe', 'aufgaben', 'ergebnis', 'ergebnisse', 'gruppe',
        'gruppen', 'hausaufgabe', 'hausaufgaben', 'klasse', 'lehrer', 'lehrerin', 'lehrkraft', 'notizen', 'schüler',
        'schülerin', 'schülerinnen', 'schülern', 'stunde', 'tafel', 'tafelbild', 'thema', 'unterricht', 'zusammenfassung',
        'förderaufgabe', 'förderaufgaben', 'kompetenz', 'kompetenzen',
    ];

    /**
     * Word stems that point to a concrete classroom moment, which makes a Reflection question lesson-specific.
     */
    private const array MOMENT_WORDS = [
        'versuch', 'experiment', 'beobacht', 'diskut', 'arbeitsblatt', 'tafel', 'modell', 'video', 'film', 'skizz',
        'gezeigt', 'durchgeführt', 'gemessen', 'gezählt', 'präsent', 'rollenspiel', 'quelle', 'karte', 'debatte', 'umfrage',
    ];

    /**
     * Words that signal an explanation instead of a list of facts.
     */
    private const array REASONING_WORDS = [
        'weil', 'deshalb', 'dadurch', 'denn', 'daher', 'darum', 'sodass', 'also', 'beispiel', 'bedeutet', 'heißt', 'wenn',
    ];

    /**
     * Bookish words and abbreviations (lower case) with the everyday words that replace them in "Einfache Sprache";
     * only those that keep the word order and the case of the words around them.
     */
    private const array SIMPLE_WORDS = [
        'beispielsweise' => 'zum Beispiel', 'z. b.' => 'zum Beispiel', 'z.b.' => 'zum Beispiel', 'd. h.' => 'das heißt',
        'd.h.' => 'das heißt', 'bzw.' => 'oder', 'ca.' => 'etwa', 'ungefähr' => 'etwa', 'außerdem' => 'auch', 'zudem' => 'auch',
        'ebenfalls' => 'auch', 'somit' => 'so', 'daher' => 'deshalb', 'dennoch' => 'trotzdem', 'bereits' => 'schon',
        'lediglich' => 'nur', 'hierbei' => 'dabei', 'erneut' => 'noch einmal', 'zunächst' => 'zuerst', 'anschließend' => 'danach',
    ];

    public function draftHomework(Lesson $lesson): array
    {
        $topic = (string) $lesson->topic;
        $terms = $this->withoutTopic($this->keyTerms($topic.'. '.$lesson->notes, $topic), $topic);
        $sentences = $this->sentences((string) $lesson->notes);
        $when = $lesson->held_on ? 'am '.$lesson->held_on->format('d.m.') : 'zuletzt';
        $term = fn (int $index): string => $terms === [] ? $topic : $terms[$index % count($terms)]['term'];
        $moment = $this->classroomMoment($sentences);

        $questions = [
            [
                'type' => QuestionType::Comprehension,
                'prompt' => "Erkläre mit eigenen Worten, was man unter „{$term(0)}“ versteht und welche Bedeutung der Begriff für das Thema „{$topic}“ hat.",
                'expected_answer' => $this->expectedAnswer($sentences, [$terms[0] ?? null]),
            ],
            count($terms) >= 3
                ? [
                    'type' => QuestionType::Comprehension,
                    'prompt' => "Beschreibe, wie „{$term(1)}“ und „{$term(2)}“ zusammenhängen.",
                    'expected_answer' => $this->expectedAnswer($sentences, [$terms[1], $terms[2]]),
                ]
                : [
                    'type' => QuestionType::Comprehension,
                    'prompt' => "Beschreibe die wichtigsten Schritte und Zusammenhänge zum Thema „{$topic}“, die ihr in der Stunde erarbeitet habt.",
                    'expected_answer' => $this->expectedAnswer($sentences, []),
                ],
            [
                'type' => QuestionType::Application,
                'prompt' => "Übertrage das Gelernte auf eine neue Situation: Finde ein eigenes Beispiel außerhalb des Unterrichts, bei dem „{$term(0)}“ eine Rolle spielt, und erkläre daran, was du über „{$term(3)}“ gelernt hast.",
                'expected_answer' => 'Ein passendes Beispiel überträgt die Inhalte der Stunde richtig: '.$this->expectedAnswer($sentences, [$terms[0] ?? null, $terms[3] ?? null]),
            ],
            [
                'type' => QuestionType::Application,
                'prompt' => "Stell dir vor, „{$term(1)}“ würde sich verändern oder ganz fehlen: Was würde dann mit „{$term(2)}“ passieren? Begründe deine Vermutung mit dem, was du in der Stunde gelernt hast.",
                'expected_answer' => 'Eine gute Antwort sagt eine plausible Folge voraus und begründet sie fachlich: '.$this->expectedAnswer($sentences, [$terms[1] ?? null, $terms[2] ?? null]),
            ],
            [
                'type' => QuestionType::Reflection,
                'prompt' => $moment === null
                    ? "In der Stunde {$when} habt ihr euch mit „{$topic}“ beschäftigt. Welcher Moment ist dir besonders im Gedächtnis geblieben, was hat dich dabei überrascht – und warum?"
                    : "In der Stunde {$when} habt ihr euch mit diesem Moment beschäftigt: „{$moment}“ Was hat dich dabei überrascht oder zum Nachdenken gebracht – und warum?",
                'expected_answer' => 'Individuelle Antwort ohne einzig richtige Lösung. Eine gute Antwort bezieht sich konkret auf diesen Moment der Stunde, beschreibt eine eigene Beobachtung oder Überraschung und begründet sie mit Fachbegriffen wie '.$this->quotedList(array_unique([$term(0), $term(1)])).'.',
            ],
        ];

        $competencyIds = Competency::query()->forSubject((string) $lesson->subject)->orderBy('id')->pluck('id')->all();

        foreach ($questions as $position => $question) {
            $questions[$position]['competency_id'] = $competencyIds === [] ? null : $competencyIds[$position % count($competencyIds)];
        }

        // Like the real AI, the draft does not repeat the teacher's own questions; the demo cannot think up others, so it leaves them out.
        $ownPrompts = $lesson->questions()->where('ai_drafted', false)->pluck('prompt')->all();

        return [
            'summary' => $this->summarize($lesson, $terms, $sentences, $when),
            'questions' => array_values(array_filter($questions, fn (array $question): bool => ! in_array($question['prompt'], $ownPrompts, true))),
        ];
    }

    /**
     * The most frequent mistake type of the group shapes the warm-up question and the summary: an explanation of the
     * core idea for `concept`, the steps of the lesson as a worked example for `procedure`, sentence starters for `incomplete`.
     */
    public function draftPracticeTask(Competency $competency, string $subject, Collection $sourceLessons, array $gaps, ?MistakeType $mistakeType = null): array
    {
        $topics = $sourceLessons->pluck('topic')->filter()->unique()->values();
        $topicText = $topics->implode('. ');
        $notes = $sourceLessons->pluck('notes')->filter()->implode("\n");
        $sentences = $this->sentences($notes);
        $topic = $topics->first() ?? $competency->name;
        $description = trim((string) $competency->description);
        $terms = $this->practiceTerms($gaps, $competency, $topicText, $notes);
        $term = fn (int $index): string => $terms === [] ? $topic : $terms[$index % count($terms)]['term'];
        $expected = fn (array $selected): string => $sentences === [] && $description !== '' ? $description : $this->expectedAnswer($sentences, $selected);

        $questions = [
            [
                'type' => QuestionType::Comprehension,
                'prompt' => "Zum Aufwärmen: Erkläre mit eigenen Worten, was man unter „{$term(0)}“ versteht. ".($topics->isEmpty() ? 'Schau bei Bedarf in deinen Unterlagen nach.' : "Bei Bedarf hilft dir die Stunde „{$topic}“ beim Nachschlagen."),
                'expected_answer' => $expected([$terms[0] ?? null]),
            ],
            count($terms) >= 2
                ? [
                    'type' => QuestionType::Comprehension,
                    'prompt' => "Beschreibe, wie „{$term(0)}“ und „{$term(1)}“ zusammenhängen.",
                    'expected_answer' => $expected([$terms[0], $terms[1]]),
                ]
                : [
                    'type' => QuestionType::Comprehension,
                    'prompt' => "Beschreibe die wichtigsten Schritte und Zusammenhänge zum Thema „{$topic}“ in deinen eigenen Worten.",
                    'expected_answer' => $expected([]),
                ],
            [
                'type' => QuestionType::Application,
                'prompt' => "Jetzt wird es praktisch: Finde ein eigenes Beispiel aus deinem Alltag, bei dem „{$term(1)}“ eine Rolle spielt, und erkläre Schritt für Schritt, wie du dabei vorgehst. Damit übst du „{$competency->name}“.",
                'expected_answer' => "Ein passendes Beispiel überträgt die Inhalte richtig und zeigt die Kompetenz „{$competency->name}“".($description === '' ? '. ' : ": {$this->sentenceEnd($description)} ").$expected([$terms[1] ?? $terms[0] ?? null]),
            ],
        ];

        if (count($terms) >= 3) {
            $questions[] = [
                'type' => QuestionType::Comprehension,
                'prompt' => "Zum Knobeln: Begründe, warum „{$term(2)}“ wichtig ist, wenn es um „{$term(0)}“ geht. Nenne mindestens ein Argument.",
                'expected_answer' => $expected([$terms[0], $terms[2]]),
            ];
        }

        $questions[0]['prompt'] = $this->practiceWarmUp($mistakeType, $term(0), $description, $sentences) ?? $questions[0]['prompt'];

        $focus = $terms === [] ? '' : ' Im Mittelpunkt stehen '.$this->quotedList(array_column(array_slice($terms, 0, 3), 'term')).'.';
        $recap = $sentences === [] ? '' : ' '.$this->expectedAnswer($sentences, array_slice($terms, 0, 2));
        $approach = match ($mistakeType) {
            MistakeType::Concept => ' Wir fangen bei der Grundidee an.',
            MistakeType::Procedure => ' Ein Beispiel in Teilschritten zeigt dir den Weg.',
            MistakeType::Incomplete => ' Satzanfänge helfen dir, vollständig zu antworten.',
            MistakeType::OffTopic => ' Lies jede Frage genau: Wonach ist gefragt?',
            MistakeType::Empty => ' Fang einfach an, jeder Versuch zählt.',
            null => '',
        };

        return [
            'summary' => "Diese Förderaufgabe übt die Kompetenz „{$competency->name}“ ({$subject})".($description === '' ? '.' : ": {$this->sentenceEnd($description)}")."{$focus}{$recap}{$approach} Die Aufgaben werden Schritt für Schritt schwerer – nimm dir Zeit, du schaffst das!",
            'questions' => array_map(fn (array $question): array => [...$question, 'competency_id' => $competency->getKey()], $questions),
        ];
    }

    public function evaluateSubmission(Submission $submission): array
    {
        $lesson = $submission->lesson;
        $terms = $this->keyTerms($lesson->topic.'. '.$lesson->notes, (string) $lesson->topic);
        $summary = (string) $submission->summary;
        $answers = $submission->answers()->get();
        $answersByQuestion = $answers->keyBy('question_id');

        $summaryScore = $this->clamp(100 * (
            0.8 * $this->ratio(count($this->coverage($terms, $summary)['covered']), min(6, count($terms)))
            + 0.2 * $this->effort($summary, 60)
        ));

        $evaluatedAnswers = [];
        $questionScores = [];

        foreach ($lesson->questions as $question) {
            $answer = $answersByQuestion->get($question->id);
            $evaluation = $this->evaluateAnswer($question, (string) $answer?->answer, $terms);
            $questionScores[] = $evaluation['score'];

            if ($answer !== null) {
                $evaluatedAnswers[$question->id] = $evaluation;
            }
        }

        $understandingScore = $questionScores === []
            ? $summaryScore
            : $this->clamp(0.4 * $summaryScore + 0.6 * array_sum($questionScores) / count($questionScores));

        $allText = $summary.' '.$answers->pluck('answer')->implode(' ');
        $coverage = $this->coverage($terms, $allText);
        $gaps = array_map(fn (string $term): string => "Gehe noch einmal auf „{$term}“ ein.", array_slice($coverage['missing'], 0, 3));

        if (count($gaps) < 3 && $this->wordCount($summary) < 30) {
            $gaps[] = 'Schreib deine Zusammenfassung ausführlicher: Was war der Ablauf der Stunde und was war das Wichtigste?';
        }

        return [
            'understanding_score' => $understandingScore,
            'feedback' => $this->submissionFeedback($understandingScore, $coverage['covered'], $lesson->practiceCompetency?->name ?? (string) $lesson->topic),
            'gaps' => $gaps,
            'answers' => $evaluatedAnswers,
        ];
    }

    /**
     * Einstieg: the first sentence of the question, cut at its first "und" when it is long, plus a hint that points
     * to the question's own wording and to the teacher's summary. Profi: the question plus a request for a reason or
     * a transfer, depending on its type. Neither uses the Erwartungshorizont.
     */
    public function differentiateQuestion(Question $question): array
    {
        $prompt = trim($question->prompt);
        $first = $this->splitSentences($prompt)[0] ?? $prompt;
        $term = preg_match('/„([^“]+)“/u', $prompt, $quoted) ? $quoted[1] : null;

        if ($this->wordCount($first) > 16 && preg_match('/^(.+?\S)\s+und\s/u', $first, $head) && $this->wordCount($head[1]) >= 6) {
            $first = rtrim($head[1], ' ,;:–-').(str_ends_with($prompt, '?') ? '?' : '.');
        }

        $hint = $term === null
            ? 'Tipp: Schreib zwei bis drei kurze Sätze und schau in die Zusammenfassung deiner Lehrkraft.'
            : "Tipp: Schreib zwei bis drei kurze Sätze. Denk dabei an „{$term}“.";

        return [
            'basis' => $this->sentenceEnd($first).' '.$hint,
            'plus' => $this->sentenceEnd($prompt).' '.match ($question->type) {
                QuestionType::Comprehension => 'Erkläre zusätzlich, warum das so ist, und nenne ein Beispiel aus dem Alltag.',
                QuestionType::Application => 'Begründe zusätzlich deine Lösung und überlege, in welcher anderen Situation dasselbe gilt.',
                QuestionType::Reflection => 'Begründe außerdem, was du daraus für dich mitnimmst und was du nächstes Mal anders machen würdest.',
            },
        ];
    }

    /**
     * The demo cannot rewrite a text, so the summary the lesson has stays word for word and gets the sentences of a fresh
     * draft from the notes that it does not contain yet.
     */
    public function reviseSummary(Lesson $lesson): string
    {
        $previous = trim((string) $lesson->summary);
        $known = mb_strtolower(strip_tags($previous));
        $added = array_filter(
            $this->sentences($this->draftHomework($lesson)['summary']),
            fn (string $sentence): bool => ! str_contains($known, mb_strtolower($sentence)),
        );

        return trim($previous."\n\n".implode(' ', $added));
    }

    /**
     * Rule-based: everyday words for bookish ones, filler words dropped, and sentences of more than twelve words split
     * at a semicolon or before "aber"/"doch". Line breaks and bullet points stay, so Markdown keeps its shape.
     */
    public function simplify(string $text, Lesson $lesson): string
    {
        $pattern = '/(?<![\p{L}])('.implode('|', array_map(fn (string $word): string => preg_quote($word, '/'), array_keys(self::SIMPLE_WORDS))).')(?![\p{L}])/iu';

        $lines = array_map(function (string $line) use ($pattern): string {
            preg_match('/^(\s*(?:[-*•]|\d+[.)]|#+)\s+)?(.*)$/su', $line, $parts);

            $plain = preg_replace_callback($pattern, function (array $match): string {
                $replacement = self::SIMPLE_WORDS[mb_strtolower($match[0])];

                return mb_substr($match[0], 0, 1) === mb_strtolower(mb_substr($match[0], 0, 1)) ? $replacement : mb_ucfirst($replacement);
            }, $parts[2]) ?? $parts[2];
            // Lower case only: a filler word at the start of a sentence stays, so the sentence keeps its capital letter.
            $plain = preg_replace('/\s+(?:eigentlich|grundsätzlich|sozusagen|quasi)(?![\p{L}])/u', '', $plain) ?? $plain;

            return $parts[1].implode(' ', array_map($this->splitLongSentence(...), $this->splitSentences($plain)));
        }, preg_split('/\R/u', trim($text)) ?: []);

        return trim(implode("\n", $lines));
    }

    public function askFollowUp(ExplanationSession $session): string
    {
        $topic = (string) $session->topic;
        $messages = $session->messages()->get();
        $studentText = $this->messagesBy($messages, MessageAuthor::Student)->implode(' ');
        $askedQuestions = $this->messagesBy($messages, MessageAuthor::Tutor);
        $wasAsked = fn (string $text): bool => $askedQuestions->contains(fn (string $asked): bool => str_contains($asked, $text));

        if (trim($studentText) === '') {
            return "Ich kenne mich mit „{$topic}“ noch gar nicht aus. Worum geht es da – kannst du es mir von Anfang an erklären?";
        }

        $round = $askedQuestions->count();
        $coverage = $this->coverage($this->sessionTerms($session), $studentText);
        $missingTemplates = [
            'Ich habe in dem Zusammenhang schon mal das Wort „%1$s“ gehört. Was hat das mit „%2$s“ zu tun?',
            'Jemand aus deiner Klasse hat vorhin „%1$s“ erwähnt. Welche Rolle spielt das dabei?',
            'Und was hat es eigentlich mit „%1$s“ auf sich?',
        ];
        $deepeningTemplates = [
            'Warum ist „%s“ dabei eigentlich so wichtig?',
            'Kannst du mir ein Beispiel geben, damit ich mir „%s“ besser vorstellen kann?',
            'Wo würde mir „%s“ im Alltag begegnen?',
            'Was würde passieren, wenn „%s“ plötzlich fehlen würde?',
        ];

        $candidates = [];

        $topicStems = $this->stems($topic);

        foreach ($coverage['missing'] as $term) {
            // The greeting already names the topic, so Tuto only asks about terms beyond it.
            if (! $wasAsked("„{$term}“") && ! $this->mentions($topicStems, $this->stem($term))) {
                $candidates[] = sprintf($missingTemplates[$round % count($missingTemplates)], $term, $topic);
            }
        }

        $covered = $coverage['covered'] ?: [$topic];

        for ($index = 0; $index < count($deepeningTemplates) * count($covered); $index++) {
            $term = $covered[($round + intdiv($index, count($deepeningTemplates))) % count($covered)];
            $candidates[] = sprintf($deepeningTemplates[($round + $index) % count($deepeningTemplates)], $term);
        }

        $question = collect($candidates)->first(fn (string $candidate): bool => ! $wasAsked($candidate));

        if ($question === null) {
            $question = "Was ist für dich das Wichtigste an „{$topic}“, das ich mir unbedingt merken sollte?";

            if (str_contains((string) $askedQuestions->last(), $question)) {
                $question = "Fällt dir noch ein weiteres Beispiel zu „{$topic}“ ein?";
            }
        }

        $reactions = ['Spannend!', 'Okay, das verstehe ich.', 'Ah, jetzt wird mir einiges klarer.', 'Danke, das hilft mir!'];

        return $reactions[$round % count($reactions)].' '.$question;
    }

    public function assessExplanation(ExplanationSession $session): array
    {
        $studentMessages = $this->messagesBy($session->messages()->get(), MessageAuthor::Student);
        $text = $studentMessages->implode(' ');

        if (trim($text) === '') {
            return [
                'understanding_score' => 0,
                'feedback' => 'Du hast noch nichts erklärt. Starte eine neue Runde und erkläre das Thema mit deinen eigenen Worten – ich bin gespannt!',
            ];
        }

        $terms = $this->sessionTerms($session);
        $coverage = $this->coverage($terms, $text);
        $reasoningCount = count(array_filter(
            $this->words(mb_strtolower($text)),
            fn (string $word): bool => in_array($word, self::REASONING_WORDS, true),
        ));

        $score = $this->clamp(100 * (
            0.45 * $this->ratio(count($coverage['covered']), min(6, count($terms)))
            + 0.25 * $this->effort($text, 120)
            + 0.2 * min(1.0, $reasoningCount / 3)
            + 0.1 * min(1.0, $studentMessages->count() / 3)
        ));

        $feedback = [match (UnderstandingLevel::fromScore($score)) {
            UnderstandingLevel::Understood => "Super erklärt – ich habe jetzt wirklich verstanden, worum es bei „{$session->topic}“ geht!",
            UnderstandingLevel::Partly => 'Danke, deine Erklärung hat mir schon einiges klar gemacht.',
            UnderstandingLevel::NeedsSupport => 'Danke fürs Erklären! Ein paar Dinge sind mir aber noch unklar geblieben.',
        }];

        if ($coverage['covered'] !== []) {
            $feedback[] = 'Gut erklärt hast du '.$this->quotedList(array_slice($coverage['covered'], 0, 3)).'.';
        }

        $feedback[] = $coverage['missing'] === []
            ? 'Du hast alle wichtigen Begriffe abgedeckt.'
            : 'Noch nicht erklärt hast du '.$this->quotedList(array_slice($coverage['missing'], 0, 3)).' – versuch beim nächsten Mal, auch darauf einzugehen.';

        if ($reasoningCount < 2) {
            $feedback[] = 'Begründe deine Aussagen öfter mit „weil“ oder einem Beispiel – so merkst du selbst, ob du es wirklich verstanden hast.';
        }

        return [
            'understanding_score' => $score,
            'feedback' => implode(' ', $feedback),
        ];
    }

    /**
     * @param  list<array{term: string, stem: string}>  $terms
     * @param  list<string>  $sentences
     */
    private function summarize(Lesson $lesson, array $terms, array $sentences, string $when): string
    {
        $intro = $terms === []
            ? "In der {$lesson->subject}-Stunde {$when} ging es um das Thema „{$lesson->topic}“."
            : "In der {$lesson->subject}-Stunde {$when} zum Thema „{$lesson->topic}“ ging es vor allem um "
                .$this->quotedList(array_column(array_slice($terms, 0, 3), 'term')).'.';

        $rankWeights = [];

        foreach ($terms as $rank => $term) {
            $rankWeights[$term['stem']] = count($terms) - $rank;
        }

        $selected = collect($sentences)
            ->map(fn (string $sentence, int $index): array => [
                'sentence' => $sentence,
                'index' => $index,
                'score' => array_sum(array_map(
                    fn (array $term): int => $this->mentions($this->stems($sentence), $term['stem']) ? $rankWeights[$term['stem']] : 0,
                    $terms,
                )),
            ])
            ->sortBy([['score', 'desc'], ['index', 'asc']])
            ->take(4)
            ->sortBy('index')
            ->pluck('sentence');

        return $selected->prepend($intro)->implode(' ');
    }

    /**
     * The note sentences about the given terms, as Erwartungshorizont.
     *
     * @param  list<string>  $sentences
     * @param  list<array{term: string, stem: string}|null>  $terms
     */
    private function expectedAnswer(array $sentences, array $terms): string
    {
        $terms = array_filter($terms);
        $relevant = array_filter($sentences, function (string $sentence) use ($terms): bool {
            $stems = $this->stems($sentence);

            return collect($terms)->contains(fn (array $term): bool => $this->mentions($stems, $term['stem']));
        });

        $chosen = array_slice($relevant === [] ? $sentences : array_values($relevant), 0, 3);

        return $chosen === [] ? 'Siehe Notizen der Lehrkraft zu dieser Stunde.' : implode(' ', $chosen);
    }

    /**
     * A note sentence describing something the class did, quoted in the Reflection question.
     *
     * @param  list<string>  $sentences
     */
    private function classroomMoment(array $sentences): ?string
    {
        $moment = collect($sentences)->first(function (string $sentence): bool {
            $lower = mb_strtolower($sentence);

            return collect(self::MOMENT_WORDS)->contains(fn (string $word): bool => str_contains($lower, $word));
        }) ?? $sentences[0] ?? null;

        if ($moment === null) {
            return null;
        }

        $words = preg_split('/\s+/u', $moment) ?: [];

        return count($words) > 30 ? implode(' ', array_slice($words, 0, 30)).' …' : $moment;
    }

    /**
     * @param  list<array{term: string, stem: string}>  $terms
     * @return array{score: int, feedback: string}
     */
    private function evaluateAnswer(Question $question, string $answer, array $terms): array
    {
        if (trim($answer) === '') {
            return ['score' => 0, 'feedback' => 'Diese Frage hast du noch nicht beantwortet.'];
        }

        if ($question->type === QuestionType::Reflection) {
            $score = $this->clamp(100 * (
                0.5 * $this->effort($answer, 40)
                + 0.35 * $this->ratio(count($this->coverage($terms, $answer)['covered']), min(2, count($terms)))
                + 0.15 * preg_match('/\b(ich|mich|mir|mein\w*)\b/iu', $answer)
            ));

            return ['score' => $score, 'feedback' => match (UnderstandingLevel::fromScore($score)) {
                UnderstandingLevel::Understood => 'Eine durchdachte Reflexion mit klarem Bezug zur Stunde – genau so!',
                UnderstandingLevel::Partly => 'Schöne Gedanken! Beziehe dich noch genauer auf den Moment aus der Stunde und begründe, warum er dich beschäftigt hat.',
                UnderstandingLevel::NeedsSupport => 'Hier fehlt noch deine eigene Sicht. Beschreibe, was du in der Stunde erlebt hast und was du darüber denkst.',
            }];
        }

        $questionStems = $this->stems($question->prompt.' '.$question->expected_answer);
        $relevant = array_values(array_filter($terms, fn (array $term): bool => $this->mentions($questionStems, $term['stem'])));

        if ($relevant === []) {
            $relevant = $this->keyTerms((string) $question->expected_answer, '', 6);
        }

        $coverage = $this->coverage($relevant, $answer);
        $missing = $coverage['missing'][0] ?? null;
        $score = $this->clamp(100 * (
            0.75 * $this->ratio(count($coverage['covered']), min(3, count($relevant)))
            + 0.25 * $this->effort($answer, 25)
        ));

        return ['score' => $score, 'feedback' => match (UnderstandingLevel::fromScore($score)) {
            UnderstandingLevel::Understood => 'Richtig erklärt – du hast die entscheidenden Punkte getroffen.',
            UnderstandingLevel::Partly => $missing === null
                ? 'Guter Ansatz! Erkläre deine Gedanken noch etwas ausführlicher.'
                : "Guter Ansatz! Ergänze noch, was „{$missing}“ damit zu tun hat.",
            UnderstandingLevel::NeedsSupport => $missing === null
                ? 'Da fehlt noch Wesentliches. Schau dir die Zusammenfassung der Stunde noch einmal an.'
                : "Da fehlt noch Wesentliches. Schau dir „{$missing}“ in deinen Unterlagen noch einmal an.",
        }];
    }

    /**
     * @param  list<string>  $coveredTerms
     */
    private function submissionFeedback(int $score, array $coveredTerms, string $topic): string
    {
        $termsUsed = $coveredTerms === [] ? '' : ' wie '.$this->quotedList(array_slice($coveredTerms, 0, 2));

        return match (UnderstandingLevel::fromScore($score)) {
            UnderstandingLevel::Understood => "Stark! Du hast die Stunde gut verstanden und wichtige Begriffe{$termsUsed} sicher verwendet. Weiter so!",
            UnderstandingLevel::Partly => "Guter Anfang! Du hast schon einige Punkte{$termsUsed} richtig eingeordnet. Schau dir die Hinweise unten an, dann sitzt das Thema.",
            UnderstandingLevel::NeedsSupport => "Danke für deine Abgabe! Bei „{$topic}“ fehlen noch einige wichtige Punkte. Lies dir die Zusammenfassung der Stunde noch einmal durch und versuche es mit den Hinweisen unten erneut – du schaffst das!",
        };
    }

    /**
     * Key terms of the session's lesson, or of the free topic when the student picked no lesson.
     *
     * @return list<array{term: string, stem: string}>
     */
    private function sessionTerms(ExplanationSession $session): array
    {
        $lesson = $session->lesson;

        return $lesson === null
            ? $this->keyTerms((string) $session->topic, (string) $session->topic)
            : $this->keyTerms($lesson->topic.'. '.$lesson->notes, $session->topic.' '.$lesson->topic);
    }

    /**
     * Up to four terms to practise: the ones the AI named in its gap hints first, then the key terms of the lessons
     * where the gap showed, and only without any lesson text the words of the competency itself.
     *
     * @param  list<string>  $gaps
     * @return list<array{term: string, stem: string}>
     */
    private function practiceTerms(array $gaps, Competency $competency, string $topics, string $notes): array
    {
        $fromGaps = collect($gaps)
            ->flatMap(fn (string $gap): array => preg_match_all('/„([^“]{2,60})“/u', $gap, $matches) ? $matches[1] : [])
            ->map(fn (string $term): array => ['term' => $term, 'stem' => $this->stem($term)]);

        $fromLessons = $this->withoutTopic($this->keyTerms($topics.'. '.$notes, $topics), $topics);
        $fromCompetency = $this->keyTerms($competency->name.'. '.$competency->description, $competency->name);

        return $fromGaps
            ->concat($fromLessons === [] || trim($notes) === '' ? $fromCompetency : $fromLessons)
            ->unique('stem')
            ->take(4)
            ->values()
            ->all();
    }

    /**
     * The first question of a Förderaufgabe for the group's most frequent mistake type, or null to keep the usual one.
     *
     * @param  list<string>  $sentences  of the lesson notes
     */
    private function practiceWarmUp(?MistakeType $mistakeType, string $term, string $description, array $sentences): ?string
    {
        $steps = array_slice($sentences, 0, 3);

        return match ($mistakeType) {
            MistakeType::Concept => 'Zuerst die Grundidee: '.$this->sentenceEnd($description !== '' ? $description : ($sentences[0] ?? "„{$term}“ ist der Kern dieser Übung"))." Erkläre danach in ein, zwei Sätzen mit eigenen Worten, was „{$term}“ bedeutet.",
            MistakeType::Procedure => 'Ein Beispiel in Teilschritten: '
                .($steps === []
                    ? "Schreib die Schritte zu „{$term}“ einzeln auf (1., 2., 3.)."
                    : implode(' ', array_map(fn (string $step, int $index): string => ($index + 1).'. '.$this->sentenceEnd($step), $steps, array_keys($steps))))
                .' Geh die Schritte nach und schreib zu jedem Schritt auf, warum er nötig ist.',
            MistakeType::Incomplete => "Zum Aufwärmen mit Gerüst: Erkläre, was man unter „{$term}“ versteht, und ergänze dazu diese Satzanfänge: „{$term}“ bedeutet … Ein Beispiel dafür ist … Wichtig ist dabei …",
            default => null,
        };
    }

    private function sentenceEnd(string $text): string
    {
        $text = trim($text);

        return preg_match('/[.!?…]$/u', $text) ? $text : $text.'.';
    }

    /**
     * Terms beyond the topic's own words, so questions don't explain the topic by itself; all terms if none are left.
     *
     * @param  list<array{term: string, stem: string}>  $terms
     * @return list<array{term: string, stem: string}>
     */
    private function withoutTopic(array $terms, string $topic): array
    {
        $topicStems = $this->stems($topic);
        $beyondTopic = array_values(array_filter($terms, fn (array $term): bool => ! $this->mentions($topicStems, $term['stem'])));

        return $beyondTopic === [] ? $terms : $beyondTopic;
    }

    /**
     * @param  Collection<int, ExplanationMessage>  $messages
     * @return Collection<int, string>
     */
    private function messagesBy(Collection $messages, MessageAuthor $author): Collection
    {
        return $messages->filter(fn (ExplanationMessage $message): bool => $message->author === $author)->pluck('content')->values();
    }

    /**
     * Content words ranked by weight: nouns (capitalised) count double, long words and words of the
     * emphasised text (the topic) get a bonus; ties keep their order of appearance.
     *
     * @return list<array{term: string, stem: string}>
     */
    private function keyTerms(string $text, string $emphasis = '', int $limit = 8): array
    {
        $candidates = [];

        foreach ($this->words($text) as $position => $word) {
            $lower = mb_strtolower($word);

            $isTooShort = mb_strlen($word) < 4 && ! preg_match('/\d|^\p{Lu}{2,}$/u', $word);
            $isAdjective = preg_match('/\p{L}{3}isch(e[mnrs]?)?$/u', $lower) === 1;

            if ($isTooShort || $isAdjective || in_array($lower, self::STOPWORDS, true)) {
                continue;
            }

            $stem = $this->stem($word);
            $isNoun = preg_match('/^\p{Lu}/u', $word) === 1;
            $candidate = $candidates[$stem] ?? ['term' => $word, 'stem' => $stem, 'weight' => mb_strlen($word) >= 8 ? 0.5 : 0.0, 'count' => 0, 'position' => $position, 'noun' => false];
            $candidate['weight'] += $isNoun ? 2 : 0.5;
            $candidate['count']++;

            if ($isNoun && ! $candidate['noun']) {
                $candidate['term'] = $word;
                $candidate['noun'] = true;
            }

            $candidates[$stem] = $candidate;
        }

        foreach ($this->stems($emphasis) as $stem) {
            if (isset($candidates[$stem])) {
                $candidates[$stem]['weight'] += 3;
            }
        }

        $relevant = array_filter($candidates, fn (array $candidate): bool => $candidate['noun'] || $candidate['count'] >= 2);

        return collect($relevant === [] ? $candidates : $relevant)
            ->sortBy([['weight', 'desc'], ['position', 'asc']])
            ->take($limit)
            ->map(fn (array $candidate): array => ['term' => $candidate['term'], 'stem' => $candidate['stem']])
            ->values()
            ->all();
    }

    /**
     * Which of the terms the text mentions, in the terms' ranking order.
     *
     * @param  list<array{term: string, stem: string}>  $terms
     * @return array{covered: list<string>, missing: list<string>}
     */
    private function coverage(array $terms, string $text): array
    {
        $stems = $this->stems($text);
        $coverage = ['covered' => [], 'missing' => []];

        foreach ($terms as $term) {
            $coverage[$this->mentions($stems, $term['stem']) ? 'covered' : 'missing'][] = $term['term'];
        }

        return $coverage;
    }

    /**
     * Matches inflections ("Pflanzen" for "Pflanze") and compounds ("Lichtenergie", "Sonnenlicht" for "Licht").
     *
     * @param  list<string>  $stems
     */
    private function mentions(array $stems, string $termStem): bool
    {
        foreach ($stems as $stem) {
            if (str_starts_with($stem, $termStem) || (mb_strlen($termStem) >= 5 && str_contains($stem, $termStem))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Note lines and sentences without bullet characters, each ending with punctuation.
     *
     * @return list<string>
     */
    private function sentences(string $text): array
    {
        $sentences = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = preg_replace('/^\s*(?:[-–—*•·>#]+|\d+[.)])\s*/u', '', $line) ?? '';

            foreach ($this->splitSentences($line) as $sentence) {
                if ($this->wordCount($sentence) >= 4) {
                    $sentences[] = preg_match('/[.!?…]$/u', $sentence) ? $sentence : $sentence.'.';
                }
            }
        }

        return $sentences;
    }

    /**
     * The sentences of one line of text as they are written, short ones included.
     *
     * @return list<string>
     */
    private function splitSentences(string $line): array
    {
        $parts = preg_split('/(?<=[.!?])(?<!\b[zZdDuB]\.)(?<!\bbzw\.)(?<!\bca\.)(?<!\busw\.)(?<!\b\d\.)(?<!\b\d\d\.)\s+(?=[\p{Lu}„"])/u', trim($line)) ?: [];

        return array_values(array_filter(array_map(fn (string $sentence): string => trim(preg_replace('/\s+/u', ' ', $sentence) ?? ''), $parts), fn (string $sentence): bool => $sentence !== ''));
    }

    /**
     * A sentence of more than twelve words, split at a semicolon or before "aber"/"doch", which both start a main clause.
     */
    private function splitLongSentence(string $sentence): string
    {
        if ($this->wordCount($sentence) <= 12) {
            return $sentence;
        }

        $parts = preg_split('/;\s+|,\s+(?=(?:aber|doch)\s)/u', $sentence) ?: [$sentence];
        $last = array_key_last($parts);

        return implode(' ', array_map(fn (string $part, int $index): string => mb_ucfirst(trim($part)).($index === $last ? '' : '.'), $parts, array_keys($parts)));
    }

    /**
     * Words, keeping hyphenated compounds ("Calvin-Zyklus") together.
     *
     * @return list<string>
     */
    private function words(string $text): array
    {
        preg_match_all('/\p{L}[\p{L}\p{N}]*(?:-\p{L}[\p{L}\p{N}]*)*/u', $text, $matches);

        return $matches[0];
    }

    /**
     * @return list<string>
     */
    private function stems(string $text): array
    {
        return array_values(array_unique(array_map($this->stem(...), $this->words($text))));
    }

    /**
     * Lower-cased word without one common German inflection ending.
     */
    private function stem(string $word): string
    {
        $word = mb_strtolower($word);

        foreach (['en', 'er', 'es', 'e', 'n', 's'] as $ending) {
            if (str_ends_with($word, $ending) && mb_strlen($word) - mb_strlen($ending) >= 4) {
                return mb_substr($word, 0, mb_strlen($word) - mb_strlen($ending));
            }
        }

        return $word;
    }

    private function wordCount(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * 0–1 for how close the text gets to the word count expected for a solid answer.
     */
    private function effort(string $text, int $expectedWords): float
    {
        return min(1.0, $this->wordCount($text) / $expectedWords);
    }

    /**
     * 0–1 share of covered terms; with nothing to cover, nothing is missing.
     */
    private function ratio(int $covered, int $total): float
    {
        return $total === 0 ? 1.0 : min(1.0, $covered / $total);
    }

    private function clamp(float $score): int
    {
        return max(0, min(100, (int) round($score)));
    }

    /**
     * @param  array<int, string>  $items
     */
    private function quotedList(array $items): string
    {
        return collect($items)->map(fn (string $item): string => "„{$item}“")->join(', ', ' und ');
    }
}
