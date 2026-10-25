<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureAppMayConnect;
use App\Models\AppAuthorizationCode;
use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Connecting a native app (OAuth 2.0 authorization code with PKCE, RFC 7636 and RFC 8252, on top of Sanctum): the app
 * opens /app/verbinden in the browser, the person signs in on the normal login page and confirms on the consent page
 * (Filament\Pages\Auth\AuthorizeApp), and this controller sends the browser back to the app with a one-time code or
 * with `access_denied`. The app trades the code for a token at POST /api/v1/token (Api\V1\TokenController).
 *
 * A request that is not from a registered app or not for one of its registered redirect URIs is never redirected
 * anywhere: the address could be anybody's (RFC 6749, 4.1.2.1).
 */
class AppAuthorizationController extends Controller
{
    /**
     * The parameters of an authorisation request once they are known to be good, or null.
     *
     * @param  array<string, mixed>  $input
     * @return array{client_id: string, redirect_uri: string, code_challenge: string, code_challenge_method: string, state: string}|null
     */
    public static function validRequest(array $input): ?array
    {
        $clients = config('lernplattform.apps.clients');
        $client = is_string($input['client_id'] ?? null) ? ($clients[$input['client_id']] ?? null) : null;

        $validator = Validator::make($input, [
            'client_id' => ['required', 'string', Rule::in(array_keys($clients))],
            'redirect_uri' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($client): void {
                if (! in_array($value, $client['redirect_uris'] ?? [], true)) {
                    $fail('The redirect URI is not registered.');
                }
            }],
            // S256 only: a "plain" challenge would be the verifier itself.
            'code_challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'code_challenge_method' => ['required', 'string', Rule::in(['S256'])],
            'state' => ['required', 'string', 'max:500'],
        ]);

        return $validator->fails() ? null : $validator->validated();
    }

    /**
     * The name the consent page and the profile show for the app.
     */
    public static function clientName(string $clientId): string
    {
        return config("lernplattform.apps.clients.{$clientId}.name", $clientId);
    }

    /**
     * "Erlauben" sends the app a one-time code, "Abbrechen" tells it the person declined. Both go to the registered
     * redirect URI, which is usually the app's own scheme (tutoapp://...), so it is a redirect away from the platform.
     */
    public function store(Request $request): RedirectResponse
    {
        $authorization = self::validRequest($request->all()) ?? abort(400, __('Diese Anfrage zum Verbinden einer App ist ungültig. Bitte starte das Verbinden in der App noch einmal.'));
        $decision = $request->input('decision');

        abort_unless(in_array($decision, ['allow', 'deny'], true), 400);

        /** @var User $user */
        $user = $request->user();
        $refusal = EnsureAppMayConnect::refusalFor($user);

        abort_if($refusal !== null, 403, $refusal);

        $answer = $decision === 'allow'
            ? ['code' => AppAuthorizationCode::issue($user, $authorization['client_id'], $authorization['redirect_uri'], $authorization['code_challenge'])]
            : ['error' => 'access_denied'];

        $redirectUri = $authorization['redirect_uri'];

        return redirect()->away($redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').http_build_query([...$answer, 'state' => $authorization['state']]));
    }
}
