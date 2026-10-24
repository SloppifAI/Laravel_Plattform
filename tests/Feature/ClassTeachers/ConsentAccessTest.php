<?php

namespace Tests\Feature\ClassTeachers;

use App\Filament\Pages\ClassConsents;
use App\Models\Lesson;
use App\Models\RecordingConsent;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\RecordingConsentGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Consents of a class follow the class assignment like the learning data: holding a lesson in a class (even a draft)
 * grants no access, and detaching a teacher takes it away.
 */
class ConsentAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private SchoolClass $colleagueClass;

    protected function setUp(): void
    {
        parent::setUp();

        $school = School::factory()->create();
        $this->teacher = User::factory()->teacher()->for($school)->create();
        $this->colleagueClass = SchoolClass::factory()->for($school)->create();
    }

    public function test_a_lesson_alone_grants_no_access_to_the_consents_of_a_class(): void
    {
        Lesson::factory()->for($this->colleagueClass)->for($this->teacher, 'teacher')->create();

        $this->assertFalse(RecordingConsentGate::teachesClass($this->teacher, $this->colleagueClass));
        $this->assertFalse($this->teacher->can('manageClass', [RecordingConsent::class, $this->colleagueClass]));
        $this->assertSame(0, $this->consentsPage()->instance()->allowedClasses()->count());
        $this->consentsPage($this->colleagueClass)->assertForbidden();
    }

    public function test_an_assigned_teacher_manages_the_consents_until_they_are_detached(): void
    {
        $this->colleagueClass->teachers()->attach($this->teacher);
        Lesson::factory()->for($this->colleagueClass)->for($this->teacher, 'teacher')->create();

        $this->assertTrue(RecordingConsentGate::teachesClass($this->teacher, $this->colleagueClass));
        $this->assertTrue($this->teacher->can('manageClass', [RecordingConsent::class, $this->colleagueClass]));
        $this->assertSame([$this->colleagueClass->id], $this->consentsPage()->instance()->allowedClasses()->pluck('id')->all());
        $this->consentsPage($this->colleagueClass)->assertSuccessful();

        $this->colleagueClass->teachers()->detach($this->teacher);

        $this->assertFalse(RecordingConsentGate::teachesClass($this->teacher, $this->colleagueClass));
        $this->assertFalse($this->teacher->can('manageClass', [RecordingConsent::class, $this->colleagueClass]));
        $this->consentsPage($this->colleagueClass)->assertForbidden();
    }

    private function consentsPage(?SchoolClass $schoolClass = null)
    {
        return Livewire::actingAs($this->teacher)
            ->withQueryParams($schoolClass ? ['klasse' => $schoolClass->getKey()] : [])
            ->test(ClassConsents::class);
    }
}
