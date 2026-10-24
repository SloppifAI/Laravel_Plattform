<?php

namespace Tests\Feature;

use App\Filament\Pages\LearningProgress;
use App\Filament\Pages\ParentReport;
use App\Models\Lesson;
use App\Models\User;
use App\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private const array TABLES = ['users', 'school_classes', 'competencies', 'lessons', 'questions', 'lesson_user', 'submissions', 'answers', 'explanation_sessions', 'explanation_messages'];

    public function test_seeding_twice_creates_no_duplicates(): void
    {
        $this->seed(DatabaseSeeder::class);
        $countsAfterFirstRun = $this->rowCounts();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($countsAfterFirstRun, $this->rowCounts());
        $this->assertSame(15, $countsAfterFirstRun['users']);
        $this->assertSame(4, $countsAfterFirstRun['lessons']);
    }

    public function test_the_demo_logins_exist_with_their_roles(): void
    {
        $existingAdmin = User::factory()->create(['username' => 'hackathon', 'role' => Role::Student]);

        $this->seed(DatabaseSeeder::class);

        foreach (['hackathon' => Role::Admin, 'lehrer' => Role::Teacher, 'weber' => Role::Teacher, 'schueler' => Role::Student] as $username => $role) {
            $user = User::query()->where('username', $username)->sole();

            $this->assertSame($role, $user->role, $username);
            $this->assertTrue(Hash::check('hackathon2026', $user->password), $username);
        }

        $this->assertSame($existingAdmin->id, User::query()->where('username', 'hackathon')->value('id'));

        $mia = User::query()->where('username', 'schueler')->sole();
        $this->assertSame('Mia Hoffmann', $mia->name);
        $this->assertSame('7b', $mia->schoolClass->name);
        $this->assertSame('lehrer', $mia->schoolClass->teacher->username);
    }

    public function test_the_demo_lessons_cover_every_state(): void
    {
        $this->seed(DatabaseSeeder::class);
        $mia = User::query()->where('username', 'schueler')->sole();

        $photosynthesis = Lesson::query()->where('topic', 'Fotosynthese')->sole();
        $this->assertCount(8, $photosynthesis->students);
        $this->assertCount(4, $photosynthesis->questions);
        $this->assertTrue($photosynthesis->submissions()->where('student_id', $mia->id)->whereNotNull('evaluated_at')->exists());

        $pythagoras = Lesson::query()->where('topic', 'Satz des Pythagoras')->sole();
        $this->assertTrue($pythagoras->students->contains($mia));
        $this->assertFalse($pythagoras->submissions()->where('student_id', $mia->id)->exists());

        $draft = Lesson::query()->where('topic', 'Ursachen der Französischen Revolution')->sole();
        $this->assertFalse($draft->isPublished());
        $this->assertCount(0, $draft->questions);

        $remedial = Lesson::query()->where('topic', 'like', '%Förderaufgabe%')->sole();
        $this->assertSame(['ben.krueger', 'noah.wagner', 'schueler'], $remedial->students->pluck('username')->sort()->values()->all());

        $this->assertSame(1, $mia->explanationSessions()->whereNotNull('finished_at')->count());
        $this->assertSame(1, $mia->explanationSessions()->whereNull('finished_at')->count());
    }

    public function test_the_insight_pages_render_the_demo_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        $teacher = User::query()->where('username', 'lehrer')->sole();
        $mia = User::query()->where('username', 'schueler')->sole();

        $this->actingAs($teacher)
            ->get(LearningProgress::getUrl())
            ->assertOk()
            ->assertSee(['Ben Krüger', 'Probleme mathematisch lösen', 'Eigenes Lernen reflektieren', 'Satz des Pythagoras']);

        $this->actingAs($teacher)
            ->get(ParentReport::getUrl(['student' => $mia]))
            ->assertOk()
            ->assertSee(['Steigend', 'Offen', 'Noch nicht abgeschlossen', 'Probleme mathematisch lösen (Mathematik): 38 Punkte']);
    }

    public function test_seeders_do_not_depend_on_dev_packages(): void
    {
        foreach (glob(database_path('seeders/*.php')) as $seeder) {
            $this->assertDoesNotMatchRegularExpression('/::factory\(|\bfake\(|Faker/', file_get_contents($seeder), basename($seeder));
        }
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
