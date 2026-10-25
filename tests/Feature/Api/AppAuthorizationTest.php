<?php

namespace Tests\Feature\Api;

use App\Filament\Pages\Auth\Login;
use App\Http\Controllers\Api\V1\TokenController;
use App\Models\AppAuthorizationCode;
use App\Models\School;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Connecting an app in the browser (/app/verbinden): OAuth 2.0 authorization code with PKCE. The person signs in on
 * the normal login page, confirms, and the app gets a one-time code on its registered redirect URI. A request that is
 * not from a registered app for one of its redirect URIs is never redirected anywhere.
 */
class AppAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    protected function setUp(): void
    {
        parent::setUp();

        config(['lernplattform.apps.clients' => ['tuto-app' => ['name' => 'Tuto-App', 'redirect_uris' => ['tutoapp://callback']]]]);
    }

    /**
     * @param  array<string, string|null>  $overrides
     * @return array<string, string>
     */
    private function authorizationRequest(array $overrides = []): array
    {
        return array_filter([
            'client_id' => 'tuto-app',
            'redirect_uri' => 'tutoapp://callback',
            'code_challenge' => AppAuthorizationCode::challengeFor(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'state' => 'zustand-123',
            ...$overrides,
        ], fn (?string $value): bool => $value !== null);
    }

    /**
     * @return array<string, string>
     */
    private function answer(TestResponse $response): array
    {
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('tutoapp://callback?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_the_whole_flow_from_the_login_to_a_working_token(): void
    {
        $teacher = User::factory()->teacher()->create(['name' => 'Frau Schulze']);
        // The intended URL comes back with its parameters sorted, as Laravel's fullUrl() has them.
        $request = $this->authorizationRequest();
        ksort($request);
        $url = route('app.authorize', $request);

        $this->get($url)->assertRedirect(Filament::getLoginUrl());

        Livewire::test(Login::class)
            ->fillForm(['username' => $teacher->username, 'password' => 'password'])
            ->call('authenticate')
            ->assertRedirect($url);

        $this->get($url)
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertSee(__(':app mit Tuto verbinden', ['app' => 'Tuto-App']))
            ->assertSee(__('Angemeldet als :name', ['name' => 'Frau Schulze']))
            ->assertSee(__('deine Stunden und Klassen sehen'))
            ->assertSee(__('Daten deiner Schüler:innen sehen'))
            ->assertSee('name="decision" value="allow"', false)
            ->assertSee(__('Erlauben'))
            ->assertSee(__('Abbrechen'));

        $answer = $this->answer($this->post(route('app.authorize.store'), [...$this->authorizationRequest(), 'decision' => 'allow'])->assertRedirect());

        $this->assertSame('zustand-123', $answer['state']);
        $this->assertSame(64, strlen($answer['code']));
        $this->assertDatabaseMissing('app_authorization_codes', ['code' => $answer['code']]);
        $this->assertDatabaseHas('app_authorization_codes', ['code' => hash('sha256', $answer['code']), 'user_id' => $teacher->id, 'used_at' => null]);

        $token = $this->postJson(route('api.token.store'), [
            'grant_type' => 'authorization_code',
            'code' => $answer['code'],
            'client_id' => 'tuto-app',
            'redirect_uri' => 'tutoapp://callback',
            'code_verifier' => self::VERIFIER,
            'device_name' => 'iPhone von Frau Schulze',
        ])
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJsonStructure(['token', 'token_type', 'abilities', 'expires_at', 'user' => ['name', 'role']])
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('abilities', TokenController::ABILITIES)
            ->assertJsonPath('user', ['name' => 'Frau Schulze', 'role' => 'teacher'])
            ->json('token');

        $accessToken = PersonalAccessToken::findToken($token);
        $this->assertTrue($accessToken->tokenable->is($teacher));
        $this->assertSame('iPhone von Frau Schulze', $accessToken->name);
        $this->assertTrue($accessToken->expires_at->isSameDay(now()->addDays(180)));

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson(route('api.lessons.index'))->assertOk();
    }

    public function test_an_administration_is_told_it_sees_the_lessons_of_its_school(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('app.authorize', $this->authorizationRequest()))
            ->assertOk()
            ->assertSee(__('die Stunden und Klassen deiner Schule sehen'))
            ->assertSee(__('Erlauben'));
    }

    public function test_cancelling_tells_the_app_access_denied_without_a_code(): void
    {
        $this->actingAs(User::factory()->teacher()->create());

        $answer = $this->answer($this->post(route('app.authorize.store'), [...$this->authorizationRequest(), 'decision' => 'deny'])->assertRedirect());

        $this->assertSame(['error' => 'access_denied', 'state' => 'zustand-123'], $answer);
        $this->assertDatabaseCount('app_authorization_codes', 0);
    }

    public function test_a_registered_redirect_uri_with_a_query_keeps_it(): void
    {
        config(['lernplattform.apps.clients.tuto-app.redirect_uris' => ['tutoapp://callback?quelle=tuto']]);
        $this->actingAs(User::factory()->teacher()->create());

        $answer = $this->answer($this->post(route('app.authorize.store'), [...$this->authorizationRequest(['redirect_uri' => 'tutoapp://callback?quelle=tuto']), 'decision' => 'deny']));

        $this->assertSame(['quelle' => 'tuto', 'error' => 'access_denied', 'state' => 'zustand-123'], $answer);
    }

    /**
     * @return array<string, array{array<string, string|null>}>
     */
    public static function invalidRequests(): array
    {
        return [
            'unknown client' => [['client_id' => 'fremde-app']],
            'no client' => [['client_id' => null]],
            'unregistered redirect URI' => [['redirect_uri' => 'https://angreifer.example/callback']],
            'redirect URI that only starts like a registered one' => [['redirect_uri' => 'tutoapp://callback/anders']],
            'no redirect URI' => [['redirect_uri' => null]],
            'no code challenge' => [['code_challenge' => null]],
            'code challenge of the wrong shape' => [['code_challenge' => 'zu-kurz']],
            'plain PKCE' => [['code_challenge_method' => 'plain']],
            'no PKCE method' => [['code_challenge_method' => null]],
            'no state' => [['state' => null]],
        ];
    }

    /**
     * @param  array<string, string|null>  $overrides
     */
    #[DataProvider('invalidRequests')]
    public function test_an_invalid_request_shows_an_error_and_never_redirects(array $overrides): void
    {
        $this->actingAs(User::factory()->teacher()->create());
        $request = $this->authorizationRequest($overrides);

        $this->get(route('app.authorize', $request))
            ->assertOk()
            ->assertHeaderMissing('Location')
            ->assertSee(__('Diese Anfrage zum Verbinden einer App ist ungültig. Bitte starte das Verbinden in der App noch einmal.'))
            ->assertDontSee('name="decision"', false);

        $this->post(route('app.authorize.store'), [...$request, 'decision' => 'allow'])
            ->assertBadRequest()
            ->assertHeaderMissing('Location');

        $this->assertDatabaseCount('app_authorization_codes', 0);
    }

    public function test_a_post_without_a_decision_is_refused(): void
    {
        $this->actingAs(User::factory()->teacher()->create())
            ->post(route('app.authorize.store'), $this->authorizationRequest())
            ->assertBadRequest()
            ->assertHeaderMissing('Location');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedAccounts(): array
    {
        return [
            'student' => ['student'],
            'operator' => ['operator'],
            'blocked school' => ['blocked'],
            'pending agreement' => ['agreement'],
        ];
    }

    private function refusedAccount(string $case): User
    {
        if ($case === 'agreement') {
            config(['lernplattform.dpa.final' => true]);
        }

        return match ($case) {
            'student' => User::factory()->student()->create(),
            'operator' => User::factory()->operator()->create(),
            'blocked' => User::factory()->teacher()->for(School::factory()->blocked())->create(),
            'agreement' => User::factory()->teacher()->create(),
        };
    }

    #[DataProvider('refusedAccounts')]
    public function test_only_teachers_and_administrations_of_an_active_school_may_connect(string $case): void
    {
        $user = $this->refusedAccount($case);

        $this->actingAs($user)->get(route('app.authorize', $this->authorizationRequest()))
            ->assertOk()
            ->assertHeaderMissing('Location')
            ->assertSee(__('App verbinden nicht möglich'))
            ->assertDontSee('name="decision"', false);

        $this->post(route('app.authorize.store'), [...$this->authorizationRequest(), 'decision' => 'allow'])
            ->assertForbidden()
            ->assertHeaderMissing('Location');

        $this->assertDatabaseCount('app_authorization_codes', 0);
    }

    public function test_a_student_is_told_why(): void
    {
        $this->actingAs(User::factory()->student()->create())
            ->get(route('app.authorize', $this->authorizationRequest()))
            ->assertSee(__('Nur Lehrkräfte und Schuladministrationen können eine App mit Tuto verbinden.'));
    }

    public function test_a_guest_cannot_answer_for_anybody(): void
    {
        $this->post(route('app.authorize.store'), [...$this->authorizationRequest(), 'decision' => 'allow'])
            ->assertRedirect(Filament::getLoginUrl());

        $this->assertDatabaseCount('app_authorization_codes', 0);
    }
}
