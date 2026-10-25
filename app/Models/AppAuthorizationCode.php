<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The one-time code a teacher or school administration hands an app by confirming on /app/verbinden (OAuth 2.0
 * authorization code with PKCE, RFC 7636). The app trades it for a token (POST /api/v1/token) within five minutes,
 * once, and only with the verifier of its code_challenge. Only the SHA-256 hash of the code is stored. Used and
 * expired codes are pruned daily (routes/console.php) and the codes of a deleted account go with it (foreign key).
 */
class AppAuthorizationCode extends Model
{
    use MassPrunable;

    public const int LIFETIME_MINUTES = 5;

    /**
     * Creates a code for the user and returns it in plain text; it exists nowhere else.
     */
    public static function issue(User $user, string $clientId, string $redirectUri, string $codeChallenge): string
    {
        $code = Str::random(64);

        $authorization = new self;
        $authorization->forceFill([
            'code' => hash('sha256', $code),
            'user_id' => $user->getKey(),
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
        ])->save();

        return $code;
    }

    /**
     * The account the code was issued to, if the code is unused, not expired and was issued to this client for this
     * redirect URI and the verifier matches its challenge; otherwise null. Any attempt spends the code: it is claimed
     * in one statement before anything else is compared, so of two requests with the same code at most one gets it.
     */
    public static function redeem(string $code, string $clientId, string $redirectUri, string $codeVerifier): ?User
    {
        $authorization = self::query()->where('code', hash('sha256', $code))->first();

        if ($authorization === null) {
            return null;
        }

        $claimed = self::query()
            ->whereKey($authorization->getKey())
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now()]);

        $isValid = $claimed === 1
            && hash_equals($authorization->client_id, $clientId)
            && hash_equals($authorization->redirect_uri, $redirectUri)
            && hash_equals($authorization->code_challenge, self::challengeFor($codeVerifier));

        return $isValid ? $authorization->user : null;
    }

    /**
     * The S256 code_challenge of a PKCE verifier: BASE64URL(SHA-256(verifier)) without padding (RFC 7636, 4.2).
     */
    public static function challengeFor(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Used and expired codes are of no use to anybody (`php artisan model:prune`).
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->whereNotNull('used_at')->orWhere('expires_at', '<=', now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
