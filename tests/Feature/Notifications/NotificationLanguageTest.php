<?php

namespace Tests\Feature\Notifications;

use App\Models\Competency;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\StudentQuestion;
use App\Models\Submission;
use App\Models\User;
use App\Support\Notify;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A bell text is stored when it is sent, so it is written in the language of the person who will read it, not in the
 * one of whoever triggers the notification.
 */
class NotificationLanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_student_gets_the_release_in_their_own_language(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        $schoolClass = SchoolClass::factory()->create();
        $german = User::factory()->student($schoolClass)->create(['locale' => 'de']);
        $english = User::factory()->student($schoolClass)->create(['locale' => 'en']);
        $noChoice = User::factory()->student($schoolClass)->create(['locale' => null]);
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor([$german, $english, $noChoice])->create(['topic' => 'Fotosynthese', 'due_on' => '2026-11-05']);

        // The teacher who releases the lesson works in English, which must not leak into the German entries.
        app()->setLocale('en');

        $this->assertSame(3, Notify::homeworkReleased($lesson, [$german, $english, $noChoice]));

        $this->assertSame(['Neue Hausaufgabe: „Fotosynthese“', 'Abgabe bis 05.11.2026.', 'Öffnen'], $this->texts($german));
        $this->assertSame(['New homework: “Fotosynthese”', 'Due by 05.11.2026.', 'Open'], $this->texts($english));
        $this->assertSame($this->texts($german), $this->texts($noChoice), 'Without a choice the platform default applies.');
        $this->assertSame('en', app()->getLocale(), 'The language of the request is restored.');
    }

    public function test_a_practice_task_is_named_in_the_language_of_the_student(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $german = User::factory()->student($schoolClass)->create(['locale' => 'de']);
        $english = User::factory()->student($schoolClass)->create(['locale' => 'en']);
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor([$german, $english])->create([
            'topic' => 'Brüche',
            'practice_competency_id' => Competency::factory()->create()->id,
        ]);

        Notify::homeworkReleased($lesson, [$german, $english]);

        $this->assertSame('Neue Förderaufgabe: „Brüche“', $this->texts($german)[0]);
        $this->assertSame('New practice task: “Brüche”', $this->texts($english)[0]);
    }

    public function test_an_english_speaking_teacher_gets_english_counts_even_when_a_german_student_hands_in(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $students = User::factory()->student($schoolClass)->count(2)->create(['locale' => 'de']);
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor($students)->create(['topic' => 'Fotosynthese']);
        $lesson->teacher->update(['locale' => 'en']);

        app()->setLocale('de');
        Notify::submissionReceived(Submission::factory()->for($lesson)->for($students[0], 'student')->create());
        Notify::submissionReceived(Submission::factory()->for($lesson)->for($students[1], 'student')->create());

        $this->assertSame(['2 new submissions for “Fotosynthese”', $lesson->subject.' · '.$schoolClass->name, 'Open'], $this->texts($lesson->teacher));
        $this->assertSame('de', app()->getLocale());
    }

    public function test_a_german_speaking_teacher_gets_a_german_count(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $student = User::factory()->student($schoolClass)->create(['locale' => 'en']);
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor([$student])->create(['topic' => 'Fotosynthese']);
        $lesson->teacher->update(['locale' => 'de']);

        Notify::submissionReceived(Submission::factory()->for($lesson)->for($student, 'student')->create());

        $this->assertSame('Neue Abgabe zu „Fotosynthese“', $this->texts($lesson->teacher)[0]);
        $this->assertSame('en', app()->getLocale());
    }

    public function test_questions_are_announced_to_the_teacher_and_answered_to_the_student_in_their_languages(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $student = User::factory()->student($schoolClass)->create(['locale' => 'de']);
        $lesson = Lesson::factory()->for($schoolClass)->publishedFor([$student])->create(['topic' => 'Fotosynthese']);
        $lesson->teacher->update(['locale' => 'en']);
        $question = StudentQuestion::factory()->for($lesson)->for($student, 'student')->create();

        Notify::questionAsked($question);
        Notify::questionAnswered($question);

        $this->assertSame('New question about “Fotosynthese”', $this->texts($lesson->teacher)[0]);
        $this->assertSame('Deine Frage zu „Fotosynthese“ wurde beantwortet', $this->texts($student)[0]);
    }

    /**
     * Title, body and button label of the user's only notification.
     *
     * @return array{0: string, 1: string|null, 2: string}
     */
    private function texts(User $user): array
    {
        $data = $user->notifications()->sole()->data;

        return [$data['title'], $data['body'], $data['actions'][0]['label']];
    }
}
