<?php

namespace Tests\Feature\Substitutions;

use App\Filament\Pages\HandoverSheet;
use App\Models\Answer;
use App\Models\ClassSubstitution;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class HandoverSheetTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $leader;

    private User $substitute;

    private SchoolClass $schoolClass;

    /**
     * @var list<User>
     */
    private array $students;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create(['name' => 'Gutenberg-Schule']);
        $this->leader = $this->teacher(['name' => 'Frau Lindner', 'email' => 'lindner@gutenberg.example']);
        $this->substitute = $this->teacher(['name' => 'Herr Vogel']);
        $this->schoolClass = SchoolClass::factory()->for($this->school)->create(['name' => '7b', 'teacher_id' => $this->leader->id]);
        $this->students = collect(['Mia Hoffmann', 'Ben Schneider', 'Lena Becker'])
            ->map(fn (string $name): User => User::factory()->student($this->schoolClass)->create(['name' => $name]))
            ->all();
    }

    public function test_the_klassenleitung_the_administration_and_the_active_substitute_open_the_sheet(): void
    {
        ClassSubstitution::factory()->for($this->schoolClass)->create(['substitute_id' => $this->substitute->id]);
        $admin = User::factory()->admin()->for($this->school)->create();

        foreach ([$this->leader, $admin, $this->substitute] as $allowed) {
            $this->actingAs($allowed)->get($this->url())->assertOk()->assertSee(['Handover sheet', 'Class 7b', 'Gutenberg-Schule']);
        }
    }

    public function test_everybody_else_gets_a_403(): void
    {
        $assigned = $this->teacher();
        $this->schoolClass->teachers()->attach($assigned);
        $held = $this->teacher();
        Lesson::factory()->for($this->schoolClass)->for($held, 'teacher')->create();
        $endedSubstitute = $this->teacher();
        $upcomingSubstitute = $this->teacher();
        ClassSubstitution::factory()->for($this->schoolClass)->ended()->create(['substitute_id' => $endedSubstitute->id]);
        ClassSubstitution::factory()->for($this->schoolClass)->upcoming()->create(['substitute_id' => $upcomingSubstitute->id]);

        foreach ([
            $assigned,
            $held,
            $this->teacher(),
            $endedSubstitute,
            $upcomingSubstitute,
            User::factory()->teacher()->create(),
            User::factory()->admin()->create(),
            $this->students[0],
            User::factory()->operator()->create(),
        ] as $outsider) {
            $this->actingAs($outsider)->get($this->url())->assertForbidden();
        }
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->get($this->url())->assertRedirect();
    }

    public function test_the_sheet_stops_working_when_the_substitution_ends_while_it_is_open(): void
    {
        ClassSubstitution::factory()->for($this->schoolClass)->create([
            'substitute_id' => $this->substitute->id,
            'starts_on' => today(),
            'ends_on' => today(),
        ]);

        $page = Livewire::actingAs($this->substitute)->test(HandoverSheet::class, ['schoolClass' => $this->schoolClass]);

        $page->assertSee('Class 7b');

        $this->travel(1)->days();

        $page->call('$refresh')->assertForbidden();
    }

    public function test_it_shows_the_klassenleitung_to_ask(): void
    {
        $this->actingAs($this->substitute);
        ClassSubstitution::factory()->for($this->schoolClass)->create(['substitute_id' => $this->substitute->id]);

        $this->get($this->url())->assertSee(['Who you can ask', 'Frau Lindner', 'lindner@gutenberg.example']);
    }

    public function test_a_formatted_summary_keeps_its_format_and_loses_scripts(): void
    {
        Lesson::factory()->for($this->schoolClass)->for($this->leader, 'teacher')->create([
            'summary' => '<p>Licht liefert <strong>Energie</strong>.</p><script>alert(1)</script>',
            'published_at' => now(),
            'due_on' => today()->addWeek(),
        ]);
        Lesson::factory()->for($this->schoolClass)->for($this->leader, 'teacher')->create([
            'summary' => "## Aus der App\n\n- Erster Punkt",
            'published_at' => now(),
            'due_on' => today()->addWeek(),
        ]);

        $this->actingAs($this->leader)
            ->get($this->url())
            ->assertSee(['<strong>Energie</strong>', 'Aus der App</h2>', '<li>Erster Punkt</li>'], false)
            ->assertDontSee(['<script>alert(1)</script>', '## Aus der App'], false);
    }

    public function test_it_lists_the_last_five_lessons_with_topic_date_and_released_summary(): void
    {
        foreach (range(1, 6) as $day) {
            Lesson::factory()->for($this->schoolClass)->for($this->leader, 'teacher')->create([
                'subject' => 'Biologie',
                'topic' => "Thema Nummer {$day}",
                'held_on' => "2026-10-0{$day}",
                'summary' => "Zusammenfassung Nummer {$day}",
                'published_at' => now(),
                'due_on' => '2026-10-20',
            ]);
        }
        Lesson::factory()->for($this->schoolClass)->for($this->leader, 'teacher')->create([
            'subject' => 'Deutsch',
            'topic' => 'Noch ein Entwurf',
            'held_on' => '2026-10-07',
            'summary' => 'Geheimer Entwurf der Zusammenfassung',
            'published_at' => null,
        ]);

        $this->actingAs($this->leader)
            ->get($this->url())
            ->assertSeeInOrder(['The last lessons', '07.10.2026', 'Noch ein Entwurf', 'The summary has not been released yet.', '06.10.2026', 'Thema Nummer 6', 'Zusammenfassung Nummer 6', 'Thema Nummer 3', 'Zusammenfassung Nummer 3', 'Open homework'])
            ->assertDontSee(['Thema Nummer 2', 'Thema Nummer 1', 'Zusammenfassung Nummer 2', 'Geheimer Entwurf der Zusammenfassung']);
    }

    public function test_lessons_dated_in_the_future_are_not_handed_over(): void
    {
        foreach (range(1, 5) as $day) {
            Lesson::factory()->for($this->schoolClass)->for($this->leader, 'teacher')->create([
                'topic' => "Geplante Klassenarbeit {$day}",
                'held_on' => today()->addDays($day),
                'published_at' => null,
            ]);
        }
        Lesson::factory()->for($this->schoolClass)->for($this->leader, 'teacher')->create(['topic' => 'Gestern gehalten', 'held_on' => today()->subDay()]);
        Lesson::factory()->for($this->schoolClass)->for($this->leader, 'teacher')->create(['topic' => 'Heute gehalten', 'held_on' => today()]);
        ClassSubstitution::factory()->for($this->schoolClass)->create(['substitute_id' => $this->substitute->id]);

        $this->actingAs($this->substitute)
            ->get($this->url())
            ->assertSeeInOrder(['Heute gehalten', 'Gestern gehalten'])
            ->assertDontSee('Geplante Klassenarbeit');
    }

    public function test_lessons_of_other_classes_and_foerderaufgaben_are_left_out(): void
    {
        $otherClass = SchoolClass::factory()->for($this->school)->create(['teacher_id' => $this->leader->id]);
        Lesson::factory()->for($otherClass)->for($this->leader, 'teacher')->create(['topic' => 'Stunde der anderen Klasse']);
        Lesson::factory()->for($this->schoolClass)->for($this->leader, 'teacher')
            ->publishedFor([$this->students[0]])
            ->create(['topic' => 'Übungsaufgabe für Mia', 'practice_competency_id' => Competency::factory()->create()->id]);

        $this->actingAs($this->leader)
            ->get($this->url())
            ->assertDontSee(['Stunde der anderen Klasse', 'Übungsaufgabe für Mia'])
            ->assertSee('No lesson has been created in this class yet.')
            ->assertSee('No homework is open at the moment.');
    }

    public function test_it_lists_open_homework_with_the_number_of_hand_ins(): void
    {
        $open = Lesson::factory()->for($this->schoolClass)->for($this->leader, 'teacher')->publishedFor()
            ->create(['topic' => 'Offene Zellenlehre', 'due_on' => today()->addDays(3)]);
        Submission::factory()->for($open)->for($this->students[0], 'student')->create();
        Submission::factory()->for($open)->for($this->students[1], 'student')->evaluated()->create();
        Lesson::factory()->for($this->schoolClass)->for($this->leader, 'teacher')->publishedFor()
            ->create(['topic' => 'Fällige Hausaufgabe', 'due_on' => today()]);
        Lesson::factory()->for($this->schoolClass)->for($this->leader, 'teacher')->publishedFor()
            ->create(['topic' => 'Abgelaufene Hausaufgabe', 'due_on' => today()->subDay()]);
        Lesson::factory()->for($this->schoolClass)->for($this->leader, 'teacher')
            ->create(['topic' => 'Nicht freigegebene Hausaufgabe', 'published_at' => null]);

        $html = $this->actingAs($this->leader)
            ->get($this->url())
            ->assertSeeInOrder(['Open homework', 'Fällige Hausaufgabe', 'Due '.today()->format('d.m.Y'), '0 of 3 handed in', 'Offene Zellenlehre', 'Due '.today()->addDays(3)->format('d.m.Y'), '2 of 3 handed in'])
            ->getContent();

        // Expired and unreleased lessons still appear among the last lessons, just not as open homework.
        $homework = Str::between($html, 'id="lp-handover-homework"', 'id="lp-handover-starter"');

        $this->assertStringNotContainsString('Abgelaufene Hausaufgabe', $homework);
        $this->assertStringNotContainsString('Nicht freigegebene Hausaufgabe', $homework);
    }

    public function test_the_starting_points_come_from_the_review_without_names(): void
    {
        $lesson = Lesson::factory()->for($this->schoolClass)->for($this->leader, 'teacher')
            ->create(['subject' => 'Biologie', 'topic' => 'Fotosynthese', 'held_on' => '2026-10-01']);
        $weak = Question::factory()->for($lesson)->create(['prompt' => 'Warum brauchen Pflanzen Licht?', 'expected_answer' => 'Geheime Lösung', 'position' => 1]);

        foreach ($this->students as $student) {
            $submission = Submission::factory()->for($lesson)->for($student, 'student')->evaluated(40)->create(['gaps' => ['Chlorophyll verwechselt']]);
            Answer::factory()->for($submission)->for($weak)->create(['score' => 20, 'answer' => 'Weil es hell ist (Antwort von '.$student->name.')', 'feedback' => 'Persönliche Rückmeldung']);
        }

        $this->actingAs($this->leader)
            ->get($this->url())
            ->assertSeeInOrder(['Starter for the next lesson', 'Biologie', 'Fotosynthese', '01.10.2026', 'Warum brauchen Pflanzen Licht?', 'On average 20 %', 'Chlorophyll verwechselt', 'Named as a gap in 3 of 3 hand-ins'])
            ->assertDontSee(['Mia', 'Hoffmann', 'Ben', 'Schneider', 'Lena', 'Becker', 'Geheime Lösung', 'Persönliche Rückmeldung', 'Weil es hell ist']);
    }

    public function test_without_enough_evaluated_hand_ins_there_are_no_starting_points(): void
    {
        $lesson = Lesson::factory()->for($this->schoolClass)->for($this->leader, 'teacher')->create(['subject' => 'Biologie']);
        $question = Question::factory()->for($lesson)->create(['prompt' => 'Einzelne Frage', 'position' => 1]);
        $submission = Submission::factory()->for($lesson)->for($this->students[0], 'student')->evaluated(10)->create();
        Answer::factory()->for($submission)->for($question)->create(['score' => 0]);

        $this->actingAs($this->leader)
            ->get($this->url())
            ->assertSee('Nothing to revise yet')
            ->assertDontSee('Einzelne Frage');
    }

    public function test_the_klassenleitung_sees_every_running_or_planned_note_and_the_substitute_only_their_own(): void
    {
        $other = $this->teacher(['name' => 'Frau Falk']);
        ClassSubstitution::factory()->for($this->schoolClass)->create(['substitute_id' => $this->substitute->id, 'note' => 'Bitte den Beamer vorher testen.']);
        ClassSubstitution::factory()->for($this->schoolClass)->upcoming()->create(['substitute_id' => $other->id, 'note' => 'Notiz nur für Frau Falk.']);
        ClassSubstitution::factory()->for($this->schoolClass)->ended()->create(['substitute_id' => $this->teacher()->id, 'note' => 'Alte Notiz einer früheren Vertretung.']);

        $this->actingAs($this->leader)
            ->get($this->url())
            ->assertSee(['Cover and handover note', 'Herr Vogel', 'Bitte den Beamer vorher testen.', 'Frau Falk', 'Notiz nur für Frau Falk.', '(planned)'])
            ->assertDontSee('Alte Notiz einer früheren Vertretung.');

        $this->actingAs($this->substitute)
            ->get($this->url())
            ->assertSee(['Handover note', 'Bitte den Beamer vorher testen.'])
            ->assertDontSee(['Notiz nur für Frau Falk.', 'Alte Notiz einer früheren Vertretung.', 'Cover and handover note']);
    }

    public function test_it_says_so_when_there_is_no_note_or_no_substitution(): void
    {
        $this->actingAs($this->leader)->get($this->url())->assertSee('No cover is set up at the moment.');

        ClassSubstitution::factory()->for($this->schoolClass)->create(['substitute_id' => $this->substitute->id, 'note' => null]);

        $this->actingAs($this->substitute)->get($this->url())->assertSee('No note provided.');
    }

    public function test_the_sheet_names_no_student_and_no_individual_result_anywhere(): void
    {
        $lesson = Lesson::factory()->for($this->schoolClass)->for($this->leader, 'teacher')->publishedFor()
            ->create(['summary' => 'Die Klasse hat Zellen behandelt.']);
        foreach ($this->students as $student) {
            Submission::factory()->for($lesson)->for($student, 'student')->evaluated(35)->create(['feedback' => 'Persönliches Feedback an '.$student->name]);
        }
        ClassSubstitution::factory()->for($this->schoolClass)->create(['substitute_id' => $this->substitute->id]);

        foreach ([$this->leader, $this->substitute, User::factory()->admin()->for($this->school)->create()] as $viewer) {
            $this->actingAs($viewer)
                ->get($this->url())
                ->assertOk()
                ->assertSee(['3 of 3 handed in', 'Die Klasse hat Zellen behandelt.', 'no names and no individual results'])
                ->assertDontSee(['Mia', 'Hoffmann', 'Ben Schneider', 'Lena', 'Becker', 'Persönliches Feedback', '35 %']);
        }
    }

    public function test_the_page_is_not_in_the_navigation(): void
    {
        $this->assertFalse(HandoverSheet::shouldRegisterNavigation());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function teacher(array $attributes = []): User
    {
        return User::factory()->teacher()->for($this->school)->create($attributes);
    }

    private function url(): string
    {
        return HandoverSheet::getUrl(['schoolClass' => $this->schoolClass->id]);
    }
}
