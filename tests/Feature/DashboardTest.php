<?php

namespace Tests\Feature;

use App\Filament\Widgets\AdminStatsWidget;
use App\Filament\Widgets\TeacherStatsWidget;
use App\Models\Answer;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_students_see_their_own_homework_score_and_feedback_only(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $student = User::factory()->student($schoolClass)->create(['name' => 'Mia Hoffmann']);
        $classmate = User::factory()->student($schoolClass)->create();

        Lesson::factory()->for($schoolClass)->publishedFor([$student])->create(['topic' => 'Photosynthese', 'due_on' => today()->addDay()]);
        $handedIn = Lesson::factory()->for($schoolClass)->publishedFor([$student])->create(['topic' => 'Zellaufbau']);
        Submission::factory()->for($handedIn)->for($student, 'student')->create();
        Submission::factory()->for($student, 'student')->evaluated(80)->create(['feedback' => 'Du hast den Ablauf klar erklärt.']);

        Lesson::factory()->for($schoolClass)->publishedFor([$classmate])->create(['topic' => 'Bruchrechnung']);
        Submission::factory()->for($classmate, 'student')->evaluated(20)->create(['feedback' => 'Rückmeldung für jemand anderen.']);

        $this->actingAs($student)
            ->get('/')
            ->assertOk()
            ->assertSee('Hallo Mia!')
            ->assertSee('1 offene Hausaufgabe')
            ->assertSee('Photosynthese')
            ->assertSee('Morgen fällig')
            ->assertSee('80')
            ->assertSee('Gut verstanden')
            ->assertSee('Du hast den Ablauf klar erklärt.')
            ->assertSee('Thema erklären')
            ->assertSee('Hier lohnt sich Üben')
            ->assertDontSee('Zellaufbau')
            ->assertDontSee('Bruchrechnung')
            ->assertDontSee('Rückmeldung für jemand anderen.')
            ->assertDontSee('Deine letzten Unterrichtsstunden')
            ->assertDontSee('Abgaben offener Hausaufgaben')
            ->assertDontSee('Abgaben diese Woche');
    }

    public function test_students_without_homework_get_encouraging_empty_states(): void
    {
        $this->actingAs(User::factory()->student()->create(['name' => 'Ben Krüger']))
            ->get('/')
            ->assertOk()
            ->assertSee('Hallo Ben!')
            ->assertSee('Gerade ist nichts offen.')
            ->assertSee('Alles erledigt.')
            ->assertSee('Gib deine erste Hausaufgabe ab')
            ->assertSee('Sobald deine Hausaufgaben bewertet sind');
    }

    public function test_teachers_see_figures_and_recent_lessons_of_their_own_lessons(): void
    {
        $teacher = User::factory()->teacher()->create();
        $schoolClass = SchoolClass::factory()->create(['teacher_id' => $teacher->id, 'name' => '7b']);
        [$weakStudent] = User::factory()->student($schoolClass)->count(2)->create();

        $lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->publishedFor()->create(['topic' => 'Photosynthese']);
        $submission = Submission::factory()->for($lesson)->for($weakStudent, 'student')->evaluated(40)->create();
        $question = Question::factory()->for($lesson)->for(Competency::factory())->create();
        Answer::factory()->for($submission)->for($question)->create(['score' => 30]);

        Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->create(['topic' => 'Zellatmung']);
        Lesson::factory()->create(['topic' => 'Stunde einer anderen Lehrkraft']);

        $this->actingAs($teacher)
            ->get('/')
            ->assertOk()
            ->assertSee('Deine letzten Unterrichtsstunden')
            ->assertSee('Neue Unterrichtsstunde')
            ->assertSee('Photosynthese')
            ->assertSee('Klasse 7b')
            ->assertSee('1 / 2 abgegeben')
            ->assertSee('Zellatmung')
            ->assertSee('Entwurf')
            ->assertDontSee('Stunde einer anderen Lehrkraft')
            ->assertDontSee('Als Nächstes fällig')
            ->assertDontSee('Abgaben diese Woche');

        $this->actingAs($teacher);

        Livewire::test(TeacherStatsWidget::class)
            ->assertSeeTextInOrder([
                'Abgaben offener Hausaufgaben', '1 / 2', 'aus 1 laufenden Hausaufgabe',
                'Ø Verständnis der letzten Hausaufgabe', '40 %', 'Förderbedarf: Photosynthese',
                'Schüler:innen mit Förderbedarf', '1', 'unter 50 % in mindestens einer Kompetenz',
            ]);
    }

    public function test_admins_see_school_wide_figures_and_shortcuts(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->teacher()->count(2)->create();
        User::factory()->student()->count(3)->create();
        Submission::factory()->create();

        $this->actingAs($admin)
            ->get('/')
            ->assertOk()
            ->assertSee('Abgaben diese Woche')
            ->assertSee('Benutzer, Klassen und das Kompetenzraster pflegen.')
            ->assertSee('Benutzer')
            ->assertDontSee('Deine letzten Unterrichtsstunden')
            ->assertDontSee('Als Nächstes fällig');

        // Factories create class teachers and lesson teachers on the side, so per-role counts come from the database.
        $countOf = fn (Role $role): string => (string) User::query()->withRole($role)->count();

        Livewire::test(AdminStatsWidget::class)
            ->assertSeeTextInOrder([
                'Schüler:innen', $countOf(Role::Student),
                'Lehrkräfte', $countOf(Role::Teacher),
                'Administration', '1',
                'Klassen', (string) SchoolClass::query()->count(),
                'Unterrichtsstunden', '1',
                'Abgaben diese Woche', '1',
            ]);
    }
}
