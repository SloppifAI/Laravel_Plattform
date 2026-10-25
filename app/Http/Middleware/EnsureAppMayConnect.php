<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Connected apps (API tokens) are for teachers and school administrations of a school that may use the platform. Checked
 * on the consent page (/app/verbinden), when a token is issued and again on every API request, so a token stops working
 * as soon as the school is blocked, an agreement becomes due or the account changes role, without being revoked.
 */
class EnsureAppMayConnect
{
    public function handle(Request $request, Closure $next): Response
    {
        $refusal = self::refusalFor($request->user());

        abort_if($refusal !== null, 403, $refusal);

        return $next($request);
    }

    /**
     * Why the account may not use an app right now, or null when it may.
     */
    public static function refusalFor(User $user): ?string
    {
        return match (true) {
            ! $user->isTeacher() && ! $user->isAdmin() => __('Nur Lehrkräfte und Schuladministrationen können eine App mit Tuto verbinden.'),
            (bool) $user->school?->isBlocked() => Login::blockedSchoolMessage(),
            (bool) $user->school?->mustAcceptDpa() => $user->isAdmin()
                ? __('Deine Schule hat den Auftragsverarbeitungsvertrag noch nicht angenommen. Bis dahin kannst du nur die Seite „Meine Schule“ nutzen.')
                : __('Deine Schule hat den Auftragsverarbeitungsvertrag noch nicht angenommen. Bis dahin ist Tuto für deine Schule gesperrt. Bitte wende dich an die Schuladministration deiner Schule.'),
            default => null,
        };
    }
}
