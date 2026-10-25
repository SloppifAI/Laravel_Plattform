<?php

namespace App;

use Filament\Support\Contracts\HasLabel;

/**
 * The kind of mistake in an answer that is not quite right (Fehlerdiagnose). It describes the answer, never the student,
 * and is only a hint: it changes no score and never reaches the parent report. See App\Support\MistakeDiagnosis.
 */
enum MistakeType: string implements HasLabel
{
    case Concept = 'concept';
    case Procedure = 'procedure';
    case Incomplete = 'incomplete';
    case OffTopic = 'off_topic';
    case Empty = 'empty';

    /**
     * The label teachers read.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::Concept => __('Grundidee noch unklar'),
            self::Procedure => __('Weg/Schritt falsch'),
            self::Incomplete => __('Unvollständig'),
            self::OffTopic => __('Am Thema vorbei'),
            self::Empty => __('Kein Versuch'),
        };
    }

    /**
     * The encouraging tip the student reads next to the answer: what to do next, never "Falsch!".
     */
    public function studentHint(): string
    {
        return match ($this) {
            self::Concept => __('Hier lohnt es sich, die Grundidee noch einmal anzuschauen: Lies die Zusammenfassung deiner Lehrkraft und erkläre dir den Kern mit eigenen Worten.'),
            self::Procedure => __('Du hast richtig angesetzt, ein Schritt ist noch nicht ganz richtig. Geh deinen Weg noch einmal Schritt für Schritt durch.'),
            self::Incomplete => __('Guter Anfang! Es fehlt noch ein Teil der Antwort.'),
            self::OffTopic => __('Deine Antwort passt zu einer anderen Frage. Lies die Frage noch einmal genau.'),
            self::Empty => __('Probier es ruhig: Auch eine unvollständige Antwort hilft dir weiter.'),
        };
    }

    /**
     * How a Förderaufgabe for a group with this most frequent mistake is built. Part of the German AI prompt, so not translated.
     */
    public function practiceGuidance(): string
    {
        return match ($this) {
            self::Concept => 'Die Grundidee ist noch unklar: Beginne mit einer kurzen Erklärung der Grundidee und leichten Einstiegsaufgaben.',
            self::Procedure => 'Ein Weg oder Schritt war falsch: Zeige ein durchgerechnetes Beispiel mit Teilschritten und lass die Teilschritte einzeln üben.',
            self::Incomplete => 'Die Antworten waren unvollständig: Gib den Aufgaben ein Gerüst, zum Beispiel Satzanfänge oder Stichpunkte, damit die Antworten vollständig werden.',
            self::OffTopic => 'Die Antworten gingen am Thema vorbei: Stelle die Fragen klar und eng und lass zuerst sagen, wonach genau gefragt ist.',
            self::Empty => 'Es gab oft keinen Versuch: Mach den Einstieg niedrigschwellig, damit ein erster Versuch leichtfällt.',
        };
    }
}
