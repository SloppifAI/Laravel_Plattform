<?php

namespace Tests\Feature\ClassBook;

use App\Filament\Pages\ClassBook;
use App\Models\ClassBookEntry;
use App\Models\ClassSubstitution;
use App\Models\Competency;
use App\Models\Lesson;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\ClassBookCsv;
use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ClassBookPageTest extends TestCase
{
    use CreatesClassBookFixtures;
    use RefreshDatabase;

    private School $school;

    private SchoolClass $schoolClass;

    /** @var Collection<int, User> */
    private Collection $students;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        [$this->schoolClass, $this->students, $this->teacher] = $this->classWithStudents(3, $this->school);
    }

    private function page(?User $user = null, ?SchoolClass $schoolClass = null)
    {
        return Livewire::actingAs($user ?? $this->teacher)
            ->withQueryParams($schoolClass ? ['klasse' => $schoolClass->getKey()] : [])
            ->test(ClassBook::class);
    }

    private function entry(array $attributes = []): ClassBookEntry
    {
        return ClassBookEntry::factory()->create(['school_class_id' => $this->schoolClass->getKey(), 'teacher_id' => $this->teacher->getKey(), ...$attributes]);
    }

    /**
     * Mounts and runs a table action through raw Livewire calls, like a browser would that ignores the hidden button.
     */
    private function forceAction(Testable $page, string $name, ClassBookEntry $entry): void
    {
        $page->call('mountAction', $name, [], ['table' => true, 'recordKey' => (string) $entry->getKey()])->call('callMountedAction');
    }

    private function admin(): User
    {
        return User::factory()->for($this->school)->admin()->create();
    }

    public function test_only_teachers_and_the_administration_open_the_page(): void
    {
        $this->get(ClassBook::getUrl())->assertRedirect();

        $this->actingAs($this->students->first())->get(ClassBook::getUrl())->assertForbidden();
        $this->actingAs(User::factory()->operator()->create())->get(ClassBook::getUrl())->assertForbidden();
        $this->actingAs($this->teacher)->get(ClassBook::getUrl())->assertOk()->assertSee('Class register');
        $this->actingAs($this->admin())->get(ClassBook::getUrl())->assertOk();
    }

    public function test_students_and_operators_cannot_mount_the_component_and_see_no_navigation_entry(): void
    {
        $this->entry(['topic' => 'Geheimes Thema']);

        Livewire::actingAs($this->students->first())->test(ClassBook::class)->assertForbidden();
        Livewire::actingAs(User::factory()->operator()->create())->test(ClassBook::class)->assertForbidden();

        $this->actingAs($this->students->first());
        $this->assertFalse(ClassBook::canAccess());
        $this->actingAs($this->teacher);
        $this->assertTrue(ClassBook::canAccess());
    }

    public function test_a_teacher_sees_only_the_classes_they_lead_or_are_assigned_to(): void
    {
        $assignedClass = SchoolClass::factory()->create(['school_id' => $this->school->getKey(), 'name' => 'Zugeordnete Klasse']);
        $assignedClass->teachers()->attach($this->teacher);
        $foreign = SchoolClass::factory()->create(['school_id' => $this->school->getKey(), 'name' => 'Fremde Klasse']);

        $this->page()
            ->assertSee($this->schoolClass->name)
            ->assertSee('Zugeordnete Klasse')
            ->assertDontSee('Fremde Klasse');

        $this->page(schoolClass: $foreign)->assertForbidden();
        $this->page(schoolClass: $assignedClass)->assertSuccessful();
    }

    public function test_the_administration_sees_every_class_of_its_own_school_but_no_other(): void
    {
        $second = SchoolClass::factory()->create(['school_id' => $this->school->getKey(), 'name' => 'Zweite Klasse']);
        $foreignSchoolClass = SchoolClass::factory()->create(['name' => 'Klasse der anderen Schule']);

        $this->page($this->admin())
            ->assertSee($this->schoolClass->name)
            ->assertSee('Zweite Klasse')
            ->assertDontSee('Klasse der anderen Schule');

        $this->page($this->admin(), $second)->assertSuccessful();
        $this->page($this->admin(), $foreignSchoolClass)->assertForbidden();
    }

    public function test_teachers_and_administrators_of_another_school_get_a_403(): void
    {
        $this->entry();
        $otherSchool = School::factory()->create();

        $this->page(User::factory()->for($otherSchool)->admin()->create(), $this->schoolClass)->assertForbidden();
        $this->page(User::factory()->for($otherSchool)->teacher()->create(), $this->schoolClass)->assertForbidden();
    }

    public function test_a_teacher_of_the_school_who_does_not_teach_the_class_gets_a_403(): void
    {
        $this->entry();
        $bystander = User::factory()->for($this->school)->teacher()->create();

        $this->page($bystander, $this->schoolClass)->assertForbidden();
    }

    public function test_switching_to_a_foreign_class_in_the_browser_is_a_403(): void
    {
        $foreign = SchoolClass::factory()->create(['school_id' => $this->school->getKey()]);

        $this->page()->set('classId', $foreign->getKey())->assertForbidden();
    }

    public function test_a_teacher_without_a_class_gets_a_hint_instead_of_a_table(): void
    {
        $lonely = User::factory()->for($this->school)->teacher()->create();

        $this->page($lonely)->assertSee('No class assigned')->assertDontSee('Create draft from lesson');
    }

    public function test_entries_are_listed_newest_first_with_status_label_absences_and_homework(): void
    {
        [$anna, $ben] = $this->students;
        $old = $this->entry(['held_on' => '2026-10-01', 'topic' => 'Altes Thema']);
        $new = $this->entry(['held_on' => '2026-10-08', 'topic' => 'Neues Thema', 'homework' => 'Seite 12', 'period' => 3])->forceFill(['confirmed_at' => now()]);
        $new->absent_student_ids = [$anna->getKey(), $ben->getKey()];
        $new->save();
        $other = ClassBookEntry::factory()->create(['topic' => 'Andere Klasse']);

        $this->page()
            ->assertCanSeeTableRecords([$new, $old], inOrder: true)
            ->assertCanNotSeeTableRecords([$other])
            ->assertSee('Confirmed')
            ->assertSee('Draft')
            ->assertSee('Seite 12')
            ->assertSee('Lesson 3')
            ->assertSee($anna->name)
            ->assertSee($ben->name);
    }

    public function test_the_administration_sees_the_absences_too(): void
    {
        $this->entry(['absent_student_ids' => [$this->students->first()->getKey()]]);

        $this->page($this->admin(), $this->schoolClass)->assertSee($this->students->first()->name);
    }

    public function test_a_draft_is_made_from_one_of_the_teachers_lessons_without_an_entry(): void
    {
        $lesson = $this->lessonOf($this->schoolClass, $this->teacher, ['subject' => 'Biologie', 'topic' => 'Das Herz', 'summary' => 'Herz und Kreislauf.']);

        $this->page()
            ->callAction('createDraft', ['lesson_id' => $lesson->getKey()])
            ->assertHasNoFormErrors();

        $entry = ClassBookEntry::query()->sole();
        $this->assertSame($lesson->getKey(), $entry->lesson_id);
        $this->assertSame('Das Herz', $entry->topic);
        $this->assertSame('Herz und Kreislauf.', $entry->content);
        $this->assertFalse($entry->isConfirmed());
    }

    public function test_lessons_with_an_entry_or_of_a_colleague_are_not_offered(): void
    {
        $colleague = User::factory()->for($this->school)->teacher()->create();
        $this->schoolClass->teachers()->attach($colleague);
        $taken = $this->lessonOf($this->schoolClass, $this->teacher);
        $this->entry(['lesson_id' => $taken->getKey()]);
        $colleaguesLesson = $this->lessonOf($this->schoolClass, $colleague);

        $this->page()
            ->callAction('createDraft', ['lesson_id' => $taken->getKey()])
            ->assertHasFormErrors(['lesson_id']);
        $this->page()
            ->callAction('createDraft', ['lesson_id' => $colleaguesLesson->getKey()])
            ->assertHasFormErrors(['lesson_id']);

        $this->assertSame(1, ClassBookEntry::query()->count());
    }

    public function test_a_practice_task_is_not_offered_and_gives_no_draft(): void
    {
        $practice = $this->lessonOf($this->schoolClass, $this->teacher, ['practice_competency_id' => Competency::factory()->create()->getKey()]);

        $this->page()
            ->callAction('createDraft', ['lesson_id' => $practice->getKey()])
            ->assertHasFormErrors(['lesson_id']);
        $this->page($this->admin(), $this->schoolClass)
            ->callAction('createDraft', ['lesson_id' => $practice->getKey()])
            ->assertHasFormErrors(['lesson_id']);

        $this->assertSame(0, ClassBookEntry::query()->count());
    }

    public function test_the_administration_makes_a_draft_from_any_lesson_of_the_class_for_that_lessons_teacher(): void
    {
        $lesson = $this->lessonOf($this->schoolClass, $this->teacher);

        $this->page($this->admin(), $this->schoolClass)->callAction('createDraft', ['lesson_id' => $lesson->getKey()]);

        $this->assertSame($this->teacher->getKey(), ClassBookEntry::query()->sole()->teacher_id);
    }

    public function test_a_second_draft_for_the_same_lesson_is_not_made(): void
    {
        $lesson = $this->lessonOf($this->schoolClass, $this->teacher);
        $page = $this->page();

        $page->callAction('createDraft', ['lesson_id' => $lesson->getKey()]);
        $page->callAction('createDraft', ['lesson_id' => $lesson->getKey()])->assertHasFormErrors(['lesson_id']);

        $this->assertSame(1, ClassBookEntry::query()->count());
    }

    public function test_the_entry_is_edited_with_the_period_and_the_absent_students(): void
    {
        [$anna, $ben] = $this->students;
        $entry = $this->entry(['subject' => 'Mathe']);

        $this->page()
            ->callAction(TestAction::make('edit')->table($entry), [
                'held_on' => '2026-10-07',
                'period' => 4,
                'subject' => 'Biologie',
                'topic' => 'Das Herz',
                'content' => 'Aufbau des Herzens',
                'homework' => 'Arbeitsblatt 3',
                'absent_student_ids' => [$anna->getKey(), $ben->getKey()],
                'remarks' => 'Test nächste Woche',
            ])
            ->assertHasNoFormErrors();

        $entry->refresh();
        $this->assertSame('2026-10-07', $entry->held_on->toDateString());
        $this->assertSame(4, $entry->period);
        $this->assertSame('Biologie', $entry->subject);
        $this->assertSame('Aufbau des Herzens', $entry->content);
        $this->assertSame('Arbeitsblatt 3', $entry->homework);
        $this->assertEqualsCanonicalizing([$anna->getKey(), $ben->getKey()], $entry->absent_student_ids);
        $this->assertSame('Test nächste Woche', $entry->remarks);
        $this->assertFalse($entry->isConfirmed());
    }

    public function test_the_edit_form_is_prefilled_with_the_entry(): void
    {
        $entry = $this->entry(['topic' => 'Das Herz', 'content' => 'Aufbau', 'absent_student_ids' => [$this->students->first()->getKey()]]);

        $this->page()
            ->mountAction(TestAction::make('edit')->table($entry))
            ->assertActionDataSet(['topic' => 'Das Herz', 'content' => 'Aufbau', 'absent_student_ids' => [$this->students->first()->getKey()]]);
    }

    public function test_the_required_fields_are_checked_when_saving(): void
    {
        $entry = $this->entry();

        $this->page()
            ->callAction(TestAction::make('edit')->table($entry), ['subject' => '', 'topic' => '', 'content' => '', 'held_on' => null, 'period' => 99])
            ->assertHasFormErrors(['subject', 'topic', 'content', 'held_on', 'period']);
    }

    public function test_a_student_of_another_class_cannot_be_marked_absent(): void
    {
        $foreignStudent = User::factory()->student()->create();
        $entry = $this->entry();

        $this->page()
            ->callAction(TestAction::make('edit')->table($entry), [
                'held_on' => '2026-10-07', 'subject' => 'Mathe', 'topic' => 'Brüche', 'content' => 'Kürzen',
                'absent_student_ids' => [$foreignStudent->getKey()],
            ])
            ->assertHasFormErrors(['absent_student_ids.0']);

        $this->assertSame([], $entry->fresh()->absent_student_ids ?? []);
    }

    public function test_saving_keeps_an_absent_student_who_has_left_the_class(): void
    {
        $gone = $this->students->first();
        $entry = $this->entry(['absent_student_ids' => [$gone->getKey()]]);
        $gone->update(['school_class_id' => null]);

        $this->page()
            ->callAction(TestAction::make('edit')->table($entry), [
                'held_on' => '2026-10-07', 'subject' => 'Mathe', 'topic' => 'Brüche', 'content' => 'Kürzen',
                'absent_student_ids' => [$gone->getKey()],
            ])
            ->assertHasNoFormErrors();

        $this->assertSame([$gone->getKey()], $entry->fresh()->absent_student_ids);
    }

    public function test_confirming_locks_the_entry(): void
    {
        $entry = $this->entry(['topic' => 'Vorher']);

        $this->page()
            ->assertTableActionVisible('edit', $entry)
            ->callAction(TestAction::make('confirm')->table($entry));

        $this->assertTrue($entry->fresh()->isConfirmed());

        $page = $this->page()
            ->assertTableActionHidden('edit', $entry)
            ->assertTableActionHidden('confirm', $entry);
        $this->forceAction($page, 'edit', $entry);
        $page->fillForm(['topic' => 'Nachher'])->call('callMountedAction');

        $this->assertSame('Vorher', $entry->fresh()->topic);
        $this->assertSame('Confirmed', $entry->fresh()->status()->getLabel());
    }

    public function test_an_entry_without_content_cannot_be_confirmed_until_it_is_filled_in(): void
    {
        // A draft from a lesson without summary and notes has no content.
        $lesson = $this->lessonOf($this->schoolClass, $this->teacher, ['summary' => null, 'notes' => '']);
        $page = $this->page()->callAction('createDraft', ['lesson_id' => $lesson->getKey()]);
        $entry = ClassBookEntry::query()->sole();

        $this->assertSame('', $entry->content);
        $page->assertSee('Content missing')->assertTableActionDisabled('confirm', $entry);
        $this->forceAction($page, 'confirm', $entry);
        $this->assertFalse($entry->fresh()->isConfirmed());

        $this->page()
            ->callAction(TestAction::make('edit')->table($entry), ['held_on' => '2026-10-07', 'subject' => 'Mathe', 'topic' => 'Brüche', 'content' => 'Kürzen geübt'])
            ->assertTableActionEnabled('confirm', $entry)
            ->callAction(TestAction::make('confirm')->table($entry));

        $this->assertTrue($entry->fresh()->isConfirmed());
    }

    public function test_a_substitute_works_in_the_class_while_the_substitution_lasts(): void
    {
        $substitute = User::factory()->for($this->school)->teacher()->create();
        $substitution = ClassSubstitution::factory()->create(['school_class_id' => $this->schoolClass->getKey(), 'substitute_id' => $substitute->getKey()]);
        $this->entry(['absent_student_ids' => [$this->students->first()->getKey()]]);

        $this->page($substitute, $this->schoolClass)
            ->assertSee('including cover')
            ->assertSee($this->students->first()->name)
            ->assertActionVisible('export');

        $substitution->update(['starts_on' => today()->subDays(7), 'ends_on' => today()->subDay()]);

        $this->page($substitute, $this->schoolClass)->assertForbidden();
    }

    public function test_a_colleague_who_did_not_write_the_entry_cannot_edit_or_confirm_it(): void
    {
        $colleague = User::factory()->for($this->school)->teacher()->create();
        $this->schoolClass->teachers()->attach($colleague);
        $entry = $this->entry(['topic' => 'Vorher']);

        $page = $this->page($colleague, $this->schoolClass)
            ->assertCanSeeTableRecords([$entry])
            ->assertTableActionHidden('edit', $entry)
            ->assertTableActionHidden('confirm', $entry);
        $this->forceAction($page, 'confirm', $entry);

        $this->assertFalse($entry->fresh()->isConfirmed());
    }

    public function test_the_administration_edits_and_confirms_an_entry_of_a_teacher(): void
    {
        $entry = $this->entry();

        $this->page($this->admin(), $this->schoolClass)
            ->callAction(TestAction::make('edit')->table($entry), ['held_on' => '2026-10-07', 'subject' => 'Mathe', 'topic' => 'Vom Admin', 'content' => 'x'])
            ->callAction(TestAction::make('confirm')->table($entry));

        $this->assertSame('Vom Admin', $entry->fresh()->topic);
        $this->assertTrue($entry->fresh()->isConfirmed());
    }

    public function test_only_the_administration_reopens_a_confirmed_entry(): void
    {
        $entry = $this->entry(['confirmed_at' => now()]);

        $page = $this->page()->assertTableActionHidden('reopen', $entry);
        $this->forceAction($page, 'reopen', $entry);
        $this->assertTrue($entry->fresh()->isConfirmed());

        $this->page($this->admin(), $this->schoolClass)
            ->assertTableActionVisible('reopen', $entry)
            ->callAction(TestAction::make('reopen')->table($entry));

        $this->assertFalse($entry->fresh()->isConfirmed());
        $this->assertTrue($this->teacher->can('update', $entry->fresh()));
    }

    public function test_the_export_downloads_a_csv_of_the_chosen_range(): void
    {
        $this->entry(['held_on' => '2026-10-05', 'topic' => 'Im Zeitraum']);
        $this->entry(['held_on' => '2026-11-05', 'topic' => 'Außerhalb']);
        $expected = ClassBookCsv::render($this->schoolClass, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

        $this->page()
            ->callAction('export', ['from' => '2026-10-01', 'until' => '2026-10-31'])
            ->assertHasNoFormErrors()
            ->assertFileDownloaded('class-register-5a-2026-10-01-to-2026-10-31.csv', $expected);

        $this->assertStringContainsString('Im Zeitraum', $expected);
        $this->assertStringNotContainsString('Außerhalb', $expected);
    }

    public function test_the_export_needs_a_range_that_does_not_end_before_it_starts(): void
    {
        $this->page()
            ->callAction('export', ['from' => '2026-10-31', 'until' => '2026-10-01'])
            ->assertHasFormErrors(['until'])
            ->assertNoFileDownloaded();
    }

    public function test_the_export_actions_are_not_there_for_someone_without_a_class(): void
    {
        $lonely = User::factory()->for($this->school)->teacher()->create();

        $this->page($lonely)
            ->assertActionHidden('export')
            ->assertActionHidden('createDraft');
    }

    public function test_the_lesson_has_a_relation_to_its_entry(): void
    {
        $lesson = Lesson::factory()->create();
        $entry = ClassBookEntry::factory()->create(['school_class_id' => $lesson->school_class_id, 'teacher_id' => $lesson->teacher_id, 'lesson_id' => $lesson->getKey()]);

        $this->assertTrue($lesson->classBookEntry->is($entry));
        $this->assertTrue($entry->lesson->is($lesson));
        $this->assertNull(Lesson::factory()->create()->classBookEntry);
    }
}
