<?php

namespace Tests\Feature\Dpa;

use App\Models\DpaAcceptance;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What a school accepted has to stay provable: the text depends on the configuration, so the fingerprint of the
 * settings and the text as shown are stored with every acceptance.
 */
class DpaProofTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lernplattform.dpa.final' => true]);
    }

    /**
     * Every setting the contract text is built from. A value that changes the text must change the fingerprint.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function settingsBehindTheText(): array
    {
        return [
            'school years kept' => [['lernplattform.retention.school_years_kept' => 3]],
            'start of the school year' => [['lernplattform.retention.school_year_starts' => '09-15']],
            'end of the two-year transition' => [['lernplattform.retention.one_year_rule_from' => '2028-08-01']],
            'days a Tuto-Mikro session is kept' => [['lernplattform.recordings.session_keep_days' => 200]],
            'hours a Tuto-Mikro session stays open' => [['lernplattform.recordings.max_session_hours' => 4]],
            'days a read notification is kept' => [['lernplattform.notifications.keep_read_days' => 7]],
            'days a notification is kept at most' => [['lernplattform.notifications.keep_days' => 30]],
            'days a substitution may last' => [['lernplattform.substitutions.max_days' => 30]],
            'session lifetime' => [['session.lifetime' => 45]],
            'minutes a reset link is valid' => [['auth.passwords.users.expire' => 15]],
            'secure session cookie' => [['session.secure' => true]],
            'platform AI switched on' => [['lernplattform.ai.enabled' => true]],
            'local AI model' => [['lernplattform.ai.provider' => 'ollama']],
            'another AI provider' => [['lernplattform.ai.provider' => 'mistral']],
        ];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('settingsBehindTheText')]
    public function test_every_setting_that_changes_the_text_changes_the_fingerprint(array $change): void
    {
        config([
            'lernplattform.retention.school_years_kept' => 1,
            'lernplattform.retention.school_year_starts' => '08-01',
            'lernplattform.retention.one_year_rule_from' => '2027-08-01',
            'lernplattform.recordings.session_keep_days' => 365,
            'lernplattform.recordings.max_session_hours' => 6,
            'lernplattform.notifications.keep_read_days' => 14,
            'lernplattform.notifications.keep_days' => 60,
            'lernplattform.substitutions.max_days' => 90,
            'session.lifetime' => 120,
            'session.secure' => false,
            'auth.passwords.users.expire' => 60,
            'auth.defaults.passwords' => 'users',
            'lernplattform.ai.enabled' => false,
            'lernplattform.ai.provider' => 'gemini',
        ]);

        $fingerprint = DpaAcceptance::fingerprint();
        $text = DpaAcceptance::render();

        config($change);

        $this->assertNotSame($fingerprint, DpaAcceptance::fingerprint());
        $this->assertNotSame($text, DpaAcceptance::render(), 'The text really depends on the setting, so the fingerprint is not made up.');
    }

    public function test_the_fingerprint_follows_the_version_and_is_stable_otherwise(): void
    {
        $this->assertSame(DpaAcceptance::fingerprint(), DpaAcceptance::fingerprint());
        $this->assertSame(64, strlen(DpaAcceptance::fingerprint()));

        $before = DpaAcceptance::fingerprint();
        config(['lernplattform.dpa.version' => '2099-01']);

        $this->assertNotSame($before, DpaAcceptance::fingerprint());
    }

    public function test_the_contract_text_does_not_change_between_two_renderings(): void
    {
        $this->assertSame(DpaAcceptance::render(), DpaAcceptance::render());
    }

    public function test_an_acceptance_keeps_the_text_the_name_and_the_username_as_they_were(): void
    {
        config(['session.lifetime' => 45]);
        $school = School::factory()->create(['name' => 'Goethe-Gymnasium', 'city' => 'Kassel', 'state' => 'Hessen']);
        $admin = User::factory()->for($school)->admin()->create(['name' => 'Sabine Schulleitung', 'username' => 'sabine.leitung']);
        $this->freezeTime();

        $school->acceptDpa($admin);

        config(['session.lifetime' => 90]);
        $school->update(['name' => 'Umbenannte Schule']);
        $admin->update(['name' => 'Sabine Neuname', 'username' => 'sabine.neu']);

        $acceptance = $school->dpaAcceptances()->sole();

        $this->assertSame(config('lernplattform.dpa.version'), $acceptance->version);
        $this->assertSame(now()->getTimestamp(), $acceptance->accepted_at->getTimestamp());
        $this->assertSame($admin->id, $acceptance->accepted_by);
        $this->assertSame('Sabine Schulleitung', $acceptance->accepted_by_name);
        $this->assertSame('sabine.leitung', $acceptance->accepted_by_username);
        $this->assertStringContainsString('<strong>Goethe-Gymnasium</strong>', $acceptance->content);
        $this->assertStringContainsString('nach 45 Minuten ohne Aktivität', $acceptance->content);
        $this->assertStringNotContainsString('90 Minuten', $acceptance->content);
        $this->assertFalse($school->refresh()->hasAcceptedCurrentDpa(), 'The settings changed since, so the acceptance is outdated.');
    }

    public function test_the_stored_text_is_the_german_contract_whatever_language_the_accepting_account_uses(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->for($school)->admin()->create(['locale' => 'en']);

        app()->setLocale('de');
        $german = DpaAcceptance::render($school);

        // The panel applies the language of the account, the contract the school read is the German one.
        app()->setLocale('en');
        $school->acceptDpa($admin);

        $content = $school->dpaAcceptances()->sole()->content;

        $this->assertSame($german, $content);
        $this->assertStringContainsString('Stunden anlegen, die Lernstände der Klasse sehen', $content);
        $this->assertStringNotContainsString('create lessons', $content);
    }

    public function test_each_acceptance_is_added_and_the_school_columns_follow_the_latest(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->for($school)->admin()->create(['name' => 'Erste Leitung']);
        $secondAdmin = User::factory()->for($school)->admin()->create(['name' => 'Zweite Leitung']);

        $this->travelTo('2026-10-24 10:00:00');
        $school->acceptDpa($admin);
        $this->travelTo('2026-10-25 10:00:00');
        config(['lernplattform.dpa.version' => '2027-02']);
        $school->acceptDpa($secondAdmin);

        $this->assertSame(2, DpaAcceptance::count());
        $this->assertSame(['2027-02', '2026-10'], $school->dpaAcceptances()->pluck('version')->all());
        $this->assertSame('Zweite Leitung', $school->refresh()->dpa_accepted_by_name);
        $this->assertSame($school->dpaAcceptances()->first()->fingerprint, $school->dpa_fingerprint);
    }

    public function test_an_acceptance_belongs_to_one_school_and_goes_with_it(): void
    {
        $school = School::factory()->create();
        $otherSchool = School::factory()->create();
        $school->acceptDpa(User::factory()->for($school)->admin()->create());
        $otherSchool->acceptDpa(User::factory()->for($otherSchool)->admin()->create());

        $this->assertSame(1, $school->dpaAcceptances()->count());
        $this->assertSame(1, $otherSchool->dpaAcceptances()->count());
        $this->assertTrue($school->dpaAcceptances()->sole()->school->is($school));

        $school->users()->delete();
        $school->delete();

        $this->assertSame(1, DpaAcceptance::count(), 'The acceptance of the other school stays.');
        $this->assertSame(0, DpaAcceptance::where('school_id', $school->id)->count());
    }

    public function test_the_factory_makes_an_acceptance_of_the_current_text(): void
    {
        $acceptance = DpaAcceptance::factory()->create();

        $this->assertSame(config('lernplattform.dpa.version'), $acceptance->version);
        $this->assertSame(DpaAcceptance::fingerprint(), $acceptance->fingerprint);
        $this->assertNotNull($acceptance->school);
    }

    public function test_the_scopes_agree_with_the_check_on_a_single_school(): void
    {
        $current = School::factory()->create();
        $current->acceptDpa(User::factory()->for($current)->admin()->create());

        $oldVersion = School::factory()->create();
        $oldVersion->forceFill(['dpa_version' => '2025-09', 'dpa_fingerprint' => DpaAcceptance::fingerprint(), 'dpa_accepted_at' => now()])->save();

        $otherSettings = School::factory()->create();
        $otherSettings->forceFill(['dpa_version' => config('lernplattform.dpa.version'), 'dpa_fingerprint' => str_repeat('a', 64), 'dpa_accepted_at' => now()])->save();

        $noFingerprint = School::factory()->create();
        $noFingerprint->forceFill(['dpa_version' => config('lernplattform.dpa.version'), 'dpa_accepted_at' => now()])->save();

        $missing = School::factory()->create();

        $this->assertSame([$current->id], School::withCurrentDpa()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$oldVersion->id, $otherSettings->id, $noFingerprint->id], School::withOutdatedDpa()->pluck('id')->all());
        $this->assertSame([$missing->id], School::withoutDpa()->pluck('id')->all());

        foreach ([$current, $oldVersion, $otherSettings, $noFingerprint, $missing] as $school) {
            $this->assertSame($school->hasAcceptedCurrentDpa(), School::withCurrentDpa()->whereKey($school)->exists());
        }
    }
}
