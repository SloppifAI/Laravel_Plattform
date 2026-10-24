<?php

namespace App\Support;

use App\Filament\Pages\MyHomework;
use App\Filament\Pages\MyQuestions;
use App\Filament\Pages\QuestionInbox;
use App\Filament\Pages\WorkOnHomework;
use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Lesson;
use App\Models\StudentQuestion;
use App\Models\Submission;
use App\Models\User;
use App\Role;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The bell of the platform: small, typed helpers that put a Filament database notification into the notifications
 * table of exactly the people concerned and of nobody from another school. Texts never name a person; teachers get
 * a count per lesson instead ("3 neue Abgaben"), so a class handing in does not flood the bell.
 *
 * Delivery is immediate (notifyNow): the production container runs no queue worker, which a queued notification needs.
 * Every helper returns the number of notifications that were put into a bell.
 */
final class Notify
{
    /**
     * The homework of a published lesson was released to its students. Call after published_at is set and the
     * students are synced; students that are not delegated, not students or not of the lesson's school get nothing.
     *
     * @param  iterable<User>  $students
     */
    public static function homeworkReleased(Lesson $lesson, iterable $students): int
    {
        if (! $lesson->isPublished()) {
            return 0;
        }

        $url = WorkOnHomework::getUrl(['lesson' => $lesson], isAbsolute: false);
        $due = $lesson->due_on ? 'Abgabe bis '.$lesson->due_on->format('d.m.Y').'.' : null;
        $title = $lesson->isPracticeTask() ? "Neue Förderaufgabe: „{$lesson->topic}“" : "Neue Hausaufgabe: „{$lesson->topic}“";

        $recipients = $lesson->students()
            ->whereKey(collect($students)->map(fn (User $student): mixed => $student->getKey())->all())
            ->where('users.role', Role::Student)
            ->where('users.school_id', $lesson->schoolClass->school_id)
            ->get();

        foreach ($recipients as $student) {
            self::send($student, self::make($title, $due, $url, Heroicon::OutlinedBookOpen));
        }

        return $recipients->count();
    }

    /**
     * A student handed in; the lesson's teacher gets one bell entry per lesson that counts the new submissions.
     */
    public static function submissionReceived(Submission $submission): int
    {
        $lesson = $submission->lesson;

        return self::sendCountedToTeacher($lesson, 'submission', fn (int $count): Notification => self::make(
            $count === 1 ? "Neue Abgabe zu „{$lesson->topic}“" : "{$count} neue Abgaben zu „{$lesson->topic}“",
            self::lessonLabel($lesson),
            LessonResource::getUrl('edit', ['record' => $lesson], isAbsolute: false),
            Heroicon::OutlinedClipboardDocumentCheck,
        ));
    }

    /**
     * A student asked a question about a lesson; assumes StudentQuestion has the relations `lesson` and `student`.
     * The teacher gets a count per lesson, linked to the question inbox once that page exists.
     */
    public static function questionAsked(StudentQuestion $question): int
    {
        $lesson = $question->lesson;

        if ($lesson === null) {
            return 0;
        }

        return self::sendCountedToTeacher($lesson, 'question', fn (int $count): Notification => self::make(
            $count === 1 ? "Neue Frage zu „{$lesson->topic}“" : "{$count} neue Fragen zu „{$lesson->topic}“",
            self::lessonLabel($lesson),
            class_exists(QuestionInbox::class)
                ? QuestionInbox::getUrl(isAbsolute: false)
                : LessonResource::getUrl('edit', ['record' => $lesson], isAbsolute: false),
            Heroicon::OutlinedChatBubbleLeftRight,
        ));
    }

    /**
     * The teacher answered a student's question; only that student is told, and only while they belong to the lesson's school.
     */
    public static function questionAnswered(StudentQuestion $question): int
    {
        $lesson = $question->lesson;
        $student = $question->student;

        if ($lesson === null || ! self::belongsToSchoolOf($student, $lesson) || ! $student->isStudent()) {
            return 0;
        }

        self::send($student, self::make(
            "Deine Frage zu „{$lesson->topic}“ wurde beantwortet",
            self::lessonLabel($lesson),
            class_exists(MyQuestions::class) ? MyQuestions::getUrl(isAbsolute: false) : MyHomework::getUrl(isAbsolute: false),
            Heroicon::OutlinedChatBubbleLeftEllipsis,
        ));

        return 1;
    }

    /**
     * Replaces the teacher's unread entry of this kind for the lesson by one with the count raised by one. Only
     * someone who may still read the lesson's results hears of it: a teacher who was turned into a student keeps the lesson's
     * teacher_id, but not the right to its hand-ins. Read, delete and insert run under a row lock, so two hand-ins at
     * the same moment cannot both start from the same count.
     *
     * @param  Closure(int): Notification  $build
     */
    private static function sendCountedToTeacher(Lesson $lesson, string $kind, Closure $build): int
    {
        $teacher = $lesson->teacher;

        if (! self::belongsToSchoolOf($teacher, $lesson) || ! Gate::forUser($teacher)->allows('viewResults', $lesson)) {
            return 0;
        }

        DB::transaction(function () use ($teacher, $lesson, $kind, $build): void {
            $previous = $teacher->unreadNotifications()
                ->where('data->viewData->kind', $kind)
                ->where('data->viewData->lesson_id', $lesson->getKey())
                ->lockForUpdate()
                ->get();
            $count = $previous->sum(fn (DatabaseNotification $entry): int => $entry->data['viewData']['count']) + 1;
            $previous->each->delete();

            self::send($teacher, $build($count)->viewData(['kind' => $kind, 'lesson_id' => $lesson->getKey(), 'count' => $count]));
        });

        return 1;
    }

    private static function make(string $title, ?string $body, string $url, Heroicon $icon): Notification
    {
        return Notification::make()
            ->title($title)
            ->body($body)
            ->icon($icon)
            ->iconColor('primary')
            ->actions([Action::make('open')->label('Öffnen')->button()->url($url)->markAsRead()]);
    }

    private static function send(User $recipient, Notification $notification): void
    {
        $recipient->notifyNow($notification->toDatabase());
    }

    private static function belongsToSchoolOf(?User $user, Lesson $lesson): bool
    {
        return $user?->school_id !== null && $user->school_id === $lesson->schoolClass->school_id;
    }

    private static function lessonLabel(Lesson $lesson): string
    {
        return $lesson->subject.' · '.$lesson->schoolClass->name;
    }
}
