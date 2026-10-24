<?php

namespace Tests\Feature\Consents;

use App\Filament\Pages\ClassConsents;
use App\Models\ConsentForm;
use App\Models\Lesson;
use App\Models\RecordingConsent;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\RecordingConsentGate;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;
use Throwable;

class ClassConsentsTest extends TestCase
{
    use CreatesConsentFixtures;
    use RefreshDatabase;

    private School $school;

    private ConsentForm $form;

    private SchoolClass $schoolClass;

    /** @var Collection<int, User> */
    private Collection $students;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = $this->schoolWithRecordings();
        $this->form = $this->publishedForm($this->school);
        [$this->schoolClass, $this->students, $this->teacher] = $this->classWithStudents($this->school, 4);
    }

    private function page(?User $user = null, ?SchoolClass $schoolClass = null)
    {
        return Livewire::actingAs($user ?? $this->teacher)
            ->withQueryParams($schoolClass ? ['klasse' => $schoolClass->getKey()] : [])
            ->test(ClassConsents::class);
    }

    public function test_only_teachers_and_the_administration_open_the_page(): void
    {
        $this->get(ClassConsents::getUrl())->assertRedirect();

        $this->actingAs(User::factory()->student()->create())->get(ClassConsents::getUrl())->assertForbidden();
        $this->actingAs(User::factory()->operator()->create())->get(ClassConsents::getUrl())->assertForbidden();
        $this->actingAs($this->teacher)->get(ClassConsents::getUrl())->assertOk()->assertSee('Einwilligungen');
        $this->actingAs(User::factory()->for($this->school)->admin()->create())->get(ClassConsents::getUrl())->assertOk();
    }

    public function test_students_and_operators_cannot_mount_the_component(): void
    {
        Livewire::actingAs($this->students->first())->test(ClassConsents::class)->assertForbidden();
        Livewire::actingAs(User::factory()->operator()->create())->test(ClassConsents::class)->assertForbidden();
    }

    public function test_the_page_is_only_in_the_navigation_for_teachers_and_administrators(): void
    {
        $this->actingAs($this->teacher);
        $this->assertTrue(ClassConsents::canAccess());

        $this->actingAs(User::factory()->for($this->school)->admin()->create());
        $this->assertTrue(ClassConsents::canAccess());

        $this->actingAs($this->students->first());
        $this->assertFalse(ClassConsents::canAccess());

        $this->actingAs(User::factory()->operator()->create());
        $this->assertFalse(ClassConsents::canAccess());
    }

    public function test_a_teacher_sees_only_the_classes_they_lead_or_teach(): void
    {
        $taught = SchoolClass::factory()->create(['school_id' => $this->school->getKey(), 'name' => 'Unterrichtete Klasse']);
        Lesson::factory()->create(['school_class_id' => $taught->getKey(), 'teacher_id' => $this->teacher->getKey()]);
        $foreign = SchoolClass::factory()->create(['school_id' => $this->school->getKey(), 'name' => 'Fremde Klasse']);

        $this->page()
            ->assertSee($this->schoolClass->name)
            ->assertSee('Unterrichtete Klasse')
            ->assertDontSee('Fremde Klasse');

        $this->page(schoolClass: $foreign)->assertForbidden();
        $this->page(schoolClass: $taught)->assertSuccessful();
    }

    public function test_the_administration_sees_every_class_of_its_own_school_but_no_other(): void
    {
        $other = SchoolClass::factory()->create(['school_id' => $this->school->getKey(), 'name' => 'Zweite Klasse']);
        $foreignSchoolClass = SchoolClass::factory()->create(['name' => 'Klasse der anderen Schule']);
        $admin = User::factory()->for($this->school)->admin()->create();

        $this->page($admin)
            ->assertSee($this->schoolClass->name)
            ->assertSee('Zweite Klasse')
            ->assertDontSee('Klasse der anderen Schule');

        $this->page($admin, $other)->assertSuccessful();
        $this->page($admin, $foreignSchoolClass)->assertForbidden();
    }

    public function test_the_administration_and_teachers_of_another_school_get_a_403(): void
    {
        $otherSchool = School::factory()->create();

        $this->page(User::factory()->for($otherSchool)->admin()->create(), $this->schoolClass)->assertForbidden();
        $this->page(User::factory()->for($otherSchool)->teacher()->create(), $this->schoolClass)->assertForbidden();
    }

    public function test_changing_the_class_to_a_foreign_one_in_the_browser_is_a_403(): void
    {
        $foreign = SchoolClass::factory()->create(['school_id' => $this->school->getKey()]);

        $this->page()->set('classId', $foreign->getKey())->assertForbidden();
    }

    public function test_every_student_shows_a_status_with_label_and_the_counts(): void
    {
        [$granted, $partial, $revoked, $missing] = $this->students;
        RecordingConsent::factory()->forForm($this->form)->create(['student_id' => $granted->getKey()]);
        RecordingConsent::factory()->forForm($this->form)->agreedTo(['audio'])->create(['student_id' => $partial->getKey()]);
        RecordingConsent::factory()->forForm($this->form)->revoked()->create(['student_id' => $revoked->getKey()]);

        $page = $this->page()
            ->assertSee('1 von 4 Einwilligungen vollständig')
            ->assertSeeInOrder([$granted->name, 'Erteilt'])
            ->assertSee('Teilweise')
            ->assertSee('Widerrufen')
            ->assertSee('Fehlt')
            ->assertSee('Noch kein Brief erzeugt');

        $page->assertCanSeeTableRecords($this->students);
    }

    public function test_outdated_consents_and_missing_student_confirmations_are_labelled(): void
    {
        $this->school->forceFill(['require_student_consent' => true])->save();
        [$confirmed, $unconfirmed, $old] = $this->students;
        RecordingConsent::factory()->forForm($this->form)->studentConfirmed()->create(['student_id' => $confirmed->getKey()]);
        RecordingConsent::factory()->forForm($this->form)->create(['student_id' => $unconfirmed->getKey()]);
        RecordingConsent::factory()->forForm($this->form)->studentConfirmed()->create(['student_id' => $old->getKey()]);
        $this->publishedForm($this->school, 2);

        $this->page()
            ->assertSee('Veraltet')
            ->assertSee('Fassung 1 (aktuell: 2)')
            ->assertSee('0 von 4 Einwilligungen vollständig');
    }

    public function test_the_student_confirmation_label_shows_for_parents_who_consented_to_the_current_form(): void
    {
        $this->school->forceFill(['require_student_consent' => true])->save();
        RecordingConsent::factory()->forForm($this->form)->create(['student_id' => $this->students->first()->getKey()]);

        $this->page()
            ->assertSee('Schüler:in-Bestätigung fehlt')
            ->assertSee('Zusätzliche Bestätigung der Schüler:innen');
    }

    public function test_only_students_of_the_selected_class_are_listed(): void
    {
        $other = User::factory()->student(SchoolClass::factory()->create(['school_id' => $this->school->getKey(), 'teacher_id' => $this->teacher->getKey()]))->create();

        $this->page(schoolClass: $this->schoolClass)
            ->assertCanSeeTableRecords($this->students)
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_a_paper_consent_is_recorded_with_who_entered_it(): void
    {
        $student = $this->students->first();

        $this->page()
            ->callAction(TestAction::make('recordPaperConsent')->table($student), [
                'guardian_name' => 'Erika Muster',
                'purposes' => ['audio', 'transcript'],
                'given_on' => today()->toDateString(),
            ])
            ->assertHasNoFormErrors();

        $consent = RecordingConsent::query()->where('student_id', $student->getKey())->firstOrFail();
        $this->assertSame(RecordingConsent::SOURCE_PAPER, $consent->source);
        $this->assertSame('Erika Muster', $consent->guardian_name);
        $this->assertSame(['audio', 'transcript'], $consent->purposes);
        $this->assertSame($this->form->getKey(), $consent->consent_form_id);
        $this->assertTrue($consent->recorder->is($this->teacher));
        $this->assertTrue($consent->granted_at->isToday());
        $this->assertSame($this->school->getKey(), $consent->school_id);
        $this->assertNull($consent->token_hash);
    }

    public function test_a_paper_consent_keeps_the_signature_date(): void
    {
        $student = $this->students->first();
        $this->form->forceFill(['published_at' => now()->subDays(10)])->save();

        $this->page()->callAction(TestAction::make('recordPaperConsent')->table($student), [
            'guardian_name' => 'Erika Muster',
            'purposes' => ['audio'],
            'given_on' => now()->subDays(3)->toDateString(),
        ]);

        $this->assertSame(now()->subDays(3)->toDateString(), RecordingConsent::query()->firstOrFail()->granted_at->toDateString());
    }

    public function test_an_old_paper_slip_cannot_override_a_later_online_revocation(): void
    {
        $student = $this->students->first();
        $this->form->forceFill(['published_at' => now()->subDays(20)])->save();
        $consent = RecordingConsent::factory()->forForm($this->form)->create([
            'student_id' => $student->getKey(),
            'granted_at' => now()->subDays(8),
            'revoked_at' => now()->subDays(4),
        ]);

        $this->page()
            ->callAction(TestAction::make('recordPaperConsent')->table($student), [
                'guardian_name' => 'Erika Muster',
                'purposes' => ['audio', 'transcript', 'ai_analysis'],
                'given_on' => now()->subDays(10)->toDateString(),
            ])
            ->assertHasFormErrors(['given_on']);

        $consent->refresh();
        $this->assertNotNull($consent->revoked_at);
        $this->assertTrue($consent->granted_at->isSameDay(now()->subDays(8)));
        $this->assertSame(RecordingConsent::SOURCE_DIGITAL, $consent->source);
        $this->assertFalse(RecordingConsentGate::check($this->schoolClass, [$student], $this->teacher)->allowed);
    }

    public function test_a_paper_slip_signed_after_the_revocation_is_a_new_consent(): void
    {
        $student = $this->students->first();
        $this->form->forceFill(['published_at' => now()->subDays(20)])->save();
        $consent = RecordingConsent::factory()->forForm($this->form)->create([
            'student_id' => $student->getKey(),
            'granted_at' => now()->subDays(8),
            'revoked_at' => now()->subDays(4),
        ]);

        $this->page()
            ->callAction(TestAction::make('recordPaperConsent')->table($student), [
                'guardian_name' => 'Erika Muster',
                'purposes' => ['audio', 'transcript', 'ai_analysis'],
                'given_on' => now()->subDays(2)->toDateString(),
            ])
            ->assertHasNoFormErrors();

        $consent->refresh();
        $this->assertNull($consent->revoked_at);
        $this->assertSame(RecordingConsent::SOURCE_PAPER, $consent->source);
        $this->assertTrue(RecordingConsentGate::check($this->schoolClass, [$student], $this->teacher)->allowed);
    }

    public function test_an_old_paper_slip_cannot_widen_a_later_narrower_online_consent(): void
    {
        $student = $this->students->first();
        $this->form->forceFill(['published_at' => now()->subDays(20)])->save();
        $consent = RecordingConsent::factory()->forForm($this->form)->agreedTo(['audio'])->create([
            'student_id' => $student->getKey(),
            'granted_at' => now()->subDays(2),
        ]);

        $this->page()
            ->callAction(TestAction::make('recordPaperConsent')->table($student), [
                'guardian_name' => 'Erika Muster',
                'purposes' => ['audio', 'transcript', 'ai_analysis'],
                'given_on' => now()->subDays(5)->toDateString(),
            ])
            ->assertHasFormErrors(['given_on']);

        $this->assertSame(['audio'], $consent->refresh()->purposes);
        $this->assertSame(RecordingConsent::SOURCE_DIGITAL, $consent->source);
    }

    public function test_a_paper_slip_may_be_dated_on_the_day_of_the_latest_declaration(): void
    {
        $student = $this->students->first();
        RecordingConsent::factory()->forForm($this->form)->agreedTo(['audio'])->create(['student_id' => $student->getKey(), 'granted_at' => now()->subHours(3)]);

        $this->page()
            ->callAction(TestAction::make('recordPaperConsent')->table($student), [
                'guardian_name' => 'Erika Muster',
                'purposes' => ['audio', 'transcript'],
                'given_on' => today()->toDateString(),
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(['audio', 'transcript'], RecordingConsent::query()->firstOrFail()->purposes);
    }

    public function test_the_paper_modal_names_the_latest_declaration(): void
    {
        $student = $this->students->first();
        RecordingConsent::factory()->forForm($this->form)->create([
            'student_id' => $student->getKey(),
            'granted_at' => now()->subDays(8),
            'revoked_at' => now()->subDays(4),
        ]);

        $this->page()
            ->mountAction(TestAction::make('recordPaperConsent')->table($student))
            ->assertMountedActionModalSee('Die Eltern haben zuletzt am '.now()->subDays(4)->format('d.m.Y').' widerrufen.');
    }

    public function test_a_paper_consent_needs_a_name_and_the_tone_recording(): void
    {
        $student = $this->students->first();

        $this->page()
            ->callAction(TestAction::make('recordPaperConsent')->table($student), ['guardian_name' => '', 'purposes' => ['audio'], 'given_on' => today()->toDateString()])
            ->assertHasFormErrors(['guardian_name']);

        $this->page()
            ->callAction(TestAction::make('recordPaperConsent')->table($student), ['guardian_name' => 'Erika', 'purposes' => ['transcript'], 'given_on' => today()->toDateString()])
            ->assertHasFormErrors(['purposes']);

        $this->page()
            ->callAction(TestAction::make('recordPaperConsent')->table($student), ['guardian_name' => 'Erika', 'purposes' => ['audio'], 'given_on' => today()->addDay()->toDateString()])
            ->assertHasFormErrors(['given_on']);

        $this->assertDatabaseCount('recording_consents', 0);
    }

    public function test_a_paper_consent_replaces_an_open_letter_and_keeps_its_link(): void
    {
        $student = $this->students->first();
        $consent = RecordingConsent::factory()->notAnswered()->create(['student_id' => $student->getKey()]);
        $token = $consent->regenerateToken();

        $this->page()->callAction(TestAction::make('recordPaperConsent')->table($student), [
            'guardian_name' => 'Erika Muster',
            'purposes' => ['audio'],
            'given_on' => today()->toDateString(),
        ]);

        $this->assertSame(1, RecordingConsent::query()->count());
        $this->assertNotNull(RecordingConsent::findByToken($token)->granted_at);
    }

    public function test_a_revocation_is_recorded_with_who_entered_it(): void
    {
        $student = $this->students->first();
        $consent = RecordingConsent::factory()->forForm($this->form)->create(['student_id' => $student->getKey()]);

        $this->page()
            ->callAction(TestAction::make('recordRevocation')->table($student), ['revoked_on' => today()->toDateString()])
            ->assertHasNoFormErrors();

        $consent->refresh();
        $this->assertTrue($consent->revoked_at->isToday());
        $this->assertTrue($consent->recorder->is($this->teacher));
    }

    public function test_the_revocation_is_only_offered_for_a_given_consent(): void
    {
        [$granted, $missing, $revoked] = $this->students;
        RecordingConsent::factory()->forForm($this->form)->create(['student_id' => $granted->getKey()]);
        RecordingConsent::factory()->forForm($this->form)->revoked()->create(['student_id' => $revoked->getKey()]);

        $this->page()
            ->assertActionVisible(TestAction::make('recordRevocation')->table($granted))
            ->assertActionHidden(TestAction::make('recordRevocation')->table($missing))
            ->assertActionHidden(TestAction::make('recordRevocation')->table($revoked));
    }

    public function test_actions_work_on_students_of_the_selected_class_only(): void
    {
        $other = User::factory()->student(SchoolClass::factory()->create(['school_id' => $this->school->getKey()]))->create();

        try {
            $this->page()->callAction(TestAction::make('recordPaperConsent')->table($other), [
                'guardian_name' => 'Erika Muster',
                'purposes' => ['audio'],
                'given_on' => today()->toDateString(),
            ]);
        } catch (Throwable) {
            // The record is not part of the table, so Filament refuses the call.
        }

        $this->assertDatabaseMissing('recording_consents', ['student_id' => $other->getKey()]);
    }

    public function test_without_a_published_form_paper_consents_and_letters_are_disabled(): void
    {
        $this->form->forceFill(['published_at' => null])->save();

        $this->page()
            ->assertActionDisabled('generateLetters')
            ->assertSee('Es gibt noch keine Einwilligungserklärung')
            ->assertActionDisabled(TestAction::make('recordPaperConsent')->table($this->students->first()));
    }

    public function test_the_letters_form_posts_to_the_letters_route_of_the_selected_class(): void
    {
        $this->actingAs($this->teacher)
            ->get(ClassConsents::getUrl())
            ->assertOk()
            ->assertSee('id="consent-letters-form"', false)
            ->assertSee('action="'.route('consents.letters', $this->schoolClass).'"', false)
            ->assertSee('name="_token"', false);
    }

    public function test_the_letters_action_hands_over_to_the_letters_form(): void
    {
        $this->page()
            ->callAction('generateLetters', ['scope' => 'all'])
            ->assertHasNoErrors();
    }

    public function test_a_teacher_without_a_class_sees_a_hint_instead_of_a_list(): void
    {
        $lonely = User::factory()->for($this->school)->teacher()->create();

        $this->page($lonely)->assertSee('Keine Klasse zugeordnet');
    }

    public function test_the_page_hints_when_recordings_are_not_switched_on_yet(): void
    {
        $this->school->disableRecordings();

        $this->page()->assertSee('noch nicht freigeschaltet');
    }
}
