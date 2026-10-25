<?php

namespace Tests\Feature;

use App\Filament\Pages\ClassBook;
use App\Models\ClassBookEntry;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Table cells end after a few words with an ellipsis and show the whole text as a tooltip (AppServiceProvider).
 */
class TableCellWordLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_long_cell_text_is_cut_after_twelve_words_and_the_tooltip_holds_the_whole_text(): void
    {
        $teacher = User::factory()->for(School::factory())->teacher()->create();
        $schoolClass = SchoolClass::factory()->create(['school_id' => $teacher->school_id, 'teacher_id' => $teacher->getKey()]);
        $long = 'Eins zwei drei vier fünf sechs sieben acht neun zehn elf zwölf dreizehn vierzehn Schlusswort';
        ClassBookEntry::factory()->for($schoolClass)->create(['teacher_id' => $teacher->getKey(), 'content' => $long, 'homework' => 'Kurze Aufgabe']);

        $html = Livewire::actingAs($teacher)
            ->withQueryParams(['klasse' => $schoolClass->getKey()])
            ->test(ClassBook::class)
            ->html();

        $this->assertStringContainsString('Eins zwei drei vier fünf sechs sieben acht neun zehn elf zwölf...', $html);
        $this->assertStringContainsString('Schlusswort', $html, 'the tooltip carries the whole text');
        $this->assertSame(1, substr_count($html, 'Schlusswort'), 'only the tooltip has the end of the text');
        $this->assertStringContainsString('Kurze Aufgabe', $html);
    }

    public function test_a_cut_right_after_a_full_stop_ends_in_three_dots_not_four(): void
    {
        $teacher = User::factory()->for(School::factory())->teacher()->create();
        $schoolClass = SchoolClass::factory()->create(['school_id' => $teacher->school_id, 'teacher_id' => $teacher->getKey()]);
        ClassBookEntry::factory()->for($schoolClass)->create([
            'teacher_id' => $teacher->getKey(),
            'content' => 'Eins zwei drei vier fünf sechs sieben acht neun zehn elf zwölf. Ein zweiter Satz',
            'homework' => 'Kurze Aufgabe',
        ]);

        $html = Livewire::actingAs($teacher)
            ->withQueryParams(['klasse' => $schoolClass->getKey()])
            ->test(ClassBook::class)
            ->html();

        $this->assertStringContainsString('elf zwölf...', $html);
        $this->assertStringNotContainsString('zwölf....', $html);
    }
}
