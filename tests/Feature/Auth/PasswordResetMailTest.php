<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordResetMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();
    }

    /**
     * Runs the real "Passwort vergessen?" flow and returns the mail that went out (array mailer).
     *
     * @return array{subject: string, body: string}
     */
    private function requestResetMailFor(string $locale): array
    {
        $user = User::factory()->create(['username' => 'maxi.muster', 'email' => 'maxi@example.test', 'locale' => $locale]);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['login' => $user->username])
            ->call('request');

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        $email = $messages[0]->getOriginalMessage();

        return ['subject' => $email->getSubject(), 'body' => $email->getHtmlBody()];
    }

    public function test_the_reset_mail_of_a_german_user_is_german(): void
    {
        $mail = $this->requestResetMailFor('de');

        $this->assertSame('Passwort zurücksetzen', $mail['subject']);

        foreach (['Hallo!', 'Viele Grüße', 'Alle Rechte vorbehalten.', '60 Minuten', 'musst du nichts tun'] as $germanText) {
            $this->assertStringContainsString($germanText, $mail['body']);
        }

        foreach (['Hello!', 'Regards', 'All rights reserved', 'You are receiving this email', 'trouble clicking'] as $englishText) {
            $this->assertStringNotContainsString($englishText, $mail['body']);
        }
    }

    public function test_the_reset_mail_of_an_english_user_stays_english(): void
    {
        $mail = $this->requestResetMailFor('en');

        $this->assertSame('Reset your password', $mail['subject']);
        $this->assertStringContainsString('Hello!', $mail['body']);
    }
}
