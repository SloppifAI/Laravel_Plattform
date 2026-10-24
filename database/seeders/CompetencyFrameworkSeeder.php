<?php

namespace Database\Seeders;

use App\Models\Competency;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Referenzrahmen for Sekundarstufe I: process competencies of the KMK-Bildungsstandards, the Kernlehrplan Geschichte and
 * the Kerncurricula Hessen Sek I (Physik, Chemie, Erdkunde, Politik und Wirtschaft, Geschichte), plus cross-subject
 * competencies (subject null) from the Medienkompetenzrahmen NRW and self-regulated learning.
 * The Hessian area names are taken from the Orientierungstexte (Leitfäden) of the Kerncurricula Sek I on kultus.hessen.de.
 * Physik and Chemie share Erkenntnisgewinnung, Kommunikation and Bewertung with Biologie; their fourth area is
 * "Nutzung fachlicher Konzepte" (Biologie keeps its older "Sachkompetenz").
 * Geschichte keeps its Kernlehrplan entries, because their names are in use. The Hessian Geschichte area adds
 * Wahrnehmungs-, Analyse- and Orientierungskompetenz; Urteilskompetenz of the Hessian model is the existing entry.
 * Methodenkompetenz and Analysekompetenz overlap in content, so the administration may decide to use only one of them.
 *
 * Every entry has a code derived from subject and name as listed here (never rename a listed entry). The seeder matches
 * on that code, so entries renamed or edited by the administration are kept as they are and not created a second time;
 * entries without a code (created before the code existed) are adopted by subject and name. An entry that has been
 * deleted is created again by the next seeding.
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
        'Physik' => [
            'Kompetenzbereiche (Kerncurriculum Hessen Sek I)' => [
                'Nutzung fachlicher Konzepte' => 'Physikalische Sachverhalte mit den Basiskonzepten Materie, Wechselwirkung, Systeme und Energie strukturieren, vernetzen und erklären.',
                'Erkenntnisgewinnung' => 'Fragestellungen und Hypothesen formulieren, Experimente planen, durchführen und auswerten, Messwerte darstellen und Modelle nutzen.',
                'Kommunikation' => 'Physikalische Informationen aus Texten, Diagrammen und Tabellen entnehmen und Sachverhalte fachsprachlich angemessen darstellen und austauschen.',
                'Bewertung' => 'Physikalische Sachverhalte und Anwendungen (z. B. Energienutzung) in ihren Chancen und Risiken abwägen und begründet Stellung nehmen.',
            ],
        ],
        'Chemie' => [
            'Kompetenzbereiche (Kerncurriculum Hessen Sek I)' => [
                'Nutzung fachlicher Konzepte' => 'Stoffe, Teilchen und chemische Reaktionen mit Basiskonzepten (z. B. Stoff-Teilchen, Struktur-Eigenschaft, Energie) strukturieren, vernetzen und erklären.',
                'Erkenntnisgewinnung' => 'Fragen und Hypothesen formulieren, Experimente sicher planen, durchführen und auswerten und Modelle (z. B. Teilchenmodell) nutzen.',
                'Kommunikation' => 'Chemische Informationen erschließen und Sachverhalte mit Fachsprache, Formeln und Reaktionsgleichungen adressatengerecht darstellen.',
                'Bewertung' => 'Chemische Sachverhalte und Stoffnutzungen (z. B. Umwelt, Gesundheit) in verschiedenen Kontexten abwägen und begründet bewerten.',
            ],
        ],
        'Geschichte' => [
            'Kompetenzbereiche (Kernlehrplan)' => [
                'Sachkompetenz' => 'Historische Ereignisse, Personen und Strukturen zeitlich und räumlich einordnen und Zusammenhänge zwischen Ursachen und Folgen erklären.',
                'Methodenkompetenz' => 'Quellen und Darstellungen (Texte, Bilder, Karikaturen, Karten) fachgerecht beschreiben, untersuchen und deuten.',
                'Urteilskompetenz' => 'Handlungen und Ereignisse aus damaliger und heutiger Sicht beurteilen und das eigene Urteil begründen.',
            ],
            'Kompetenzbereiche (Kerncurriculum Hessen Sek I)' => [
                'Wahrnehmungskompetenz' => 'Fragen an Vergangenheit und Geschichte stellen und begründete Vermutungen zu möglichen Antworten entwickeln.',
                'Analysekompetenz' => 'Informationen aus Quellen und Darstellungen beschaffen und untersuchen, um Fragen zu beantworten und Vermutungen zu überprüfen.',
                'Orientierungskompetenz' => 'Eine reflektierte Beziehung zwischen historischen Einsichten und der eigenen Lebenswelt herstellen und sich in der Gegenwart orientieren.',
            ],
        ],
        'Erdkunde' => [
            'Kompetenzbereiche (Kerncurriculum Hessen Sek I)' => [
                'Geographische Analysekompetenz' => 'Hypothesen zu räumlichen Phänomenen, Strukturen und Prozessen aufstellen und überprüfen und Wechselwirkungen zwischen Natur-Umwelt und Mensch-Gesellschaft erklären.',
                'Räumliche Orientierungskompetenz' => 'Karten, Atlas, Pläne und Web-GIS auswerten und die Lage von Orten und Phänomenen mit geographischen Ordnungssystemen in Beziehung setzen.',
                'Geographische Methodenkompetenz' => 'Geographische Informationen aus Quellen, Karten, Diagrammen und im Gelände gewinnen und von einer Darstellungsform in eine andere übertragen.',
                'Geographische Urteils- und Kommunikationskompetenz' => 'Räumliche Entwicklungen und Eingriffe des Menschen mehrperspektivisch und kriterienbezogen beurteilen und Ergebnisse fachsprachlich darstellen.',
            ],
        ],
        'Politik und Wirtschaft' => [
            'Kompetenzbereiche (Kerncurriculum Hessen Sek I)' => [
                'Analysekompetenz' => 'Politische und wirtschaftliche Sachverhalte (z. B. Demokratie, Märkte, Haushalt) untersuchen, Zusammenhänge erkennen und mit Fachbegriffen erklären.',
                'Urteilskompetenz' => 'Politische und wirtschaftliche Fragen aus unterschiedlichen Perspektiven abwägen und ein begründetes Sach- und Werturteil bilden.',
                'Handlungskompetenz' => 'Eigene Interessen vertreten, Konflikte friedlich austragen und Möglichkeiten politischer Beteiligung nutzen.',
                'Methodenkompetenz' => 'Informationen aus Texten, Statistiken, Karikaturen und Medien recherchieren, auswerten und kritisch auf ihre Absicht prüfen.',
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
            $subject = $subject === '' ? null : $subject;

            foreach ($areas as $area => $competencies) {
                foreach ($competencies as $name => $description) {
                    $code = self::codeFor($subject, $name);
                    $existing = Competency::query()->where('code', $code)->first()
                        ?? Competency::query()->whereNull('code')->where('subject', $subject)->where('name', $name)->first();

                    if ($existing === null) {
                        Competency::query()->forceCreate(['code' => $code, 'subject' => $subject, 'area' => $area, 'name' => $name, 'description' => $description]);
                    } elseif ($existing->code === null) {
                        $existing->forceFill(['code' => $code])->save();
                    }
                }
            }
        }
    }

    public static function codeFor(?string $subject, string $name): string
    {
        return Str::slug($subject ?? 'allgemein').'.'.Str::slug($name);
    }
}
