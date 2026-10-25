<?php

namespace Tests\Feature\Lessons;

use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Models\Lesson;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class LessonWorksheetCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_deleting_a_lesson_deletes_its_worksheet_files(): void
    {
        $teacher = User::factory()->teacher()->create();
        $lesson = Lesson::factory()->for($teacher, 'teacher')->create(['attachments' => ['worksheets/one.pdf', 'worksheets/two.jpg']]);
        $other = Lesson::factory()->for($teacher, 'teacher')->create(['attachments' => ['worksheets/other.pdf']]);

        foreach (['one.pdf', 'two.jpg', 'other.pdf'] as $name) {
            Storage::disk('local')->put("worksheets/{$name}", 'x');
        }

        $lesson->delete();

        Storage::disk('local')->assertMissing('worksheets/one.pdf');
        Storage::disk('local')->assertMissing('worksheets/two.jpg');
        Storage::disk('local')->assertExists('worksheets/other.pdf');
        $this->assertModelExists($other);
    }

    public function test_the_teacher_deleting_a_lesson_in_the_panel_deletes_its_worksheet_files(): void
    {
        $teacher = User::factory()->teacher()->create();
        $lesson = Lesson::factory()->for($teacher, 'teacher')->create(['attachments' => ['worksheets/handout.pdf']]);
        Storage::disk('local')->put('worksheets/handout.pdf', 'x');
        $this->actingAs($teacher);

        Livewire::test(EditLesson::class, ['record' => $lesson->getKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($lesson);
        Storage::disk('local')->assertMissing('worksheets/handout.pdf');
    }

    public function test_bulk_deleting_lessons_in_the_panel_deletes_their_worksheet_files(): void
    {
        $teacher = User::factory()->teacher()->create();
        $lessons = Lesson::factory()->count(2)->for($teacher, 'teacher')->sequence(
            ['attachments' => ['worksheets/first.pdf']],
            ['attachments' => ['worksheets/second.pdf']],
        )->create();
        Storage::disk('local')->put('worksheets/first.pdf', 'x');
        Storage::disk('local')->put('worksheets/second.pdf', 'x');
        $this->actingAs($teacher);

        Livewire::test(ListLessons::class)
            ->callTableBulkAction('delete', $lessons);

        $this->assertModelMissing($lessons[0]);
        $this->assertModelMissing($lessons[1]);
        Storage::disk('local')->assertMissing('worksheets/first.pdf');
        Storage::disk('local')->assertMissing('worksheets/second.pdf');
    }

    public function test_a_file_another_lesson_still_lists_stays(): void
    {
        $lesson = Lesson::factory()->create(['attachments' => ['worksheets/shared.pdf']]);
        Lesson::factory()->create(['attachments' => ['worksheets/shared.pdf']]);
        Storage::disk('local')->put('worksheets/shared.pdf', 'x');

        $lesson->delete();

        Storage::disk('local')->assertExists('worksheets/shared.pdf');
    }

    public function test_a_delete_that_is_rolled_back_keeps_the_worksheet_files(): void
    {
        $lesson = Lesson::factory()->create(['attachments' => ['worksheets/kept.pdf']]);
        Storage::disk('local')->put('worksheets/kept.pdf', 'x');

        DB::beginTransaction();
        $lesson->delete();
        DB::rollBack();

        $this->assertModelExists($lesson);
        Storage::disk('local')->assertExists('worksheets/kept.pdf');
    }
}
