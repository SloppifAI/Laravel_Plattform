<?php

namespace Tests\Feature\Api;

use App\Filament\Pages\Auth\Login;
use App\Http\Controllers\Api\V1\TokenController;
use App\Models\AppAuthorizationCode;
use App\Models\School;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The token endpoint: an app trades a one-time code from /app/verbinden and its PKCE verifier for a token, once, within
 * five minutes, for the client and redirect URI the code was issued to. There is no password login at all.
 */
class TokenTest extends TestCase
{
    use RefreshDatabase;

    private const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lernplattform.apps.clients' => [
            'tuto-app' => ['name' => 'Tuto-App', 'redirect_uris' => ['tutoapp://callback', 'tutoapp://zweiter-weg']],
            'andere-app' => ['name' => 'Andere App', 'redirect_uris' => ['andereapp://callback']],
        ]]);

        $this->teacher = User::factory()->teacher()->create(['name' => 'Frau Schulze']);
    }

    private function code(?User $user = null, string $clientId = 'tuto-app', string $redirectUri = 'tutoapp://callback'): string
    {
        return AppAuthorizationCode::issue($user ?? $this->teacher, $clientId, $redirectUri, AppAuthorizationCode::challengeFor(self::VERIFIER));
    }

    /**
     * @param  array<string, string|null>  $overrides
     */
    private function exchange(string $code, array $overrides = []): TestResponse
    {
        return $this->postJson(route('api.token.store'), array_filter([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => 'tuto-app',
            'redirect_uri' => 'tutoapp://callback',
            'code_verifier' => self::VERIFIER,
            'device_name' => 'Tablet im Lehrerzimmer',
            ...$overrides,
        ], fn (?string $value): bool => $value !== null));
    }

    private function assertRefused(TestResponse $response, string $error): void
    {
        $response->assertBadRequest()->assertJsonPath('error', $error)->assertJsonStructure(['error', 'message', 'error_description']);
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_a_code_buys_a_token_with_exactly_the_apps_abilities(): void
    {
        $response = $this->exchange($this->code())
            ->assertCreated()
            ->assertJsonPath('abilities', ['lessons:read', 'lessons:create', 'lessons:summary'])
            ->assertJsonPath('user', ['name' => 'Frau Schulze', 'role' => 'teacher'])
            ->assertJsonMissingPath('user.email');

        $token = PersonalAccessToken::findToken($response->json('token'));
        $this->assertSame(TokenController::ABILITIES, $token->abilities);
        $this->assertSame('Tablet im Lehrerzimmer', $token->name);
        $this->assertSame($token->expires_at->toIso8601String(), $response->json('expires_at'));
        $this->assertNotNull(AppAuthorizationCode::query()->sole()->used_at);
    }

    public function test_an_administration_gets_a_token_too(): void
    {
        $admin = User::factory()->admin()->create();

        $this->exchange($this->code($admin))->assertCreated()->assertJsonPath('user.role', 'admin');
    }

    public function test_a_wrong_verifier_spends_the_code(): void
    {
        $code = $this->code();

        $this->assertRefused($this->exchange($code, ['code_verifier' => str_repeat('a', 43)]), 'invalid_grant');
        $this->assertRefused($this->exchange($code), 'invalid_grant');
    }

    public function test_an_expired_code_is_refused(): void
    {
        $code = $this->code();

        $this->travel(AppAuthorizationCode::LIFETIME_MINUTES + 1)->minutes();

        $this->assertRefused($this->exchange($code), 'invalid_grant');
    }

    public function test_a_code_works_only_once(): void
    {
        $code = $this->code();

        $this->exchange($code)->assertCreated();
        $this->exchange($code)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');

        $this->assertSame(1, PersonalAccessToken::query()->count());
    }

    public function test_an_unknown_code_is_refused(): void
    {
        $this->code();

        $this->assertRefused($this->exchange(str_repeat('x', 64)), 'invalid_grant');
    }

    public function test_a_code_of_another_client_is_refused(): void
    {
        $code = $this->code();

        $this->assertRefused($this->exchange($code, ['client_id' => 'andere-app', 'redirect_uri' => 'andereapp://callback']), 'invalid_grant');
    }

    public function test_a_code_for_another_redirect_uri_is_refused(): void
    {
        $code = $this->code();

        $this->assertRefused($this->exchange($code, ['redirect_uri' => 'tutoapp://zweiter-weg']), 'invalid_grant');
    }

    public function test_an_unknown_client_is_refused(): void
    {
        $this->assertRefused($this->exchange($this->code(), ['client_id' => 'fremde-app']), 'invalid_client');
    }

    /**
     * @return array<string, array{array<string, string|null>}>
     */
    public static function incompleteRequests(): array
    {
        return [
            'no grant type' => [['grant_type' => null]],
            'password grant' => [['grant_type' => 'password']],
            'no client' => [['client_id' => null]],
            'no redirect URI' => [['redirect_uri' => null]],
            'no verifier' => [['code_verifier' => null]],
            'verifier too short' => [['code_verifier' => 'kurz']],
            'no device name' => [['device_name' => null]],
            'device name too long' => [['device_name' => str_repeat('x', 101)]],
        ];
    }

    /**
     * @param  array<string, string|null>  $overrides
     */
    #[DataProvider('incompleteRequests')]
    public function test_an_incomplete_request_is_refused(array $overrides): void
    {
        $code = $this->code();

        $this->assertRefused($this->exchange($code, $overrides), 'invalid_request');
        $this->assertNull(AppAuthorizationCode::query()->sole()->used_at);
    }

    public function test_there_is_no_password_login(): void
    {
        $this->assertRefused($this->postJson(route('api.token.store'), [
            'username' => $this->teacher->username,
            'password' => 'password',
            'device_name' => 'Tablet',
        ]), 'invalid_request');

        $this->postJson(route('api.token.store'), [
            'grant_type' => 'password',
            'username' => $this->teacher->username,
            'password' => 'password',
            'client_id' => 'tuto-app',
        ])->assertBadRequest();

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_the_default_user_route_is_gone(): void
    {
        $token = $this->teacher->createToken('Tablet', TokenController::ABILITIES)->plainTextToken;

        $this->withToken($token)->getJson('/api/user')->assertNotFound();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function accountsThatMayNoLongerConnect(): array
    {
        return ['now a student' => ['student'], 'blocked school' => ['blocked'], 'pending agreement' => ['agreement']];
    }

    #[DataProvider('accountsThatMayNoLongerConnect')]
    public function test_the_account_is_checked_again_at_the_exchange(string $case): void
    {
        $code = $this->code();

        match ($case) {
            'student' => $this->teacher->forceFill(['role' => Role::Student])->save(),
            'blocked' => $this->teacher->school->forceFill(['blocked_at' => now()])->save(),
            'agreement' => config(['lernplattform.dpa.final' => true]),
        };

        $this->assertRefused($this->exchange($code), 'invalid_grant');
    }

    public function test_an_operator_code_is_refused_at_the_exchange(): void
    {
        $this->assertRefused($this->exchange($this->code(User::factory()->operator()->create())), 'invalid_grant');
    }

    public function test_a_blocked_school_is_named_as_the_reason(): void
    {
        $user = User::factory()->teacher()->for(School::factory()->blocked())->create();

        $this->exchange($this->code($user))->assertJsonPath('message', Login::blockedSchoolMessage());
    }

    public function test_the_token_endpoint_is_rate_limited_per_ip(): void
    {
        foreach (range(1, 10) as $attempt) {
            $this->exchange(str_repeat('x', 64))->assertBadRequest();
        }

        $this->exchange($this->code())->assertTooManyRequests();
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_an_app_revokes_its_own_token(): void
    {
        $other = $this->teacher->createToken('Andere App', TokenController::ABILITIES);
        $token = $this->teacher->createToken('Tablet', TokenController::ABILITIES)->plainTextToken;

        $this->withToken($token)->deleteJson(route('api.token.destroy'))->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson(route('api.lessons.index'))->assertUnauthorized();
        $this->assertModelExists($other->accessToken);
    }

    public function test_an_expired_token_is_refused(): void
    {
        $token = $this->exchange($this->code())->json('token');

        $this->travel(181)->days();

        $this->withToken($token)->getJson(route('api.lessons.index'))->assertUnauthorized();
    }

    public function test_a_session_login_does_not_reach_the_api(): void
    {
        $this->actingAs($this->teacher)->getJson(route('api.lessons.index'))->assertUnauthorized();
    }

    public function test_deleting_an_account_deletes_its_tokens_and_codes(): void
    {
        $this->teacher->createToken('Tablet', TokenController::ABILITIES);
        $this->code();
        $other = User::factory()->teacher()->create();
        $kept = $other->createToken('Tablet', TokenController::ABILITIES);
        $this->code($other);

        $this->teacher->delete();

        $this->assertSame([$kept->accessToken->getKey()], PersonalAccessToken::query()->pluck('id')->all());
        $this->assertSame([$other->id], AppAuthorizationCode::query()->pluck('user_id')->all());
    }

    public function test_a_new_password_ends_the_connected_apps_of_that_account_only(): void
    {
        $token = $this->exchange($this->code())->json('token');
        $this->code();
        $other = User::factory()->teacher()->create();
        $kept = $other->createToken('Tablet', TokenController::ABILITIES);
        $this->code($other);

        $this->teacher->update(['name' => 'Frau Schulze-Meier']);
        $this->assertSame(1, $this->teacher->tokens()->count());
        $this->assertSame(1, AppAuthorizationCode::query()->whereBelongsTo($this->teacher)->where('used_at', null)->count());

        $this->teacher->update(['password' => 'ein-ganz-neues-passwort']);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson(route('api.lessons.index'))->assertUnauthorized();
        $this->assertSame(0, $this->teacher->tokens()->count());
        $this->assertSame(0, AppAuthorizationCode::query()->whereBelongsTo($this->teacher)->count());
        $this->assertSame([$kept->accessToken->getKey()], PersonalAccessToken::query()->pluck('id')->all());
        $this->assertSame(1, AppAuthorizationCode::query()->whereBelongsTo($other)->count());
    }

    public function test_spent_and_expired_codes_are_pruned(): void
    {
        $this->exchange($this->code())->assertCreated();
        $this->code();
        $this->travel(AppAuthorizationCode::LIFETIME_MINUTES + 1)->minutes();
        $fresh = $this->code();

        $this->artisan('model:prune', ['--model' => [AppAuthorizationCode::class]])->assertSuccessful();

        $this->assertSame([hash('sha256', $fresh)], AppAuthorizationCode::query()->pluck('code')->all());
    }

    public function test_expired_tokens_and_codes_are_pruned_daily(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('sanctum:prune-expired')
            ->expectsOutputToContain('model:prune');
    }
}
