<?php

namespace Tests\Feature\Notifications;

use App\Filament\Pages\WorkOnHomework;
use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\Role;
use App\Support\Notify;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class HomeworkNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_released_homework_notifies_only_the_delegated_students_of_the_lessons_school(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        [$delegated, $notDelegated] = User::factory()->student($schoolClass)->count(2)->create();
        $otherSchool = User::factory()->student()->create();
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor([$delegated, $otherSchool])->create(['topic' => 'Fotosynthese', 'due_on' => '2026-11-05']);

        $sent = Notify::homeworkReleased($lesson, [$delegated, $notDelegated, $otherSchool, $lesson->teacher]);

        $this->assertSame(1, $sent);
        $this->assertCount(1, $delegated->notifications);
        $this->assertCount(0, $notDelegated->notifications);
        $this->assertCount(0, $otherSchool->notifications);
        $this->assertCount(0, $lesson->teacher->notifications);

        $data = $delegated->notifications->first()->data;
        $this->assertSame('Neue Hausaufgabe: „Fotosynthese“', $data['title']);
        $this->assertSame('Abgabe bis 05.11.2026.', $data['body']);
        $this->assertSame(WorkOnHomework::getUrl(['lesson' => $lesson], isAbsolute: false), $data['actions'][0]['url']);
        $this->assertStringStartsWith('/', $data['actions'][0]['url']);
    }

    public function test_practice_tasks_are_called_that_and_unpublished_lessons_notify_nobody(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $student = User::factory()->student($schoolClass)->create();
        $practice = Lesson::factory()->for($schoolClass)->publishedFor([$student])->create([
            'topic' => 'Brüche',
            'practice_competency_id' => Competency::factory()->create()->id,
        ]);
        $draft = Lesson::factory()->for($schoolClass)->create();
        $draft->students()->attach($student);

        $this->assertSame(1, Notify::homeworkReleased($practice, [$student]));
        $this->assertSame(0, Notify::homeworkReleased($draft, [$student]));
        $this->assertSame('Neue Förderaufgabe: „Brüche“', $student->notifications->sole()->data['title']);
    }

    public function test_submission_notifies_only_the_lessons_teacher_without_the_students_name(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $student = User::factory()->student($schoolClass)->create(['name' => 'Mia Hoffmann']);
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor([$student])->create(['topic' => 'Fotosynthese']);
        $otherTeacher = User::factory()->teacher()->for($lesson->schoolClass->school)->create();
        $submission = Submission::factory()->for($lesson)->for($student, 'student')->create();

        $this->assertSame(1, Notify::submissionReceived($submission));

        $this->assertCount(0, $student->notifications);
        $this->assertCount(0, $otherTeacher->notifications);
        $notification = $lesson->teacher->notifications->sole();
        $this->assertSame('Neue Abgabe zu „Fotosynthese“', $notification->data['title']);
        $this->assertSame(LessonResource::getUrl('edit', ['record' => $lesson], isAbsolute: false), $notification->data['actions'][0]['url']);
        $this->assertStringNotContainsString('Hoffmann', json_encode($notification->data));
    }

    public function test_submissions_of_one_lesson_are_counted_in_a_single_unread_entry(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $students = User::factory()->student($schoolClass)->count(3)->create();
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor($students)->create(['topic' => 'Fotosynthese']);
        $otherLesson = Lesson::factory()->for($schoolClass)->publishedFor($students)->create(['teacher_id' => $lesson->teacher_id, 'topic' => 'Zellen']);

        Notify::submissionReceived(Submission::factory()->for($lesson)->for($students[0], 'student')->create());
        Notify::submissionReceived(Submission::factory()->for($lesson)->for($students[1], 'student')->create());
        Notify::submissionReceived(Submission::factory()->for($otherLesson)->for($students[0], 'student')->create());

        $titles = $lesson->teacher->unreadNotifications->pluck('data.title');
        $this->assertCount(2, $titles);
        $this->assertContains('2 neue Abgaben zu „Fotosynthese“', $titles);
        $this->assertContains('Neue Abgabe zu „Zellen“', $titles);

        $lesson->teacher->unreadNotifications()->where('data->viewData->lesson_id', $lesson->id)->update(['read_at' => now()]);
        Notify::submissionReceived(Submission::factory()->for($lesson)->for($students[2], 'student')->create());

        $this->assertSame('Neue Abgabe zu „Fotosynthese“', $lesson->teacher->unreadNotifications()->where('data->viewData->lesson_id', $lesson->id)->sole()->data['title']);
    }

    public function test_a_teacher_who_became_a_student_is_not_notified_about_the_lessons_submissions(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $student = User::factory()->student($schoolClass)->create();
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor([$student])->create(['topic' => 'Fotosynthese']);
        $formerTeacher = $lesson->teacher;
        $formerTeacher->update(['role' => Role::Student, 'school_class_id' => SchoolClass::factory()->for($schoolClass->school)->create()->id]);

        $this->assertSame(0, Notify::submissionReceived(Submission::factory()->for($lesson)->for($student, 'student')->create()));
        $this->assertCount(0, $formerTeacher->notifications);
    }

    public function test_the_counted_entry_is_replaced_inside_a_transaction(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $student = User::factory()->student($schoolClass)->create();
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor([$student])->create();
        $levels = [];
        Event::listen(NotificationSent::class, function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });
        $outside = DB::transactionLevel();

        Notify::submissionReceived(Submission::factory()->for($lesson)->for($student, 'student')->create());

        $this->assertCount(1, $levels);
        $this->assertGreaterThan($outside, $levels[0]);
    }

    public function test_a_teacher_of_another_school_is_never_notified(): void
    {
        $student = User::factory()->student()->create();
        $foreignTeacher = User::factory()->teacher()->create();
        $lesson = Lesson::factory()->for($student->schoolClass)->publishedFor([$student])->create(['teacher_id' => $foreignTeacher->id]);

        $this->assertSame(0, Notify::submissionReceived(Submission::factory()->for($lesson)->for($student, 'student')->create()));
        $this->assertCount(0, $foreignTeacher->notifications);
    }
}
