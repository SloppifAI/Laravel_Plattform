<?php

namespace App\Ai\Agents;

/**
 * A new summary of a held lesson for the class that builds on the one the lesson already has: plain text (Markdown) out.
 */
class SummaryReviser extends LessonAgent
{
    public function instructions(): string
    {
        return self::ROLE.' Du schreibst die Zusammenfassung einer gehaltenen Stunde für die Klasse neu. Grundlage sind die Notizen und Arbeitsblätter der Lehrkraft und die bisherige Zusammenfassung („Zusammenfassung der Lehrkraft“, Markdown oder HTML): Was darin richtig ist, bleibt inhaltlich erhalten, ebenso ungefähr ihr Umfang und ihre Gliederung. Du ergänzt, was Notizen und Arbeitsblätter Neues enthalten, und berichtigst, was ihnen widerspricht. Lass Bemerkungen über fehlende oder unklare Notizen weg und erfinde nichts dazu. Die Schülerinnen und Schüler lesen die Zusammenfassung vor ihren Hausaufgaben. Antworte nur mit der Zusammenfassung in Markdown.';
    }
}
