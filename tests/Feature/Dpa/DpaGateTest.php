<?php

namespace Tests\Feature\Dpa;

use App\Filament\Pages\MySchool;
use App\Filament\Resources\Users\UserResource;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Processing without a contract under Art. 28 Abs. 3 DSGVO is what the agreement is there to prevent, so once the
 * contract is released a school that never accepted it can use nothing but "Meine Schule", where it accepts.
 */
class DpaGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['lernplattform.dpa.final' => true]);
    }

    /**
     * A member of the school. A student comes from the factory with a class of its own, so the class has to be the school's.
     */
    private function member(School $school, string $role): User
    {
        return $role === 'student'
            ? User::factory()->student(SchoolClass::factory()->for($school)->create())->create()
            : User::factory()->for($school)->{$role}()->create();
    }

    public function test_the_school_administration_is_sent_to_my_school_from_every_other_page(): void
    {
        $school = School::factory()->create();
        $this->actingAs(User::factory()->for($school)->admin()->create());

        $this->get('/')->assertRedirect(MySchool::getUrl());
        $this->get(UserResource::getUrl())->assertRedirect(MySchool::getUrl());
    }

    public function test_the_school_administration_can_open_my_school_and_the_contract_and_accept(): void
    {
        $school = School::factory()->create();
        $admin = User::factory()->for($school)->admin()->create();
        $this->actingAs($admin);

        $this->get(MySchool::getUrl())->assertOk()->assertSee('Noch nicht angenommen');
        $this->get(route('legal.dpa.school'))->assertOk();
        $this->get(route('legal.dpa'))->assertOk();

        $school->acceptDpa($admin);
        $this->actingAs($admin->fresh());

        $this->get('/')->assertOk();
        $this->get(UserResource::getUrl())->assertOk();
    }

    public function test_teachers_and_students_get_a_403_that_names_the_reason(): void
    {
        $school = School::factory()->create();

        foreach (['teacher', 'student'] as $role) {
            $this->actingAs($this->member($school, $role));

            $this->get('/')
                ->assertForbidden()
                ->assertSee('Deine Schule hat den Auftragsverarbeitungsvertrag noch nicht angenommen.')
                ->assertSee('Bitte wende dich an die Schuladministration deiner Schule.');

            $this->get(MySchool::getUrl())->assertForbidden();
        }
    }

    public function test_everybody_can_still_sign_out(): void
    {
        $school = School::factory()->create();
        $this->actingAs(User::factory()->for($school)->teacher()->create());

        $this->post(route('filament.lernplattform.auth.logout'))->assertRedirect();

        $this->assertGuest();
    }

    public function test_an_outdated_acceptance_holds_nobody_back_because_the_accepted_version_stays_in_force(): void
    {
        $school = School::factory()->create();
        $school->forceFill(['dpa_version' => '2025-09', 'dpa_accepted_at' => now()])->save();
        $this->actingAs(User::factory()->for($school)->teacher()->create());

        $this->assertTrue($school->hasAcceptedDpa());
        $this->assertFalse($school->hasAcceptedCurrentDpa());
        $this->assertFalse($school->mustAcceptDpa());

        $this->get('/')->assertOk();
    }

    public function test_a_school_that_accepted_is_not_held_back(): void
    {
        $school = School::factory()->create();
        $school->acceptDpa(User::factory()->for($school)->admin()->create());

        foreach (['teacher', 'student'] as $role) {
            $this->actingAs($this->member($school, $role));

            $this->get('/')->assertOk();
        }
    }

    public function test_nobody_is_held_back_while_the_contract_is_a_draft(): void
    {
        config(['lernplattform.dpa.final' => false]);
        $school = School::factory()->create();

        $this->assertFalse($school->mustAcceptDpa());

        foreach (['admin', 'teacher', 'student'] as $role) {
            $this->actingAs($this->member($school, $role));

            $this->get('/')->assertOk();
        }
    }

    public function test_the_operator_has_no_school_and_is_not_held_back(): void
    {
        $this->actingAs(User::factory()->operator()->create());

        $this->get('/')->assertOk();
    }

    public function test_another_school_that_accepted_does_not_free_a_school_that_did_not(): void
    {
        $accepted = School::factory()->create();
        $accepted->acceptDpa(User::factory()->for($accepted)->admin()->create());
        $open = School::factory()->create();

        $this->actingAs(User::factory()->for($open)->teacher()->create());

        $this->get('/')->assertForbidden();
    }

    public function test_recordings_stay_off_for_a_school_that_never_accepted(): void
    {
        $school = School::factory()->create();
        $school->forceFill([
            'recordings_enabled_at' => now(),
            'recording_checklist' => array_fill_keys(School::RECORDING_CHECKLIST, true),
        ])->save();

        $this->assertFalse($school->recordingsEnabled());

        $school->acceptDpa(User::factory()->for($school)->admin()->create());

        $this->assertTrue($school->refresh()->recordingsEnabled());

        config(['lernplattform.dpa.final' => false]);
        $open = School::factory()->create();
        $open->forceFill([
            'recordings_enabled_at' => now(),
            'recording_checklist' => array_fill_keys(School::RECORDING_CHECKLIST, true),
        ])->save();

        $this->assertTrue($open->recordingsEnabled(), 'A draft cannot be required.');
    }
}
