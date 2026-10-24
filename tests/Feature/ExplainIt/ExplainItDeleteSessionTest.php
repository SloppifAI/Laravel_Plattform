<?php

namespace Tests\Feature\ExplainIt;

use App\Filament\Pages\ExplainIt;
use App\MessageAuthor;
use App\Models\ExplanationMessage;
use App\Models\ExplanationSession;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExplainItDeleteSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_deletes_the_open_session_after_confirming_and_lands_on_the_start_page(): void
    {
        $session = $this->sessionWithMessages();
        $other = ExplanationSession::factory()->for($session->student, 'student')->create(['topic' => 'Bleibt erhalten']);

        Livewire::actingAs($session->student)
            ->test(ExplainIt::class)
            ->call('openSession', $session->id)
            ->assertSee('Gespräch löschen')
            ->mountAction('deleteSession', ['session' => $session->id])
            ->assertMountedActionModalSee('Das Gespräch wird endgültig gelöscht.')
            ->callMountedAction()
            ->assertSet('sessionId', null)
            ->assertSee('Los geht’s')
            ->assertSee('Bleibt erhalten')
            ->assertDontSee($session->topic);

        $this->assertModelMissing($session);
        $this->assertDatabaseCount(ExplanationMessage::class, 0);
        $this->assertModelExists($other);
    }

    public function test_a_student_deletes_a_session_from_the_list_while_another_one_is_open(): void
    {
        $student = User::factory()->student()->create();
        $open = ExplanationSession::factory()->for($student, 'student')->create();
        $deleted = ExplanationSession::factory()->for($student, 'student')->finished()->create();

        Livewire::actingAs($student)
            ->test(ExplainIt::class)
            ->call('openSession', $open->id)
            ->callAction(TestAction::make('deleteSession')->arguments(['session' => $deleted->id]))
            ->assertSet('sessionId', null);

        $this->assertModelMissing($deleted);
        $this->assertModelExists($open);
    }

    public function test_another_students_session_cannot_be_deleted(): void
    {
        $foreignSession = $this->sessionWithMessages();
        $student = User::factory()->student()->create();

        Livewire::actingAs($student)
            ->test(ExplainIt::class)
            ->callAction(TestAction::make('deleteSession')->arguments(['session' => $foreignSession->id]))
            ->assertNotFound();

        Livewire::actingAs($student)
            ->test(ExplainIt::class)
            ->callAction(TestAction::make('deleteSession')->arguments([]))
            ->assertNotFound();

        $this->assertModelExists($foreignSession);
        $this->assertSame(2, $foreignSession->messages()->count());
    }

    public function test_every_chat_message_wrapper_is_readable_aloud_but_not_the_coloured_bubble(): void
    {
        $session = $this->sessionWithMessages();

        $html = Livewire::actingAs($session->student)
            ->test(ExplainIt::class)
            ->call('openSession', $session->id)
            ->html();

        $this->assertSame(2, substr_count($html, 'data-read-aloud'), 'One per message.');
        $this->assertSame(
            2,
            preg_match_all('/<div data-read-aloud[^>]*>\s*<p[^>]*>\s*(?:Du|Tuto)\s*<\/p>\s*<div[^>]*rounded-2xl(?:(?!data-read-aloud)[^>])*>/', $html),
            'The attribute sits on the wrapper that holds the name label, the bubble follows without it.',
        );
    }

    private function sessionWithMessages(): ExplanationSession
    {
        $session = ExplanationSession::factory()->for(User::factory()->student(), 'student')->create(['topic' => 'Der Wasserkreislauf']);
        ExplanationMessage::factory()->for($session, 'session')->create(['author' => MessageAuthor::Tutor, 'content' => 'Hallo?']);
        ExplanationMessage::factory()->for($session, 'session')->create(['author' => MessageAuthor::Student, 'content' => 'Pflanzen machen Zucker.']);

        return $session;
    }
}
