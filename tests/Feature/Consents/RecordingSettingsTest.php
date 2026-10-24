<?php

namespace Tests\Feature\Consents;

use App\Filament\Pages\RecordingSettings;
use App\Models\ConsentForm;
use App\Models\RecordingConsent;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RecordingSettingsTest extends TestCase
{
    use CreatesConsentFixtures;
    use RefreshDatabase;

    private School $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create(['name' => 'Goethe-Gymnasium', 'city' => 'Kassel']);
        $this->admin = User::factory()->for($this->school)->admin()->create();
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function checklist(bool $value = true): array
    {
        return ['checklist' => array_fill_keys(School::RECORDING_CHECKLIST, $value)];
    }

    private function page(?User $user = null)
    {
        return Livewire::actingAs($user ?? $this->admin)->test(RecordingSettings::class);
    }

    public function test_only_the_school_administration_opens_the_page(): void
    {
        $this->get(RecordingSettings::getUrl())->assertRedirect();

        foreach (['teacher', 'student', 'operator'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create())->get(RecordingSettings::getUrl())->assertForbidden();
        }

        $this->actingAs($this->admin)->get(RecordingSettings::getUrl())->assertOk()->assertSee('Tuto-Mikro & consents');
    }

    public function test_the_page_starts_with_recordings_off_and_the_template_for_the_school(): void
    {
        config(['lernplattform.recordings.session_keep_days' => 10]);

        $this->page()
            ->assertSee('Tuto-Mikro is off')
            ->assertSee('No version has been published yet.')
            ->assertActionDisabled('enableRecordings')
            ->assertFormSet([
                'title' => ConsentForm::defaultTitle(),
                'body' => ConsentForm::defaultBody($this->school),
                'purposes' => array_keys(ConsentForm::PURPOSES),
            ], 'consentFormForm')
            ->assertSee('after 10 days');

        $this->assertStringContainsString('nach 10 Tagen', ConsentForm::defaultBody($this->school));
    }

    public function test_every_checklist_item_is_explained(): void
    {
        $page = $this->page();

        foreach (RecordingSettings::checklistItems() as $key => $item) {
            $this->assertContains($key, School::RECORDING_CHECKLIST);
            $page->assertSee($item['label'])->assertSee($item['help']);
        }

        $this->assertCount(count(School::RECORDING_CHECKLIST), RecordingSettings::checklistItems());
    }

    public function test_recordings_need_every_checklist_item_and_a_published_form(): void
    {
        $this->publishedForm($this->school);

        foreach (School::RECORDING_CHECKLIST as $missing) {
            $checklist = $this->checklist();
            $checklist['checklist'][$missing] = false;

            $this->page()
                ->fillForm($checklist, 'settingsForm')
                ->assertActionDisabled('enableRecordings');
        }

        $this->assertNull($this->school->refresh()->recordings_enabled_at);
    }

    public function test_the_switch_stays_off_without_a_published_consent_form(): void
    {
        $this->page()
            ->fillForm($this->checklist(), 'settingsForm')
            ->assertActionDisabled('enableRecordings')
            ->assertSee('Publish consent form');

        $this->assertNull($this->school->refresh()->recordings_enabled_at);
    }

    public function test_with_everything_confirmed_the_administration_enables_recordings(): void
    {
        $this->publishedForm($this->school);

        $this->page()
            ->fillForm($this->checklist(), 'settingsForm')
            ->assertActionEnabled('enableRecordings')
            ->callAction('enableRecordings')
            ->assertNotified('Tuto-Mikro is enabled');

        $this->school->refresh();
        $this->assertTrue($this->school->recordingsEnabled());
        $this->assertTrue($this->school->recordingsEnabledBy->is($this->admin));
        $this->assertTrue($this->school->recordings_enabled_at->isToday());
        $this->assertTrue($this->school->isRecordingChecklistComplete());
    }

    public function test_the_administration_disables_recordings_any_time(): void
    {
        $school = $this->schoolWithRecordings();
        $this->publishedForm($school);
        $admin = User::factory()->for($school)->admin()->create();

        $this->page($admin)
            ->assertSee('Tuto-Mikro is enabled')
            ->callAction('disableRecordings')
            ->assertNotified('Tuto-Mikro is off');

        $this->assertFalse($school->refresh()->recordingsEnabled());
        $this->assertNull($school->recordings_enabled_by);
    }

    public function test_only_the_fitting_switch_is_shown(): void
    {
        $this->page()
            ->assertSee("mountAction('enableRecordings'", false)
            ->assertDontSee("mountAction('disableRecordings'", false);

        $school = $this->schoolWithRecordings();
        $this->publishedForm($school);

        $this->page(User::factory()->for($school)->admin()->create())
            ->assertSee("mountAction('disableRecordings'", false)
            ->assertDontSee("mountAction('enableRecordings'", false);
    }

    public function test_taking_back_a_confirmation_switches_recordings_off(): void
    {
        $school = $this->schoolWithRecordings();
        $this->publishedForm($school);
        $admin = User::factory()->for($school)->admin()->create();

        $this->page($admin)
            ->fillForm(['checklist.dsfa_done' => false], 'settingsForm')
            ->call('saveSettings')
            ->assertNotified('Tuto-Mikro switched off');

        $school->refresh();
        $this->assertFalse($school->recordingsEnabled());
        $this->assertNull($school->recordings_enabled_at);
        $this->assertFalse($school->recording_checklist['dsfa_done']);
    }

    public function test_the_checklist_and_the_student_switch_are_saved(): void
    {
        $this->page()
            ->fillForm([...$this->checklist(false), 'checklist.dsfa_done' => true, 'require_student_consent' => true], 'settingsForm')
            ->call('saveSettings')
            ->assertNotified('Saved');

        $this->school->refresh();
        $this->assertTrue($this->school->require_student_consent);
        $this->assertTrue($this->school->recording_checklist['dsfa_done']);
        $this->assertFalse($this->school->recording_checklist['personalrat_agreed']);
        $this->assertFalse($this->school->isRecordingChecklistComplete());
    }

    public function test_publishing_the_template_creates_version_one(): void
    {
        $this->page()
            ->callAction('publishConsentForm')
            ->assertHasNoFormErrors()
            ->assertNotified('Version 1 published');

        $form = $this->school->currentConsentForm();
        $this->assertSame(1, $form->version);
        $this->assertSame(ConsentForm::defaultTitle(), $form->title);
        $this->assertStringContainsString('Goethe-Gymnasium', $form->body);
        $this->assertSame(array_keys(ConsentForm::PURPOSES), $form->purposes);
        $this->assertTrue($form->creator->is($this->admin));
        $this->assertNotNull($form->published_at);
        $this->assertTrue($form->school->is($this->school));
    }

    public function test_a_new_version_makes_existing_consents_outdated_and_takes_back_the_approval(): void
    {
        $school = $this->schoolWithRecordings();
        $first = $this->publishedForm($school);
        [, $students] = $this->classWithStudents($school, 2);
        $this->consentAll($students, $first);
        $admin = User::factory()->for($school)->admin()->create();

        $this->page($admin)
            ->fillForm(['title' => 'Neue Überschrift', 'body' => "## Neu\n\nGeänderter Text.", 'purposes' => ['audio', 'transcript']], 'consentFormForm')
            ->callAction('publishConsentForm')
            ->assertHasNoFormErrors()
            ->assertNotified('Version 2 published');

        $second = $school->refresh()->currentConsentForm();
        $this->assertSame(2, $second->version);
        $this->assertSame('Neue Überschrift', $second->title);
        $this->assertSame(['audio', 'transcript'], $second->purposes);
        $this->assertFalse($school->recording_checklist['consent_form_approved']);
        $this->assertFalse($school->recordingsEnabled());

        // Version 1 stays exactly as it was published.
        $this->assertSame($first->title, $first->refresh()->title);
        $this->assertSame($first->body, $first->body);
        $this->assertSame(2, ConsentForm::query()->where('school_id', $school->getKey())->count());

        foreach ($students as $student) {
            $consent = RecordingConsent::query()->where('student_id', $student->getKey())->firstOrFail();
            $this->assertSame($first->getKey(), $consent->consent_form_id);
            $this->assertFalse($consent->consent_form_id === $second->getKey());
        }
    }

    public function test_an_unchanged_text_does_not_make_a_new_version(): void
    {
        $form = $this->publishedForm($this->school);

        $this->page()
            ->callAction('publishConsentForm')
            ->assertNotified('The text is unchanged');

        $this->assertSame(1, ConsentForm::query()->count());
        $this->assertTrue($this->school->currentConsentForm()->is($form));
    }

    public function test_the_tone_recording_is_always_part_of_a_form(): void
    {
        $this->page()
            ->fillForm(['purposes' => ['transcript']], 'consentFormForm')
            ->callAction('publishConsentForm')
            ->assertHasFormErrors(['purposes'], 'consentFormForm');

        $this->assertDatabaseCount('consent_forms', 0);

        $this->page()
            ->fillForm(['purposes' => ['audio']], 'consentFormForm')
            ->callAction('publishConsentForm')
            ->assertHasNoFormErrors([], 'consentFormForm');

        $this->assertSame(['audio'], $this->school->currentConsentForm()->purposes);
    }

    public function test_the_form_needs_a_title_a_text_and_a_purpose(): void
    {
        $this->page()
            ->fillForm(['title' => '', 'body' => '', 'purposes' => []], 'consentFormForm')
            ->callAction('publishConsentForm')
            ->assertHasFormErrors(['title' => 'required', 'body' => 'required', 'purposes' => 'required'], 'consentFormForm');

        $this->assertDatabaseCount('consent_forms', 0);
    }

    public function test_versions_are_numbered_per_school(): void
    {
        $other = School::factory()->create();
        $this->publishedForm($other);
        $this->publishedForm($other, 2);

        $this->page()->callAction('publishConsentForm');

        $this->assertSame(1, $this->school->currentConsentForm()->version);
    }

    public function test_the_page_shows_the_versions_and_how_many_students_consented(): void
    {
        $school = $this->schoolWithRecordings();
        $first = $this->publishedForm($school);
        $second = $this->publishedForm($school, 2);
        [, $students] = $this->classWithStudents($school, 4);
        $this->consentAll($students->take(1), $first);
        $this->consentAll($students->skip(1)->take(2), $second);
        $admin = User::factory()->for($school)->admin()->create();

        $this->page($admin)
            ->assertSee('Version 2')
            ->assertSee('Version 1')
            ->assertSee('2 of 4')
            ->assertSee('1 consent with this version');
    }

    public function test_the_administration_only_ever_touches_its_own_school(): void
    {
        $other = School::factory()->create();
        $this->publishedForm($other);

        $this->page()
            ->fillForm($this->checklist(), 'settingsForm')
            ->call('saveSettings');

        $this->assertNull($other->refresh()->recording_checklist);
        $this->assertNull($other->recordings_enabled_at);
    }

    public function test_the_form_starts_with_the_suggested_subjects_without_tuto_mikro(): void
    {
        $this->assertNull($this->school->mikro_blocked_subjects);

        $this->page()->assertFormSet(['mikro_blocked_subjects' => ['Religion', 'Ethik', 'Philosophie', 'Sexualkunde', 'Sexualerziehung', 'Werte und Normen', 'LER']], 'settingsForm');
    }

    public function test_the_subjects_without_tuto_mikro_are_saved_tidied_up(): void
    {
        $this->page()
            ->fillForm(['mikro_blocked_subjects' => ['  Kunst ', 'kunst', 'Religion', '  ', 'Werte  und Normen'], ...$this->checklist(false)], 'settingsForm')
            ->call('saveSettings')
            ->assertNotified('Saved');

        $school = $this->school->refresh();
        $this->assertSame(['Kunst', 'Religion', 'Werte und Normen'], $school->mikro_blocked_subjects);
        $this->assertTrue($school->blocksMikroForSubject(' kunst'));
        $this->assertFalse($school->blocksMikroForSubject('Ethik'));
    }

    public function test_an_empty_list_is_the_schools_choice_to_block_nothing(): void
    {
        $this->page()
            ->fillForm(['mikro_blocked_subjects' => [], ...$this->checklist(false)], 'settingsForm')
            ->call('saveSettings');

        $school = $this->school->refresh();
        $this->assertSame([], $school->mikro_blocked_subjects);
        $this->assertFalse($school->blocksMikroForSubject('Religion'));
    }

    public function test_the_subjects_of_another_school_stay_as_they_are(): void
    {
        $other = School::factory()->create(['name' => 'Schiller-Schule']);
        $other->forceFill(['mikro_blocked_subjects' => ['Kunst']])->save();

        $this->page()
            ->fillForm(['mikro_blocked_subjects' => ['Musik'], ...$this->checklist(false)], 'settingsForm')
            ->call('saveSettings');

        $this->assertSame(['Musik'], $this->school->refresh()->mikro_blocked_subjects);
        $this->assertSame(['Kunst'], $other->refresh()->mikro_blocked_subjects);
    }

    public function test_a_subject_name_must_stay_short(): void
    {
        $this->page()
            ->fillForm(['mikro_blocked_subjects' => [str_repeat('x', 101)], ...$this->checklist(false)], 'settingsForm')
            ->call('saveSettings')
            ->assertHasFormErrors(['mikro_blocked_subjects.0'], 'settingsForm');

        $this->assertNull($this->school->refresh()->mikro_blocked_subjects);
    }

    public function test_other_roles_cannot_use_the_livewire_component(): void
    {
        foreach (['teacher', 'student', 'operator'] as $role) {
            Livewire::actingAs(User::factory()->{$role}()->create())
                ->test(RecordingSettings::class)
                ->assertForbidden();
        }
    }
}
