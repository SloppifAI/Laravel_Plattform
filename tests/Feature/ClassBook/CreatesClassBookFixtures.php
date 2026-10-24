<?php

namespace Tests\Feature\ClassBook;

use App\Models\ConsentForm;
use App\Models\Lesson;
use App\Models\RecordingConsent;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Builders shared by the Klassenbuch tests: a class with its Klassenleitung and students, and lessons held in it.
 */
trait CreatesClassBookFixtures
{
    /**
     * @return array{0: SchoolClass, 1: Collection<int, User>, 2: User}
     */
    protected function classWithStudents(int $students = 3, ?School $school = null, string $name = '5a'): array
    {
        $school ??= School::factory()->create();
        $teacher = User::factory()->for($school)->teacher()->create();
        $schoolClass = SchoolClass::factory()->create(['school_id' => $school->getKey(), 'teacher_id' => $teacher->getKey(), 'name' => $name]);

        return [$schoolClass, User::factory()->count($students)->student($schoolClass)->create(), $teacher];
    }

    /**
     * The parents of the students agreed to everything in the current consent form of the class's school (published on
     * the first call), so Tuto-Mikro could have been started with them.
     *
     * @param  iterable<User>  $students
     */
    protected function giveConsent(SchoolClass $schoolClass, iterable $students): void
    {
        $school = $schoolClass->school;
        $form = $school->currentConsentForm() ?? ConsentForm::factory()->create(['school_id' => $school->getKey()]);

        foreach ($students as $student) {
            RecordingConsent::factory()->forForm($form)->create(['student_id' => $student->getKey()]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function lessonOf(SchoolClass $schoolClass, User $teacher, array $attributes = []): Lesson
    {
        return Lesson::factory()->create(['school_class_id' => $schoolClass->getKey(), 'teacher_id' => $teacher->getKey(), ...$attributes]);
    }
}
