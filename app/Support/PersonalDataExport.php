<?php

namespace App\Support;

use App\MessageAuthor;
use App\Models\Answer;
use App\Models\ExplanationMessage;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Submission;
use App\Models\User;

/**
 * Everything the platform stores about one person, as plain JSON-serialisable data with German keys
 * (Auskunft nach Art. 15, Datenübertragbarkeit nach Art. 20 DSGVO).
 *
 * Deliberately left out: password hash, remember token, the teachers' Erwartungshorizonte and anything that
 * belongs to other people (classmates' hand-ins, students' content in a teacher's export).
 * The caller decides who may ask for whom (e.g. UserPolicy::view plus the school check). Pass the asking person as
 * `$viewer` whenever it is not the owner: then only what that person may see anyway is included, so a school
 * administration never receives a student's private "Erklär's mir" conversations.
 */
class PersonalDataExport
{
    /**
     * @return array<string, mixed>
     */
    public static function for(User $user, ?User $viewer = null): array
    {
        $viewer ??= $user;

        $export = [
            'hinweis' => 'Die zu diesem Konto gespeicherten personenbezogenen Daten (Auskunft nach Art. 15 und Art. 20 DSGVO). '
                .'Nicht enthalten sind das Passwort, Anmelde-Token und Daten anderer Personen.',
            'erstellt_am' => now()->toIso8601String(),
            'konto' => [
                'name' => $user->name,
                'benutzername' => $user->username,
                'email' => $user->email,
                'rolle' => $user->role->getLabel(),
                'schule' => $user->school?->name,
                'klasse' => $user->schoolClass?->name,
                'registriert_am' => $user->created_at?->toIso8601String(),
            ],
        ];

        if ($user->isStudent()) {
            $export['zugewiesene_hausaufgaben'] = self::assignedHomework($user);
            $export['abgaben'] = self::submissions($user, $viewer);
            $export['erklaer_mir_gespraeche'] = self::explanationSessions($user, $viewer);
        }

        if ($user->isTeacher()) {
            $export['klassenleitung'] = $user->ledClasses()->orderBy('name')->pluck('name')->all();
            $export['unterrichtsstunden'] = self::taughtLessons($user, $viewer);
        }

        return $export;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function assignedHomework(User $student): array
    {
        // Published only: a draft (e.g. a Förderaufgabe) is hidden from the student by LessonPolicy.
        return $student->assignedLessons()
            ->published()
            ->orderBy('lessons.id')
            ->get()
            ->map(fn (Lesson $lesson): array => [
                'fach' => $lesson->subject,
                'thema' => $lesson->topic,
                'unterricht_am' => $lesson->held_on->toDateString(),
                'faellig_am' => $lesson->due_on?->toDateString(),
                'zugewiesen_am' => $lesson->pivot->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function submissions(User $student, User $viewer): array
    {
        return $student->submissions()
            ->with(['lesson', 'answers.question'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Submission $submission): bool => self::isVisible($viewer, $student, $submission))
            ->values()
            ->map(fn (Submission $submission): array => [
                'fach' => $submission->lesson->subject,
                'thema' => $submission->lesson->topic,
                'unterricht_am' => $submission->lesson->held_on->toDateString(),
                'eingereicht_am' => $submission->created_at?->toIso8601String(),
                'zusammenfassung' => $submission->summary,
                'verstaendnis_punkte' => $submission->understanding_score,
                'rueckmeldung' => $submission->feedback,
                'luecken' => $submission->gaps ?? [],
                'bewertet_am' => $submission->evaluated_at?->toIso8601String(),
                'antworten' => $submission->answers
                    ->sortBy(fn (Answer $answer): array => [$answer->question->position, $answer->id])
                    ->map(fn (Answer $answer): array => [
                        'frage' => $answer->question->prompt,
                        'antwort' => $answer->answer,
                        'punkte' => $answer->score,
                        'rueckmeldung' => $answer->feedback,
                    ])
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function explanationSessions(User $student, User $viewer): array
    {
        return $student->explanationSessions()
            ->with(['lesson', 'messages'])
            ->orderBy('id')
            ->get()
            ->filter(fn (ExplanationSession $session): bool => self::isVisible($viewer, $student, $session))
            ->values()
            ->map(fn (ExplanationSession $session): array => [
                'thema' => $session->topic,
                'fach' => $session->lesson?->subject,
                'begonnen_am' => $session->created_at?->toIso8601String(),
                'beendet_am' => $session->finished_at?->toIso8601String(),
                'verstaendnis_punkte' => $session->understanding_score,
                'rueckmeldung' => $session->feedback,
                'nachrichten' => $session->messages
                    ->map(fn (ExplanationMessage $message): array => [
                        'von' => $message->author === MessageAuthor::Student ? 'Schüler:in' : 'Tuto (KI-Tutor)',
                        'text' => $message->content,
                        'gesendet_am' => $message->created_at?->toIso8601String(),
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * Lessons with the teacher's own texts; of the students only the number of hand-ins.
     *
     * @return list<array<string, mixed>>
     */
    private static function taughtLessons(User $teacher, User $viewer): array
    {
        return $teacher->taughtLessons()
            ->with('schoolClass')
            ->withCount(['questions', 'students', 'submissions'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Lesson $lesson): bool => self::isVisible($viewer, $teacher, $lesson))
            ->values()
            ->map(fn (Lesson $lesson): array => [
                'fach' => $lesson->subject,
                'thema' => $lesson->topic,
                'klasse' => $lesson->schoolClass->name,
                'unterricht_am' => $lesson->held_on->toDateString(),
                'notizen' => $lesson->notes,
                'zusammenfassung' => $lesson->summary,
                'anzahl_arbeitsblaetter' => count($lesson->attachments ?? []),
                'anzahl_fragen' => $lesson->questions_count,
                'ist_foerderaufgabe' => $lesson->isPracticeTask(),
                'veroeffentlicht_am' => $lesson->published_at?->toIso8601String(),
                'faellig_am' => $lesson->due_on?->toDateString(),
                'anzahl_zugewiesene_schueler' => $lesson->students_count,
                'anzahl_abgaben' => $lesson->submissions_count,
            ])
            ->all();
    }

    /**
     * The owner sees everything of their own; anybody else only what their own policies allow.
     */
    private static function isVisible(User $viewer, User $owner, Lesson|Submission|ExplanationSession $record): bool
    {
        return $viewer->is($owner) || $viewer->can('view', $record);
    }
}
