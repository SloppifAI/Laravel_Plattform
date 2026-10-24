<?php

namespace App\Support;

use App\MessageAuthor;
use App\Models\Answer;
use App\Models\ClassBookEntry;
use App\Models\ClassSubstitution;
use App\Models\ConsentForm;
use App\Models\DpaAcceptance;
use App\Models\ExplanationMessage;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Models\RecordingConsent;
use App\Models\StudentQuestion;
use App\Models\Submission;
use App\Models\User;
use App\QuestionLevel;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\App;

/**
 * Everything the platform stores about one person, as plain JSON-serialisable data with German keys
 * (Auskunft nach Art. 15, Datenübertragbarkeit nach Art. 20 DSGVO).
 *
 * Deliberately left out: password hash, remember token, the hash of a consent link, the teachers' Erwartungshorizonte
 * and anything that belongs to other people (classmates' hand-ins, students' content and names in a teacher's export,
 * the substitute's name in the export of whoever set the substitution up).
 * The caller decides who may ask for whom (e.g. UserPolicy::view plus the school check). Pass the asking person as
 * `$viewer` whenever it is not the owner: then only what that person may see anyway is included, so a school
 * administration never receives a student's private "Erklär's mir" conversations, a teacher's Tuto-Mikro sessions or
 * the bell (which nobody but its owner reads). The platform operator, who may only ask for administration accounts,
 * gets the account and the contract acceptances and nothing from inside a school (classes, lessons, notifications).
 */
class PersonalDataExport
{
    /**
     * @return array<string, mixed>
     */
    public static function for(User $user, ?User $viewer = null): array
    {
        $viewer ??= $user;
        $isOwner = $viewer->is($user);

        $export = [
            'hinweis' => 'Die zu diesem Konto gespeicherten personenbezogenen Daten (Auskunft nach Art. 15 und Art. 20 DSGVO). '
                .'Nicht enthalten sind das Passwort, Anmelde-Token und Daten anderer Personen.',
            'erstellt_am' => now()->toIso8601String(),
            'konto' => [
                'name' => $user->name,
                'benutzername' => $user->username,
                'email' => $user->email,
                'rolle' => self::germanLabel($user->role),
                'schule' => $user->school?->name,
                'klasse' => $user->schoolClass?->name,
                'sprache' => $user->locale === null ? null : (config('lernplattform.locale.available')[$user->locale] ?? $user->locale),
                'registriert_am' => $user->created_at?->toIso8601String(),
            ],
        ];

        if ($user->isStudent()) {
            $export['zugewiesene_hausaufgaben'] = self::assignedHomework($user);
            $export['abgaben'] = self::submissions($user, $viewer);
            $export['erklaer_mir_gespraeche'] = self::explanationSessions($user, $viewer);
            $export['rueckfragen'] = self::askedQuestions($user, $viewer);
            $export['klassenbuch_fehlzeiten'] = self::classBookAbsences($user, $viewer);
            $export['einwilligung_tuto_mikro'] = self::recordingConsent($user, $viewer);
            $export['tuto_mikro_anwesenheit'] = self::mikroAttendance($user, $viewer);
        }

        if ($user->isTeacher()) {
            $export['klassenleitung'] = $user->ledClasses()->orderBy('name')->pluck('name')->all();
            $export['zugeordnete_klassen'] = $user->taughtClasses()->orderBy('name')->pluck('name')->all();
            $export['vertretungen'] = self::substitutions($user, $viewer);
            $export['unterrichtsstunden'] = self::taughtLessons($user, $viewer);
            $export['beantwortete_rueckfragen'] = self::answeredQuestions($user, $viewer);
            $export['klassenbuch_eintraege'] = self::classBookEntries($user, $viewer);
            $export['tuto_mikro_sitzungen'] = self::mikroSessions($user, $viewer);
        }

        if ($viewer->isOperator() && ! $isOwner) {
            return [...$export, ...array_filter(['schuleinstellungen' => self::schoolSettings($user, agreementsOnly: true)])];
        }

        // An administration teaches, answers Rückfragen and writes Klassenbuch entries like a teacher, but only if it did.
        // Acts of a Klassenleitung or an administration, and the bell: only listed when there are any.
        // The school's settings and the recorded consents: for the owner and the administration of the school only.
        $seesSchool = $isOwner || ($user->school !== null && $viewer->can('view', $user->school));
        $optional = array_filter([
            'unterrichtsstunden' => $user->isAdmin() ? self::taughtLessons($user, $viewer) : [],
            'beantwortete_rueckfragen' => $user->isAdmin() ? self::answeredQuestions($user, $viewer) : [],
            'klassenbuch_eintraege' => $user->isAdmin() ? self::classBookEntries($user, $viewer) : [],
            'eingerichtete_vertretungen' => self::grantedSubstitutions($user, $viewer),
            'erfasste_einwilligungserklaerungen' => $seesSchool ? RecordingConsent::query()->where('recorded_by', $user->getKey())->count() : 0,
            'schuleinstellungen' => $seesSchool ? self::schoolSettings($user) : [],
            'benachrichtigungen' => $isOwner ? self::notifications($user) : [],
        ]);

        // Students and teachers always get the bell section in their own file.
        if ($isOwner && ($user->isStudent() || $user->isTeacher())) {
            $optional['benachrichtigungen'] ??= [];
        }

        return [...$export, ...$optional];
    }

