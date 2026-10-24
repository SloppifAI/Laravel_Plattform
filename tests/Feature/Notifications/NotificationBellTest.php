<?php

namespace Tests\Feature\Notifications;

use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Notify;
use Filament\Livewire\DatabaseNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_role_gets_the_bell_in_the_panel(): void
    {
        foreach (['student', 'teacher', 'admin'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create())
                ->get('/')
                ->assertOk()
                ->assertSeeLivewire(DatabaseNotifications::class);
        }
    }

    public function test_the_bell_is_polled_once_a_minute(): void
    {
        $this->assertSame('60s', filament()->getPanel('lernplattform')->getDatabaseNotificationsPollingInterval());
    }

    public function test_the_bell_lists_only_the_users_own_notifications(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        [$student, $classmate] = User::factory()->student($schoolClass)->count(2)->create();
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor([$student])->create(['topic' => 'Fotosynthese']);
        Notify::homeworkReleased($lesson, [$student]);

        $this->actingAs($student);
        Livewire::test(DatabaseNotifications::class)->assertSee('Neue Hausaufgabe: „Fotosynthese“');

        $this->actingAs($classmate);
        Livewire::test(DatabaseNotifications::class)->assertDontSee('Fotosynthese');
    }
}
