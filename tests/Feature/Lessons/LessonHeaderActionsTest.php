<?php

namespace Tests\Feature\Lessons;

use App\Filament\Pages\ClassBook;
use App\Filament\Pages\RecordLesson;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Models\ClassBookEntry;
use App\Models\ClassSubstitution;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The header of the lesson page: the way into the Klassenbuch entry of the lesson, and Tuto-Mikro only where its page opens.
 */
class LessonHeaderActionsTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolClass = SchoolClass::factory()->create(['name' => '7b']);
        $this->teacher = $this->schoolClass->teacher;
    }

    public function test_the_class_book_action_starts_the_draft_and_opens_it(): void
    {
        $lesson = $this->lesson(['subject' => 'Biologie', 'topic' => 'Fotosynthese']);

        Livewire::actingAs($this->teacher)->test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->assertActionVisible('classBook')
            ->assertActionHasLabel('classBook', 'Class register entry')
            ->callAction('classBook');

        $entry = ClassBookEntry::query()->where('lesson_id', $lesson->id)->sole();
        $this->assertSame('Fotosynthese', $entry->topic);
        $this->assertFalse($entry->isConfirmed());
    }

    public function test_an_open_entry_goes_to_its_edit_dialog_in_the_book_of_the_class(): void
    {
        $lesson = $this->lesson(['topic' => 'Fotosynthese']);

        Livewire::actingAs($this->teacher)->test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->callAction('classBook')
            ->assertRedirect($this->bookUrl($entry = ClassBookEntry::query()->sole(), openEditDialog: true));

        // The link really lands in the dialog of that entry.
        $mounted = Livewire::actingAs($this->teacher)
            ->withQueryParams(['klasse' => $this->schoolClass->id, 'tableAction' => 'edit', 'tableActionRecord' => $entry->id])
            ->test(ClassBook::class)
            ->call('mountDefaultActions') // what the browser does on load (wire:init)
            ->instance()->mountedActions[0];

        $this->assertSame('edit', $mounted['name']);
        $this->assertEquals($entry->id, $mounted['context']['recordKey']);
    }

    public function test_an_existing_entry_is_opened_and_not_made_twice(): void
    {
        $lesson = $this->lesson();
        $entry = ClassBookEntry::factory()->create(['lesson_id' => $lesson->id, 'school_class_id' => $this->schoolClass->id, 'teacher_id' => $this->teacher->id, 'topic' => 'Von Hand']);

        Livewire::actingAs($this->teacher)->test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->callAction('classBook')
            ->assertRedirect($this->bookUrl($entry, openEditDialog: true));

        $this->assertSame(1, ClassBookEntry::query()->count());
        $this->assertSame('Von Hand', $entry->refresh()->topic);
    }

    public function test_a_confirmed_entry_is_shown_in_the_book_without_the_edit_dialog(): void
    {
        $lesson = $this->lesson();
        $entry = ClassBookEntry::factory()->confirmed()->create(['lesson_id' => $lesson->id, 'school_class_id' => $this->schoolClass->id, 'teacher_id' => $this->teacher->id]);

        Livewire::actingAs($this->teacher)->test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->callAction('classBook')
            ->assertRedirect($this->bookUrl($entry, openEditDialog: false));
    }

    public function test_the_administration_of_the_school_gets_the_action_too(): void
    {
        $lesson = $this->lesson();
        $admin = User::factory()->admin()->for($this->schoolClass->school)->create();

        Livewire::actingAs($admin)->test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->assertActionVisible('classBook')
            ->callAction('classBook');

        $this->assertSame(1, ClassBookEntry::query()->where('lesson_id', $lesson->id)->count());
    }

    public function test_nobody_else_gets_the_class_book_action_and_nothing_is_made_by_force(): void
    {
        // A teacher who held the lesson as a substitute and whose period is over no longer keeps the book of the class.
        $substitute = User::factory()->teacher()->for($this->schoolClass->school)->create();
        ClassSubstitution::factory()->ended()->for($this->schoolClass)->create(['substitute_id' => $substitute->id]);
        $lesson = $this->lesson(teacher: $substitute);

        // Raw calls, like a browser would that ignores the hidden button.
        Livewire::actingAs($substitute)->test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->assertActionHidden('classBook')
            ->call('mountAction', 'classBook')
            ->call('callMountedAction');

        $this->assertSame(0, ClassBookEntry::query()->count());
    }

    public function test_a_practice_task_has_no_class_book_entry(): void
    {
        $task = $this->lesson(['practice_competency_id' => Competency::factory()->create()->id]);

        Livewire::actingAs($this->teacher)->test(EditLesson::class, ['record' => $task->getRouteKey()])
            ->assertActionHidden('classBook');
    }

    public function test_tuto_mikro_is_offered_where_the_page_opens(): void
    {
        $lesson = $this->lesson(['subject' => 'Biologie']);

        Livewire::actingAs($this->teacher)->test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->assertActionVisible('record');
    }

    public function test_tuto_mikro_is_hidden_for_a_subject_the_school_keeps_free_of_it(): void
    {
        $lesson = $this->lesson(['subject' => 'Religion']);

        Livewire::actingAs($this->teacher)->test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->assertActionHidden('record');

        // The school's own list replaces the suggested one.
        $this->schoolClass->school->forceFill(['mikro_blocked_subjects' => ['Biologie']])->save();
        $biology = $this->lesson(['subject' => ' biologie ']);

        Livewire::actingAs($this->teacher)->test(EditLesson::class, ['record' => $biology->getRouteKey()])
            ->assertActionHidden('record');
        Livewire::actingAs($this->teacher)->test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->assertActionVisible('record');
    }

    public function test_tuto_mikro_is_gone_for_a_teacher_who_no_longer_teaches_the_class(): void
    {
        $substitute = User::factory()->teacher()->for($this->schoolClass->school)->create();
        $assigned = User::factory()->teacher()->for($this->schoolClass->school)->create();
        $this->schoolClass->teachers()->attach($assigned);
        $substitution = ClassSubstitution::factory()->for($this->schoolClass)->create(['substitute_id' => $substitute->id]);
        $substituteLesson = $this->lesson(teacher: $substitute);
        $assignedLesson = $this->lesson(teacher: $assigned);

        // Still teaching: button and page are there.
        foreach ([[$substitute, $substituteLesson], [$assigned, $assignedLesson]] as [$teacher, $lesson]) {
            Livewire::actingAs($teacher)->test(EditLesson::class, ['record' => $lesson->getRouteKey()])->assertActionVisible('record');
            $this->actingAs($teacher)->get(RecordLesson::getUrl(['lesson' => $lesson]))->assertOk();
        }

        $substitution->end();
        $this->schoolClass->teachers()->detach($assigned);

        // The lessons stay theirs, the class's roster and consent decisions do not.
        foreach ([[$substitute, $substituteLesson], [$assigned, $assignedLesson]] as [$teacher, $lesson]) {
            Livewire::actingAs($teacher)->test(EditLesson::class, ['record' => $lesson->getRouteKey()])->assertOk()->assertActionHidden('record');
            $this->actingAs($teacher)->get(RecordLesson::getUrl(['lesson' => $lesson]))->assertForbidden();
        }
    }

    public function test_the_tuto_mikro_page_answers_403_for_a_blocked_subject_and_a_practice_task_by_url(): void
    {
        $blocked = $this->lesson(['subject' => 'Religion']);
        $task = $this->lesson(['practice_competency_id' => Competency::factory()->create()->id]);
        $fine = $this->lesson(['subject' => 'Biologie']);

        $this->actingAs($this->teacher)->get(RecordLesson::getUrl(['lesson' => $blocked]))->assertForbidden();
        $this->actingAs($this->teacher)->get(RecordLesson::getUrl(['lesson' => $task]))->assertForbidden();
        $this->actingAs($this->teacher)->get(RecordLesson::getUrl(['lesson' => $fine]))->assertOk();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lesson(array $attributes = [], ?User $teacher = null): Lesson
    {
        return Lesson::factory()->for($this->schoolClass)->for($teacher ?? $this->teacher, 'teacher')->create($attributes);
    }

    private function bookUrl(ClassBookEntry $entry, bool $openEditDialog): string
    {
        return ClassBook::getUrl([
            'klasse' => $this->schoolClass->id,
            ...($openEditDialog ? ['tableAction' => 'edit', 'tableActionRecord' => $entry->id] : []),
        ]);
    }
}
