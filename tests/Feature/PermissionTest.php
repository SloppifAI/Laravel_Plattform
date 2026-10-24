<?php

namespace Tests\Feature;

use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_manage_users_and_classes(): void
    {
        $admin = User::factory()->admin()->create();
        $teacher = User::factory()->teacher()->create();
        $student = User::factory()->student()->create();

        $this->assertTrue($admin->can('viewAny', User::class));
        $this->assertTrue($admin->can('create', SchoolClass::class));
        $this->assertFalse($admin->can('delete', $admin));
        $this->assertFalse($teacher->can('viewAny', User::class));
        $this->assertFalse($teacher->can('viewAny', SchoolClass::class));
        $this->assertFalse($student->can('viewAny', User::class));
    }

    public function test_teachers_only_manage_their_own_lessons(): void
    {
        $lesson = Lesson::factory()->create();
        $otherTeacher = User::factory()->teacher()->create();

        $this->assertTrue($lesson->teacher->can('update', $lesson));
        $this->assertFalse($otherTeacher->can('update', $lesson));
        $this->assertTrue(User::factory()->admin()->for($lesson->schoolClass->school)->create()->can('update', $lesson));
        $this->assertFalse(User::factory()->admin()->create()->can('update', $lesson));
        $this->assertFalse(User::factory()->operator()->create()->can('view', $lesson));
    }

    public function test_free_topic_conversations_stay_private_to_the_student(): void
    {
        $student = User::factory()->student()->create();
        $freeTopic = ExplanationSession::factory()->for($student, 'student')->create(['lesson_id' => null]);
        $schoolAdmin = User::factory()->admin()->for($student->school)->create();

        $this->assertTrue($student->can('view', $freeTopic));
        $this->assertFalse($schoolAdmin->can('view', $freeTopic));
        $this->assertFalse($schoolAdmin->can('delete', $freeTopic));
    }

    public function test_students_only_see_published_homework_delegated_to_them(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        [$assigned, $notAssigned] = User::factory()->student($schoolClass)->count(2)->create();
        $draft = Lesson::factory()->for($schoolClass)->create();
        $published = Lesson::factory()->for($schoolClass)->publishedFor([$assigned])->create();
        $draft->students()->attach($assigned);

        $this->assertTrue($assigned->can('view', $published));
        $this->assertFalse($assigned->can('view', $draft));
        $this->assertFalse($notAssigned->can('view', $published));
        $this->assertFalse($assigned->can('viewAny', Lesson::class));
        $this->assertSame([$published->id], Lesson::assignedTo($assigned)->pluck('id')->all());
    }

    public function test_publishing_without_students_delegates_to_the_whole_class(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        User::factory()->student($schoolClass)->count(3)->create();

        $lesson = Lesson::factory()->for($schoolClass)->publishedFor()->create();

        $this->assertCount(3, $lesson->students);
    }

    public function test_submissions_are_private_to_student_and_lesson_teacher(): void
    {
        $submission = Submission::factory()->for(User::factory()->student(), 'student')->create();
        $classmate = User::factory()->student()->create();

        $this->assertTrue($submission->student->can('view', $submission));
        $this->assertTrue($submission->lesson->teacher->can('view', $submission));
        $this->assertFalse($classmate->can('view', $submission));
        $this->assertFalse(User::factory()->teacher()->create()->can('view', $submission));
        $this->assertFalse($submission->student->can('update', $submission));
    }

    public function test_explanation_sessions_are_writable_only_by_their_student_until_finished(): void
    {
        $session = ExplanationSession::factory()->for(User::factory()->student(), 'student')->create();
        $finished = ExplanationSession::factory()->for($session->student, 'student')->finished()->create();

        $this->assertTrue($session->student->can('update', $session));
        $this->assertFalse($session->student->can('update', $finished));
        $this->assertFalse(User::factory()->student()->create()->can('view', $session));
        $this->assertFalse(User::factory()->teacher()->create()->can('view', $session));
    }
}
