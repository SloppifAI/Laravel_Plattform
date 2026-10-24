<?php

namespace Tests\Feature\Consents;

use App\Filament\Pages\MyRecordingConsent;
use App\Models\ConsentForm;
use App\Models\RecordingConsent;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyRecordingConsentTest extends TestCase
{
    use CreatesConsentFixtures;
    use RefreshDatabase;

    private School $school;

    private ConsentForm $form;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = $this->schoolWithRecordings(requireStudentConsent: true);
        $this->form = $this->publishedForm($this->school);
        [, $students] = $this->classWithStudents($this->school, 1);
        $this->student = $students->first();
    }

    public function test_only_students_of_schools_that_ask_for_the_confirmation_get_the_page(): void
    {
        $this->actingAs($this->student)->get(MyRecordingConsent::getUrl())->assertOk()->assertSee('Tuto-Mikro im Unterricht');
        $this->assertTrue(MyRecordingConsent::canAccess());

        $this->school->forceFill(['require_student_consent' => false])->save();
        $this->actingAs($this->student->refresh())->get(MyRecordingConsent::getUrl())->assertForbidden();

        $this->school->forceFill(['require_student_consent' => true])->save();
        $this->school->disableRecordings();
        $this->actingAs($this->student->refresh())->get(MyRecordingConsent::getUrl())->assertForbidden();
    }

    public function test_nobody_else_gets_the_page(): void
    {
        $this->get(MyRecordingConsent::getUrl())->assertRedirect();

        foreach (['teacher', 'admin'] as $role) {
            $this->actingAs(User::factory()->for($this->school)->{$role}()->create())->get(MyRecordingConsent::getUrl())->assertForbidden();
        }

        $this->actingAs(User::factory()->operator()->create())->get(MyRecordingConsent::getUrl())->assertForbidden();
    }

    public function test_before_the_parents_consented_the_student_cannot_confirm(): void
    {
        Livewire::actingAs($this->student)
            ->test(MyRecordingConsent::class)
            ->assertSee('Sobald deine Eltern eingewilligt haben')
            ->assertDontSee('Ich bin einverstanden')
            ->call('confirm')
            ->assertNotified('Deine Eltern müssen zuerst einwilligen');

        $this->assertDatabaseCount('recording_consents', 0);
    }

    public function test_with_the_parents_consent_the_student_confirms_and_withdraws(): void
    {
        $consent = RecordingConsent::factory()->forForm($this->form)->create(['student_id' => $this->student->getKey()]);

        $page = Livewire::actingAs($this->student)
            ->test(MyRecordingConsent::class)
            ->assertSee('Liegt vor')
            ->assertSee('Noch nicht gesagt')
            ->assertSee('Ich bin einverstanden')
            ->call('confirm')
            ->assertNotified('Danke, du bist einverstanden');

        $this->assertNotNull($consent->refresh()->student_confirmed_at);

        $page->assertSee('Ja, seit '.now()->format('d.m.Y'))
            ->assertSee('Ich bin nicht mehr einverstanden')
            ->call('withdraw')
            ->assertNotified('Du bist nicht mehr einverstanden');

        $this->assertNull($consent->refresh()->student_confirmed_at);
    }

    public function test_a_revoked_or_outdated_parent_consent_cannot_be_confirmed(): void
    {
        $consent = RecordingConsent::factory()->forForm($this->form)->revoked()->create(['student_id' => $this->student->getKey()]);

        Livewire::actingAs($this->student)->test(MyRecordingConsent::class)->call('confirm')->assertNotified('Deine Eltern müssen zuerst einwilligen');
        $this->assertNull($consent->refresh()->student_confirmed_at);

        $consent->forceFill(['revoked_at' => null])->save();
        $this->publishedForm($this->school, 2);

        Livewire::actingAs($this->student)->test(MyRecordingConsent::class)->call('confirm')->assertNotified('Deine Eltern müssen zuerst einwilligen');
        $this->assertNull($consent->refresh()->student_confirmed_at);
    }

    public function test_a_student_only_ever_touches_their_own_record(): void
    {
        $classmate = User::factory()->student($this->student->schoolClass)->create();
        $own = RecordingConsent::factory()->forForm($this->form)->create(['student_id' => $this->student->getKey()]);
        $other = RecordingConsent::factory()->forForm($this->form)->create(['student_id' => $classmate->getKey()]);

        Livewire::actingAs($this->student)->test(MyRecordingConsent::class)->call('confirm');

        $this->assertNotNull($own->refresh()->student_confirmed_at);
        $this->assertNull($other->refresh()->student_confirmed_at);
    }

    public function test_other_roles_cannot_call_the_actions(): void
    {
        $teacher = User::factory()->for($this->school)->teacher()->create();

        Livewire::actingAs($teacher)->test(MyRecordingConsent::class)->assertForbidden();
    }

    public function test_the_policy_only_lets_a_student_confirm_their_own_agreement(): void
    {
        $consent = RecordingConsent::factory()->forForm($this->form)->create(['student_id' => $this->student->getKey()]);
        $classmate = User::factory()->student($this->student->schoolClass)->create();
        $admin = User::factory()->for($this->school)->admin()->create();

        $this->assertTrue($this->student->can('confirmOwn', $consent));
        $this->assertFalse($classmate->can('confirmOwn', $consent));
        $this->assertFalse($admin->can('confirmOwn', $consent));
        $this->assertFalse(User::factory()->for($this->school)->teacher()->create()->can('confirmOwn', $consent));
    }

    public function test_the_policies_scope_consents_to_the_own_school_and_classes(): void
    {
        $schoolClass = SchoolClass::factory()->create(['school_id' => $this->school->getKey()]);
        $otherSchoolAdmin = User::factory()->admin()->create();
        $admin = User::factory()->for($this->school)->admin()->create();

        $this->assertTrue($admin->can('manageClass', [RecordingConsent::class, $schoolClass]));
        $this->assertFalse($otherSchoolAdmin->can('manageClass', [RecordingConsent::class, $schoolClass]));
        $this->assertFalse($this->student->can('manageClass', [RecordingConsent::class, $schoolClass]));
        $this->assertFalse($this->student->can('viewAny', RecordingConsent::class));
        $this->assertFalse(User::factory()->operator()->create()->can('viewAny', RecordingConsent::class));

        $this->assertTrue($admin->can('create', [ConsentForm::class, $this->school]));
        $this->assertFalse($otherSchoolAdmin->can('create', [ConsentForm::class, $this->school]));
        $this->assertFalse($admin->can('update', $this->form));
        $this->assertFalse($admin->can('delete', $this->form));
        $this->assertTrue($admin->can('view', $this->form));
        $this->assertFalse($otherSchoolAdmin->can('view', $this->form));
        $this->assertFalse(User::factory()->for($this->school)->teacher()->create()->can('viewAny', ConsentForm::class));
    }
}
