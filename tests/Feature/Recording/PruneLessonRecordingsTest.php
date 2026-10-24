<?php

namespace Tests\Feature\Recording;

use App\Models\Lesson;
use App\Models\LessonRecording;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

    public function test_only_recordings_older_than_the_retention_period_are_deleted_with_their_files(): void
    {
        $expired = LessonRecording::factory()->for($this->lesson)->withAudio()->startedDaysAgo(15)->create();
        $justExpired = LessonRecording::factory()->for($this->lesson)->withAudio()->startedDaysAgo(14)->create(['started_at' => now()->subDays(14)->subMinute()]);
        $kept = LessonRecording::factory()->for($this->lesson)->withAudio()->startedDaysAgo(13)->create();
        $today = LessonRecording::factory()->for($this->lesson)->withAudio()->create();

        $this->artisan('lernplattform:prune-recordings')->assertSuccessful();

        $this->assertModelMissing($expired);
        $this->assertModelMissing($justExpired);
        $this->assertFalse(LessonRecording::disk()->exists($expired->path));
        $this->assertFalse(LessonRecording::disk()->exists($justExpired->path));
        $this->assertModelExists($kept);
        $this->assertModelExists($today);
        $this->assertTrue(LessonRecording::disk()->exists($kept->path));
        $this->assertTrue(LessonRecording::disk()->exists($today->path));
    }

    public function test_the_retention_period_is_configurable(): void
    {
        config(['lernplattform.recordings.keep_days' => 3]);
        $expired = LessonRecording::factory()->for($this->lesson)->withAudio()->startedDaysAgo(4)->create();
        $kept = LessonRecording::factory()->for($this->lesson)->withAudio()->startedDaysAgo(2)->create();

        $this->artisan('lernplattform:prune-recordings')->assertSuccessful();

        $this->assertModelMissing($expired);
        $this->assertModelExists($kept);
    }

    public function test_the_default_is_fourteen_days(): void
    {
        $this->assertSame(14, config('lernplattform.recordings.keep_days'));
    }

    public function test_a_broken_setting_deletes_nothing(): void
    {
        config(['lernplattform.recordings.keep_days' => 0]);
        $old = LessonRecording::factory()->for($this->lesson)->withAudio()->startedDaysAgo(400)->create();

        $this->artisan('lernplattform:prune-recordings')->assertFailed();

        $this->assertModelExists($old);
        $this->assertTrue(LessonRecording::disk()->exists($old->path));
    }

    public function test_a_dry_run_only_counts(): void
    {
        $expired = LessonRecording::factory()->for($this->lesson)->withAudio()->startedDaysAgo(20)->create();

        $this->artisan('lernplattform:prune-recordings --dry-run')->assertSuccessful();

        $this->assertModelExists($expired);
        $this->assertTrue(LessonRecording::disk()->exists($expired->path));
    }

    public function test_audio_files_without_a_row_are_swept_but_fresh_ones_are_not(): void
    {
        $disk = LessonRecording::disk();
        $disk->put('recordings/99/orphan.webm', 'old');
        touch($disk->path('recordings/99/orphan.webm'), now()->subDays(2)->getTimestamp());
        $disk->put('recordings/99/fresh.webm', 'new');
        $kept = LessonRecording::factory()->for($this->lesson)->withAudio()->create();
        touch($disk->path($kept->path), now()->subDays(2)->getTimestamp());

        $this->artisan('lernplattform:prune-recordings')->assertSuccessful();

        $this->assertFalse($disk->exists('recordings/99/orphan.webm'));
        $this->assertTrue($disk->exists('recordings/99/fresh.webm'));
        $this->assertTrue($disk->exists($kept->path));
    }

    public function test_files_of_a_deleted_lesson_are_swept_as_well(): void
    {
        $recording = LessonRecording::factory()->for($this->lesson)->withAudio()->create();
        touch(LessonRecording::disk()->path($recording->path), now()->subDays(2)->getTimestamp());

        // The foreign key removes the row without a model event, so the file stays behind until the sweep.
        Lesson::query()->whereKey($this->lesson->id)->delete();
        $this->assertSame(0, LessonRecording::count());
        $this->assertTrue(LessonRecording::disk()->exists($recording->path));

        $this->artisan('lernplattform:prune-recordings')->assertSuccessful();

        $this->assertFalse(LessonRecording::disk()->exists($recording->path));
        $this->assertSame([], Storage::disk('local')->allFiles('recordings'));
    }

    public function test_the_command_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'lernplattform:prune-recordings'));

        $this->assertCount(1, $events);
        $this->assertSame('45 3 * * *', $events->first()->expression);
    }
}
