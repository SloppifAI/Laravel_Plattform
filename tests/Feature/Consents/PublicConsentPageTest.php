<?php

namespace Tests\Feature\Consents;

use App\Models\ConsentForm;
use App\Models\RecordingConsent;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicConsentPageTest extends TestCase
{
    use CreatesConsentFixtures;
    use RefreshDatabase;

    private School $school;

    private ConsentForm $form;

    private SchoolClass $schoolClass;

    private User $student;

    private RecordingConsent $consent;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->school = $this->schoolWithRecordings();
        $this->school->update(['name' => 'Goethe-Gymnasium']);
        $this->form = ConsentForm::factory()->create(['school_id' => $this->school->getKey(), 'body' => "## Was wird aufgezeichnet?\n\nNur der Ton."]);
        $this->schoolClass = SchoolClass::factory()->create(['school_id' => $this->school->getKey(), 'name' => '7b']);
        $this->student = User::factory()->student($this->schoolClass)->create(['name' => 'Anna Müllerschmidt']);
        $this->consent = RecordingConsent::factory()->notAnswered()->create(['student_id' => $this->student->getKey()]);
        $this->token = $this->consent->regenerateToken();
    }

    private function url(?string $token = null): string
    {
        return route('consents.show', $token ?? $this->token);
    }

    private function assertNothingTicked(string $html): void
    {
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*\schecked/', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function declaration(array $overrides = []): array
    {
        return [
            'consent_form' => $this->form->getKey(),
            'purposes' => ['audio', 'transcript'],
            'guardian_name' => 'Erika Müllerschmidt',
            'is_guardian' => '1',
            ...$overrides,
        ];
    }

    public function test_the_page_shows_school_first_name_class_and_the_current_text_without_a_login(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertSee('Goethe-Gymnasium')
            ->assertSee('Anna')
            ->assertSee('7b')
            ->assertSee($this->form->title)
            ->assertSee('Was wird aufgezeichnet?')
            ->assertSee('Nur der Ton.')
            ->assertSee('Ton des Unterrichts (Tuto-Mikro)')
            ->assertSee('Notizen aus der Stunde (aus dem Ton erkannter Text)')
            ->assertSee('Spracherkennung und Zusammenfassung durch den KI-Anbieter')
            ->assertSee('Ich bin erziehungsberechtigt')
            ->assertDontSee('name="_token"', false)
            ->assertDontSee('Einwilligung widerrufen');
    }

    public function test_nothing_is_pre_ticked(): void
    {
        $this->assertNothingTicked($this->get($this->url())->assertOk()->getContent());
    }

    public function test_no_other_student_data_is_exposed(): void
    {
        $classmate = User::factory()->student($this->schoolClass)->create(['name' => 'Berta Beispielfrau', 'username' => 'berta.beispiel']);
        $otherSchool = School::factory()->create(['name' => 'Schiller-Realschule']);
        $teacher = User::factory()->for($this->school)->teacher()->create(['name' => 'Herr Lehrmann']);
        $this->schoolClass->update(['teacher_id' => $teacher->getKey()]);
        $this->consent->forceFill(['guardian_name' => 'Erika Geheim'])->save();

        $this->get($this->url())
            ->assertOk()
            ->assertDontSee('Müllerschmidt')
            ->assertDontSee($this->student->username)
            ->assertDontSee($this->student->email)
            ->assertDontSee($classmate->name)
            ->assertDontSee('Berta')
            ->assertDontSee($otherSchool->name)
            ->assertDontSee('Herr Lehrmann')
            ->assertDontSee($this->schoolClass->join_code)
            ->assertDontSee('Erika Geheim');
    }

    public function test_the_page_is_not_indexed_cached_or_passed_on(): void
    {
        $response = $this->get($this->url())->assertOk();

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', false);
    }

    public function test_the_routes_run_without_the_web_group_and_with_their_own_rate_limit(): void
    {
        foreach (['consents.show', 'consents.store', 'consents.revoke'] as $name) {
            $middleware = app('router')->gatherRouteMiddleware(app('router')->getRoutes()->getByName($name));

            $this->assertTrue(collect($middleware)->contains(fn (mixed $each): bool => is_string($each) && str_ends_with($each, 'ThrottleRequests:consent')), $name);
            $this->assertNotContains(StartSession::class, $middleware, $name);
            $this->assertNotContains(PreventRequestForgery::class, $middleware, $name);
        }
    }

    /**
     * The sessions table would hold the full URL (with the plain token), the IP address and the user agent of every
     * visit, which defeats storing only the hash of the token.
     */
    public function test_the_public_page_starts_no_session_and_keeps_no_trace_of_the_visit(): void
    {
        config(['session.driver' => 'database']);

        $responses = [
            $this->get($this->url()),
            $this->post(route('consents.store', $this->token), $this->declaration(['is_guardian' => null])),
            $this->post(route('consents.store', $this->token), $this->declaration()),
            $this->get($this->url().'?ergebnis=erteilt'),
            $this->post(route('consents.revoke', $this->token)),
        ];

        foreach ($responses as $response) {
            $this->assertSame([], $response->headers->getCookies());
        }

        $this->assertSame(0, DB::table('sessions')->count());
        $this->assertFalse(DB::table('sessions')->where('payload', 'like', '%'.$this->token.'%')->exists());
    }

    public function test_the_parents_grant_the_consent(): void
    {
        $this->post(route('consents.store', $this->token), $this->declaration())
            ->assertRedirect($this->url().'?ergebnis=erteilt');

        $consent = $this->consent->refresh();
        $this->assertNotNull($consent->granted_at);
        $this->assertNull($consent->revoked_at);
        $this->assertSame(['audio', 'transcript'], $consent->purposes);
        $this->assertSame('Erika Müllerschmidt', $consent->guardian_name);
        $this->assertSame($this->form->getKey(), $consent->consent_form_id);
        $this->assertSame(RecordingConsent::SOURCE_DIGITAL, $consent->source);
        $this->assertNull($consent->recorded_by);

        // The redirect after granting thanks the parents; the page without the flag only states the consent.
        $this->get($this->url().'?ergebnis=erteilt')->assertOk()->assertSee('Vielen Dank');
        $this->get($this->url())
            ->assertOk()
            ->assertDontSee('Vielen Dank')
            ->assertSee('Einwilligung erteilt am '.now()->format('d.m.Y'))
            ->assertSee('Ton des Unterrichts (Tuto-Mikro), Notizen aus der Stunde (aus dem Ton erkannter Text)')
            ->assertSee('Einwilligung widerrufen');
    }

    public function test_the_confirmation_after_granting_and_the_revoke_button_are_shown(): void
    {
        $this->followingRedirects()
            ->post(route('consents.store', $this->token), $this->declaration())
            ->assertOk()
            ->assertSee('Vielen Dank, Ihre Einwilligung wurde gespeichert.')
            ->assertSee('Einwilligung widerrufen');
    }

    public function test_a_declaration_needs_the_guardian_confirmation_a_name_and_the_tone_recording(): void
    {
        $this->post(route('consents.store', $this->token), $this->declaration(['is_guardian' => null]))
            ->assertStatus(422)
            ->assertSee('Bitte bestätigen Sie, dass Sie erziehungsberechtigt sind.');

        $this->post(route('consents.store', $this->token), $this->declaration(['guardian_name' => '']))
            ->assertStatus(422)
            ->assertSee('Bitte tragen Sie Ihren Namen ein.');

        $this->post(route('consents.store', $this->token), $this->declaration(['purposes' => ['transcript']]))
            ->assertStatus(422)
            ->assertSee('Der Ton ist die Grundlage der anderen Zwecke.');

        $this->post(route('consents.store', $this->token), $this->declaration(['purposes' => []]))
            ->assertStatus(422)
            ->assertSee('Bitte wählen Sie mindestens den Ton aus.');

        $this->post(route('consents.store', $this->token), $this->declaration(['purposes' => ['audio', 'video']]))
            ->assertStatus(422)
            ->assertSee('Diese Auswahl ist nicht möglich.');

        $this->assertNull($this->consent->refresh()->granted_at);
    }

    public function test_the_errors_are_shown_on_the_page_and_the_input_is_kept(): void
    {
        $html = $this->post(route('consents.store', $this->token), $this->declaration(['is_guardian' => null, 'guardian_name' => 'Erika', 'purposes' => ['audio', 'transcript']]))
            ->assertStatus(422)
            ->assertSee('Ihre Eingabe konnte nicht gespeichert werden.')
            ->assertSee('Bitte bestätigen Sie, dass Sie erziehungsberechtigt sind.')
            ->assertSee('value="Erika"', false)
            ->assertSee('value="transcript"', false)
            ->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*value="transcript"[^>]*\schecked/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*value="ai_analysis"[^>]*\schecked/', $html);
    }

    public function test_odd_input_in_a_failed_declaration_does_not_break_the_page(): void
    {
        $this->post(route('consents.store', $this->token), ['purposes' => 'audio', 'guardian_name' => ['x'], 'consent_form' => $this->form->getKey()])
            ->assertStatus(422)
            ->assertSee('Ihre Eingabe konnte nicht gespeichert werden.');

        $this->post(route('consents.store', $this->token), ['purposes' => [['audio']], 'guardian_name' => 'Erika'])
            ->assertStatus(422);
    }

    public function test_a_declaration_for_a_text_that_changed_in_the_meantime_is_refused(): void
    {
        $stale = $this->declaration();
        $this->publishedForm($this->school, 2);

        $this->post(route('consents.store', $this->token), $stale)
            ->assertStatus(422)
            ->assertSee('Der Text der Einwilligung wurde in der Zwischenzeit geändert.');

        $this->assertNull($this->consent->refresh()->granted_at);
    }

    public function test_the_parents_revoke_on_the_same_link(): void
    {
        $this->consent->grant($this->form, ['audio'], 'Erika Müllerschmidt');

        $this->post(route('consents.revoke', $this->token))->assertRedirect($this->url().'?ergebnis=widerrufen');

        $this->assertNotNull($this->consent->refresh()->revoked_at);

        $this->get($this->url().'?ergebnis=widerrufen')->assertOk()->assertSee('Ihre Einwilligung wurde widerrufen.');
        $this->get($this->url())
            ->assertOk()
            ->assertSee('Sie haben Ihre Einwilligung am '.now()->format('d.m.Y').' widerrufen')
            ->assertDontSee('<button type="submit" class="consent-button consent-button-secondary">Einwilligung widerrufen</button>', false)
            ->assertSee('Einwilligung erteilen');
    }

    public function test_revoking_twice_keeps_the_first_time(): void
    {
        $this->consent->grant($this->form, ['audio'], 'Erika');
        $this->travelTo(now()->subDay());
        $this->post(route('consents.revoke', $this->token));
        $first = $this->consent->refresh()->revoked_at;
        $this->travelBack();

        $this->post(route('consents.revoke', $this->token));

        $this->assertTrue($first->equalTo($this->consent->refresh()->revoked_at));
    }

    public function test_revoking_without_any_consent_changes_nothing(): void
    {
        $this->post(route('consents.revoke', $this->token))->assertRedirect();

        $this->assertNull($this->consent->refresh()->revoked_at);
        $this->get($this->url().'?ergebnis=widerrufen')->assertOk()->assertDontSee('Ihre Einwilligung wurde widerrufen.');
    }

    public function test_the_parents_can_consent_again_after_revoking(): void
    {
        $this->consent->grant($this->form, ['audio'], 'Erika');
        $this->consent->revoke();

        $this->post(route('consents.store', $this->token), $this->declaration(['purposes' => ['audio']]))->assertRedirect();

        $this->consent->refresh();
        $this->assertNull($this->consent->revoked_at);
        $this->assertSame(['audio'], $this->consent->purposes);
    }

    public function test_a_consent_for_an_older_version_is_marked_outdated_and_can_be_renewed(): void
    {
        $this->consent->grant($this->form, ['audio', 'transcript'], 'Erika');
        $newForm = ConsentForm::factory()->version(2)->create(['school_id' => $this->school->getKey(), 'body' => 'Der neue Text.']);

        $html = $this->get($this->url())
            ->assertOk()
            ->assertSee('Der Text hat sich geändert')
            ->assertSee('Der neue Text.')
            ->assertSee('Einwilligung erteilen')
            ->getContent();
        $this->assertNothingTicked($html);

        $this->post(route('consents.store', $this->token), $this->declaration(['consent_form' => $newForm->getKey()]))->assertRedirect();

        $this->assertSame($newForm->getKey(), $this->consent->refresh()->consent_form_id);
    }

    public function test_a_renewed_consent_needs_the_students_confirmation_again(): void
    {
        $this->consent->grant($this->form, ['audio'], 'Erika');
        $this->consent->confirmByStudent();

        $this->post(route('consents.store', $this->token), $this->declaration());

        $this->assertNull($this->consent->refresh()->student_confirmed_at);
    }

    public function test_an_unknown_token_gets_a_neutral_404(): void
    {
        $this->get($this->url(Str::random(48)))->assertNotFound()->assertDontSee('Goethe-Gymnasium');
        $this->post(route('consents.store', Str::random(48)), $this->declaration())->assertNotFound();
        $this->post(route('consents.revoke', Str::random(48)))->assertNotFound();
    }

    public function test_a_token_of_the_wrong_shape_is_a_404(): void
    {
        foreach (['kurz', str_repeat('a', 39), str_repeat('a', 65), str_repeat('ä', 48), str_repeat('a-', 24), '%20'.str_repeat('a', 45)] as $token) {
            $this->get('/einwilligung/'.$token)->assertNotFound();
        }
    }

    public function test_the_hash_of_a_token_is_no_token(): void
    {
        $this->get($this->url($this->consent->refresh()->token_hash))->assertNotFound();
    }

    public function test_a_replaced_link_stops_working_and_the_new_one_works(): void
    {
        $new = $this->consent->regenerateToken();

        $this->get($this->url($this->token))->assertNotFound();
        $this->post(route('consents.revoke', $this->token))->assertNotFound();
        $this->get($this->url($new))->assertOk();
    }

    public function test_one_link_is_limited_but_other_links_behind_the_same_ip_are_not(): void
    {
        $other = RecordingConsent::factory()->notAnswered()->create([
            'student_id' => User::factory()->student($this->schoolClass)->create()->getKey(),
        ]);
        $otherToken = $other->regenerateToken();

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->get($this->url())->assertOk();
        }

        $this->get($this->url())->assertStatus(429);
        $this->post(route('consents.revoke', $this->token))->assertStatus(429);
        $this->get($this->url($otherToken))->assertOk();
    }

    public function test_a_parents_evening_behind_one_address_is_not_locked_out(): void
    {
        // Granting takes three requests: the page, the POST and the redirect. 60 families in a minute are 180 requests from one IP.
        for ($family = 0; $family < 60; $family++) {
            $student = User::factory()->student($this->schoolClass)->create();
            $token = RecordingConsent::factory()->notAnswered()->create(['student_id' => $student->getKey()])->regenerateToken();

            $this->get($this->url($token))->assertOk();
            $this->post(route('consents.store', $token), $this->declaration())->assertRedirect();
            $this->get($this->url($token).'?ergebnis=erteilt')->assertOk();
        }
    }

    public function test_the_rate_limit_per_address_stops_guessing(): void
    {
        for ($attempt = 0; $attempt < 240; $attempt++) {
            $this->get($this->url(Str::random(48)))->assertNotFound();
        }

        $this->get($this->url(Str::random(48)))->assertStatus(429);
        $this->get($this->url())->assertStatus(429);
    }

    public function test_revoking_works_for_a_blocked_school(): void
    {
        $this->consent->grant($this->form, ['audio'], 'Erika');
        $this->school->forceFill(['blocked_at' => now()])->save();

        $this->post(route('consents.revoke', $this->token))->assertRedirect();

        $this->assertNotNull($this->consent->refresh()->revoked_at);
    }
}
