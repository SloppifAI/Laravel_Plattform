<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\ClassConsents;
use App\Filament\Pages\LearningProgress;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmptyClassStatesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function pages(): array
    {
        return [
            'consents' => [ClassConsents::class, 'As soon as the school administration assigns you'],
            'learning progress' => [LearningProgress::class, 'As soon as you are the form teacher'],
        ];
    }

    #[DataProvider('pages')]
    public function test_the_administration_of_a_school_without_classes_is_told_to_create_one(string $page, string $teacherHint): void
    {
        $admin = User::factory()->for(School::factory())->admin()->create();

        Livewire::actingAs($admin)
            ->test($page)
            ->assertSee('Your school has no class yet. Create a class first under “Classes”.')
            ->assertDontSee($teacherHint);

        app()->setLocale('de');

        Livewire::actingAs($admin)
            ->test($page)
            ->assertSee('In deiner Schule gibt es noch keine Klasse. Lege zuerst unter „Klassen“ eine Klasse an.');
    }

    #[DataProvider('pages')]
    public function test_a_teacher_without_a_class_keeps_the_hint_for_teachers(string $page, string $teacherHint): void
    {
        $teacher = User::factory()->for(School::factory())->teacher()->create();

        Livewire::actingAs($teacher)
            ->test($page)
            ->assertSee($teacherHint)
            ->assertDontSee('Create a class first');
    }
}
