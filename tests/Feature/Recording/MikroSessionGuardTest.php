<?php

namespace Tests\Feature\Recording;

use App\Ai\RecordingAnalyst;
use App\Models\RecordingConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Transcription;
use Tests\TestCase;

/**
 * What keeps a session honest after it has started, in every mode: the consent and the switches are checked again
 * during the lesson (also where no segments are sent) and before every summary, and a session that was never ended
 * does not authorise anything forever.
 */
class MikroSessionGuardTest extends TestCase
{
    use RecordingFixtures;
    use RefreshDatabase;

    private const string TEXT = 'Heute sprechen wir über die Fotosynthese. Anna Müller fragt: Warum brauchen Pflanzen Licht?';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLesson();
        $this->actingAs($this->teacher);
    }

    private function check(int $recordingId): TestResponse
    {
        return $this->postJson(route('recordings.check', $recordingId));
    }

    private function refuseAnyAnalysis(): void
    {
        $this->mock(RecordingAnalyst::class)->shouldNotReceive('analyse');
    }

    public function test_a_running_session_without_segments_passes_the_check(): void
    {
        $recording = $this->startedSession();

        $this->check($recording->id)->assertOk()->assertExactJson(['allowed' => true]);
    }

    public function test_a_consent_revoked_during_a_session_without_segments_stops_it_at_the_next_check(): void
    {
        $recording = $this->startedSession();
        $this->check($recording->id)->assertOk();

        RecordingConsent::query()->where('student_id', $this->students[1]->id)->update(['revoked_at' => now()]);

        $response = $this->check($recording->id)->assertStatus(409);

        $this->assertStringContainsString('1 von 3', $response->json('message'));
    }

    public function test_the_school_switching_tuto_mikro_off_stops_a_session_without_segments(): void
    {
        $recording = $this->startedSession();

        $this->switchRecordings($this->schoolClass->school, false);

        $this->check($recording->id)->assertStatus(409)->assertJsonPath('message', 'Tuto-Mikro ist für deine Schule nicht freigeschaltet.');
    }

    public function test_a_finished_session_fails_the_check(): void
    {
        $recording = $this->startedSession();
        $this->postJson(route('recordings.finish', $recording), ['duration_seconds' => 30])->assertOk();

        $this->check($recording->id)->assertStatus(409)->assertJsonPath('message', 'Das Mikro ist schon aus.');
    }

    public function test_nobody_else_can_use_the_check(): void
    {
        $recording = $this->startedSession();

        foreach ($this->outsiders() as $who => $user) {
            $this->actingAs($user);
            $this->check($recording->id)->assertForbidden("{$who} must not use the check");
        }

        auth()->logout();
        $this->check($recording->id)->assertUnauthorized();
    }

    public function test_a_session_that_was_never_ended_takes_nothing_after_a_lesson_could_have_lasted(): void
    {
        $this->enableAi();
        $recording = $this->startedSession();
        $calls = 0;
        Transcription::fake(function () use (&$calls): string {
            $calls++;

            return 'Text.';
        });

        $this->travel(5)->hours();
        $this->check($recording->id)->assertOk();
        $this->postSegment($recording)->assertOk();

        $this->travel(2)->hours();
        $this->check($recording->id)->assertStatus(409)->assertJsonPath('message', 'Diese Sitzung läuft schon zu lange. Starte Tuto-Mikro neu und wähle die Anwesenden noch einmal aus.');
        $this->postSegment($recording)->assertStatus(409);

        $this->assertSame(1, $calls);
        $this->assertSame([], $this->filesOnDisk());
    }

    public function test_the_age_limit_follows_the_setting(): void
    {
        config(['lernplattform.recordings.max_session_hours' => 1]);
        $recording = $this->startedSession();

        $this->travel(61)->minutes();

        $this->check($recording->id)->assertStatus(409);
    }

    public function test_a_session_that_was_never_ended_gets_no_summary_but_an_ended_one_does(): void
    {
        $open = $this->startedSession();
        $ended = $this->startedSession();
        $this->postJson(route('recordings.finish', $ended), ['duration_seconds' => 600])->assertOk();

        $this->travel(8)->hours();

        $this->postJson(route('recordings.analyse', $open), ['text' => self::TEXT])->assertStatus(409);
        $this->postJson(route('recordings.analyse', $ended), ['text' => self::TEXT])->assertOk();
    }

    public function test_the_summary_checks_the_consent_again_in_a_school_without_ai(): void
    {
        $recording = $this->startedSession();
        $this->refuseAnyAnalysis();

        // Revoked between the start at 08:00 and the summary after the lesson.
        RecordingConsent::query()->where('student_id', $this->students[0]->id)->update(['revoked_at' => now()]);

        $response = $this->postJson(route('recordings.analyse', $recording), ['text' => self::TEXT])->assertStatus(409);

        $this->assertStringContainsString('1 von 3', $response->json('message'));
    }

    public function test_the_summary_is_refused_once_the_school_switched_tuto_mikro_off(): void
    {
        $recording = $this->startedSession();
        $this->refuseAnyAnalysis();

        $this->switchRecordings($this->schoolClass->school, false);

        $this->postJson(route('recordings.analyse', $recording), ['text' => self::TEXT])->assertStatus(409);
    }

    public function test_the_summary_still_works_for_a_school_without_ai_while_everything_holds(): void
    {
        $recording = $this->startedSession();

        $this->postJson(route('recordings.analyse', $recording), ['text' => self::TEXT])
            ->assertOk()
            ->assertJsonStructure(['analysis' => ['summary', 'key_terms', 'board_outline', 'class_questions', 'suggested_notes']]);
    }
}
