<?php

namespace Tests\Feature\ClassBook;

use App\Filament\Pages\ClassBook;
use App\Models\ClassBookEntry;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\User;
use App\Support\ClassBookDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The tests of the class register run in English; this one makes sure the German interface (the default) still reads German.
 */
class ClassBookGermanTest extends TestCase
{
    use CreatesClassBookFixtures;
    use RefreshDatabase;

    public function test_the_page_reads_german(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        [$schoolClass, , $teacher] = $this->classWithStudents();
        ClassBookEntry::factory()->create(['school_class_id' => $schoolClass->getKey(), 'teacher_id' => $teacher->getKey(), 'period' => 3]);

        $this->actingAs($teacher)->get(ClassBook::getUrl())
            ->assertOk()
            ->assertSee('Klassenbuch')
            ->assertSee('Fach und Thema')
            ->assertSee('3. Stunde')
            ->assertSee('Entwurf')
            ->assertSee('Fehlzeiten sind personenbezogen')
            ->assertDontSee('Class register');
    }

    public function test_a_teacher_without_a_class_reads_the_hint_in_german(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        [, , $teacher] = $this->classWithStudents();
        $lonely = User::factory()->for($teacher->school)->teacher()->create();

        $this->actingAs($lonely)->get(ClassBook::getUrl())
            ->assertOk()
            ->assertSee('Keine Klasse zugeordnet')
            ->assertSee('Die Schuladministration kann dich einer Klasse zuordnen.');
    }

    public function test_the_draft_homework_line_is_worded_in_german(): void
    {
        app()->setLocale('de');
        [$schoolClass, $students, $teacher] = $this->classWithStudents();
        $lesson = Lesson::factory()->publishedFor($students)->create(['school_class_id' => $schoolClass->getKey(), 'teacher_id' => $teacher->getKey(), 'due_on' => '2026-10-12']);
        Question::factory()->count(3)->for($lesson)->create();

        $this->assertSame('Hausaufgabe in Tuto: 3 Fragen, fällig am 12.10.2026.', ClassBookDraft::createFor($lesson)->homework);
    }
}
