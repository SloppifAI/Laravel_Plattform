<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class PasswordResetRequestTest extends TestCase
{
    use RefreshDatabase;

    private const string NEUTRAL_TITLE = 'Schau in dein Postfach';

    /**
     * The account lookup and the mail run after the response; the tests run them right away.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();
    }

    public function test_a_reset_link_is_sent_to_the_email_of_the_user_found_by_username(): void
    {
        Notification::fake();
        $user = User::factory()->create(['username' => 'maxi.muster', 'email' => 'maxi@example.test']);
        $bystander = User::factory()->create();

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['login' => ' Maxi.Muster '])
            ->call('request')
            ->assertNotified(self::NEUTRAL_TITLE);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
        Notification::assertNotSentTo($bystander, ResetPasswordNotification::class);
    }

    public function test_a_reset_link_is_sent_when_the_user_enters_their_email_address(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'maxi@example.test']);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['login' => 'maxi@example.test'])
            ->call('request')
            ->assertNotified(self::NEUTRAL_TITLE);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_the_link_from_the_mail_resets_the_password(): void
    {
        Notification::fake();
        $user = User::factory()->create(['username' => 'maxi.muster', 'email' => 'maxi@example.test', 'password' => 'altes-passwort-1']);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['login' => 'maxi.muster'])
            ->call('request');

        $resetUrl = null;
        $token = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use (&$resetUrl, &$token): bool {
            $resetUrl = $notification->url;
            $token = $notification->token;

            return true;
        });

        $this->get($resetUrl)->assertOk();

        Livewire::test(ResetPassword::class, ['email' => 'maxi@example.test', 'token' => $token])
            ->fillForm(['password' => 'neues-passwort-1', 'passwordConfirmation' => 'neues-passwort-1'])
            ->call('resetPassword')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('neues-passwort-1', $user->refresh()->password));
    }

    public function test_an_unknown_username_gets_the_same_message_and_no_mail(): void
    {
        Notification::fake();

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['login' => 'gibt-es-nicht'])
            ->call('request')
            ->assertHasNoFormErrors()
            ->assertNotified(self::NEUTRAL_TITLE);

        Notification::assertNothingSent();
    }

    public function test_a_user_without_an_email_gets_the_same_message_and_no_mail(): void
    {
        Notification::fake();
        User::factory()->student()->create(['username' => 'ohne.mail', 'email' => null]);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['login' => 'ohne.mail'])
            ->call('request')
            ->assertHasNoFormErrors()
            ->assertNotified(self::NEUTRAL_TITLE);

        Notification::assertNothingSent();
    }

    public function test_asking_twice_for_the_same_user_does_not_reveal_the_broker_throttle(): void
    {
        Notification::fake();
        $user = User::factory()->create(['username' => 'maxi.muster']);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['login' => 'maxi.muster'])
            ->call('request')
            ->fillForm(['login' => 'maxi.muster'])
            ->call('request')
            ->assertNotified(self::NEUTRAL_TITLE);

        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
    }

    public function test_a_whole_class_can_ask_for_a_reset_in_the_same_minute(): void
    {
        Notification::fake();
        $users = User::factory()->count(10)->create();

        foreach ($users as $user) {
            Livewire::test(RequestPasswordReset::class)
                ->fillForm(['login' => $user->username])
                ->call('request');
        }

        Notification::assertSentTimes(ResetPasswordNotification::class, 10);
    }

    public function test_requests_are_still_rate_limited(): void
    {
        Notification::fake();
        User::factory()->create(['username' => 'maxi.muster']);

        $page = Livewire::test(RequestPasswordReset::class);

        foreach (range(1, 30) as $ignored) {
            $page->fillForm(['login' => 'gibt-es-nicht'])->call('request');
        }

        $page->fillForm(['login' => 'maxi.muster'])->call('request');

        Notification::assertNothingSent();
    }

    public function test_the_lookup_and_the_mail_wait_until_after_the_response(): void
    {
        $this->withDefer();
        Notification::fake();
        User::factory()->create(['username' => 'maxi.muster']);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['login' => 'maxi.muster'])
            ->call('request')
            ->assertNotified(self::NEUTRAL_TITLE);

        Notification::assertNothingSent();
    }

    public function test_the_mail_is_sent_directly_and_does_not_wait_for_a_queue_worker(): void
    {
        Queue::fake();
        User::factory()->create(['username' => 'maxi.muster', 'email' => 'maxi@example.test']);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['login' => 'maxi.muster'])
            ->call('request');

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'maxi@example.test']);
    }

    public function test_a_failing_mail_server_does_not_reveal_that_the_account_exists(): void
    {
        $this->withoutExceptionHandling();
        $this->app->bind(ResetPasswordNotification::class, fn () => throw new RuntimeException('SMTP down'));
        User::factory()->create(['username' => 'maxi.muster']);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['login' => 'maxi.muster'])
            ->call('request')
            ->assertNotified(self::NEUTRAL_TITLE);
    }

    public function test_the_page_always_explains_what_pupils_without_an_email_can_do(): void
    {
        $this->get(route('filament.lernplattform.auth.password-reset.request'))
            ->assertOk()
            ->assertSee('Benutzername oder E-Mail')
            ->assertSee('Kein E-Mail-Konto? Die Schulverwaltung kann dein Passwort zurücksetzen.');
    }
}
