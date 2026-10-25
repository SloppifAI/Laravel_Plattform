<?php

namespace Tests\Feature\Api;

use App\Filament\Pages\Auth\Login;
use App\Http\Controllers\Api\V1\TokenController;
use App\Models\ClassSubstitution;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a connected app sees and changes: the lessons the account may edit (LessonPolicy::update), the classes it may
 * place lessons in, new lessons there and the summaries; nothing of another teacher or another school, no
 * Förderaufgaben and nothing about students. Every route asks for its own ability.
 */
class LessonApiTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->teacher()->create();
        $this->class = SchoolClass::factory()->for($this->teacher->school)->create(['name' => '7b']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lesson(?User $teacher = null, ?SchoolClass $class = null, array $attributes = []): Lesson
    {
        return Lesson::factory()->for($class ?? $this->class)->for($teacher ?? $this->teacher, 'teacher')->create($attributes);
    }

    /**
     * @param  list<string>  $abilities
     */
    private function token(?User $user = null, array $abilities = TokenController::ABILITIES): string
    {
        return ($user ?? $this->teacher)->createToken('Tablet', $abilities)->plainTextToken;
    }

    private function otherSchoolsLesson(): Lesson
    {
        return Lesson::factory()->create();
    }

    public function test_a_teacher_lists_only_their_own_lessons_newest_first_without_practice_tasks(): void
    {
        $older = $this->lesson(attributes: ['held_on' => '2026-10-20']);
        $newer = $this->lesson(attributes: ['held_on' => '2026-10-23']);
        $this->lesson(User::factory()->teacher()->for($this->teacher->school)->create());
        $this->lesson(attributes: ['practice_competency_id' => Competency::factory()]);
        $this->otherSchoolsLesson();

        $this->withToken($this->token())->getJson(route('api.lessons.index'))
            ->assertOk()
            ->assertJsonPath('data.*.id', [$newer->id, $older->id])
            ->assertJsonPath('meta.total', 2);
    }

    public function test_an_administration_lists_the_lessons_of_its_school_without_practice_tasks(): void
    {
        $admin = User::factory()->admin()->for($this->teacher->school)->create();
        $own = $this->lesson(attributes: ['held_on' => '2026-10-23']);
        $colleagues = $this->lesson(User::factory()->teacher()->for($this->teacher->school)->create(), attributes: ['held_on' => '2026-10-22']);
        $this->lesson(attributes: ['practice_competency_id' => Competency::factory()]);
        $this->otherSchoolsLesson();

        $this->withToken($this->token($admin))->getJson(route('api.lessons.index'))
            ->assertOk()
            ->assertJsonPath('data.*.id', [$own->id, $colleagues->id]);
    }

    public function test_the_list_filters_by_the_day_the_lesson_was_held(): void
    {
        $this->lesson(attributes: ['held_on' => '2026-10-20']);
        $wanted = $this->lesson(attributes: ['held_on' => '2026-10-23']);

        $this->withToken($this->token())->getJson(route('api.lessons.index', ['held_on' => '2026-10-23']))
            ->assertOk()
            ->assertJsonPath('data.*.id', [$wanted->id]);

        $this->withToken($this->token())->getJson(route('api.lessons.index', ['held_on' => '23.10.2026']))
            ->assertJsonValidationErrors('held_on');
    }

    public function test_a_lesson_shows_what_the_app_needs_and_nothing_about_students(): void
    {
        $lesson = $this->lesson(attributes: ['subject' => 'Biologie', 'topic' => 'Fotosynthese', 'held_on' => '2026-10-23', 'summary' => 'Pflanzen bauen Zucker.']);
        $lesson->students()->attach(User::factory()->student($this->class)->create());

        $this->withToken($this->token())->getJson(route('api.lessons.show', $lesson))
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $lesson->id,
                'subject' => 'Biologie',
                'topic' => 'Fotosynthese',
                'held_on' => '2026-10-23',
                'class' => ['id' => $this->class->id, 'name' => '7b'],
                'summary' => 'Pflanzen bauen Zucker.',
                'summary_from_app' => false,
                'released' => false,
            ]]);
    }

    public function test_another_teachers_or_another_schools_lesson_is_refused(): void
    {
        $colleagues = $this->lesson(User::factory()->teacher()->for($this->teacher->school)->create());
        $otherSchools = $this->otherSchoolsLesson();
        $admin = User::factory()->admin()->for($this->teacher->school)->create();

        foreach ([$colleagues, $otherSchools] as $lesson) {
            $this->withToken($this->token())->getJson(route('api.lessons.show', $lesson))->assertForbidden();
            $this->withToken($this->token())->putJson(route('api.lessons.summary.update', $lesson), ['summary' => 'Fremd'])->assertForbidden();
        }

        // The guard keeps the user of the last request within a test.
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token($admin))->getJson(route('api.lessons.show', $colleagues))->assertOk();
        $this->withToken($this->token($admin))->getJson(route('api.lessons.show', $otherSchools))->assertForbidden();
        $this->withToken($this->token($admin))->putJson(route('api.lessons.summary.update', $otherSchools), ['summary' => 'Fremd'])->assertForbidden();
        $this->assertNull($otherSchools->refresh()->summary);
        $this->assertNull($colleagues->refresh()->summary);
    }

    public function test_the_app_sets_the_summary_and_the_plain_language_copy_goes(): void
    {
        $lesson = $this->lesson(attributes: ['summary' => 'Alt.']);
        $lesson->forceFill(['summary_simple' => 'Alt, einfach.'])->save();

        $this->withToken($this->token())->putJson(route('api.lessons.summary.update', $lesson), ['summary' => "  Neu aus der App.  \n"])
            ->assertOk()
            ->assertJsonPath('data.summary', 'Neu aus der App.')
            ->assertJsonPath('data.summary_from_app', true);

        $lesson->refresh();
        $this->assertSame('Neu aus der App.', $lesson->summary);
        $this->assertTrue($lesson->summary_from_app);
        $this->assertNull($lesson->summary_simple);
    }

    public function test_an_administration_sets_the_summary_of_a_lesson_of_its_school(): void
    {
        $lesson = $this->lesson();
        $admin = User::factory()->admin()->for($this->teacher->school)->create();

        $this->withToken($this->token($admin))->putJson(route('api.lessons.summary.update', $lesson), ['summary' => 'Von der Schulleitung.'])->assertOk();

        $this->assertSame('Von der Schulleitung.', $lesson->refresh()->summary);
    }

    public function test_the_summary_is_validated(): void
    {
        $lesson = $this->lesson(attributes: ['summary' => 'Bleibt.']);

        foreach ([[], ['summary' => '   '], ['summary' => ['Liste']], ['summary' => str_repeat('a', 5001)]] as $body) {
            $this->withToken($this->token())->putJson(route('api.lessons.summary.update', $lesson), $body)->assertJsonValidationErrors('summary');
        }

        $this->assertSame('Bleibt.', $lesson->refresh()->summary);
        $this->assertFalse($lesson->summary_from_app);
    }

    public function test_the_summary_of_a_practice_task_cannot_be_set(): void
    {
        $practiceTask = $this->lesson(attributes: ['practice_competency_id' => Competency::factory(), 'summary' => 'Förderaufgabe.']);

        $this->withToken($this->token())->putJson(route('api.lessons.summary.update', $practiceTask), ['summary' => 'Aus der App.'])
            ->assertJsonValidationErrors(['summary' => __('Die Zusammenfassung einer Förderaufgabe kann eine App nicht ändern.')]);

        $this->assertSame('Förderaufgabe.', $practiceTask->refresh()->summary);
    }

    public function test_a_practice_task_is_not_shown_to_an_app(): void
    {
        $practiceTask = $this->lesson(attributes: ['practice_competency_id' => Competency::factory(), 'topic' => 'Förderaufgabe: Bruchrechnung']);
        $admin = User::factory()->admin()->for($this->teacher->school)->create();

        $this->withToken($this->token())->getJson(route('api.lessons.show', $practiceTask))->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token($admin))->getJson(route('api.lessons.show', $practiceTask))->assertNotFound();
    }

    public function test_a_token_stops_working_once_the_account_may_no_longer_connect_an_app(): void
    {
        $lesson = $this->lesson();
        $token = $this->token();

        $this->withToken($token)->getJson(route('api.lessons.show', $lesson))->assertOk();

        $this->teacher->school->forceFill(['blocked_at' => now()])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson(route('api.lessons.show', $lesson))
            ->assertForbidden()
            ->assertJsonPath('message', Login::blockedSchoolMessage());

        $this->teacher->school->forceFill(['blocked_at' => null])->save();
        config(['lernplattform.dpa.final' => true]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->putJson(route('api.lessons.summary.update', $lesson), ['summary' => 'Neu'])->assertForbidden();

        config(['lernplattform.dpa.final' => false]);
        $this->teacher->forceFill(['role' => Role::Student])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson(route('api.lessons.index'))->assertForbidden();

        $this->assertNull($lesson->refresh()->summary);
    }

    public function test_the_list_filters_by_class(): void
    {
        $otherClass = SchoolClass::factory()->for($this->teacher->school)->create();
        $this->lesson();
        $wanted = $this->lesson(class: $otherClass);

        $this->withToken($this->token())->getJson(route('api.lessons.index', ['class_id' => $otherClass->id]))
            ->assertOk()
            ->assertJsonPath('data.*.id', [$wanted->id]);
    }

    public function test_a_teacher_lists_the_classes_they_teach(): void
    {
        $led = SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => '5a']);
        $assigned = SchoolClass::factory()->for($this->teacher->school)->create(['name' => '6c']);
        $assigned->teachers()->attach($this->teacher);
        $covered = SchoolClass::factory()->for($this->teacher->school)->create(['name' => '8d']);
        ClassSubstitution::factory()->for($covered)->create(['substitute_id' => $this->teacher->id]);
        ClassSubstitution::factory()->ended()->for(SchoolClass::factory()->for($this->teacher->school))->create(['substitute_id' => $this->teacher->id]);
        SchoolClass::factory()->create();

        $this->withToken($this->token())->getJson(route('api.classes.index'))
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $led->id, 'name' => '5a'],
                ['id' => $assigned->id, 'name' => '6c'],
                ['id' => $covered->id, 'name' => '8d'],
            ]]);
    }

    public function test_an_administration_lists_the_classes_of_its_school(): void
    {
        $admin = User::factory()->admin()->for($this->teacher->school)->create();
        SchoolClass::factory()->create();

        $this->withToken($this->token($admin))->getJson(route('api.classes.index'))
            ->assertOk()
            ->assertJsonPath('data.*.id', [$this->class->id]);
    }

    public function test_a_teacher_creates_a_lesson_in_a_class_they_teach(): void
    {
        $taught = SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => '5a']);

        $response = $this->withToken($this->token())->postJson(route('api.lessons.store'), [
            'school_class_id' => $taught->id,
            'subject' => 'Biologie',
            'topic' => 'Fotosynthese',
        ])
            ->assertCreated()
            ->assertJsonPath('data.subject', 'Biologie')
            ->assertJsonPath('data.held_on', today()->format('Y-m-d'))
            ->assertJsonPath('data.class', ['id' => $taught->id, 'name' => '5a'])
            ->assertJsonPath('data.summary', null)
            ->assertJsonPath('data.summary_from_app', false)
            ->assertJsonPath('data.released', false);

        $lesson = Lesson::query()->findOrFail($response->json('data.id'));
        $this->assertTrue($lesson->teacher->is($this->teacher));
        $this->assertSame('', $lesson->notes);
        $this->assertNull($lesson->practice_competency_id);
    }

    public function test_a_lesson_takes_the_day_and_the_notes_from_the_app(): void
    {
        $taught = SchoolClass::factory()->for($this->teacher, 'teacher')->create();

        $id = $this->withToken($this->token())->postJson(route('api.lessons.store'), [
            'school_class_id' => $taught->id,
            'subject' => 'Mathematik',
            'topic' => 'Brüche',
            'held_on' => '2026-10-23',
            'notes' => 'Tafelbild: Brüche kürzen',
        ])->assertCreated()->json('data.id');

        $lesson = Lesson::query()->findOrFail($id);
        $this->assertSame('2026-10-23', $lesson->held_on->format('Y-m-d'));
        $this->assertSame('Tafelbild: Brüche kürzen', $lesson->notes);
    }

    public function test_a_lesson_cannot_be_created_in_a_class_the_teacher_does_not_teach(): void
    {
        $otherSchoolsClass = SchoolClass::factory()->create();
        $admin = User::factory()->admin()->for($this->teacher->school)->create();
        $attempts = [
            [$this->teacher, $this->class],
            [$this->teacher, $otherSchoolsClass],
            [$admin, $otherSchoolsClass],
        ];

        foreach ($attempts as [$user, $class]) {
            $this->withToken($this->token($user))->postJson(route('api.lessons.store'), [
                'school_class_id' => $class->id,
                'subject' => 'Biologie',
                'topic' => 'Fremd',
            ])->assertForbidden();
            $this->app['auth']->forgetGuards();
        }

        $this->assertSame(0, Lesson::query()->count());
    }

    public function test_an_administration_creates_a_lesson_in_any_class_of_its_school_as_its_own(): void
    {
        $admin = User::factory()->admin()->for($this->teacher->school)->create();

        $id = $this->withToken($this->token($admin))->postJson(route('api.lessons.store'), [
            'school_class_id' => $this->class->id,
            'subject' => 'Deutsch',
            'topic' => 'Gedichte',
        ])->assertCreated()->json('data.id');

        $this->assertTrue(Lesson::query()->findOrFail($id)->teacher->is($admin));
    }

    public function test_a_new_lesson_is_validated(): void
    {
        $taught = SchoolClass::factory()->for($this->teacher, 'teacher')->create();

        $this->withToken($this->token())->postJson(route('api.lessons.store'), [])
            ->assertJsonValidationErrors(['school_class_id', 'subject', 'topic']);
        $this->withToken($this->token())->postJson(route('api.lessons.store'), [
            'school_class_id' => $taught->id,
            'subject' => str_repeat('a', 256),
            'topic' => 'Thema',
            'held_on' => '23.10.2026',
        ])->assertJsonValidationErrors(['subject', 'held_on']);
        $this->withToken($this->token())->postJson(route('api.lessons.store'), [
            'school_class_id' => 999999,
            'subject' => 'Biologie',
            'topic' => 'Thema',
        ])->assertNotFound();

        $this->assertSame(0, Lesson::query()->count());
    }

    public function test_every_route_asks_for_its_ability(): void
    {
        $lesson = $this->lesson();
        $taught = SchoolClass::factory()->for($this->teacher, 'teacher')->create();
        $requests = [
            'lessons:read' => fn (string $token): array => [
                $this->withToken($token)->getJson(route('api.classes.index')),
                $this->withToken($token)->getJson(route('api.lessons.index')),
                $this->withToken($token)->getJson(route('api.lessons.show', $lesson)),
            ],
            'lessons:create' => fn (string $token): array => [
                $this->withToken($token)->postJson(route('api.lessons.store'), ['school_class_id' => $taught->id, 'subject' => 'Biologie', 'topic' => 'Thema']),
            ],
            'lessons:summary' => fn (string $token): array => [
                $this->withToken($token)->putJson(route('api.lessons.summary.update', $lesson), ['summary' => 'Aus der App.']),
            ],
        ];

        foreach ($requests as $ability => $send) {
            foreach ($send($this->token(abilities: array_values(array_diff(TokenController::ABILITIES, [$ability])))) as $response) {
                $response->assertForbidden();
            }

            $this->app['auth']->forgetGuards();

            foreach ($send($this->token(abilities: [$ability])) as $response) {
                $response->assertSuccessful();
            }

            $this->app['auth']->forgetGuards();
        }
    }

    public function test_the_api_answers_in_json_without_an_accept_header(): void
    {
        $this->get(route('api.lessons.index'))->assertUnauthorized()->assertJsonStructure(['message']);
    }
}
