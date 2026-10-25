<?php

namespace App\Filament\Pages\Auth;

use App\Http\Controllers\AppAuthorizationController;
use App\Http\Middleware\EnsureAppMayConnect;
use App\Models\User;
use Filament\Pages\SimplePage;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The consent page of /app/verbinden (route app.authorize): a signed-in teacher or school administration sees which
 * app asks, what it may and may not do, and answers with "Erlauben" or "Abbrechen" (AppAuthorizationController::store).
 * An invalid request gets an error and a refused account (students, the operator, a blocked school, a school with a
 * pending agreement) a short explanation, both without any redirect. Guests reach it after the normal login.
 *
 * Registered in routes/web.php, not in the panel: it needs Laravel's `auth` with the intended URL and no panel checks
 * that would answer a blocked school with a bare 403. No topbar, so the page makes no Livewire requests of its own;
 * the state is protected for the same reason, it only lives for the first render.
 */
class AuthorizeApp extends SimplePage
{
    protected string $view = 'filament.pages.auth.authorize-app';

    protected bool $hasTopbar = false;

    /**
     * @var array{client_id: string, redirect_uri: string, code_challenge: string, code_challenge_method: string, state: string}|null
     */
    protected ?array $authorization = null;

    /**
     * Why the signed-in account may not connect an app, or null.
     */
    protected ?string $refusal = null;

    public function mount(): void
    {
        $this->authorization = AppAuthorizationController::validRequest(request()->query());
        $this->refusal = $this->authorization === null ? null : EnsureAppMayConnect::refusalFor($this->signedInUser());
    }

    public function getTitle(): string|Htmlable
    {
        return $this->mayConsent()
            ? __(':app mit Tuto verbinden', ['app' => AppAuthorizationController::clientName($this->authorization['client_id'])])
            : __('App verbinden nicht möglich');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->mayConsent() ? __('Angemeldet als :name', ['name' => $this->signedInUser()->name]) : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'authorization' => $this->mayConsent() ? $this->authorization : null,
            'problem' => $this->authorization === null
                ? __('Diese Anfrage zum Verbinden einer App ist ungültig. Bitte starte das Verbinden in der App noch einmal.')
                : $this->refusal,
            'isAdmin' => $this->signedInUser()->isAdmin(),
        ];
    }

    protected function mayConsent(): bool
    {
        return $this->authorization !== null && $this->refusal === null;
    }

    protected function signedInUser(): User
    {
        return auth()->user();
    }
}
