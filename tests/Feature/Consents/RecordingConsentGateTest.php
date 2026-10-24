<?php

namespace Tests\Feature\Consents;

use App\Models\Competency;
use App\Models\ConsentForm;
use App\Models\Lesson;
use App\Models\RecordingConsent;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\RecordingConsentGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class RecordingConsentGateTest extends TestCase
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
        [$this->schoolClass, $this->students, $this->teacher] = $this->classWithStudents($this->school, 3);
        $this->consentAll($this->students, $this->form);
    }

    public function test_everyone_present_has_consented(): void
    {
        $result = RecordingConsentGate::check($this->schoolClass, $this->students, $this->teacher);

        $this->assertTrue($result->allowed);
        $this->assertSame([], $result->reasons);
        $this->assertTrue($result->studentsLackingConsent->isEmpty());
    }

    public function test_the_gate_accepts_any_iterable_of_students(): void
    {
        $generator = (function () {
            yield from $this->students;
        })();

        $this->assertTrue(RecordingConsentGate::check($this->schoolClass, $generator, $this->teacher)->allowed);
    }

    public function test_a_missing_consent_blocks_and_names_the_student(): void
    {
        $newcomer = User::factory()->student($this->schoolClass)->create();

        $result = RecordingConsentGate::check($this->schoolClass, $this->students->push($newcomer), $this->teacher);

        $this->assertFalse($result->allowed);
        $this->assertSame([$newcomer->getKey()], $result->studentsLackingConsent->keys()->all());
        $this->assertStringContainsString('Es liegt keine Einwilligung', $result->studentReasons[$newcomer->getKey()]);
        $this->assertContains('Für 1 von 4 anwesenden Schüler:innen liegt keine gültige Einwilligung vor.', $result->reasons);
    }

    public function test_a_letter_without_an_answer_counts_as_missing(): void
    {
        $student = $this->students->first();
        RecordingConsent::query()->where('student_id', $student->getKey())->delete();
        RecordingConsent::factory()->notAnswered()->create(['student_id' => $student->getKey(), 'consent_form_id' => null]);

        $result = RecordingConsentGate::check($this->schoolClass, $this->students, $this->teacher);

        $this->assertFalse($result->allowed);
        $this->assertSame([$student->getKey()], $result->studentsLackingConsent->keys()->all());
    }

    public function test_a_revoked_consent_blocks(): void
    {
        $student = $this->students->first();
        RecordingConsent::query()->where('student_id', $student->getKey())->firstOrFail()->revoke();

        $result = RecordingConsentGate::check($this->schoolClass, $this->students, $this->teacher);

        $this->assertFalse($result->allowed);
        $this->assertSame('Die Einwilligung wurde widerrufen', $result->studentReasons[$student->getKey()]);
    }

    public function test_a_consent_for_an_older_version_of_the_form_blocks_everyone_until_confirmed_again(): void
    {
        $this->publishedForm($this->school, 2);

        $result = RecordingConsentGate::check($this->schoolClass, $this->students, $this->teacher);

        $this->assertFalse($result->allowed);
        $this->assertCount(3, $result->studentsLackingConsent);
        $this->assertStringContainsString('ältere Fassung', $result->studentReasons[$this->students->first()->getKey()]);
    }

    public function test_a_consent_covering_only_part_of_the_requested_purposes_blocks(): void
    {
        $student = $this->students->first();
        RecordingConsent::query()->where('student_id', $student->getKey())->update(['purposes' => json_encode(['audio'])]);

        $this->assertTrue(RecordingConsentGate::check($this->schoolClass, $this->students, $this->teacher, ['audio'])->allowed);

        $result = RecordingConsentGate::check($this->schoolClass, $this->students, $this->teacher, ['audio', 'transcript']);

        $this->assertFalse($result->allowed);
        $this->assertSame([$student->getKey()], $result->studentsLackingConsent->keys()->all());
        $this->assertStringContainsString('nicht alle nötigen Zwecke', $result->studentReasons[$student->getKey()]);
    }

    public function test_without_a_list_of_purposes_the_check_asks_for_everything_the_form_offers(): void
    {
        $student = $this->students->first();
        RecordingConsent::query()->where('student_id', $student->getKey())->update(['purposes' => json_encode(['audio'])]);

        $result = RecordingConsentGate::check($this->schoolClass, $this->students, $this->teacher);

        $this->assertFalse($result->allowed);
        $this->assertSame([$student->getKey()], $result->studentsLackingConsent->keys()->all());
        $this->assertStringContainsString('nicht alle nötigen Zwecke', $result->studentReasons[$student->getKey()]);

        $this->assertTrue(RecordingConsentGate::check($this->schoolClass, $this->students, $this->teacher, ['audio'])->allowed);
        $this->assertFalse(RecordingConsentGate::check($this->schoolClass, $this->students, $this->teacher, ['audio', 'transcript'])->allowed);
        $this->assertFalse(RecordingConsentGate::check($this->schoolClass, $this->students, $this->teacher, ['audio', 'ai_analysis'])->allowed);
    }

    public function test_the_default_follows_a_form_that_offers_fewer_purposes(): void
    {
        $form = ConsentForm::factory()->version(2)->create(['school_id' => $this->school->getKey(), 'purposes' => ['audio']]);
        RecordingConsent::query()->delete();
        $this->consentAll($this->students, $form);

        $this->assertTrue(RecordingConsentGate::check($this->schoolClass, $this->students, $this->teacher)->allowed);
    }

    public function test_the_tone_recording_is_always_required_whatever_purposes_are_asked_for(): void
    {
        $student = $this->students->first();
        RecordingConsent::query()->where('student_id', $student->getKey())->update(['purposes' => json_encode(['transcript'])]);

        $this->assertFalse(RecordingConsentGate::check($this->schoolClass, $this->students, $this->teacher, ['transcript'])->allowed);
    }

    public function test_an_unknown_purpose_is_a_programming_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RecordingConsentGate::check($this->schoolClass, $this->students, $this->teacher, ['video']);
    }

    public function test_the_students_own_confirmation_is_required_when_the_school_asks_for_it(): void
    {
        $this->school->forceFill(['require_student_consent' => true])->save();
        $confirmed = $this->students->first();
        RecordingConsent::query()->where('student_id', $confirmed->getKey())->firstOrFail()->confirmByStudent();

        $result = RecordingConsentGate::check($this->schoolClass->refresh(), $this->students, $this->teacher);

        $this->assertFalse($result->allowed);
        $this->assertSame(array_values($this->students->skip(1)->modelKeys()), $result->studentsLackingConsent->keys()->all());
        $this->assertStringContainsString('Bestätigung der Schüler:in', $result->studentReasons[$this->students->last()->getKey()]);

        foreach ($this->students as $student) {
            RecordingConsent::query()->where('student_id', $student->getKey())->firstOrFail()->confirmByStudent();
        }

        $this->assertTrue(RecordingConsentGate::check($this->schoolClass->refresh(), $this->students, $this->teacher)->allowed);
    }

    public function test_the_students_confirmation_is_ignored_when_the_school_does_not_ask_for_it(): void
    {
        $this->assertTrue(RecordingConsentGate::check($this->schoolClass, $this->students, $this->teacher)->allowed);
    }

    public function test_recordings_switched_off_block(): void
    {
        $this->school->disableRecordings();

        $result = RecordingConsentGate::check($this->schoolClass->refresh(), $this->students, $this->teacher);

        $this->assertFalse($result->allowed);
        $this->assertContains('Aufnahmen sind für deine Schule nicht freigeschaltet.', $result->reasons);
        $this->assertTrue($result->studentsLackingConsent->isEmpty());
    }

    public function test_an_incomplete_checklist_blocks_even_though_the_switch_is_on(): void
    {
        $this->school->forceFill(['recording_checklist' => [...array_fill_keys(School::RECORDING_CHECKLIST, true), 'dsfa_done' => false]])->save();

        $this->assertFalse(RecordingConsentGate::check($this->schoolClass->refresh(), $this->students, $this->teacher)->allowed);
    }

    public function test_a_blocked_school_blocks(): void
    {
        $this->school->forceFill(['blocked_at' => now()])->save();

        $result = RecordingConsentGate::check($this->schoolClass->refresh(), $this->students, $this->teacher);

        $this->assertFalse($result->allowed);
        $this->assertContains('Die Schule ist gesperrt.', $result->reasons);
    }

    public function test_a_school_without_a_published_form_blocks(): void
    {
        $this->form->forceFill(['published_at' => null])->save();

        $result = RecordingConsentGate::check($this->schoolClass->refresh(), $this->students, $this->teacher);

        $this->assertFalse($result->allowed);
        $this->assertContains('Die Schule hat noch keine Einwilligungserklärung veröffentlicht.', $result->reasons);
    }

    public function test_a_student_from_another_class_blocks_even_with_a_valid_consent(): void
    {
        $otherClass = SchoolClass::factory()->create(['school_id' => $this->school->getKey()]);
        $visitor = User::factory()->student($otherClass)->create();
        $this->consentAll([$visitor], $this->form);

        $result = RecordingConsentGate::check($this->schoolClass, $this->students->push($visitor), $this->teacher);

        $this->assertFalse($result->allowed);
        $this->assertSame([$visitor->getKey()], $result->studentsLackingConsent->keys()->all());
        $this->assertSame('Gehört nicht zu dieser Klasse', $result->studentReasons[$visitor->getKey()]);
    }

    public function test_a_student_of_another_school_blocks(): void
    {
        $otherSchool = $this->schoolWithRecordings();
        $stranger = User::factory()->student(SchoolClass::factory()->create(['school_id' => $otherSchool->getKey()]))->create();
        $this->consentAll([$stranger], $this->publishedForm($otherSchool));

        $this->assertFalse(RecordingConsentGate::check($this->schoolClass, $this->students->push($stranger), $this->teacher)->allowed);
    }

    public function test_a_teacher_who_neither_leads_nor_teaches_the_class_is_blocked(): void
    {
        $colleague = User::factory()->for($this->school)->teacher()->create();

        $result = RecordingConsentGate::check($this->schoolClass, $this->students, $colleague);

        $this->assertFalse($result->allowed);
        $this->assertContains('Du leitest diese Klasse nicht und unterrichtest sie nicht.', $result->reasons);
    }

    public function test_an_assigned_teacher_may_record_but_a_lesson_alone_does_not_count(): void
    {
        $colleague = User::factory()->for($this->school)->teacher()->create();
        Lesson::factory()->create(['school_class_id' => $this->schoolClass->getKey(), 'teacher_id' => $colleague->getKey()]);

        $this->assertFalse(RecordingConsentGate::check($this->schoolClass, $this->students, $colleague)->allowed);

        $this->schoolClass->teachers()->attach($colleague);

        $this->assertTrue(RecordingConsentGate::check($this->schoolClass, $this->students, $colleague)->allowed);
    }

    public function test_the_lessons_subject_blocks_when_the_school_blocks_it(): void
    {
        $lesson = Lesson::factory()->create(['school_class_id' => $this->schoolClass->getKey(), 'teacher_id' => $this->teacher->getKey(), 'subject' => '  evangelische religion ']);
        $reason = 'Im Fach evangelische religion ist Tuto-Mikro an deiner Schule nicht möglich.';
        $this->school->forceFill(['mikro_blocked_subjects' => ['Kunst']])->save();

        $this->assertTrue(RecordingConsentGate::check($this->schoolClass->fresh(), $this->students, $this->teacher, null, $lesson->fresh())->allowed);
        $this->assertNull(RecordingConsentGate::lessonRefusal($lesson));

        $this->school->forceFill(['mikro_blocked_subjects' => ['Evangelische Religion']])->save();
        $result = RecordingConsentGate::check($this->schoolClass->fresh(), $this->students, $this->teacher, null, $lesson->fresh());

        $this->assertFalse($result->allowed);
        $this->assertSame([$reason], $result->reasons);
        $this->assertSame($reason, RecordingConsentGate::lessonRefusal($lesson->fresh()));
    }

    public function test_a_foerderaufgabe_never_gets_the_microphone(): void
    {
        $task = Lesson::factory()->create(['school_class_id' => $this->schoolClass->getKey(), 'teacher_id' => $this->teacher->getKey(), 'practice_competency_id' => Competency::factory()]);

        $result = RecordingConsentGate::check($this->schoolClass, $this->students, $this->teacher, null, $task);

        $this->assertFalse($result->allowed);
        $this->assertSame(['Für Förderaufgaben gibt es kein Tuto-Mikro.'], $result->reasons);
    }

    public function test_only_teachers_of_the_school_may_record(): void
    {
        $outsider = User::factory()->teacher()->create();
        $admin = User::factory()->for($this->school)->admin()->create();

        $this->assertFalse(RecordingConsentGate::check($this->schoolClass, $this->students, $outsider)->allowed);
        $this->assertFalse(RecordingConsentGate::check($this->schoolClass, $this->students, $admin)->allowed);
    }

    public function test_nobody_present_is_not_enough(): void
    {
        $result = RecordingConsentGate::check($this->schoolClass, [], $this->teacher);

        $this->assertFalse($result->allowed);
        $this->assertContains('Es sind keine anwesenden Schüler:innen angegeben.', $result->reasons);
    }

    public function test_only_the_students_present_count(): void
    {
        RecordingConsent::query()->where('student_id', $this->students->first()->getKey())->delete();

        $this->assertTrue(RecordingConsentGate::check($this->schoolClass, $this->students->skip(1), $this->teacher)->allowed);
        $this->assertFalse(RecordingConsentGate::check($this->schoolClass, $this->students, $this->teacher)->allowed);
    }

    public function test_the_check_needs_a_constant_number_of_queries(): void
    {
        $many = User::factory()->count(20)->student($this->schoolClass)->create();
        $this->consentAll($many, $this->form);

        DB::enableQueryLog();
        RecordingConsentGate::check($this->schoolClass, $this->students, $this->teacher);
        $few = count(DB::getQueryLog());

        DB::flushQueryLog();
        RecordingConsentGate::check($this->schoolClass, $this->students->concat($many), $this->teacher);

        $this->assertLessThanOrEqual($few, count(DB::getQueryLog()));
    }
}
