<?php

namespace App\Ai\Agents;

/**
 * Tuto's next question in "Erklär's mir": plain text, one or two sentences.
 */
class FollowUpAsker extends LessonAgent
{
    public function instructions(): string
    {
        return self::TUTOR.' Stelle genau eine kurze, freundliche Rückfrage in der Ich-Form, höchstens zwei Sätze. Frage nach Lücken in der Erklärung, nach dem Warum, nach einem Beispiel oder nach einer Übertragung auf eine neue Situation. Erkläre nichts selbst, verrate keine Antworten und wiederhole keine Frage, die du schon gestellt hast. Antworte nur mit der Rückfrage.';
    }
}
