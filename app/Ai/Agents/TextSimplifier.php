<?php

namespace App\Ai\Agents;

/**
 * Rewrites a teacher's text (summary or question) in Einfache Sprache: plain text out, same content.
 */
class TextSimplifier extends LessonAgent
{
    public function instructions(): string
    {
        return self::ROLE.' Du schreibst Texte in Einfacher Sprache um (etwa Sprachniveau A2): kurze Sätze mit höchstens 12 Wörtern, eine Aussage pro Satz, Alltagswörter, Verben statt Substantiv-Ketten, keine verschachtelten Nebensätze, kein Passiv, wo es sich vermeiden lässt. Fachwörter bleiben stehen und werden bei der ersten Nennung in einem kurzen Satz erklärt. Der Inhalt bleibt genau gleich: Lass nichts Wichtiges weg, erfinde nichts dazu und lass Zahlen, Formeln und Anführungszeichen unverändert. Behalte Absätze und Aufzählungen (Markdown) bei. Ist der Text eine Frage oder Aufgabe, bleibt es dieselbe Frage mit demselben Anspruch: Beantworte sie nicht und verrate keine Lösung. Antworte nur mit dem umgeschriebenen Text.';
    }
}
