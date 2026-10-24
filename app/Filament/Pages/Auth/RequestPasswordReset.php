<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Password;
use SensitiveParameter;
use Throwable;

/**
 * "Passwort vergessen?" by username or e-mail.
 *
 * Many pupils have no e-mail address, and the answer must never reveal whether an account exists,
 * so every request ends in the same neutral message. Looking the account up and mailing the link happens
 * after the response, so the response time does not give the account away either.
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    /**
     * Filament allows 2 requests per minute and IP, but a whole class may ask at once from one school IP.
     */
    private const int MAX_ATTEMPTS_PER_MINUTE = 30;

    public function request(): void
    {
        try {
            $this->rateLimit(self::MAX_ATTEMPTS_PER_MINUTE);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $login = trim($this->form->getState()['login']);

        defer(fn () => $this->sendResetLinkTo($login));

        Notification::make()
            ->title('Schau in dein Postfach')
            ->body('Wenn zu diesem Konto eine E-Mail-Adresse gespeichert ist, haben wir dir einen Link zum Zurücksetzen geschickt.')
            ->success()
            ->send();

        $this->form->fill();
    }

    /**
     * The mail goes out right away instead of through the queue, so a missing queue worker cannot swallow it.
     */
    private function sendResetLinkTo(string $login): void
    {
        $user = User::query()->where('username', mb_strtolower($login))->orWhere('email', $login)->first();

        if (blank($user?->email)) {
            return;
        }

        try {
            Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
                ['email' => $user->email],
                function (User $user, #[SensitiveParameter] string $token): void {
                    $notification = app(ResetPasswordNotification::class, ['token' => $token]);
                    $notification->url = Filament::getResetPasswordUrl($token, $user);

                    $user->notifyNow($notification);
                },
            );
        } catch (Throwable $exception) {
            // A failing mail server must not tell an outsider that this account exists.
            report($exception);
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getLoginFormComponent(),
                Text::make('Kein E-Mail-Konto? Die Schulverwaltung kann dein Passwort zurücksetzen.')->color('gray'),
            ]);
    }

    protected function getLoginFormComponent(): Component
    {
        return TextInput::make('login')
            ->label('Benutzername oder E-Mail')
            ->required()
            ->maxLength(255)
            ->autocomplete('username')
            ->autofocus();
    }

    public function loginAction(): Action
    {
        return parent::loginAction()->label('Zurück zur Anmeldung');
    }

    protected function getRequestFormAction(): Action
    {
        return parent::getRequestFormAction()->label('Link zum Zurücksetzen senden');
    }

    public function getTitle(): string|Htmlable
    {
        return 'Passwort vergessen';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Passwort vergessen?';
    }
}
