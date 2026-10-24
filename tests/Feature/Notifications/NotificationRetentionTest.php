<?php

namespace Tests\Feature\Notifications;

use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Notify;
use App\Support\PersonalDataExport;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

class NotificationRetentionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Four students in one class, each with one bell entry for the same lesson.
     *
     * @return array{0: Lesson, 1: list<User>}
     */
    private function studentsWithEntries(): array
    {
        $schoolClass = SchoolClass::factory()->create();
        $students = User::factory()->student($schoolClass)->count(4)->create();
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor($students)->create(['topic' => 'Fotosynthese']);
        Notify::homeworkReleased($lesson, $students);

        return [$lesson, $students->all()];
    }

    public function test_entries_are_deleted_after_the_retention_periods_and_fresh_ones_are_kept(): void
    {
        [, [$readLong, $readShort, $unreadOld, $unreadFresh]] = $this->studentsWithEntries();

        $update = fn (User $user, array $values) => DatabaseNotification::query()->where('notifiable_id', $user->id)->update($values);
        $update($readLong, ['read_at' => now()->subDays(15)]);
        $update($readShort, ['read_at' => now()->subDays(5)]);
        $update($unreadOld, ['created_at' => now()->subDays(61)]);

        $this->artisan('lernplattform:prune-notifications')->assertSuccessful();

        $this->assertCount(0, $readLong->notifications);
        $this->assertCount(1, $readShort->notifications);
        $this->assertCount(0, $unreadOld->notifications);
        $this->assertCount(1, $unreadFresh->notifications);
    }

    public function test_entries_of_deleted_accounts_do_not_stay_behind(): void
    {
        [$lesson, [$deleted, $kept]] = $this->studentsWithEntries();

        $deleted->delete();
        $lesson->delete();
        $this->assertDatabaseCount('notifications', 4);

        $this->artisan('lernplattform:prune-notifications')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 3);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $deleted->id]);
        $this->assertCount(1, $kept->notifications);
    }

    public function test_a_broken_setting_deletes_nothing(): void
    {
        $this->studentsWithEntries();
        config(['lernplattform.notifications.keep_days' => 0]);

        $this->artisan('lernplattform:prune-notifications')->assertFailed();

        $this->assertDatabaseCount('notifications', 4);
    }

    public function test_the_command_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains($event->command, 'lernplattform:prune-notifications'));

        $this->assertCount(1, $events);
        $this->assertSame('0 4 * * *', $events->first()->expression);
    }

    public function test_the_data_export_lists_the_own_entries_only(): void
    {
        [, [$student, $classmate]] = $this->studentsWithEntries();
        DatabaseNotification::query()->where('notifiable_id', $student->id)->update(['read_at' => now()]);

        $export = PersonalDataExport::for($student);

        $this->assertCount(1, $export['benachrichtigungen']);
        $this->assertSame('New homework: “Fotosynthese”', $export['benachrichtigungen'][0]['titel']);
        $this->assertNotNull($export['benachrichtigungen'][0]['gelesen_am']);
        $this->assertCount(1, PersonalDataExport::for($classmate)['benachrichtigungen']);
        $this->assertArrayNotHasKey('benachrichtigungen', PersonalDataExport::for(User::factory()->admin()->create()));
    }
}
