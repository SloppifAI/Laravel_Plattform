<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAppMayConnect;
use App\Http\Requests\Api\V1\StoreTokenRequest;
use App\Models\AppAuthorizationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The token endpoint of the app authorisation (AppAuthorizationController): the app trades the one-time code and the
 * PKCE verifier for a Sanctum token. There is no password login: the app never sees the password. The person sees the
 * token in the profile ("Verbundene Apps") and can revoke it there.
 */
class TokenController extends Controller
{
    /**
     * Everything a connected app may do, and nothing more: see its lessons and classes, create a lesson and set a
     * lesson's summary. Each API route asks for its ability (routes/api.php).
     *
     * @var list<string>
     */
    public const array ABILITIES = ['lessons:read', 'lessons:create', 'lessons:summary'];

    public function store(StoreTokenRequest $request): JsonResponse
    {
        if (! array_key_exists($request->validated('client_id'), config('lernplattform.apps.clients'))) {
            return StoreTokenRequest::error('invalid_client', __('Diese App ist nicht bei Tuto registriert.'));
        }

        $user = AppAuthorizationCode::redeem(
            $request->validated('code'),
            $request->validated('client_id'),
            $request->validated('redirect_uri'),
            $request->validated('code_verifier'),
        );

        if ($user === null) {
            return StoreTokenRequest::error('invalid_grant', __('Der Code ist ungültig, abgelaufen oder schon benutzt. Bitte verbinde die App noch einmal.'));
        }

        // The account may have changed in the minutes since the consent (role, blocked school, agreement).
        $refusal = EnsureAppMayConnect::refusalFor($user);

        if ($refusal !== null) {
            return StoreTokenRequest::error('invalid_grant', $refusal);
        }

        $minutes = config('sanctum.expiration');
        $token = $user->createToken($request->validated('device_name'), self::ABILITIES, $minutes ? now()->addMinutes($minutes) : null);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => $token->accessToken->abilities,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => ['name' => $user->name, 'role' => $user->role->value],
        ], 201, ['Cache-Control' => 'no-store']);
    }

    /**
     * Revokes the token the request came with. Works even when the account may no longer use the app.
     */
    public function destroy(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