    /**
     * The bell entries of the account; they carry only texts about the person's own lessons and homework.
     *
     * @return list<array<string, mixed>>
     */
    private static function notifications(User $user): array
    {
        return $user->notifications()
            ->oldest()
            ->get()
            ->map(fn (DatabaseNotification $notification): array => [
                'titel' => $notification->data['title'] ?? null,
                'text' => $notification->data['body'] ?? null,
                'erhalten_am' => $notification->created_at?->toIso8601String(),
                'gelesen_am' => $notification->read_at?->toIso8601String(),
            ])
            ->all();
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
                        'frage' => $answer->askedPrompt(),
                        'stufe' => self::germanLabel(QuestionLevel::tryFrom((string) $answer->level)),
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
     * The student's questions to the teacher with the answers, without the name of who answered.
     *
     * @return list<array<string, mixed>>
     */
    private static function askedQuestions(User $student, User $viewer): array
    {
        return StudentQuestion::query()
            ->with('lesson')
            ->where('student_id', $student->getKey())
            ->orderBy('id')
            ->get()
            ->filter(fn (StudentQuestion $question): bool => self::isVisible($viewer, $student, $question))
            ->values()
            ->map(fn (StudentQuestion $question): array => [
                'fach' => $question->lesson?->subject,
                'thema' => $question->lesson?->topic,
                'gefragt_am' => $question->created_at?->toIso8601String(),
                'frage' => $question->question,
                'antwort' => $question->answer,
                'beantwortet_am' => $question->answered_at?->toIso8601String(),
                'gelesen_am' => $question->read_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Answers the teacher wrote to students' questions: the teacher's own text, not the student's question or name.
     *
     * @return list<array<string, mixed>>
     */
    private static function answeredQuestions(User $teacher, User $viewer): array
    {
        return StudentQuestion::query()
            ->with('lesson')
            ->where('answered_by', $teacher->getKey())
            ->orderBy('id')
            ->get()
            ->filter(fn (StudentQuestion $question): bool => self::isVisible($viewer, $teacher, $question))
            ->values()
            ->map(fn (StudentQuestion $question): array => [
                'fach' => $question->lesson?->subject,
                'thema' => $question->lesson?->topic,
                'antwort' => $question->answer,
                'beantwortet_am' => $question->answered_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The parents' declaration on Tuto-Mikro for the student, as recorded: who declared, which purposes, when, on paper or
     * digitally. For anybody but the student only if they look after the consents of the student's class.
     *
     * @return array<string, mixed>|null
     */
    private static function recordingConsent(User $student, User $viewer): ?array
    {
        $consent = RecordingConsent::query()->with('consentForm')->where('student_id', $student->getKey())->first();

        $mayRead = $viewer->is($student)
            || ($student->schoolClass !== null && $viewer->can('manageClass', [RecordingConsent::class, $student->schoolClass]));

        if ($consent === null || ! $mayRead) {
            return null;
        }

        return [
            'erziehungsberechtigte_person' => $consent->guardian_name,
            'zwecke' => array_values(array_intersect_key(ConsentForm::PURPOSES, array_flip($consent->purposes ?? []))),
            'fassung_des_einwilligungstextes' => $consent->consentForm?->version,
            'art' => $consent->source === RecordingConsent::SOURCE_PAPER ? 'auf Papier' : 'digital',
            'einwilligungsschreiben_erstellt_am' => $consent->token_generated_at?->toIso8601String(),
            'erteilt_am' => $consent->granted_at?->toIso8601String(),
            'widerrufen_am' => $consent->revoked_at?->toIso8601String(),
            'eigene_bestaetigung_am' => $consent->student_confirmed_at?->toIso8601String(),
        ];
    }

    /**
     * Tuto-Mikro sessions the student was marked present in: when and in which lesson, nothing about the others.
     *
     * @return list<array<string, mixed>>
     */
    private static function mikroAttendance(User $student, User $viewer): array
    {
        return LessonRecording::query()
            ->with('lesson.schoolClass')
            ->whereJsonContains('present_student_ids', $student->getKey())
            ->orderBy('started_at')
            ->get()
            ->filter(fn (LessonRecording $recording): bool => self::isVisible($viewer, $student, $recording))
            ->values()
            ->map(fn (LessonRecording $recording): array => [
                'fach' => $recording->lesson->subject,
                'thema' => $recording->lesson->topic,
                'klasse' => $recording->lesson->schoolClass?->name,
                'beginn' => $recording->started_at->toIso8601String(),
                'ende' => $recording->finished_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The teacher's Tuto-Mikro sessions: the stored proof without audio or text; of the students only the number.
     *
     * @return list<array<string, mixed>>
     */
    private static function mikroSessions(User $teacher, User $viewer): array
    {
        return LessonRecording::query()
            ->with('lesson.schoolClass')
            ->where('teacher_id', $teacher->getKey())
            ->orderBy('started_at')
            ->get()
            ->filter(fn (LessonRecording $recording): bool => self::isVisible($viewer, $teacher, $recording))
            ->values()
            ->map(fn (LessonRecording $recording): array => [
                'fach' => $recording->lesson->subject,
                'thema' => $recording->lesson->topic,
                'klasse' => $recording->lesson->schoolClass?->name,
                'beginn' => $recording->started_at->toIso8601String(),
                'ende' => $recording->finished_at?->toIso8601String(),
                'dauer_sekunden' => $recording->duration_seconds,
                'anzahl_anwesende_schueler' => count($recording->present_student_ids ?? []),
                'anwesenheit_bestaetigt_am' => $recording->attendance_confirmed_at?->toIso8601String(),
                'fassung_des_einwilligungstextes' => $recording->consent_form_version,
                'gepruefte_zwecke' => array_values(array_intersect_key(ConsentForm::PURPOSES_SHORT, array_flip($recording->purposes ?? []))),
                'ton_an_ki_anbieter' => $recording->audio_sent_to_ai,
            ])
            ->all();
    }

    /**
     * The substitutions in which the teacher stands in for a class.
     *
     * @return list<array<string, mixed>>
     */
    private static function substitutions(User $teacher, User $viewer): array
    {
        return ClassSubstitution::query()
            ->with('schoolClass')
            ->where('substitute_id', $teacher->getKey())
            ->orderBy('starts_on')
            ->get()
            ->filter(fn (ClassSubstitution $substitution): bool => self::isVisible($viewer, $teacher, $substitution))
            ->values()
            ->map(fn (ClassSubstitution $substitution): array => [
                'klasse' => $substitution->schoolClass->name,
                'von' => $substitution->starts_on->toDateString(),
                'bis' => $substitution->ends_on->toDateString(),
                'hinweis' => $substitution->note,
            ])
            ->all();
    }

    /**
     * The substitutions the person set up, without the substitute's name.
     *
     * @return list<array<string, mixed>>
     */
    private static function grantedSubstitutions(User $user, User $viewer): array
    {
        return ClassSubstitution::query()
            ->with('schoolClass')
            ->where('granted_by', $user->getKey())
            ->orderBy('starts_on')
            ->get()
            ->filter(fn (ClassSubstitution $substitution): bool => self::isVisible($viewer, $user, $substitution))
            ->values()
            ->map(fn (ClassSubstitution $substitution): array => [
                'klasse' => $substitution->schoolClass->name,
                'von' => $substitution->starts_on->toDateString(),
                'bis' => $substitution->ends_on->toDateString(),
                'eingerichtet_am' => $substitution->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * What an administration did for its school: switching Tuto-Mikro and the AI on, accepting the data processing
     * agreement, writing consent forms. Empty for everybody else. `$agreementsOnly` is what the platform operator gets.
     *
     * @return array<string, mixed>
     */
    private static function schoolSettings(User $user, bool $agreementsOnly = false): array
    {
        $school = $user->school;

        return array_filter([
            'ki_funktionen_eingeschaltet_am' => ! $agreementsOnly && $school !== null && (int) $school->ai_enabled_by === $user->getKey() ? $school->ai_enabled_at?->toIso8601String() : null,
            'tuto_mikro_freigeschaltet_am' => ! $agreementsOnly && $school !== null && (int) $school->recordings_enabled_by === $user->getKey() ? $school->recordings_enabled_at?->toIso8601String() : null,
            'avv_annahmen' => DpaAcceptance::query()
                ->where('accepted_by', $user->getKey())
                ->orderBy('accepted_at')
                ->get()
                ->map(fn (DpaAcceptance $acceptance): array => ['version' => $acceptance->version, 'angenommen_am' => $acceptance->accepted_at->toIso8601String()])
                ->all(),
            'einwilligungstexte' => $agreementsOnly ? [] : ConsentForm::query()
                ->where('created_by', $user->getKey())
                ->orderBy('version')
                ->get()
                ->map(fn (ConsentForm $form): array => ['fassung' => $form->version, 'titel' => $form->title, 'veroeffentlicht_am' => $form->published_at?->toIso8601String()])
                ->all(),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
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
     * The days the student is marked absent in a Klassenbuch: date, subject, class and status of the entry, without
     * the other students and the teacher's texts.
     *
     * @return list<array<string, mixed>>
     */
    private static function classBookAbsences(User $student, User $viewer): array
    {
        return ClassBookEntry::query()
            ->with('schoolClass')
            ->whereJsonContains('absent_student_ids', $student->getKey())
            ->orderBy('held_on')
            ->orderBy('id')
            ->get()
            ->filter(fn (ClassBookEntry $entry): bool => self::isVisible($viewer, $student, $entry))
            ->values()
            ->map(fn (ClassBookEntry $entry): array => [
                'datum' => $entry->held_on->toDateString(),
                'fach' => $entry->subject,
                'klasse' => $entry->schoolClass->name,
                'status' => self::germanLabel($entry->status()),
            ])
            ->all();
    }

    /**
     * The Klassenbuch entries the teacher wrote, with their own texts; of the absent students only the number.
     *
     * @return list<array<string, mixed>>
     */
    private static function classBookEntries(User $teacher, User $viewer): array
    {
        return ClassBookEntry::query()
            ->with('schoolClass')
            ->where('teacher_id', $teacher->getKey())
            ->orderBy('held_on')
            ->orderBy('id')
            ->get()
            ->filter(fn (ClassBookEntry $entry): bool => self::isVisible($viewer, $teacher, $entry))
            ->values()
            ->map(fn (ClassBookEntry $entry): array => [
                'klasse' => $entry->schoolClass->name,
                'datum' => $entry->held_on->toDateString(),
                'stunde' => $entry->period,
                'fach' => $entry->subject,
                'thema' => $entry->topic,
                'unterrichtsinhalt' => $entry->content,
                'hausaufgabe' => $entry->homework,
                'bemerkungen' => $entry->remarks,
                'anzahl_fehlende_schueler' => count($entry->absent_student_ids ?? []),
                'status' => self::germanLabel($entry->status()),
                'bestaetigt_am' => $entry->confirmed_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The owner sees everything of their own; anybody else only what their own policies allow (a Tuto-Mikro session has
     * no view ability, so it is the owner's only).
     */
    private static function isVisible(User $viewer, User $owner, Lesson|Submission|ExplanationSession|ClassBookEntry|StudentQuestion|LessonRecording|ClassSubstitution $record): bool
    {
        return $viewer->is($owner) || $viewer->can('view', $record);
    }

    /**
     * The export is a German document, so the labels of enums in it are German whatever language the viewer reads the interface in.
     */
    private static function germanLabel(?HasLabel $case): ?string
    {
        $locale = App::getLocale();
        App::setLocale('de');

        try {
            return $case?->getLabel();
        } finally {
            App::setLocale($locale);
        }
    }
}
