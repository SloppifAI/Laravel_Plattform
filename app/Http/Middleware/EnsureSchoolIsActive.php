<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\MySchool;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * Filament's Authenticate, except that a user whose school got blocked meanwhile is signed out and sent
 * to the login page, where the reason is shown, instead of running into a bare "403" on every page.
 *
 * It also holds back a school that never accepted the released data processing agreement (Art. 28 DSGVO): its
 * administration can only open "Meine Schule", where it accepts, and everybody else gets a 403 that names the reason.
 * Signing out stays possible. An outdated acceptance does not hold anybody back, the accepted version stays in force.
 *
 * The panel registers it as persistent, so it also covers the Livewire requests of a page that is still open.
 */
class EnsureSchoolIsActive extends Authenticate
{
    /**
     * Session flash read by the login page.
     */
    public const string BLOCKED_SCHOOL_FLASH_KEY = 'schoolIsBlocked';

    /**
     * @param  array<string>  $guards
     */
    protected function authenticate($request, array $guards): void
    {
        $user = Filament::auth()->user();

        if ($user !== null && ! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
            Filament::auth()->logout();
            session()->invalidate();
            session()->regenerateToken();
            session()->flash(self::BLOCKED_SCHOOL_FLASH_KEY, true);

            throw new HttpResponseException(redirect()->to(Filament::getLoginUrl()));
        }

        parent::authenticate($request, $guards);

        if ($user?->school?->mustAcceptDpa() && ! $this->mayPassUntilTheAgreementIsAccepted($request, $user)) {
            $this->refuseUntilTheAgreementIsAccepted($request, $user);
        }
    }

    /**
     * shortcut: a Livewire request of the school administration passes whichever page sent it, only the page loads are
     * held back, so just a page opened before the school was held back can still act. Upgrade by checking the component
     * name in the request payload if that ever matters.
     */
    private function mayPassUntilTheAgreementIsAccepted(Request $request, User $user): bool
    {
        $panel = Filament::getCurrentOrDefaultPanel();

        return $request->routeIs($panel->generateRouteName('auth.logout'))
            || ($user->isAdmin() && ($request->routeIs(MySchool::getRouteName($panel)) || $request->hasHeader('X-Livewire')));
    }

    private function refuseUntilTheAgreementIsAccepted(Request $request, User $user): never
    {
        if ($user->isAdmin() && $request->isMethod('GET')) {
            throw new HttpResponseException(redirect()->to(MySchool::getUrl()));
        }

        abort(403, $user->isAdmin()
            ? __('Deine Schule hat den Auftragsverarbeitungsvertrag noch nicht angenommen. Bis dahin kannst du nur die Seite „Meine Schule“ nutzen.')
            : __('Deine Schule hat den Auftragsverarbeitungsvertrag noch nicht angenommen. Bis dahin ist Tuto für deine Schule gesperrt. Bitte wende dich an die Schuladministration deiner Schule.'));
    }
}
