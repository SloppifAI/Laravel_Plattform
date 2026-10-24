<?php

namespace Tests\Feature\Recording;

use App\Models\LessonRecording;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneLessonRecordingsTest extends TestCase
{
    use RecordingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLesson();
    }

    private function leftover(string $path): void
    {
        LessonRecording::disk()->put($path, 'bytes');
    }

    public function test_only_sessions_older_than_the_retention_period_are_deleted(): void
    {
        $expired = LessonRecording::factory()->for($this->lesson)->startedDaysAgo(366)->create();
        $kept = LessonRecording::factory()->for($this->lesson)->startedDaysAgo(364)->create();
        $today = LessonRecording::factory()->for($this->lesson)->create();

        $this->artisan('lernplattform:prune-recordings')->assertSuccessful();

        $this->assertModelMissing($expired);
        $this->assertModelExists($kept);
        $this->assertModelExists($today);
    }

    public function test_the_retention_period_follows_the_setting(): void
    {
        config(['lernplattform.recordings.session_keep_days' => 7]);
        $expired = LessonRecording::factory()->for($this->lesson)->startedDaysAgo(8)->create();
        $kept = LessonRecording::factory()->for($this->lesson)->startedDaysAgo(6)->create();

        $this->artisan('lernplattform:prune-recordings')->assertSuccessful();

        $this->assertModelMissing($expired);
        $this->assertModelExists($kept);
    }

    public function test_sound_files_left_by_the_former_mode_are_removed_and_other_files_stay(): void
    {
        $this->leftover('recordings/segments/stray.webm');
        $this->leftover('recordings/1/old-audio-of-the-former-mode.webm');
        $this->leftover('consent-letters/unrelated.pdf');

        $this->artisan('lernplattform:prune-recordings')->assertSuccessful();

        $this->assertSame(['consent-letters/unrelated.pdf'], LessonRecording::disk()->allFiles());
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $expired = LessonRecording::factory()->for($this->lesson)->startedDaysAgo(400)->create();
        $this->leftover('recordings/segments/stray.webm');

        $this->artisan('lernplattform:prune-recordings --dry-run')->assertSuccessful();

        $this->assertModelExists($expired);
        $this->assertTrue(LessonRecording::disk()->exists('recordings/segments/stray.webm'));
    }

    public function test_a_broken_setting_never_turns_into_delete_everything(): void
    {
        config(['lernplattform.recordings.session_keep_days' => 0]);
        $session = LessonRecording::factory()->for($this->lesson)->startedDaysAgo(400)->create();

        $this->artisan('lernplattform:prune-recordings')->assertFailed();

        $this->assertModelExists($session);
    }

    public function test_the_command_runs_every_day(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains($event->command, 'lernplattform:prune-recordings'));

        $this->assertNotNull($event, 'The prune command is scheduled');
        $this->assertSame('45 3 * * *', $event->expression);
    }
}
