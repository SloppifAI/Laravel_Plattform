<?php

namespace App\Support;

use App\Filament\Pages\MyQuestions;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\StudentQuestion;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * The "Frage an meine Lehrkraft" modal shared by "Erklär’s mir" and the result page of a homework. The page decides
 * what the question is about and authorizes it; this action only asks for the text, keeps the limit of open questions,
 * stores the question and tells the lesson's teacher.
 */
final class AskTeacher
{
    /**
     * @param  Closure(): array{0: Lesson, 1: ExplanationSession|null}  $resolve  Runs on every submit, before anything is stored: it looks up the lesson the question is about (and the session it comes from, if any) and authorizes the student for it.
     */
    public static function action(Closure $resolve): Action
    {
        return Action::make('askTeacher')
            ->label(__('Frage an meine Lehrkraft'))
            ->icon(Heroicon::OutlinedQuestionMarkCircle)
            ->color('gray')
            ->modalIcon(Heroicon::OutlinedQuestionMarkCircle)
            ->modalHeading(__('Frage an deine Lehrkraft'))
            ->modalDescription(__('Deine Lehrkraft antwortet dir unter „Meine Rückfragen“. Lesen können deine Frage nur sie und die Schuladministration, deine Mitschüler:innen nicht.'))
            ->schema([
                Textarea::make('question')
                    ->label(__('Deine Frage'))
                    ->placeholder(__('Was möchtest du deine Lehrkraft fragen?'))
                    ->rows(4)
                    ->required()
                    ->maxLength(2000)
                    ->validationMessages([
                        'required' => __('Schreib zuerst deine Frage auf.'),
                        'max' => __('Das ist zu lang. Fass dich bitte kürzer.'),
                    ]),
            ])
            ->modalSubmitActionLabel(__('Frage abschicken'))
            ->modalCancelActionLabel(__('Abbrechen'))
            ->action(function (array $data) use ($resolve): void {
                [$lesson, $session] = $resolve();

                /** @var User $student */
                $student = Filament::auth()->user();

                $openQuestions = StudentQuestion::query()->whereBelongsTo($student, 'student')->whereBelongsTo($lesson)->open()->count();

                if ($openQuestions >= StudentQuestion::MAX_OPEN_PER_LESSON) {
                    Notification::make()
                        ->warning()
                        ->title(__('Du hast schon :count offene Fragen zu dieser Stunde', ['count' => StudentQuestion::MAX_OPEN_PER_LESSON]))
                        ->body(__('Warte auf die Antworten deiner Lehrkraft, bevor du weitere Fragen stellst.'))
                        ->send();

                    return;
                }

                $question = StudentQuestion::query()->create([
                    'student_id' => $student->getKey(),
                    'lesson_id' => $lesson->getKey(),
                    'explanation_session_id' => $session?->getKey(),
                    'question' => trim($data['question']),
                ]);

                // The question is stored: a failing bell must not turn it into an error for the student.
                try {
                    Notify::questionAsked($question);
                } catch (Throwable $exception) {
                    report($exception);
                }

                Notification::make()
                    ->success()
                    ->title(__('Deine Frage ist bei deiner Lehrkraft'))
                    ->body(__('Die Antwort findest du unter „Meine Rückfragen“.'))
                    ->actions([Action::make('open')->label(__('Zu meinen Rückfragen'))->url(MyQuestions::getUrl())])
                    ->send();
            });
    }
}
