<?php

namespace Tests\Feature\Consents;

use App\ConsentStatus;
use App\Models\ConsentForm;
use App\Models\RecordingConsent;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class RecordingConsentModelTest extends TestCase
{
    use CreatesConsentFixtures;
    use RefreshDatabase;

    public function test_only_the_hash_of_the_token_is_stored(): void
    {
        $consent = RecordingConsent::factory()->notAnswered()->create();

        $token = $consent->regenerateToken();

        $this->assertGreaterThanOrEqual(40, strlen($token));
        $this->assertSame(hash('sha256', $token), $consent->refresh()->token_hash);
        $this->assertNotNull($consent->token_generated_at);
        $this->assertStringNotContainsString($token, json_encode(DB::table('recording_consents')->get()));
        $this->assertTrue(RecordingConsent::findByToken($token)->is($consent));
    }

    public function test_regenerating_the_token_invalidates_the_old_link(): void
    {
        $consent = RecordingConsent::factory()->notAnswered()->create();
        $old = $consent->regenerateToken();

        $new = $consent->regenerateToken();

        $this->assertNotSame($old, $new);
        $this->assertNull(RecordingConsent::findByToken($old));
        $this->assertTrue(RecordingConsent::findByToken($new)->is($consent));
    }

    public function test_every_student_has_one_consent_record(): void
    {
        $student = User::factory()->student()->create();

        RecordingConsent::forStudent($student)->forceFill(['school_id' => $student->school_id])->save();
        RecordingConsent::forStudent($student)->forceFill(['school_id' => $student->school_id])->save();

        $this->assertSame(1, RecordingConsent::query()->where('student_id', $student->getKey())->count());
    }

    public function test_granting_keeps_only_the_purposes_the_form_offers_and_resets_the_students_confirmation(): void
    {
        $school = School::factory()->create();
        $form = ConsentForm::factory()->offering(['audio', 'transcript'])->create(['school_id' => $school->getKey()]);
        $consent = RecordingConsent::factory()->studentConfirmed()->create(['consent_form_id' => $form->getKey(), 'school_id' => $school->getKey(), 'student_id' => User::factory()->student(SchoolClass::factory()->create(['school_id' => $school->getKey()]))]);

        $consent->grant($form, ['ai_analysis', 'transcript', 'audio'], 'Erika Muster');

        $consent->refresh();
        $this->assertSame(['audio', 'transcript'], $consent->purposes);
        $this->assertSame('Erika Muster', $consent->guardian_name);
        $this->assertNull($consent->student_confirmed_at);
        $this->assertSame(RecordingConsent::SOURCE_DIGITAL, $consent->source);
    }

    public function test_a_consent_without_the_tone_recording_is_refused(): void
    {
        $consent = RecordingConsent::factory()->notAnswered()->create();

        $this->expectException(InvalidArgumentException::class);

        $consent->grant($consent->consentForm, ['transcript'], 'Erika Muster');
    }

    public function test_a_declaration_dated_before_the_latest_one_cannot_replace_it(): void
    {
        $school = School::factory()->create();
        $form = $this->publishedForm($school);
        $consent = RecordingConsent::factory()->forForm($form)->create(['granted_at' => now()->subDays(8), 'revoked_at' => now()->subDays(4)]);

        $this->assertTrue($consent->latestDeclarationAt()->isSameDay(now()->subDays(4)));
        $this->assertTrue($consent->predatesLatestDeclaration(now()->subDays(10)));
        $this->assertFalse($consent->predatesLatestDeclaration(now()->subDays(4)));
        $this->assertFalse($consent->predatesLatestDeclaration(now()));

        try {
            $consent->grant($form, ['audio'], 'Erika', RecordingConsent::SOURCE_PAPER, null, now()->subDays(10));
            $this->fail('A backdated declaration replaced a later one.');
        } catch (InvalidArgumentException) {
            $this->assertNotNull($consent->refresh()->revoked_at);
        }

        $consent->grant($form, ['audio'], 'Erika', RecordingConsent::SOURCE_PAPER, null, now()->subDays(2));
        $this->assertNull($consent->refresh()->revoked_at);
    }

    public function test_a_revocation_dated_before_the_consent_cannot_cancel_it(): void
    {
        $form = $this->publishedForm(School::factory()->create());
        $consent = RecordingConsent::factory()->forForm($form)->create(['granted_at' => now()->subDays(2)]);

        try {
            $consent->revoke(null, now()->subDays(5));
            $this->fail('A revocation older than the consent must be refused.');
        } catch (InvalidArgumentException) {
            $this->assertNull($consent->refresh()->revoked_at);
        }

        $consent->revoke(null, now()->subDays(2));

        $this->assertTrue($consent->refresh()->revoked_at->isSameDay(now()->subDays(2)));
    }

    public function test_a_consent_without_a_declaration_has_no_latest_one(): void
    {
        $consent = RecordingConsent::factory()->notAnswered()->create();

        $this->assertNull($consent->latestDeclarationAt());
        $this->assertFalse($consent->predatesLatestDeclaration(now()->subYear()));
    }

    public function test_publishing_a_new_version_makes_older_consents_outdated(): void
    {
        $school = School::factory()->create();
        $first = $this->publishedForm($school, 1);
        $consent = RecordingConsent::factory()->forForm($first)->create();

        $this->assertSame(ConsentStatus::Granted, ConsentStatus::for($consent, $school->currentConsentForm(), false));

        $second = $this->publishedForm($school, 2);

        $this->assertTrue($school->currentConsentForm()->is($second));
        $this->assertSame(ConsentStatus::Outdated, ConsentStatus::for($consent, $school->currentConsentForm(), false));
    }

    public function test_a_draft_does_not_outdate_anything(): void
    {
        $school = School::factory()->create();
        $first = $this->publishedForm($school, 1);
        $consent = RecordingConsent::factory()->forForm($first)->create();
        ConsentForm::factory()->draft()->version(2)->create(['school_id' => $school->getKey()]);

        $this->assertTrue($school->currentConsentForm()->is($first));
        $this->assertSame(ConsentStatus::Granted, ConsentStatus::for($consent, $school->currentConsentForm(), false));
    }

    public function test_the_status_for_every_state(): void
    {
        $school = School::factory()->create();
        $form = $this->publishedForm($school);

        $this->assertSame(ConsentStatus::Missing, ConsentStatus::for(null, $form, false));
        $this->assertSame(ConsentStatus::Missing, ConsentStatus::for(RecordingConsent::factory()->notAnswered()->create(), $form, false));
        $this->assertSame(ConsentStatus::Granted, ConsentStatus::for(RecordingConsent::factory()->forForm($form)->create(), $form, false));
        $this->assertSame(ConsentStatus::Revoked, ConsentStatus::for(RecordingConsent::factory()->forForm($form)->revoked()->create(), $form, false));
        $this->assertSame(ConsentStatus::Partial, ConsentStatus::for(RecordingConsent::factory()->forForm($form)->agreedTo(['audio'])->create(), $form, false));
        $this->assertSame(ConsentStatus::StudentPending, ConsentStatus::for(RecordingConsent::factory()->forForm($form)->create(), $form, true));
        $this->assertSame(ConsentStatus::Granted, ConsentStatus::for(RecordingConsent::factory()->forForm($form)->studentConfirmed()->create(), $form, true));
        $this->assertSame(ConsentStatus::Outdated, ConsentStatus::for(RecordingConsent::factory()->forForm($form)->create(), null, false));
    }

    public function test_every_status_has_a_label_and_an_icon_besides_its_colour(): void
    {
        foreach (ConsentStatus::cases() as $status) {
            $this->assertNotSame('', $status->getLabel());
            $this->assertNotNull($status->getIcon());
        }

        $this->assertSame('Student confirmation missing', ConsentStatus::StudentPending->getLabel());
    }

    public function test_recordings_cannot_be_enabled_without_the_whole_checklist(): void
    {
        $school = School::factory()->create();
        $this->publishedForm($school);
        $admin = User::factory()->for($school)->admin()->create();
        $checklist = array_fill_keys(School::RECORDING_CHECKLIST, true);

        foreach (School::RECORDING_CHECKLIST as $missing) {
            $school->forceFill(['recording_checklist' => [...$checklist, $missing => false]])->save();

            $this->assertFalse($school->canEnableRecordings(), $missing);

            try {
                $school->enableRecordings($admin);
                $this->fail("Enabling worked without {$missing}.");
            } catch (DomainException) {
                $this->assertNull($school->refresh()->recordings_enabled_at);
            }
        }
    }

    public function test_recordings_cannot_be_enabled_without_a_published_form(): void
    {
        $school = School::factory()->create(['blocked_at' => null]);
        $school->forceFill(['recording_checklist' => array_fill_keys(School::RECORDING_CHECKLIST, true)])->save();
        ConsentForm::factory()->draft()->create(['school_id' => $school->getKey()]);

        $this->expectException(DomainException::class);

        $school->enableRecordings(User::factory()->for($school)->admin()->create());
    }

    public function test_enabling_and_disabling_remember_who_and_when(): void
    {
        $school = $this->schoolWithRecordings();
        $school->forceFill(['recordings_enabled_at' => null])->save();
        $this->publishedForm($school);
        $admin = User::factory()->for($school)->admin()->create();

        $school->enableRecordings($admin);

        $this->assertTrue($school->refresh()->recordingsEnabled());
        $this->assertTrue($school->recordingsEnabledBy->is($admin));

        $school->disableRecordings();

        $this->assertFalse($school->refresh()->recordingsEnabled());
        $this->assertNull($school->recordings_enabled_by);
    }

    public function test_a_blocked_school_has_no_recordings(): void
    {
        $school = $this->schoolWithRecordings();

        $this->assertTrue($school->recordingsEnabled());

        $school->forceFill(['blocked_at' => now()])->save();

        $this->assertFalse($school->recordingsEnabled());
    }

    public function test_the_recording_switch_is_not_mass_assignable(): void
    {
        $school = School::factory()->create();

        $school->update(['recordings_enabled_at' => now(), 'require_student_consent' => true, 'recording_checklist' => ['dsfa_done' => true]]);

        $school->refresh();
        $this->assertNull($school->recordings_enabled_at);
        $this->assertFalse($school->require_student_consent);
        $this->assertNull($school->recording_checklist);
    }

    public function test_the_first_name_for_parents(): void
    {
        $student = User::factory()->make(['name' => 'Anna Maria Müller']);

        $this->assertSame('Anna', RecordingConsent::firstName($student));
        $this->assertSame('Anna M.', RecordingConsent::firstName($student, withInitial: true));
        $this->assertSame('Anna', RecordingConsent::firstName(User::factory()->make(['name' => 'Anna']), withInitial: true));
    }

    public function test_the_default_form_names_the_school_and_the_retention(): void
    {
        config(['lernplattform.recordings.session_keep_days' => 21, 'lernplattform.retention.school_years_kept' => 1]);
        $school = School::factory()->create(['name' => 'Goethe-Gymnasium', 'city' => 'Kassel', 'contact_email' => 'sekretariat@goethe.example.test']);

        $body = ConsentForm::defaultBody($school);

        $this->assertStringContainsString('Goethe-Gymnasium', $body);
        $this->assertStringContainsString('nach 21 Tagen', $body);
        $this->assertStringContainsString('zum Ende des Schuljahres', $body);
        $this->assertStringContainsString('Tuto-Mikro', $body);
        $this->assertStringContainsString('Ton wird nicht gespeichert', $body);
        $this->assertStringContainsString('sekretariat@goethe.example.test', $body);
        $this->assertStringContainsString('jederzeit', $body);
        $this->assertStringContainsString('ohne Nachteile', $body);
        $this->assertStringContainsString('pseudonymisierten', $body);
    }

    public function test_the_ai_purpose_names_the_sound_that_goes_to_the_provider_and_what_declining_means(): void
    {
        $label = ConsentForm::PURPOSES['ai_analysis'];
        $body = ConsentForm::defaultBody(School::factory()->create());

        // The purpose gates the speech recognition of the sound segments, not only a text summary.
        $this->assertStringContainsString('Spracherkennung', $label);
        $this->assertStringContainsString('Ton-Abschnitte', $label);
        $this->assertStringContainsString('Text nur pseudonymisiert', $label);
        $this->assertStringContainsString('nicht pseudonymisiert', $body);
        $this->assertStringContainsString('bleibt das Tuto-Mikro für die ganze Klasse aus', $body);
        $this->assertStringContainsString('Sind die KI-Funktionen ausgeschaltet, wird diese Zustimmung nicht gebraucht', $body);
        $this->assertStringContainsString('derzeit Google Gemini', $body);
        $this->assertStringContainsString('verlässt der Ton das Gerät der Lehrkraft nicht', $body);
    }

    public function test_the_default_form_names_the_blocked_subjects_and_the_explicit_consent_of_article_9(): void
    {
        $body = ConsentForm::defaultBody(School::factory()->create());

        $this->assertStringContainsString('In den Fächern '.implode(', ', School::DEFAULT_MIKRO_BLOCKED_SUBJECTS).' und bei Förderaufgaben bleibt es immer aus.', $body);
        $this->assertStringContainsString('Art. 9 Abs. 2 lit. a DSGVO', $body);
        $this->assertStringContainsString('auch die Schuladministration', $body);
    }

    public function test_the_form_text_is_rendered_without_raw_html(): void
    {
        $form = ConsentForm::factory()->make(['body' => "## Titel\n\n<script>alert(1)</script>\n\n[Link](javascript:alert(1))\n\n- Punkt"]);

        $html = $form->html();

        $this->assertStringContainsString('<h2>Titel</h2>', $html);
        $this->assertStringContainsString('<li>Punkt</li>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }
}
