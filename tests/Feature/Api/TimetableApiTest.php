<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\V1\TokenController;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\TimetableEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/timetable?date=…: the user's own slots on the weekday of the date, ordered by start, with the bell times,
 * the class, subject and room, and the Tuto lesson of the same class, subject and date. Nothing about students.
 */
class TimetableApiTest extends TestCase
{
    use RefreshDatabase;

    /** A Monday. */
    private const string MONDAY = '2026-10-26';

    private User $teacher;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lernplattform.timetable.periods' => [1 => ['08:00', '08:45'], 2 => ['08:50', '09:35'], 3 => ['09:50', '10:35']]]);
        $this->teacher = User::factory()->teacher()->create();
        $this->class = SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => '7b']);
    }

    private function token(?User $user = null): string
    {
        return ($user ?? $this->teacher)->createToken('Laptop', TokenController::ABILITIES)->plainTextToken;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function slot(array $attributes = []): TimetableEntry
    {
        return TimetableEntry::factory()->for($this->class)->create(['teacher_id' => $this->teacher->id, 'weekday' => 1, ...$attributes]);
    }

    public function test_a_teacher_gets_only_their_own_slots_of_the_weekday_ordered_by_start(): void
    {
        $this->slot(['period' => 3, 'subject' => 'Mathematik', 'room' => 'B12']);
        $this->slot(['period' => 1, 'subject' => 'Deutsch', 'room' => null]);
        $this->slot(['period' => 2, 'subject' => 'Physik', 'weekday' => 2]);
        $colleague = User::factory()->teacher()->for($this->teacher->school)->create();
        TimetableEntry::factory()->for($this->class)->create(['teacher_id' => $colleague->id, 'weekday' => 1, 'period' => 2, 'subject' => 'Englisch']);
        // Even naming the teacher, a slot of a class of another school is none of theirs.
        TimetableEntry::factory()->for(SchoolClass::factory()->for(School::factory()))->create(['teacher_id' => $this->teacher->id, 'weekday' => 1, 'period' => 2, 'subject' => 'Andere Schule']);

        $this->withToken($this->token())->getJson(route('api.timetable.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->assertExactJson(['data' => [
                ['starts_at' => '08:00', 'ends_at' => '08:45', 'class' => ['id' => $this->class->id, 'name' => '7b'], 'subject' => 'Deutsch', 'room' => null, 'lesson_id' => null],
                ['starts_at' => '09:50', 'ends_at' => '10:35', 'class' => ['id' => $this->class->id, 'name' => '7b'], 'subject' => 'Mathematik', 'room' => 'B12', 'lesson_id' => null],
            ]]);
    }

    public function test_an_administration_gets_its_own_slots(): void
    {
        $admin = User::factory()->admin()->for($this->teacher->school)->create();
        TimetableEntry::factory()->for($this->class)->create(['teacher_id' => $admin->id, 'weekday' => 1, 'period' => 2, 'subject' => 'Informatik']);
        $this->slot(['period' => 1, 'subject' => 'Deutsch']);

        $this->withToken($this->token($admin))->getJson(route('api.timetable.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->assertJsonPath('data.*.subject', ['Informatik'])
            ->assertJsonPath('data.0.starts_at', '08:50');
    }

    public function test_the_lesson_of_the_same_class_subject_and_date_is_linked(): void
    {
        $this->slot(['period' => 1, 'subject' => 'Mathematik']);
        $this->slot(['period' => 2, 'subject' => 'Biologie']);
        $lesson = Lesson::factory()->for($this->class)->for($this->teacher, 'teacher')->create(['subject' => 'Mathematik', 'held_on' => self::MONDAY]);
        Lesson::factory()->for($this->class)->for($this->teacher, 'teacher')->create(['subject' => 'Mathematik', 'held_on' => '2026-10-19']);
        Lesson::factory()->for($this->class)->for($this->teacher, 'teacher')->create(['subject' => 'Chemie', 'held_on' => self::MONDAY]);
        Lesson::factory()->for($this->class)->for(User::factory()->teacher()->for($this->teacher->school), 'teacher')->create(['subject' => 'Biologie', 'held_on' => self::MONDAY]);
        Lesson::factory()->for($this->class)->for($this->teacher, 'teacher')->create(['subject' => 'Biologie', 'held_on' => self::MONDAY, 'practice_competency_id' => Competency::factory()]);

        Lesson::factory()->for($this->class)->for($this->teacher, 'teacher')->create(['subject' => 'Mathematik', 'held_on' => self::MONDAY]);
        $otherClass = SchoolClass::factory()->for($this->teacher, 'teacher')->create();
        Lesson::factory()->for($otherClass)->for($this->teacher, 'teacher')->create(['subject' => 'Biologie', 'held_on' => self::MONDAY]);

        $this->withToken($this->token())->getJson(route('api.timetable.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->assertJsonPath('data.*.lesson_id', [$lesson->id, null]);
    }

    public function test_an_administration_gets_the_lesson_of_a_colleague_of_its_school(): void
    {
        $admin = User::factory()->admin()->for($this->teacher->school)->create();
        TimetableEntry::factory()->for($this->class)->create(['teacher_id' => $admin->id, 'weekday' => 1, 'period' => 1, 'subject' => 'Mathematik']);
        $colleagues = Lesson::factory()->for($this->class)->for($this->teacher, 'teacher')->create(['subject' => 'Mathematik', 'held_on' => self::MONDAY]);

        $this->withToken($this->token($admin))->getJson(route('api.timetable.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->assertJsonPath('data.0.lesson_id', $colleagues->id);
    }

    public function test_a_slot_whose_period_has_no_bell_time_is_left_out(): void
    {
        $this->slot(['period' => 1, 'subject' => 'Deutsch']);
        $this->slot(['period' => 9, 'subject' => 'Gestrichen']);

        $this->withToken($this->token())->getJson(route('api.timetable.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->assertJsonPath('data.*.subject', ['Deutsch']);
    }

    public function test_a_day_without_lessons_or_a_teacher_without_a_timetable_get_an_empty_list(): void
    {
        $this->slot(['period' => 1]);

        $this->withToken($this->token())->getJson(route('api.timetable.index', ['date' => '2026-10-31']))
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token(User::factory()->teacher()->for($this->teacher->school)->create()))
            ->getJson(route('api.timetable.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_a_missing_or_invalid_date_is_refused(): void
    {
        foreach ([[], ['date' => '26.10.2026'], ['date' => '2026-02-30'], ['date' => 'heute']] as $query) {
            $this->withToken($this->token())->getJson(route('api.timetable.index', $query))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('date');
        }
    }
}
