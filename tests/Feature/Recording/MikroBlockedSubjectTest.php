<?php

namespace Tests\Feature\Recording;

use App\Filament\Pages\RecordLesson;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Support\RecordingConsentGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Subjects without Tuto-Mikro (the school's list, by default Religion, Ethik, ...) and Förderaufgaben: the start, the
 * check during a session and the page all refuse, with a reason for the teacher.
 */
class MikroBlockedSubjectTest extends TestCase
{
    use RecordingFixtures;
    use RefreshDatabase;

    private const string REASON = 'Tuto-Mikro is not possible in Religion at your school.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLesson();
    }

    private function blockSubjects(?array $subjects): void
    {
        $this->schoolClass->school->forceFill(['mikro_blocked_subjects' => $subjects])->save();
    }

    private function changeSubject(string $subject): void
    {
        $this->lesson->update(['subject' => $subject]);
    }

    public function test_the_suggested_subjects_are_blocked_until_the_school_chooses_its_own_list(): void
    {
        foreach (['Religion', 'Ethik', 'Philosophie', 'Sexualkunde', 'Sexualerziehung', 'Werte und Normen', 'LER'] as $subject) {
            $this->changeSubject($subject);

            $this->startSession()->assertConflict()->assertJsonPath('message', "Tuto-Mikro is not possible in {$subject} at your school.");
        }

        $this->assertSame(0, LessonRecording::query()->count());

        $this->blockSubjects([]);

        $this->startSession()->assertCreated();
    }

    public function test_matching_ignores_case_and_surrounding_spaces(): void
    {
        $this->blockSubjects(['Religion', ' Kunst ']);

        foreach (['religion', '  RELIGION  ', 'kunst'] as $subject) {
            $this->changeSubject($subject);
            $this->startSession()->assertConflict();
        }
    }

    public function test_odd_and_doubled_spaces_do_not_get_around_the_block(): void
    {
        $this->blockSubjects(['Evangelische Religion', 'Werte und Normen']);

        // A non-breaking space at the end, between the words, and a doubled space: the lesson form stores what was typed.
        foreach (["Evangelische Religion\u{00A0}", "Evangelische\u{00A0}Religion", 'Evangelische  Religion', "\u{00A0}Werte  und\tNormen "] as $subject) {
            $this->changeSubject($subject);
            $this->startSession()->assertConflict();
        }

        $this->assertSame(0, LessonRecording::query()->count());
    }

    public function test_the_official_names_of_the_subjects_are_blocked_by_the_suggested_list(): void
    {
        foreach (['Katholische Religion', 'Ev. Religionslehre', 'Kath. Religionslehre', 'Islamischer Religionsunterricht', 'Praktische Philosophie', 'Lebensgestaltung-Ethik-Religionskunde'] as $subject) {
            $this->changeSubject($subject);
            $this->startSession()->assertConflict();
        }
    }

    public function test_a_blocked_name_only_blocks_words_that_begin_with_it_and_short_names_the_whole_word(): void
    {
        // "LER" is on the suggested list: it must not block "Lernen" or "Keller", "Ethik" not "Mathematik".
        foreach (['Lernen', 'Lerncoaching', 'Keller', 'Mathematik', 'Deutsch'] as $subject) {
            $this->changeSubject($subject);
            $this->startSession()->assertCreated();
        }

        $this->changeSubject('LER');
        $this->startSession()->assertConflict();
    }

    public function test_the_schools_own_list_replaces_the_suggestion(): void
    {
        $this->blockSubjects(['Kunst']);

        $this->changeSubject('Religion');
        $this->startSession()->assertCreated();

        $this->changeSubject('Kunst');
        $this->startSession()->assertConflict();
    }

    public function test_the_list_of_another_school_does_not_count(): void
    {
        $this->blockSubjects([]);
        $other = Lesson::factory()->create(['subject' => 'Biologie']);
        $other->schoolClass->school->forceFill(['mikro_blocked_subjects' => ['Biologie']])->save();

        $this->startSession()->assertCreated();
    }

    public function test_the_check_during_a_session_stops_it_when_the_subject_becomes_blocked(): void
    {
        $this->blockSubjects([]);
        $recording = $this->startedSession();

        $this->postJson(route('recordings.check', $recording))->assertOk();

        $this->blockSubjects(['Biologie']);

        $this->postJson(route('recordings.check', $recording))
            ->assertConflict()
            ->assertJsonPath('message', 'Tuto-Mikro is not possible in Biologie at your school.');
    }

    public function test_the_page_does_not_open_for_a_blocked_subject_even_by_url(): void
    {
        $this->page()->assertOk();

        $this->changeSubject('Religion');

        $this->actingAs($this->teacher)->get($this->pageUrl())->assertForbidden();
        $this->page()->assertForbidden();

        $this->blockSubjects([]);

        $this->actingAs($this->teacher)->get($this->pageUrl())->assertOk();
    }

    public function test_the_lesson_refusal_is_there_for_the_lesson_screen_to_ask(): void
    {
        $this->assertNull(RecordingConsentGate::lessonRefusal($this->lesson));

        $this->changeSubject('Ethik');

        $this->assertSame('Tuto-Mikro is not possible in Ethik at your school.', RecordingConsentGate::lessonRefusal($this->lesson->refresh()));
    }

    public function test_a_foerderaufgabe_has_no_page_and_cannot_start(): void
    {
        $task = Lesson::factory()->for($this->schoolClass)->for($this->teacher, 'teacher')->create(['practice_competency_id' => Competency::factory()]);

        $this->actingAs($this->teacher)->get($this->pageUrl($task))->assertForbidden();

        $this->actingAs($this->teacher)
            ->postJson(route('recordings.store', $task), ['present' => $this->students->modelKeys(), 'attendance_confirmed' => true])
            ->assertConflict()
            ->assertJsonPath('message', 'There is no Tuto-Mikro for practice tasks.');

        $this->assertSame(0, LessonRecording::query()->count());
    }

    private function page()
    {
        return Livewire::actingAs($this->teacher)->test(RecordLesson::class, ['lesson' => Lesson::findOrFail($this->lesson->getKey())]);
    }
}
