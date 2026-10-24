<?php

namespace Database\Seeders;

use App\Models\Competency;
use Illuminate\Database\Seeder;

/**
 * Referenzrahmen for Sekundarstufe I: process competencies of the KMK-Bildungsstandards and the Kernlehrplan Geschichte,
 * plus cross-subject competencies (subject null) from the Medienkompetenzrahmen NRW and self-regulated learning.
 * Existing entries are kept as they are, so edits by the administration survive every deploy.
 */
class CompetencyFrameworkSeeder extends Seeder
{
    /**
     * The null key (stored by PHP as '') holds the cross-subject competencies.
     *
     * @var array<string, array<string, array<string, string>>> subject => area => name => description
     */
    public const array FRAMEWORK = [
        'Mathematik' => [
            'Allgemeine mathematische Kompetenzen (KMK)' => [
                'Mathematisch argumentieren' => 'Vermutungen aufstellen, mathematische Aussagen begründen und widerlegen, Lösungswege nachvollziehbar erläutern.',
                'Probleme mathematisch lösen' => 'Zu einer Problemsituation Fragen stellen, Lösungsstrategien (z. B. Skizze, Rückwärtsarbeiten, Zerlegen) auswählen und Ergebnisse überprüfen.',
                'Mathematisch modellieren' => 'Sachsituationen in mathematische Modelle übersetzen, im Modell arbeiten und Ergebnisse an der Realität prüfen.',
                'Mathematische Darstellungen verwenden' => 'Tabellen, Diagramme, Skizzen und Graphen erstellen, interpretieren und zwischen Darstellungen wechseln.',
                'Mit symbolischen, formalen und technischen Elementen umgehen' => 'Formeln und Variablen sicher verwenden, Terme umformen, Lösungsverfahren und Hilfsmittel wie den Taschenrechner nutzen.',
                'Mathematisch kommunizieren' => 'Überlegungen und Lösungswege fachsprachlich angemessen darstellen und Äußerungen anderer verstehen und prüfen.',
            ],
        ],
        'Deutsch' => [
            'Kompetenzbereiche (KMK)' => [
                'Sprechen und Zuhören' => 'Gesprächsbeiträge adressatengerecht formulieren, aktiv zuhören, Redebeiträge strukturieren und vortragen.',
                'Schreiben' => 'Texte planen, adressaten- und situationsgerecht verfassen und überarbeiten; Rechtschreibung und Zeichensetzung beherrschen.',
                'Lesen – mit Texten und Medien umgehen' => 'Lesestrategien anwenden, Informationen entnehmen, literarische und Sachtexte verstehen und deuten.',
                'Sprache und Sprachgebrauch untersuchen' => 'Wortarten, Satzglieder und sprachliche Mittel kennen, Sprachgebrauch reflektieren und bewusst einsetzen.',
            ],
        ],
        'Biologie' => [
            'Kompetenzbereiche (KMK)' => [
                'Sachkompetenz' => 'Biologische Phänomene und Vorgänge mit Fachbegriffen und Basiskonzepten (z. B. Stoff- und Energieumwandlung) beschreiben und erklären.',
                'Erkenntnisgewinnung' => 'Fragen und Hypothesen formulieren, Experimente planen und auswerten, Variablen kontrollieren, Modelle nutzen.',
                'Kommunikation' => 'Informationen erschließen, biologische Sachverhalte fachsprachlich und adressatengerecht darstellen und diskutieren.',
                'Bewertung' => 'Biologische Sachverhalte in verschiedenen Kontexten erkennen, Argumente abwägen und begründet Stellung nehmen.',
            ],
        ],
        'Geschichte' => [
            'Kompetenzbereiche (Kernlehrplan)' => [
                'Sachkompetenz' => 'Historische Ereignisse, Personen und Strukturen zeitlich und räumlich einordnen und Zusammenhänge zwischen Ursachen und Folgen erklären.',
                'Methodenkompetenz' => 'Quellen und Darstellungen (Texte, Bilder, Karikaturen, Karten) fachgerecht beschreiben, untersuchen und deuten.',
                'Urteilskompetenz' => 'Handlungen und Ereignisse aus damaliger und heutiger Sicht beurteilen und das eigene Urteil begründen.',
            ],
        ],
        null => [
            'Medienkompetenzrahmen NRW' => [
                'Informieren und Recherchieren' => 'Informationen zielgerichtet suchen, auswerten und Quellen kritisch auf ihre Glaubwürdigkeit prüfen.',
                'Produzieren und Präsentieren' => 'Ergebnisse mediengestützt gestalten und präsentieren, Quellen und Urheberrecht beachten.',
                'Analysieren und Reflektieren' => 'Wirkung und Absicht von Medien erkennen und den eigenen Mediengebrauch reflektieren.',
            ],
            'Selbstreguliertes Lernen' => [
                'Lernprozess planen und überwachen' => 'Lernziele setzen, Arbeitsschritte planen, Zeit einteilen und den eigenen Fortschritt kontrollieren.',
                'Eigenes Lernen reflektieren' => 'Benennen, was beim Lernen geholfen hat und was unklar geblieben ist, und daraus nächste Schritte ableiten.',
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::FRAMEWORK as $subject => $areas) {
            foreach ($areas as $area => $competencies) {
                foreach ($competencies as $name => $description) {
                    Competency::firstOrCreate(
                        ['subject' => $subject === '' ? null : $subject, 'name' => $name],
                        ['area' => $area, 'description' => $description],
                    );
                }
            }
        }
    }
}
