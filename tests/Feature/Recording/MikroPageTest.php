<?php

namespace Tests\Feature\Recording;

use App\Filament\Pages\RecordLesson;
use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Lesson;
use App\Models\LessonRecording;
use App\Models\RecordingConsent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class MikroPageTest extends TestCase
{
    use RecordingFixtures;
    use RefreshDatabase;

    /**
     * What the glossary forbids in the UI, as the stems a reader would hit.
     */
    private const string FORBIDDEN_WORDS = '/aufnehm|aufnahm|aufgenommen|mitschnitt|abhör|überwach|transkript/iu';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLesson();
    }

    private function html(): string
    {
        return $this->actingAs($this->teacher)->get($this->pageUrl())->assertOk()->getContent();
    }

    /**
     * The page without the Filament chrome around it (navigation, footer), which other pages of the panel fill.
     */
    private function content(string $html): string
    {
        $this->assertSame(1, preg_match('/<main\b.*<\/main>/s', $html, $match), 'The page has a main area');

        return $match[0];
    }

    private function page(): Testable
    {
        return Livewire::actingAs($this->teacher)->test(RecordLesson::class, ['lesson' => Lesson::findOrFail($this->lesson->getKey())]);
    }

    public function test_the_lessons_teacher_opens_the_page(): void
    {
        $this->actingAs($this->teacher)->get($this->pageUrl())
            ->assertOk()
            ->assertSee('Tuto-Mikro')
            ->assertSee('Wer ist heute da?')
            ->assertSee('Notizen aus der Stunde')
            ->assertSee('Zusammenfassung erstellen')
            ->assertSee('Mikro an')
            ->assertSee('Mikro aus');
    }

    public function test_the_page_lives_at_a_url_without_recording_words(): void
    {
        $this->assertStringContainsString('/tuto-mikro/'.$this->lesson->id, $this->pageUrl());
        // The component config is JSON in a script, where slashes are escaped.
        $this->assertMatchesRegularExpression('#tuto-mikro[\\\\/]+'.$this->lesson->id.'[\\\\/]+start#', $this->html());
    }

    public function test_nobody_else_opens_the_page(): void
    {
        foreach ($this->outsiders() as $who => $user) {
            $this->actingAs($user)->get($this->pageUrl())->assertForbidden("{$who} must not open the page");
        }

        $this->actingAs($this->teacher)->get($this->pageUrl(Lesson::factory()->create()))->assertForbidden();
    }

    public function test_nobody_else_can_use_the_page_actions(): void
    {
        foreach ($this->outsiders() as $user) {
            Livewire::actingAs($user)->test(RecordLesson::class, ['lesson' => $this->lesson])->assertForbidden();
        }
    }

    public function test_everybody_is_present_at_first_and_the_consents_decide_whether_it_can_start(): void
    {
        $this->page()
            ->assertSet('present', $this->students->modelKeys())
            ->assertSet('canStart', true)
            ->assertSee('Alle 3 anwesenden Schüler:innen haben eingewilligt')
            ->assertSee('Fassung '.$this->form->version);
    }

    public function test_a_student_without_consent_blocks_the_start_and_is_named_to_the_teacher_only(): void
    {
        RecordingConsent::query()->where('student_id', $this->students[1]->id)->delete();

        $page = $this->page()
            ->assertSet('canStart', false)
            ->assertSee('Tuto-Mikro kann noch nicht starten.')
            ->assertSee('Für 1 von 3 anwesenden Schüler:innen liegt keine gültige Einwilligung vor.')
            ->assertSee('Ben Schneider')
            ->assertSee('Es liegt keine Einwilligung der Erziehungsberechtigten vor');

        // Marked as absent, the lesson can start; the reason disappears with the tick.
        $page->set('present', [$this->students[0]->id, $this->students[2]->id])
            ->assertSet('canStart', true)
            ->assertDontSee('Es liegt keine Einwilligung der Erziehungsberechtigten vor');

        $page->call('selectNone')->assertSet('canStart', false)->assertSee('Es sind keine anwesenden Schüler:innen angegeben.');
        $page->call('selectAll')->assertSet('canStart', false);
    }

    public function test_the_ai_analysis_consent_counts_once_the_school_ai_is_on(): void
    {
        RecordingConsent::query()->where('student_id', $this->students[0]->id)->update(['purposes' => ['audio', 'transcript']]);

        $this->page()->assertSet('canStart', true);

        $this->enableAi();

        $this->page()->assertSet('canStart', false)->assertSee('Die Einwilligung deckt nicht alle nötigen Zwecke ab');
    }

    public function test_a_school_without_the_switch_cannot_start_and_is_told_in_the_words_of_tuto_mikro(): void
    {
        $this->switchRecordings($this->schoolClass->school, false);

        $page = $this->page()->assertSet('canStart', false)->assertSee('Tuto-Mikro ist für deine Schule nicht freigeschaltet.');

        $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN_WORDS, $page->html());
    }

    public function test_the_page_never_uses_the_forbidden_words(): void
    {
        $states = [
            'switch on, nothing missing' => fn () => null,
            'a consent missing' => fn () => RecordingConsent::query()->where('student_id', $this->students[0]->id)->delete(),
            'the school ai on' => fn () => $this->enableAi(),
            'the school switch off' => fn () => $this->switchRecordings($this->schoolClass->school, false),
        ];

        foreach ($states as $state => $arrange) {
            $arrange();
            $content = $this->content($this->html());

            $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN_WORDS, $content, "The page says a forbidden word ({$state})");
        }
    }

    public function test_the_page_has_no_microphone_ear_or_eye_icons(): void
    {
        $content = $this->content($this->html());

        foreach (['heroicon-o-microphone', 'heroicon-s-microphone', 'heroicon-m-microphone', 'heroicon-o-eye', 'heroicon-s-eye', 'heroicon-o-eye-slash', 'heroicon-s-eye-slash'] as $icon) {
            $svg = svg($icon)->toHtml();

            $this->assertSame(1, preg_match('/<path[^>]*\sd="([^"]+)"/', $svg, $path), "{$icon} has a path");
            $this->assertStringNotContainsString($path[1], $content, "{$icon} must not be on the page");
        }
    }

    public function test_the_indicator_says_mikro_an_in_blue_and_mikro_aus_in_red_and_is_a_live_region(): void
    {
        $html = $this->html();

        // The bar: fixed, a status region read aloud, one state per colour and wording.
        $this->assertMatchesRegularExpression('/<div class="lp-mic-bar" role="status" aria-live="polite" aria-atomic="true"/', $html);
        $this->assertStringContainsString("x-bind:data-state=\"listening ? 'on' : 'off'\"", $html);
        $this->assertStringContainsString('Mikro an – Tuto schreibt mit', $html);
        $this->assertStringContainsString("return 'Mikro an – Tuto schreibt mit';", $html);
        $this->assertStringContainsString("return this.phase === 'on' ? 'Mikro aus – du schreibst die Notizen selbst' : 'Mikro aus';", $html);
        $this->assertStringContainsString('<span x-text="barLabel">Mikro aus</span>', $html);
        $this->assertMatchesRegularExpression('/\.lp-mic-bar \{[^}]*position: fixed;[^}]*inset-inline: 0;[^}]*background: var\(--danger-100/s', $html);
        $this->assertMatchesRegularExpression('/\.lp-mic-bar\[data-state="on"\] \{[^}]*background: var\(--primary-100/s', $html);

        // The blue ring around the window while listening, and the title prefix.
        $this->assertStringContainsString('class="lp-mic-ring" x-cloak x-show="listening" aria-hidden="true"', $html);
        $this->assertStringContainsString("'● Mikro an – '", $html);

        // Screen readers get warnings and results too; the pulse respects reduced motion.
        $this->assertMatchesRegularExpression('/<p class="lp-mic-sr" role="status" aria-live="polite" x-text="announcement">/', $html);
        $this->assertStringContainsString('prefers-reduced-motion: no-preference', $html);
    }

    public function test_the_microphone_is_requested_for_sound_only_and_in_one_place(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('getUserMedia({ audio: true, video: false })', $html);
        $this->assertSame(1, substr_count($html, 'navigator.mediaDevices.getUserMedia('));
        $this->assertStringContainsString("permissions.query({ name: 'microphone' })", $html);
        $this->assertStringContainsString('Das Mikrofon ist für diese Seite blockiert.', $html);
    }

    public function test_the_page_is_honest_about_the_microphone_and_the_voices_it_cannot_separate(): void
    {
        $content = $this->content($this->html());

        $this->assertStringContainsString('Nur dein Mikrofon.', $content);
        $this->assertStringContainsString('Ansteckmikrofon', $content);
        $this->assertStringContainsString('Headset', $content);
        $this->assertStringContainsString('Der Browser kann Stimmen nicht trennen', $content);
        $this->assertStringContainsString('auch Stimmen aus der Klasse', $content);
        $this->assertStringContainsString('Der Ton wird nicht gespeichert.', $content);
        $this->assertStringContainsString('Verlässt du die Seite, sind die Notizen weg.', $content);
    }

    public function test_the_page_names_the_destination_of_the_sound_only_with_the_school_ai(): void
    {
        $this->assertStringContainsString('Der Ton verlässt dein Gerät nicht', $this->content($this->html()));

        $this->enableAi();
        $content = $this->content($this->html());

        $this->assertStringContainsString('zur Spracherkennung an Gemini', $content);
        $this->assertStringContainsString('Bei uns läuft er nur durch den Arbeitsspeicher und wird nirgends abgelegt.', $content);
        $this->assertStringNotContainsString('Der Ton verlässt dein Gerät nicht', $content);
    }

    public function test_the_notes_are_reviewed_in_the_browser_and_the_page_warns_before_it_is_left(): void
    {
        $html = $this->html();

        $this->assertMatchesRegularExpression('/<textarea[^>]*x-model="notes"/', $html);
        $this->assertStringContainsString("window.addEventListener('beforeunload'", $html);
        $this->assertStringContainsString('Lies den Text durch und korrigiere Erkennungsfehler', $html);
        $this->assertStringContainsString('Zusammenfassung erstellen', $html);
        $this->assertStringContainsString('In Stundennotizen übernehmen', $html);
        $this->assertStringContainsString('Hausaufgabe daraus erstellen', $html);
        $this->assertStringContainsString('Tafelbild-Vorschlag', $html);
        $this->assertStringContainsString('Fragen aus der Klasse', $html);

        // The notes are never a property of the server component, so they never travel with a Livewire request.
        $this->assertStringNotContainsString('wire:model="notes"', $html);
    }

    public function test_the_attendance_locks_while_the_microphone_is_on(): void
    {
        $html = $this->html();

        $this->assertMatchesRegularExpression('/<fieldset[^>]*x-bind:disabled="active"/', $html);
    }

    public function test_the_teacher_confirms_the_list_and_every_change_of_the_list_takes_the_confirmation_back(): void
    {
        $page = $this->page()
            ->assertSet('attendanceConfirmed', false)
            ->assertSee('Ich bestätige:')
            ->assertSee('Nur die Schüler:innen, die hier angehakt sind, sind im Raum.')
            ->assertSeeHtml('wire:model.live="attendanceConfirmed"');

        $page->set('attendanceConfirmed', true)->assertSet('attendanceConfirmed', true);
        $page->set('present', [$this->students[0]->id, $this->students[1]->id])->assertSet('attendanceConfirmed', false);

        $page->set('attendanceConfirmed', true)->call('selectNone')->assertSet('attendanceConfirmed', false);
        $page->set('attendanceConfirmed', true)->call('selectAll')->assertSet('attendanceConfirmed', false);
    }

    public function test_the_start_button_follows_the_confirmation_and_the_start_request_carries_it(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('this.$wire.canStart && this.$wire.attendanceConfirmed && this.phase', $html);
        $this->assertStringContainsString('attendance_confirmed: this.$wire.attendanceConfirmed', $html);
        $this->assertStringContainsString('Bestätige oben noch, dass nur die Angehakten im Raum sind.', $html);
    }

    public function test_hidden_names_show_no_name_no_number_and_no_reason_and_the_choice_is_remembered(): void
    {
        RecordingConsent::query()->where('student_id', $this->students[1]->id)->delete();
        $html = $this->html();
        $content = $this->content($html);

        // Neither a position in the class list nor the highlight or the reason of a single student is visible while the names are hidden.
        $this->assertDoesNotMatchRegularExpression('/Schüler:in\s+(\{\{|\d)/u', $content);
        $this->assertStringContainsString('<span x-cloak x-show="hideNames">Schüler:in</span>', $content);
        $this->assertStringContainsString('<span x-cloak x-show="! hideNames">Ben Schneider</span>', $content);
        $this->assertMatchesRegularExpression('/<span x-cloak x-show="! hideNames" class="[^"]*">\s*<svg[^>]*>.*?<\/svg>\s*Es liegt keine Einwilligung der Erziehungsberechtigten vor/s', $content);
        $this->assertStringContainsString("x-bind:class=\"hideNames ? 'ring-gray-950/10 dark:ring-white/10' : 'bg-warning-50 ring-warning-600/30 dark:bg-warning-500/10'\"", $content);

        $this->assertStringContainsString('x-on:click="toggleNames()"', $content);
        $this->assertStringContainsString("window.localStorage.getItem(HIDE_NAMES_KEY) === '1'", $html);
        $this->assertStringContainsString("window.localStorage.setItem(HIDE_NAMES_KEY, this.hideNames ? '1' : '0')", $html);
    }

    public function test_without_ai_and_without_recognition_on_the_device_the_microphone_stays_closed_and_the_bar_says_so(): void
    {
        $html = $this->html();

        // The page tells the script what it was rendered with; the script opens the microphone only when somebody could listen.
        $this->assertStringContainsString('\u0022aiEnabled\u0022:false', $html);
        $this->assertStringContainsString('if (config.aiEnabled || this.sttAvailable) {', $html);
        $this->assertSame(1, substr_count($html, 'navigator.mediaDevices.getUserMedia('));
        $this->assertStringContainsString("return this.phase === 'on' && this.mode !== 'manual';", $html);
        $this->assertStringContainsString('Mikro aus – du schreibst die Notizen selbst', $html);

        $this->enableAi();

        $this->assertStringContainsString('\u0022aiEnabled\u0022:true', $this->html());
    }

    public function test_a_session_without_segments_asks_the_check_at_the_rhythm_of_the_segments(): void
    {
        $html = $this->html();

        $this->assertStringContainsString("if (this.mode !== 'server') {", $html);
        $this->assertStringContainsString('heartbeatTimer = setInterval(() => this.heartbeat(), body.segment_seconds * 1000);', $html);
        $this->assertStringContainsString('session.check_url', $html);
        $this->assertSame(3, substr_count($html, 'clearInterval(heartbeatTimer);'), 'stop, fail and abandon all end the check');
    }

    public function test_the_sessions_of_the_lesson_are_listed_without_any_sound_or_text(): void
    {
        $this->travelTo(now()->setTime(10, 15));
        $recording = LessonRecording::factory()->for($this->lesson)->presentStudents($this->students)->finished(2700)->create(['audio_sent_to_ai' => true]);

        $this->page()
            ->assertSee('Tuto-Mikro in dieser Stunde')
            ->assertSee('10:15')
            ->assertSee('Dauer 45:00')
            ->assertSee('3 anwesend')
            ->assertSee('Ton zur Spracherkennung an Gemini gesendet')
            ->assertSee('Es gibt keinen Ton und keinen Text.');

        $this->assertSame(1, LessonRecording::query()->whereKey($recording->id)->count());
    }

    public function test_the_summary_is_taken_over_into_the_lesson_notes_with_the_names_replaced(): void
    {
        $this->page()
            ->call('applyToNotes', "Zusammenfassung:\nAnna Müller fragt nach dem Licht.")
            ->assertNotified('In die Stundennotizen übernommen');

        $notes = $this->lesson->fresh()->notes;

        $this->assertStringStartsWith('Tafelbild: Licht, Wasser, CO2', $notes);
        $this->assertMatchesRegularExpression('/\n\nTuto-Mikro vom \d{2}\.\d{2}\.\d{4}, \d{2}:\d{2} Uhr:\nZusammenfassung:\nSchüler:in A fragt nach dem Licht\.$/', $notes);
        $this->assertStringNotContainsString('Anna', $notes);
        $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN_WORDS, $notes);
    }

    public function test_nothing_is_taken_over_without_text_or_beyond_the_size_limit(): void
    {
        $this->page()->call('applyToNotes', '   ')->assertNotified('Noch nichts zum Übernehmen');
        $this->page()->call('applyToNotes', str_repeat('Licht ', 11000))->assertNotified('Die Stundennotizen wären zu lang');

        $this->assertSame('Tafelbild: Licht, Wasser, CO2', $this->lesson->fresh()->notes);
    }

    public function test_the_homework_is_made_from_the_notes_in_the_lesson(): void
    {
        $this->page()
            ->call('createHomework', 'Zusammenfassung: Fotosynthese.')
            ->assertRedirect(LessonResource::getUrl('edit', ['record' => $this->lesson]));

        $this->assertStringContainsString('Zusammenfassung: Fotosynthese.', $this->lesson->fresh()->notes);
    }

    public function test_a_released_lesson_gets_no_new_homework(): void
    {
        $this->lesson->update(['published_at' => now(), 'due_on' => today()->addWeek()]);

        $this->page()
            ->assertSee('Diese Stunde ist schon freigegeben')
            ->call('createHomework', 'Zusammenfassung: Fotosynthese.')
            ->assertNoRedirect()
            ->assertNotified('Diese Stunde ist schon freigegeben');

        $this->assertSame('Tafelbild: Licht, Wasser, CO2', $this->lesson->fresh()->notes);
    }

    public function test_a_student_id_that_does_not_belong_to_the_class_never_counts_as_present(): void
    {
        $foreigner = User::factory()->student()->create();

        $this->page()->set('present', [$foreigner->id])->assertSet('canStart', false);
    }
}
