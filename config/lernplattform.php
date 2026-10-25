<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Language
    |--------------------------------------------------------------------------
    |
    | The UI texts are written in German and are their own translation keys (`__('Speichern')`); translations live in
    | lang/areas/<area>/<locale>.json (see TranslationServiceProvider). `default` is the language until somebody picks
    | one, `available` maps every selectable language code to its name in that language. A signed-in user's choice
    | (users.locale) wins over the one made on the login page (session), which wins over `default` (SetLocale).
    |
    */

    'locale' => [
        'default' => env('LERNPLATTFORM_LOCALE', 'de'),
        'available' => ['de' => 'Deutsch', 'en' => 'English'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention of learning data
    |--------------------------------------------------------------------------
    |
    | Lessons (with their questions, hand-ins and answers) and "Erklär's mir" conversations are kept for the
    | current school year plus the previous ones: the default `school_years_kept` = 1 keeps only the current school
    | year, so everything is deleted when the next one begins ("Löschen zum Schuljahresende"); 2 would keep the
    | current and the last school year. `school_year_starts` is the month and day (MM-DD) a school year begins. A lesson is only
    | deleted when its date, its last change, its due date and its newest hand-in all lie before the start of
    | the oldest kept school year. See `php artisan lernplattform:prune-learning-data`.
    |
    | `one_year_rule_from` is the transition to the one-year default: the agreements schools accepted so far promise two
    | school years, so until this date (YYYY-MM-DD) a setting of 1 still keeps two, and no school year is deleted ahead of
    | its time. From that date on only the current school year is kept (the lessons of 2026/27 and later are then deleted
    | at the end of their school year, those of 2025/26 on 01.08.2027). Empty = the setting applies at once.
    |
    */

    'retention' => [
        'school_years_kept' => (int) env('LERNPLATTFORM_SCHOOL_YEARS_KEPT', 1),
        'school_year_starts' => env('LERNPLATTFORM_SCHOOL_YEAR_STARTS', '08-01'),
        'one_year_rule_from' => env('LERNPLATTFORM_ONE_YEAR_RULE_FROM', '2027-08-01'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tuto-Mikro (the teacher's microphone, sound is not stored)
    |--------------------------------------------------------------------------
    |
    | The browser cuts the teacher's microphone into segments of `segment_seconds`, each a complete file of about
    | 100 KB (`max_segment_kb` caps one upload). A segment is recognised from memory and never written to a disk, so no
    | sound and no text is stored. The recognised text stays in the teacher's browser until the teacher takes something
    | over into the lesson notes; `max_text_chars` caps the text sent for the summary.
    |
    | A session (who led it, who was present and when the teacher confirmed that list, which consent form version,
    | whether segments went to the AI) is kept for `session_keep_days` days as proof for the parents and the school, then
    | deleted (`php artisan lernplattform:prune-recordings`, scheduled daily, which also sweeps files left by the former
    | recording mode). A session that was never ended and is older than `max_session_hours` accepts nothing more.
    |
    | `keep_days` is how long texts that still describe the former recording mode say recordings are kept; nothing of
    | that kind is stored any more. The data processing agreement no longer reads it (DpaAcceptance::terms()).
    |
    */

    'recordings' => [
        'keep_days' => (int) env('LERNPLATTFORM_RECORDING_KEEP_DAYS', 14),
        'session_keep_days' => (int) env('LERNPLATTFORM_MIKRO_SESSION_KEEP_DAYS', 365),
        'segment_seconds' => 25,
        'max_session_hours' => 6,
        'max_segment_kb' => (int) env('LERNPLATTFORM_MIKRO_MAX_SEGMENT_KB', 2048),
        'max_text_chars' => 100000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Data processing agreement (AVV, Art. 28 DSGVO)
    |--------------------------------------------------------------------------
    |
    | `version` identifies the text of the agreement on /auftragsverarbeitung. Schools accept it electronically; the
    | accepted version, the text as the school saw it and who accepted it are stored (DpaAcceptance). Change the version
    | whenever the text changes in substance: every school that accepted an older version then sees a prominent request
    | to accept the new one under "Meine Schule", and the operator sees it as outdated in the school list. Values the
    | text takes from the configuration (retention periods and the transition date, Tuto-Mikro and notification limits,
    | the longest substitution, session lifetime, the platform's AI switch and provider) are part of the acceptance as
    | well, see DpaAcceptance::terms(): changing one of them makes every acceptance outdated, too, under the same version.
    |
    | `final` releases the contract and is on by default. A school that never accepted can use nothing but the
    | "Meine Schule" page, and its lessons cannot be recorded, until its administration accepts. With
    | LERNPLATTFORM_DPA_FINAL=false the text is a draft: nobody can accept it, a registering school gets no acceptance box
    | and nobody is held back by a missing acceptance.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | AI (Laravel AI SDK)
    |--------------------------------------------------------------------------
    |
    | The real AI runs through the Laravel AI SDK (config/ai.php, default provider Gemini via GEMINI_API_KEY). It is
    | used only when `enabled` is true AND the school opted in under its settings (School::aiEnabled()); every other
    | request is answered by the offline demo logic, so nothing leaves the platform. `provider` and `model` choose the
    | provider from config/ai.php and an optional model (null = the provider's default).
    |
    | `thinking_level` is how long Gemini thinks before it answers (minimal, low, medium or high; empty = the model's
    | own default, which is high for Gemini 3 and made a homework check take a minute or more). Other providers ignore
    | it. Gemini 3 Pro knows only low and high.
    |
    */

    'ai' => [
        'enabled' => (bool) env('AI_ENABLED', filled(env('GEMINI_API_KEY'))),
        'provider' => env('AI_PROVIDER', 'gemini'),
        'model' => env('AI_MODEL'),
        'transcription_model' => env('AI_TRANSCRIPTION_MODEL'),
        'timeout' => (int) env('AI_TIMEOUT', 120),
        'thinking_level' => env('AI_THINKING_LEVEL', 'low'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mistake types (Fehlerdiagnose)
    |--------------------------------------------------------------------------
    |
    | After a hand-in is evaluated, every answer scoring below `threshold` (0–100) gets the kind of its mistake as a hint
    | (App\MistakeType: core idea unclear, a step wrong, incomplete, off topic, no attempt): from the AI's reading in the
    | same request for a school with the AI switched on, from fixed text rules otherwise (App\Ai\MistakeClassifier). A
    | reading less sure than `min_confidence` (0–1) is not stored: no type is better than a wrong one. The type never
    | changes a score, the support threshold or the parent report, and a teacher's correction is never overwritten.
    | A failing classifier leaves the type empty; the evaluation of the hand-in never waits for it or fails because of it.
    |
    */

    'mistakes' => [
        'threshold' => (int) env('LERNPLATTFORM_MISTAKES_THRESHOLD', 80),
        'min_confidence' => (float) env('LERNPLATTFORM_MISTAKES_MIN_CONFIDENCE', 0.6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vertretung (substitute handover)
    |--------------------------------------------------------------------------
    |
    | A Klassenleitung or the administration lets a teacher of the school teach a class for a period (first and last
    | day included). `max_days` caps one period and the running and planned periods of the same teacher in the same
    | class together, so a substitution cannot be chained into a permanent assignment: for that, the administration
    | assigns the teacher to the class. A new period may be set up once the earlier ones have run down.
    |
    */

    'substitutions' => [
        'max_days' => (int) env('LERNPLATTFORM_SUBSTITUTION_MAX_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Timetable
    |--------------------------------------------------------------------------
    |
    | The bell times of the periods a timetable entry can sit in, numbered from 1, as start and end (HH:MM). The school
    | administration places the entries on Monday to Friday; students and teachers see their week on "Stundenplan".
    | The number of periods here is the number of rows of the timetable.
    |
    */

    'timetable' => [
        'periods' => [
            1 => ['08:00', '08:45'],
            2 => ['08:45', '09:30'],
            3 => ['09:50', '10:35'],
            4 => ['10:35', '11:20'],
            5 => ['11:40', '12:25'],
            6 => ['12:25', '13:10'],
            7 => ['14:00', '14:45'],
            8 => ['14:45', '15:30'],
            9 => ['15:30', '16:15'],
            10 => ['16:15', '17:00'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications (the bell)
    |--------------------------------------------------------------------------
    |
    | An entry names a lesson topic, subject and class. It is deleted `keep_read_days` days after it was read and at the
    | latest `keep_days` days after it was created; entries of deleted accounts go with the next run. See
    | `php artisan lernplattform:prune-notifications` (scheduled daily).
    |
    */

    'notifications' => [
        'keep_read_days' => (int) env('LERNPLATTFORM_NOTIFICATIONS_KEEP_READ_DAYS', 14),
        'keep_days' => (int) env('LERNPLATTFORM_NOTIFICATIONS_KEEP_DAYS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Revising in intervals ("Heute wiederholen")
    |--------------------------------------------------------------------------
    |
    | When a hand-in is evaluated, every answered comprehension and application question goes into box 1 of the student's
    | Leitner boxes, due the next day (App\Support\ReviewSchedule). `intervals` are the days until the next revision,
    | one entry per box: with 1, 3, 7 and 21 a question rated "Sofort gewusst" moves up a box and comes back after the
    | interval of the new box, "Mit Mühe" stays in its box and waits for its interval again, "Nicht gewusst" goes back to
    | box 1. A question that moves past the last interval counts as consolidated and is not asked any more (box 5 with the
    | default four intervals). LERNPLATTFORM_REVIEW_INTERVALS is a comma-separated list of days, each at least 1.
    |
    | `daily_limit` is the most questions a student gets per day, the oldest due ones first. The self-ratings are private
    | to the student and only move the question between boxes: they never count as a score, never reach the learning
    | progress, the parents' report or a teacher, and no response times are kept. The rows go with their question, so with
    | the lesson (`lernplattform:prune-learning-data`), and with the student's account.
    |
    */

    'review' => [
        'intervals' => array_map('intval', explode(',', (string) env('LERNPLATTFORM_REVIEW_INTERVALS', '1,3,7,21'))),
        'daily_limit' => (int) env('LERNPLATTFORM_REVIEW_DAILY_LIMIT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Connected apps
    |--------------------------------------------------------------------------
    |
    | Native apps a teacher or school administration may connect to their account. The app opens /app/verbinden in the
    | browser (OAuth 2.0 authorization code with PKCE, RFC 7636 and RFC 8252), the person signs in on the normal login
    | page and confirms, and the app trades the one-time code for a token at POST /api/v1/token. The app never sees the
    | password. Only the clients listed here may ask, and the redirect_uri of a request must equal one of the client's
    | `redirect_uris` exactly. LERNPLATTFORM_APP_REDIRECT_URIS is a comma-separated list (default tutoapp://callback).
    | `completion_page` (default true) answers "Erlauben" and "Abbrechen" with a page that opens the redirect URI and
    | has a button for it, because a desktop browser hands the link to the app but stays on the consent page; false
    | redirects straight to the redirect URI instead.
    |
    */

    'apps' => [
        'clients' => [
            'tuto-app' => [
                'name' => 'Tuto Mic',
                'redirect_uris' => array_values(array_filter(array_map('trim', explode(',', (string) env('LERNPLATTFORM_APP_REDIRECT_URIS', 'tutoapp://callback'))))),
                'completion_page' => true,
            ],
        ],
    ],

    'dpa' => [
        'version' => '2026-10',
        'title' => 'Vertrag zur Auftragsverarbeitung (AVV)',
        'final' => (bool) env('LERNPLATTFORM_DPA_FINAL', true),
    ],

];
