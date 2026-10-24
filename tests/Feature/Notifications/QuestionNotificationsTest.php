<?php

namespace Tests\Feature\Notifications;

use App\Filament\Pages\MyHomework;
use App\Filament\Pages\MyQuestions;
use App\Filament\Pages\QuestionInbox;
use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\StudentQuestion;
use App\Models\User;
use App\Role;
use App\Support\Notify;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $schoolClass = SchoolClass::factory()->create();
        $this->student = User::factory()->student($schoolClass)->create(['name' => 'Mia Hoffmann']);
        $this->lesson = Lesson::factory()->for($schoolClass)->publishedFor([$this->student])->create(['topic' => 'Fotosynthese']);
    }

    private function question(): StudentQuestion
    {
        return StudentQuestion::factory()->for($this->student, 'student')->for($this->lesson)->create();
    }

    public function test_a_new_question_notifies_only_the_lessons_teacher_and_counts_per_lesson(): void
    {
        $classmate = User::factory()->student($this->lesson->schoolClass)->create();

        $this->assertSame(1, Notify::questionAsked($this->question()));
        $this->assertSame(1, Notify::questionAsked($this->question()));

        $this->assertCount(0, $this->student->notifications);
        $this->assertCount(0, $classmate->notifications);
        $notification = $this->lesson->teacher->unreadNotifications->sole();
        $this->assertSame('2 new questions about “Fotosynthese”', $notification->data['title']);
        $this->assertStringNotContainsString('Hoffmann', json_encode($notification->data));

        $expectedUrl = class_exists(QuestionInbox::class)
            ? QuestionInbox::getUrl(isAbsolute: false)
            : LessonResource::getUrl('edit', ['record' => $this->lesson], isAbsolute: false);
        $this->assertSame($expectedUrl, $notification->data['actions'][0]['url']);
    }

    public function test_an_answer_notifies_only_the_student_who_asked(): void
    {
        $question = $this->question();

        $this->assertSame(1, Notify::questionAnswered($question));

        $this->assertCount(0, $this->lesson->teacher->notifications);
        $notification = $this->student->notifications->sole();
        $this->assertSame('Your question about “Fotosynthese” has been answered', $notification->data['title']);
        $expectedUrl = class_exists(MyQuestions::class) ? MyQuestions::getUrl(isAbsolute: false) : MyHomework::getUrl(isAbsolute: false);
        $this->assertSame($expectedUrl, $notification->data['actions'][0]['url']);
    }

    public function test_a_teacher_who_became_a_student_is_not_notified_about_new_questions(): void
    {
        $formerTeacher = $this->lesson->teacher;
        $formerTeacher->update(['role' => Role::Student, 'school_class_id' => SchoolClass::factory()->for($this->lesson->schoolClass->school)->create()->id]);

        $this->assertSame(0, Notify::questionAsked($this->question()));
        $this->assertCount(0, $formerTeacher->notifications);
    }

    public function test_nobody_from_another_school_or_without_a_lesson_is_notified(): void
    {
        $foreignStudent = User::factory()->student()->create();
        $foreignQuestion = StudentQuestion::factory()->for($foreignStudent, 'student')->for($this->lesson)->create();
        $foreignTeacher = User::factory()->teacher()->create();
        $foreignLesson = Lesson::factory()->for($this->student->schoolClass)->create(['teacher_id' => $foreignTeacher->id]);

        $this->assertSame(0, Notify::questionAnswered($foreignQuestion));
        $this->assertSame(0, Notify::questionAsked(StudentQuestion::factory()->for($this->student, 'student')->for($foreignLesson)->create()));
        $this->assertSame(0, Notify::questionAsked(StudentQuestion::factory()->for($this->student, 'student')->create(['lesson_id' => null])));
        $this->assertSame(0, Notify::questionAnswered(StudentQuestion::factory()->for($this->student, 'student')->create(['lesson_id' => null])));

        $this->assertCount(0, $foreignStudent->notifications);
        $this->assertCount(0, $foreignTeacher->notifications);
    }
}
