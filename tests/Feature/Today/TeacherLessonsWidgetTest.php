<?php

namespace Tests\Feature\Today;

use App\Filament\Pages\LessonStarter;
use App\Filament\Pages\Today;
use App\Filament\Widgets\TeacherLessonsWidget;
use App\Models\ClassSubstitution;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Submission;
use App\Models\User;
use App\Support\LessonReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The teacher's dashboard leads to "Heute", where the homework of today's lessons goes out in 30 seconds.
 */
class TeacherLessonsWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_teacher_dashboard_links_to_heute(): void
    {
        $teacher = User::factory()->teacher()->create();

        Livewire::actingAs($teacher)->test(TeacherLessonsWidget::class)
            ->assertSee('Heute: Hausaufgaben freigeben')
            ->assertSeeHtml('href="'.Today::getUrl().'"');

        $this->actingAs($teacher)->get('/')->assertOk()->assertSee('Heute: Hausaufgaben freigeben')->assertSee(Today::getUrl());
        $this->actingAs($teacher)->get(Today::getUrl())->assertOk();
    }

    public function test_the_widget_is_only_for_teachers(): void
    {
        foreach ([User::factory()->admin(), User::factory()->student(), User::factory()->operator()] as $user) {
            $this->actingAs($user->create());

            $this->assertFalse(TeacherLessonsWidget::canView());
        }
    }

    public function test_the_starter_board_link_is_gone_when_a_substitutes_period_is_over(): void
    {
        $schoolClass = SchoolClass::factory()->create();
        $substitute = User::factory()->teacher()->for($schoolClass->school)->create();
        $lesson = Lesson::factory()->for($schoolClass)->for($substitute, 'teacher')->create();
        Submission::factory()->evaluated()->count(LessonReview::MIN_GROUP)->for($lesson)->create();
        $starterUrl = LessonStarter::getUrl(['lesson' => $lesson]);
        $substitution = ClassSubstitution::factory()->for($schoolClass)->create(['substitute_id' => $substitute->id]);

        Livewire::actingAs($substitute)->test(TeacherLessonsWidget::class)->assertSeeHtml($starterUrl);

        $substitution->end();

        Livewire::actingAs($substitute)->test(TeacherLessonsWidget::class)
            ->assertSee($lesson->topic)
            ->assertDontSeeHtml($starterUrl);
    }
}
