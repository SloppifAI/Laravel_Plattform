<?php

namespace Tests\Feature\Recording;

use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Models\RecordingConsent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Starting Tuto-Mikro: the school's switch, the consent of every present student and the lesson's own teacher, all on the server.
 */
class MikroStartTest extends TestCase
{
    use RecordingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLesson();
    }

    public function test_a_session_starts_with_all_consents_and_stores_the_snapshot(): void
    {
        $this->travelTo(now()->setTime(10, 15));

        $response = $this->startSession()->assertCreated();

        $recording = LessonRecording::sole();
        $this->assertSame($this->teacher->id, $recording->teacher_id);
        $this->assertSame($this->lesson->id, $recording->lesson_id);
        $this->assertEquals(now(), $recording->started_at);
        $this->assertNull($recording->finished_at);
        $this->assertSame($this->students->modelKeys(), $recording->present_student_ids);
        $this->assertEquals(now(), $recording->attendance_confirmed_at);
        $this->assertSame($this->form->version, $recording->consent_form_version);
        $this->assertSame(['audio', 'transcript'], $recording->purposes);
        $this->assertFalse($recording->audio_sent_to_ai);

        $response->assertJson([
            'id' => $recording->id,
            'transcribes' => false,
            'segment_seconds' => 25,
            'segment_url' => route('recordings.segment', $recording),
            'check_url' => route('recordings.check', $recording),
            'end_url' => route('recordings.finish', $recording),
            'analyse_url' => route('recordings.analyse', $recording),
        ]);
    }

    public function test_only_the_students_marked_as_present_count(): void
    {
        RecordingConsent::query()->where('student_id', $this->students[2]->id)->delete();

        $this->startSession($this->students->take(2))->assertCreated();

        $this->assertSame([$this->students[0]->id, $this->students[1]->id], LessonRecording::sole()->present_student_ids);
    }

    public function test_with_the_school_ai_the_ai_analysis_is_part_of_what_is_checked(): void
    {
        $this->enableAi();

        $this->startSession()->assertCreated()->assertJson(['transcribes' => true]);

        $this->assertSame(['audio', 'transcript', 'ai_analysis'], LessonRecording::sole()->purposes);
    }

    public function test_a_session_is_refused_without_the_consent_of_a_present_student(): void
    {
        RecordingConsent::query()->where('student_id', $this->students[1]->id)->delete();

        $response = $this->startSession()->assertStatus(409);

        $this->assertStringContainsString('1 of 3', $response->json('message'));
        $this->assertSame(0, LessonRecording::count());

        // The same student, marked as absent: the lesson can start.
        $this->startSession($this->students->where('id', '!=', $this->students[1]->id))->assertCreated();
    }

    public function test_a_revoked_or_outdated_consent_refuses_the_session(): void
    {
        RecordingConsent::query()->where('student_id', $this->students[0]->id)->update(['revoked_at' => now()]);
        $this->startSession()->assertStatus(409);

        RecordingConsent::query()->where('student_id', $this->students[0]->id)->update(['revoked_at' => null]);
        $this->startSession()->assertCreated();

        $this->form->school->consentForms()->create([
            'version' => 2, 'title' => 'Neu', 'body' => 'Neu', 'purposes' => $this->form->purposes, 'published_at' => now(),
        ]);
        $this->startSession()->assertStatus(409);

        $this->assertSame(1, LessonRecording::count());
    }

    public function test_a_consent_without_the_ai_analysis_is_enough_only_while_the_ai_is_off(): void
    {
        RecordingConsent::query()->where('student_id', $this->students[0]->id)->update(['purposes' => ['audio', 'transcript']]);

        $this->startSession()->assertCreated();

        $this->enableAi();

        $this->startSession()->assertStatus(409);
        $this->assertSame(1, LessonRecording::count());
    }

    public function test_a_consent_without_the_transcript_refuses_the_session(): void
    {
        RecordingConsent::query()->where('student_id', $this->students[0]->id)->update(['purposes' => ['audio']]);

        $this->startSession()->assertStatus(409);
    }

    public function test_a_session_needs_the_school_switch(): void
    {
        $this->switchRecordings($this->schoolClass->school, false);

        $this->startSession()->assertForbidden();

        $this->assertSame(0, LessonRecording::count());
    }

    public function test_the_gate_wording_never_says_recording(): void
    {
        $this->switchRecordings($this->schoolClass->school, false);
        RecordingConsent::query()->where('student_id', $this->students[0]->id)->delete();

        $check = LessonRecording::consentCheck($this->lesson, $this->teacher, $this->students);

        $this->assertFalse($check->allowed);
        $this->assertContains('Tuto-Mikro is not enabled for your school.', $check->reasons);

        foreach ([...$check->reasons, ...$check->studentReasons] as $text) {
            $this->assertDoesNotMatchRegularExpression('/aufnahm|aufnehm|mitschnitt|transkript|\brecord|transcript|monitor|listen in/i', $text);
        }
    }

    public function test_the_present_students_must_be_given_and_must_belong_to_the_class(): void
    {
        $this->actingAs($this->teacher);
        $url = route('recordings.store', $this->lesson);
        $foreigner = User::factory()->student()->create();

        $confirmed = ['attendance_confirmed' => true];

        $this->postJson($url, $confirmed)->assertUnprocessable()->assertJsonValidationErrors('present');
        $this->postJson($url, ['present' => [], ...$confirmed])->assertUnprocessable()->assertJsonValidationErrors('present');
        $this->postJson($url, ['present' => ['abc'], ...$confirmed])->assertUnprocessable()->assertJsonValidationErrors('present.0');
        $this->postJson($url, ['present' => [$this->students[0]->id, $foreigner->id], ...$confirmed])->assertUnprocessable()->assertJsonMissingValidationErrors('present');
        $this->postJson($url, ['present' => [$this->students[0]->id, 999999], ...$confirmed])->assertUnprocessable()->assertJsonMissingValidationErrors('present');
        $this->postJson($url, ['present' => [$this->teacher->id], ...$confirmed])->assertUnprocessable()->assertJsonMissingValidationErrors('present');

        $this->assertSame(0, LessonRecording::count());
    }

    public function test_a_session_needs_the_teachers_confirmation_of_the_attendance_list(): void
    {
        $this->actingAs($this->teacher);
        $url = route('recordings.store', $this->lesson);
        $present = $this->students->modelKeys();

        $this->postJson($url, ['present' => $present])->assertUnprocessable()->assertJsonValidationErrors('attendance_confirmed');
        $this->postJson($url, ['present' => $present, 'attendance_confirmed' => false])->assertUnprocessable()->assertJsonValidationErrors('attendance_confirmed');
        $this->postJson($url, ['present' => $present, 'attendance_confirmed' => 'no'])->assertUnprocessable()->assertJsonValidationErrors('attendance_confirmed');
        $this->startSession(attendanceConfirmed: false)->assertUnprocessable()->assertJsonPath('message', 'Confirm that only the ticked students are in the room.');

        $this->assertSame(0, LessonRecording::count());

        $this->startSession()->assertCreated();

        $this->assertNotNull(LessonRecording::sole()->attendance_confirmed_at);
    }

    public function test_nobody_but_the_lessons_teacher_starts_a_session(): void
    {
        foreach ($this->outsiders() as $who => $user) {
            $this->startSession(as: $user)->assertForbidden("{$who} must not start Tuto-Mikro");
        }

        // A teacher cannot start the lesson of a colleague either.
        $colleagueLesson = Lesson::factory()->create();
        $this->actingAs($this->teacher)->postJson(route('recordings.store', $colleagueLesson), ['present' => [1]])->assertForbidden();

        $this->assertSame(0, LessonRecording::count());
    }

    public function test_the_lesson_must_still_be_led_by_the_teacher(): void
    {
        $this->lesson->update(['teacher_id' => $this->outsiders()['another teacher of the same school']->id]);

        $this->startSession()->assertForbidden();
    }

    public function test_guests_get_nothing(): void
    {
        $recording = $this->startedSession();
        auth()->logout();

        $this->postJson(route('recordings.store', $this->lesson), ['present' => [1]])->assertUnauthorized();
        $this->postSegment($recording)->assertUnauthorized();
        $this->postJson(route('recordings.finish', $recording), ['duration_seconds' => 5])->assertUnauthorized();
        $this->postJson(route('recordings.analyse', $recording), ['text' => 'x'])->assertUnauthorized();
        $this->get($this->pageUrl())->assertRedirect();
    }

    public function test_a_session_is_ended_with_its_duration_and_repeating_it_changes_nothing(): void
    {
        $recording = $this->startedSession();

        $this->postJson(route('recordings.finish', $recording), ['duration_seconds' => 754])->assertOk()->assertJson(['finished' => true, 'duration_seconds' => 754]);

        $recording->refresh();
        $this->assertTrue($recording->isFinished());
        $this->assertSame(754, $recording->duration_seconds);
        $this->assertSame('12:34', $recording->durationLabel());

        $this->postJson(route('recordings.finish', $recording), ['duration_seconds' => 999])->assertOk()->assertJson(['duration_seconds' => 754]);
    }

    public function test_the_duration_must_be_a_sensible_number(): void
    {
        $recording = $this->startedSession();

        $this->postJson(route('recordings.finish', $recording), ['duration_seconds' => -1])->assertUnprocessable();
        $this->postJson(route('recordings.finish', $recording), ['duration_seconds' => 999999])->assertUnprocessable();
        $this->postJson(route('recordings.finish', $recording))->assertUnprocessable();
        $this->assertFalse($recording->fresh()->isFinished());
    }

    public function test_nobody_else_can_end_a_session(): void
    {
        $recording = $this->startedSession();

        foreach ($this->outsiders() as $who => $user) {
            $this->actingAs($user)->postJson(route('recordings.finish', $recording), ['duration_seconds' => 5])->assertForbidden("{$who} must not end the session");
        }

        $this->assertFalse($recording->fresh()->isFinished());
    }

    public function test_the_session_table_holds_no_sound_and_no_text(): void
    {
        $this->startedSession();

        $columns = Schema::getColumnListing('lesson_recordings');

        foreach (['path', 'mime_type', 'chunks_received', 'transcript', 'transcript_status', 'transcript_source', 'analysis', 'analysed_at', 'consent_confirmed_at'] as $removed) {
            $this->assertNotContains($removed, $columns);
        }
    }
}
