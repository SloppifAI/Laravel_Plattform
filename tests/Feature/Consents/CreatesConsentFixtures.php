<?php

namespace Tests\Feature\Consents;

use App\Models\ConsentForm;
use App\Models\RecordingConsent;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Builders shared by the consent tests: a school with recordings switched on, a class with its Klassenleitung and students.
 */
trait CreatesConsentFixtures
{
    /**
     * A school with the whole checklist confirmed and recordings switched on, without a consent form.
     */
    protected function schoolWithRecordings(bool $requireStudentConsent = false): School
    {
        $school = School::factory()->create();

        $school->forceFill([
            'recording_checklist' => array_fill_keys(School::RECORDING_CHECKLIST, true),
            'recordings_enabled_at' => now(),
            'require_student_consent' => $requireStudentConsent,
        ])->save();

        return $school;
    }

    protected function publishedForm(School $school, int $version = 1): ConsentForm
    {
        return ConsentForm::factory()->version($version)->create(['school_id' => $school->getKey()]);
    }

    /**
     * @return array{0: SchoolClass, 1: Collection<int, User>, 2: User}
     */
    protected function classWithStudents(School $school, int $students = 3): array
    {
        $teacher = User::factory()->for($school)->teacher()->create();
        $schoolClass = SchoolClass::factory()->create(['school_id' => $school->getKey(), 'teacher_id' => $teacher->getKey()]);

        return [$schoolClass, User::factory()->count($students)->student($schoolClass)->create(), $teacher];
    }

    /**
     * The parents of every given student agreed to everything in the form.
     *
     * @param  iterable<User>  $students
     */
    protected function consentAll(iterable $students, ConsentForm $form): void
    {
        foreach ($students as $student) {
            RecordingConsent::factory()->forForm($form)->create(['student_id' => $student->getKey()]);
        }
    }
}
