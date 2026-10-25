<?php

namespace Tests\Feature\Substitutions;

use App\Models\ClassSubstitution;
use App\Models\Lesson;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * An ended substitution and its handover note expire with the learning data (PruneLearningData), but never ahead of the
 * lessons of the substitute in the class, because LessonPolicy::viewResults() recognises a former substitute by the row.
 */
class SubstitutionRetentionTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private User $substitute;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'lernplattform.retention.school_years_kept' => 2,
            'lernplattform.retention.school_year_starts' => '08-01',
            'lernplattform.retention.one_year_rule_from' => null,
        ]);

        // The cut-off is 2025-08-01.
        $this->travelTo('2026-10-24');

        $school = School::factory()->create();
        $this->schoolClass = SchoolClass::factory()->for($school)->create(['teacher_id' => User::factory()->teacher()->for($school)->create()->id]);
        $this->substitute = User::factory()->teacher()->for($school)->create();
    }

    /**
     * A substitution of ten days that ended on the given day and was last touched on `$touchedOn` (default: that day).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function substitution(string $endsOn, ?string $touchedOn = null, array $attributes = []): ClassSubstitution
    {
        return ClassSubstitution::factory()->for($this->schoolClass)->create([
            'substitute_id' => $this->substitute->id,
            'starts_on' => CarbonImmutable::parse($endsOn)->subDays(9)->toDateString(),
            'ends_on' => $endsOn,
            'note' => 'Frau Schulze ist krank',
            'created_at' => $touchedOn ?? $endsOn,
            'updated_at' => $touchedOn ?? $endsOn,
            ...$attributes,
        ]);
    }

    private function expiredLesson(): Lesson
    {
        return Lesson::factory()->for($this->schoolClass)->for($this->substitute, 'teacher')->create(['held_on' => '2025-07-20', 'created_at' => '2025-07-20', 'updated_at' => '2025-07-20']);
    }

    public function test_an_ended_substitution_is_deleted_with_its_note_after_the_kept_school_years(): void
    {
        $expired = $this->substitution('2025-07-31');
        $firstKeptDay = $this->substitution('2025-08-01');
        $retouched = $this->substitution('2025-07-31', touchedOn: '2026-10-01');
        $running = $this->substitution(today()->addDays(3)->toDateString(), touchedOn: today()->toDateString());
        $upcoming = ClassSubstitution::factory()->for($this->schoolClass)->upcoming()->create(['substitute_id' => $this->substitute->id]);
        // Lessons of the substitute in another class do not hold the row back.
        Lesson::factory()->for(SchoolClass::factory()->for($this->schoolClass->school)->create(), 'schoolClass')->for($this->substitute, 'teacher')->create();

        $this->artisan('lernplattform:prune-learning-data')->assertSuccessful();

        $this->assertModelMissing($expired);
        foreach ([$firstKeptDay, $retouched, $running, $upcoming] as $kept) {
            $this->assertModelExists($kept);
        }
        $this->assertModelExists($this->schoolClass);
        $this->assertModelExists($this->substitute);
    }

    public function test_a_dry_run_counts_the_substitutions_and_keeps_them(): void
    {
        $expired = $this->substitution('2025-07-31');

        $this->artisan('lernplattform:prune-learning-data --dry-run')
            ->expectsTable(['Gegenstand', 'würde gelöscht'], [
                ['Unterrichtsstunden', 0],
                ['Fragen', 0],
                ['Rückfragen', 0],
                ['Abgaben', 0],
                ['Antworten', 0],
                ['Erklär\'s-mir-Gespräche', 0],
                ['Nachrichten', 0],
                ['Klassenbuch-Einträge', 0],
                ['Vertretungen', 1],
                ['Arbeitsblatt-Dateien', 0],
            ])
            ->assertSuccessful();

        $this->assertModelExists($expired);
    }

    public function test_a_former_substitute_does_not_regain_the_results_of_a_lesson_that_outlives_the_substitution_row(): void
    {
        $expired = $this->substitution('2025-07-31');
        // Held in the period, but changed after the cut-off, so it is kept.
        $lesson = Lesson::factory()->for($this->schoolClass)->for($this->substitute, 'teacher')->create(['held_on' => '2025-07-20']);
        $this->assertFalse($this->substitute->can('viewResults', $lesson));

        $this->artisan('lernplattform:prune-learning-data')->assertSuccessful();

        $this->assertModelExists($lesson);
        $this->assertModelExists($expired);
        $this->assertFalse($this->substitute->fresh()->can('viewResults', $lesson->fresh()));

        $lesson->delete();
        $this->artisan('lernplattform:prune-learning-data')->assertSuccessful();

        $this->assertModelMissing($expired);
    }

    public function test_a_substitution_goes_the_run_after_the_last_lesson_of_the_substitute_in_the_class(): void
    {
        $expired = $this->substitution('2025-07-31');
        $lesson = $this->expiredLesson();

        $this->artisan('lernplattform:prune-learning-data')->assertSuccessful();

        $this->assertModelMissing($lesson);
        $this->assertModelExists($expired);

        $this->artisan('lernplattform:prune-learning-data')->assertSuccessful();

        $this->assertModelMissing($expired);
    }
}
