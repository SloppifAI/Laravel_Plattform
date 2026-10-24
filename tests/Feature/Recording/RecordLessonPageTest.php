<?php

namespace Tests\Feature\Recording;

use App\Filament\Pages\RecordLesson;
use App\Filament\Resources\Lessons\LessonResource;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Models\LessonRecording;
use App\Models\User;
use App\TranscriptStatus;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class RecordLessonPageTest extends TestCase
{
    use RecordingFixtures;
    use RefreshDatabase;

    private const string TRANSCRIPT = 'Heute sprechen wir über die Fotosynthese. Anna Müller fragt: Warum brauchen Pflanzen Licht? Die Fotosynthese findet in den Chloroplasten statt.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLesson();
        User::factory()->student($this->schoolClass)->create(['name' => 'Anna Müller']);
    }

    private function html(): string
    {
        return $this->actingAs($this->teacher)->get($this->pageUrl())->assertOk()->getContent();
    }

    private function page(): Testable
    {
        return Livewire::actingAs($this->teacher)->test(RecordLesson::class, ['lesson' => $this->lesson]);
    }

    private function buttonTag(string $html, string $label): string
    {
        $this->assertSame(1, preg_match('/<button\b[^>]*>(?:(?!<\/button>).)*?'.preg_quote($label, '/').'/s', $html, $match), "No button \"{$label}\"");
        preg_match('/<button\b[^>]*>/', $match[0], $tag);

        return $tag[0];
    }

    private function isDisabled(string $tag): bool
    {
        return (bool) preg_match('/\sdisabled(?:=|\s|>)/', $tag);
    }

    public function test_the_consent_is_asked_in_the_page_before_the_microphone(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('Ich habe die Klasse informiert, und Aufnahmen sind an meiner Schule erlaubt.', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*type="checkbox"[^>]*x-model="consentChecked"/', $html);
        foreach (['Aufgenommen wird nur Ton', 'kein Video', 'nie an eine KI gesendet', 'automatisch gelöscht', 'Informiere die Klasse vorher', 'Regeln deiner Schule'] as $promise) {
            $this->assertStringContainsString($promise, $html);
        }

        // The microphone is requested in exactly one place, and that is only reached through the confirmed dialog.
        $this->assertSame(1, substr_count($html, 'navigator.mediaDevices.getUserMedia('));
        $this->assertSame(1, substr_count($html, 'this.start()'));
        $this->assertMatchesRegularExpression('/async confirmConsent\(\) \{\s*if \(!this\.consentChecked\) \{\s*return;\s*\}\s*this\.closeConsent\(\);\s*await this\.start\(\);/', $html);
        $this->assertStringContainsString('x-bind:disabled="! consentChecked"', $html);
        $this->assertStringContainsString("permissions.query({ name: 'microphone' })", $html);
        $this->assertStringContainsString('Das Mikrofon ist für diese Seite blockiert.', $html);
        $this->assertStringContainsString('Zulassen', $html);
    }

    public function test_the_microphone_is_requested_for_audio_only(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('getUserMedia({ audio: true, video: false })', $html);
        $this->assertStringContainsString('video: false', $html);
        $this->assertStringNotContainsString('video: true', $html);
        $this->assertStringNotContainsString('getDisplayMedia', $html);
        $this->assertStringNotContainsString('video/webm', $html);
        $this->assertStringContainsString('audio/webm;codecs=opus', $html);
        $this->assertStringContainsString('audioBitsPerSecond', $html);
    }

    public function test_the_recording_bar_cannot_be_missed_or_scrolled_away(): void
    {
        $html = $this->html();

        $this->assertMatchesRegularExpression('/<div\s+class="lp-rec-bar"\s+role="status"\s+aria-live="polite"/', $html);
        $this->assertMatchesRegularExpression('/\.lp-rec-bar \{[^}]*position: fixed;[^}]*inset-block-start: 0;[^}]*inset-inline: 0;/s', $html);
        $this->assertStringContainsString('Aufnahme läuft', $html);
        $this->assertStringContainsString('Aufnahme pausiert', $html);
        $this->assertStringContainsString("recording: '● REC – '", $html);

        // Paused looks different, and the pulse only exists for people who accept motion.
        $this->assertStringContainsString('.lp-rec-bar[data-state="paused"]', $html);
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: no-preference\) \{\s*\.lp-rec-bar\[data-state="recording"\] \.lp-rec-dot \{\s*animation:/', $html);
        $this->assertStringNotContainsString('animation: lp-rec-pulse', preg_replace('/@media \(prefers-reduced-motion: no-preference\) \{.*?\n    \}\n/s', '', $html));

        foreach (['Pause', 'Fortsetzen', 'Aufnahme beenden'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    public function test_leaving_the_page_ends_the_recording_and_releases_the_microphone(): void
    {
        $html = $this->html();

        $this->assertStringContainsString("window.addEventListener('beforeunload'", $html);
        $this->assertStringContainsString("window.addEventListener('pagehide'", $html);
        $this->assertStringContainsString('track.stop()', $html);
        $this->assertStringContainsString("addEventListener('ended'", $html);
        $this->assertStringNotContainsString('serviceWorker', $html);
        $this->assertStringNotContainsString('requestPictureInPicture', $html);
    }

    public function test_the_live_transcript_only_runs_on_the_device(): void
    {
        $html = $this->html();

        $this->assertStringContainsString("Recognition.available({ langs: ['de-DE'], processLocally: true })", $html);
        $this->assertStringContainsString('=== \'available\'', $html);
        $this->assertStringContainsString('instance.processLocally = true;', $html);

        // A recogniser without the attribute could be a cloud one: it is neither offered nor started.
        $this->assertStringContainsString("'processLocally' in Recognition.prototype", $html);
        $this->assertMatchesRegularExpression('/if \(!recognisesOnDevice\) \{\s*this\.sttEnabled = false;/', $html);
        $this->assertStringNotContainsString('instance.processLocally !== true', $html);
        $this->assertStringContainsString('Live-Transkript (nur auf diesem Gerät)', $html);
        $this->assertStringContainsString('Live-Transkript vorbereitet – wird aktiv, sobald die Transkription freigeschaltet ist', $html);
        $this->assertStringNotContainsString('webkitSpeechRecognition.start', $html);
    }

    public function test_the_page_references_no_external_resource(): void
    {
        $recorder = view('filament.pages.record-lesson-recorder')->render();
        $this->assertDoesNotMatchRegularExpression('#https?://#', $recorder);

        $allowedHosts = ['localhost', '127.0.0.1', parse_url((string) config('app.url'), PHP_URL_HOST), 'www.w3.org'];
        preg_match_all('#https?://([^/:\s"\'<>)]+)#', $this->html(), $matches);

        $this->assertSame([], array_values(array_diff(array_unique($matches[1]), $allowedHosts)));
    }

    public function test_the_header_action_is_visible_only_to_the_lessons_teacher(): void
    {
        Livewire::actingAs($this->teacher)
            ->test(EditLesson::class, ['record' => $this->lesson->getRouteKey()])
            ->assertActionVisible('record')
            ->assertActionHasUrl('record', RecordLesson::getUrl(['lesson' => $this->lesson]))
            ->assertSee('Stunde aufnehmen');

        // The school administration may edit the lesson, but gets no recording button.
        Livewire::actingAs($this->outsiders()['admin of the same school'])
            ->test(EditLesson::class, ['record' => $this->lesson->getRouteKey()])
            ->assertOk()
            ->assertActionHidden('record');
    }

    public function test_the_page_has_no_navigation_entry(): void
    {
        $this->assertSame(RecordLesson::getUrl(['lesson' => $this->lesson]), url('/aufnahme/'.$this->lesson->id));
        $this->assertFalse(RecordLesson::shouldRegisterNavigation());
    }

    public function test_it_lists_the_recordings_with_player_download_and_delete(): void
    {
        $recording = LessonRecording::factory()->for($this->lesson)->withAudio()->finished(754)->create(['started_at' => '2026-10-24 10:15:00']);

        $html = $this->html();

        $this->assertStringContainsString('Aufnahme vom 24.10.2026, 10:15 Uhr', $html);
        $this->assertStringContainsString('Dauer 12:34', $html);
        $this->assertMatchesRegularExpression('#<audio controls[^>]*src="'.preg_quote(route('recordings.stream', $recording), '#').'"#', $html);
        $this->assertStringContainsString('href="'.route('recordings.download', $recording).'"', $html);
        $this->assertStringContainsString('Herunterladen', $html);
        $this->assertStringContainsString('Löschen', $html);
        $this->assertStringNotContainsString($recording->path, $html);
        $this->assertStringNotContainsString('Noch keine Aufnahme', $html);
    }

    public function test_it_says_so_when_there_is_no_recording_yet(): void
    {
        $this->assertStringContainsString('Noch keine Aufnahme', $this->html());
    }

    public function test_an_interrupted_recording_is_marked_as_such(): void
    {
        LessonRecording::factory()->for($this->lesson)->withAudio()->interrupted()->create();

        $this->assertStringContainsString('Unterbrochen, die Dauer ist unbekannt', $this->html());
    }

    public function test_the_transcript_section_shows_the_status_as_label_and_icon(): void
    {
        $cases = [
            ['none', 'Vorbereitet'],
            ['live', 'Live mitgeschrieben'],
            ['pending', 'Ausstehend'],
            ['processing', 'Läuft'],
            ['done', 'Fertig'],
            ['failed', 'Fehlgeschlagen'],
        ];

        foreach ($cases as [$status, $label]) {
            LessonRecording::query()->delete();
            LessonRecording::factory()->for($this->lesson)->withAudio()->interrupted()->create(['transcript_status' => $status]);

            $html = $this->html();

            $this->assertStringContainsString('Transkript &amp; Auswertung', $html);
            $this->assertMatchesRegularExpression('/fi-badge[^>]*>\s*(?:<svg.*?<\/svg>\s*)?(?:<span[^>]*>\s*)?'.preg_quote($label, '/').'/s', $html, "Badge \"{$label}\" with icon");
        }
    }

    public function test_the_transcript_and_evaluation_buttons_follow_the_configuration(): void
    {
        LessonRecording::factory()->for($this->lesson)->withAudio()->finished()->create();

        // Everything off: the server transcription is disabled with an explanation, the evaluation needs text.
        $html = $this->html();
        $this->assertTrue($this->isDisabled($this->buttonTag($html, 'Transkript erstellen')));
        $this->assertStringContainsString('noch nicht freigeschaltet', $html);
        $this->assertTrue($this->isDisabled($this->buttonTag($html, 'Mit KI auswerten')));
        $this->assertStringContainsString('Demo-Modus', $html);
        $this->assertFalse($this->isDisabled($this->buttonTag($html, 'Transkript speichern')));

        config(['services.transcription.enabled' => true, 'services.anthropic.enabled' => true]);
        LessonRecording::query()->update(['transcript' => self::TRANSCRIPT, 'transcript_status' => TranscriptStatus::Done]);

        $html = $this->html();
        $this->assertFalse($this->isDisabled($this->buttonTag($html, 'Transkript erstellen')));
        $this->assertFalse($this->isDisabled($this->buttonTag($html, 'Mit KI auswerten')));
        $this->assertStringNotContainsString('Demo-Modus', $html);
        $this->assertStringContainsString('Die KI bekommt nur den Text, nie den Ton.', $html);
    }

    public function test_saving_the_transcript_makes_it_a_manual_one(): void
    {
        $recording = LessonRecording::factory()->for($this->lesson)->withAudio()->interrupted()->create(['transcript' => 'alt', 'transcript_status' => TranscriptStatus::Live, 'transcript_source' => LessonRecording::SOURCE_ON_DEVICE]);

        $this->page()
            ->assertSet('transcripts.'.$recording->id, 'alt')
            ->set('transcripts.'.$recording->id, '  Heute: Fotosynthese  ')
            ->call('saveTranscript', $recording->id);

        $recording->refresh();
        $this->assertSame('Heute: Fotosynthese', $recording->transcript);
        $this->assertSame(LessonRecording::SOURCE_MANUAL, $recording->transcript_source);
        $this->assertSame(TranscriptStatus::Done, $recording->transcript_status);

        $this->page()->set('transcripts.'.$recording->id, '')->call('saveTranscript', $recording->id);
        $recording->refresh();
        $this->assertNull($recording->transcript);
        $this->assertSame(TranscriptStatus::None, $recording->transcript_status);
        $this->assertNull($recording->transcript_source);
    }

    public function test_a_transcript_can_be_pasted_into_a_recording_that_has_none(): void
    {
        $recording = $this->recordingOf($this->lesson);

        $this->page()->set('transcripts.'.$recording->id, self::TRANSCRIPT)->call('saveTranscript', $recording->id);

        $this->assertSame(self::TRANSCRIPT, $recording->fresh()->transcript);
    }

    public function test_the_evaluation_runs_with_the_demo_and_shows_the_result_cards(): void
    {
        $recording = $this->recordingOf($this->lesson);

        $page = $this->page()->set('transcripts.'.$recording->id, self::TRANSCRIPT)->call('analyse', $recording->id);

        $recording->refresh();
        $this->assertSame(self::TRANSCRIPT, $recording->transcript);
        $this->assertSame(['summary', 'key_terms', 'board_outline', 'class_questions', 'suggested_notes'], array_keys($recording->analysis));
        $this->assertNotNull($recording->analysed_at);
        $this->assertContains('Fotosynthese', $recording->analysis['key_terms']);

        $page->assertSee(['Zusammenfassung', 'Kernbegriffe', 'Tafelbild-Vorschlag', 'Fragen aus der Klasse', 'Warum brauchen Pflanzen Licht?', 'In Stundennotizen übernehmen', 'Hausaufgabe daraus erstellen']);
    }

    public function test_an_empty_transcript_is_not_evaluated(): void
    {
        $recording = $this->recordingOf($this->lesson);

        $this->page()->call('analyse', $recording->id);

        $this->assertNull($recording->fresh()->analysis);
    }

    public function test_the_evaluation_failing_keeps_the_transcript_and_says_so(): void
    {
        config(['services.anthropic.enabled' => true, 'services.anthropic.key' => null]);
        $recording = $this->recordingOf($this->lesson);

        $this->page()->set('transcripts.'.$recording->id, self::TRANSCRIPT)->call('analyse', $recording->id)->assertNotified('Auswertung fehlgeschlagen');

        $this->assertSame(self::TRANSCRIPT, $recording->fresh()->transcript);
        $this->assertNull($recording->fresh()->analysis);
    }

    public function test_the_notes_suggestion_is_appended_to_the_lesson_notes(): void
    {
        $recording = $this->recordingOf($this->lesson, 'withTranscript');
        $recording->update(['started_at' => '2026-10-24 10:15:00', 'analysis' => [
            'summary' => 'Zusammenfassung der Stunde.',
            'key_terms' => ['Fotosynthese'],
            'board_outline' => ['Licht'],
            'class_questions' => [],
            'suggested_notes' => "Zusammenfassung:\nAnna Müller erklärt die Fotosynthese.",
        ]]);

        $this->page()->call('applyToNotes', $recording->id)->assertNotified('In die Stundennotizen übernommen');

        $notes = $this->lesson->fresh()->notes;
        $this->assertStringStartsWith('Tafelbild: Licht, Wasser, CO2', $notes);
        $this->assertStringContainsString("Aufnahme vom 24.10.2026, 10:15 Uhr:\nZusammenfassung:\nSchüler:in A erklärt die Fotosynthese.", $notes);
        $this->assertStringNotContainsString('Anna', $notes);
    }

    public function test_without_an_evaluation_the_transcript_is_appended(): void
    {
        $recording = $this->recordingOf($this->lesson);

        $this->page()->set('transcripts.'.$recording->id, self::TRANSCRIPT)->call('applyToNotes', $recording->id);

        $notes = $this->lesson->fresh()->notes;
        $this->assertStringStartsWith('Tafelbild: Licht, Wasser, CO2', $notes);
        $this->assertStringContainsString('Heute sprechen wir über die Fotosynthese. Schüler:in A fragt: Warum brauchen Pflanzen Licht?', $notes);

        // A second click appends again; the notes keep growing only by what the teacher asks for.
        $this->page()->call('applyToNotes', $recording->id);
        $this->assertSame(2, substr_count($this->lesson->fresh()->notes, 'Warum brauchen Pflanzen Licht?'));
    }

    public function test_nothing_is_appended_when_there_is_nothing_to_append_or_no_room(): void
    {
        $recording = $this->recordingOf($this->lesson);

        $this->page()->call('applyToNotes', $recording->id)->assertNotified('Noch nichts zum Übernehmen');
        $this->assertSame('Tafelbild: Licht, Wasser, CO2', $this->lesson->fresh()->notes);

        $this->page()->set('transcripts.'.$recording->id, str_repeat('Fotosynthese ', 4700))->call('applyToNotes', $recording->id)->assertNotified('Die Stundennotizen wären zu lang');
        $this->assertSame('Tafelbild: Licht, Wasser, CO2', $this->lesson->fresh()->notes);
    }

    public function test_creating_homework_puts_the_result_in_the_notes_and_goes_to_the_lesson(): void
    {
        $recording = $this->recordingOf($this->lesson);

        $this->page()
            ->set('transcripts.'.$recording->id, self::TRANSCRIPT)
            ->call('createHomework', $recording->id)
            ->assertRedirect(LessonResource::getUrl('edit', ['record' => $this->lesson]));

        $this->assertStringContainsString('Warum brauchen Pflanzen Licht?', $this->lesson->fresh()->notes);
    }

    public function test_homework_cannot_be_created_for_a_lesson_that_is_already_distributed(): void
    {
        $this->lesson->update(['published_at' => now(), 'due_on' => now()->addWeek()]);
        $recording = $this->recordingOf($this->lesson, 'withTranscript');

        $this->page()->call('createHomework', $recording->id)->assertNoRedirect();

        $this->assertSame('Tafelbild: Licht, Wasser, CO2', $this->lesson->fresh()->notes);
        $this->assertTrue($this->isDisabled($this->buttonTag($this->html(), 'Hausaufgabe daraus erstellen')));
    }

    public function test_the_server_transcription_does_nothing_while_it_is_disabled(): void
    {
        $recording = $this->recordingOf($this->lesson);

        $this->page()->call('createTranscript', $recording->id)->assertNotified('Transkription noch nicht freigeschaltet');

        $recording->refresh();
        $this->assertSame(TranscriptStatus::None, $recording->transcript_status);
        $this->assertNull($recording->transcript);
    }

    public function test_the_server_transcription_reports_a_provider_that_is_not_connected(): void
    {
        config(['services.transcription.enabled' => true]);
        $recording = $this->recordingOf($this->lesson);

        $this->page()->call('createTranscript', $recording->id)->assertNotified('Transkription fehlgeschlagen');

        $recording->refresh();
        $this->assertSame(TranscriptStatus::Failed, $recording->transcript_status);
        $this->assertNull($recording->transcript);
    }

    public function test_a_running_recording_is_listed_without_player_text_box_or_delete_button(): void
    {
        $running = LessonRecording::factory()->for($this->lesson)->withAudio()->create();
        $interrupted = $this->recordingOf($this->lesson);

        $html = $this->html();

        $this->assertStringContainsString('Läuft gerade', $html);
        $this->assertSame(1, substr_count($html, '<audio'), 'Only the interrupted recording has a player');
        $this->assertStringNotContainsString(route('recordings.stream', $running), $html);
        $this->assertStringNotContainsString(route('recordings.download', $running), $html);
        $this->assertStringContainsString('\u0022recording\u0022:'.$interrupted->id.'}', $html);
        $this->assertStringNotContainsString('\u0022recording\u0022:'.$running->id.'}', $html, 'Only the interrupted recording can be deleted');
    }

    public function test_a_running_recording_cannot_be_deleted_from_the_page(): void
    {
        $running = LessonRecording::factory()->for($this->lesson)->withAudio()->create();

        $this->page()
            ->callAction(TestAction::make('deleteRecording')->arguments(['recording' => $running->id]))
            ->assertNotified('Diese Aufnahme läuft gerade');

        $this->assertModelExists($running);
        $this->assertTrue($running->fresh()->hasAudio());

        // A minute and a half without any sign of life and it is an interrupted recording the teacher may delete.
        $this->travel(LessonRecording::LIVE_SECONDS + 1)->seconds();
        $this->page()->callAction(TestAction::make('deleteRecording')->arguments(['recording' => $running->id]));

        $this->assertModelMissing($running);
    }

    public function test_editing_an_interrupted_recording_does_not_make_it_look_as_if_it_were_running(): void
    {
        $recording = $this->recordingOf($this->lesson);
        $before = $recording->fresh()->updated_at;

        $this->page()->set('transcripts.'.$recording->id, self::TRANSCRIPT)->call('analyse', $recording->id);
        $this->page()->set('transcripts.'.$recording->id, self::TRANSCRIPT.' Mehr.')->call('saveTranscript', $recording->id);

        $recording->refresh();
        $this->assertNotNull($recording->analysis);
        $this->assertEquals($before, $recording->updated_at);
        $this->assertFalse($recording->isLive());
        $this->assertStringNotContainsString('Läuft gerade', $this->html());
    }

    public function test_a_transcript_that_is_too_long_for_the_notes_tells_the_teacher_to_evaluate_it_first(): void
    {
        $recording = $this->recordingOf($this->lesson);

        $this->page()
            ->set('transcripts.'.$recording->id, str_repeat('Fotosynthese ', 4700))
            ->call('applyToNotes', $recording->id)
            ->assertNotified(Notification::make()
                ->danger()
                ->title('Die Stundennotizen wären zu lang')
                ->body('Das Transkript ist zu lang für die Notizen. Lass es zuerst auswerten, dann wird die Zusammenfassung übernommen.'));

        $this->assertSame('Tafelbild: Licht, Wasser, CO2', $this->lesson->fresh()->notes);
    }

    public function test_every_player_has_its_own_name(): void
    {
        LessonRecording::factory()->for($this->lesson)->withAudio()->finished()->create(['started_at' => '2026-10-24 10:15:00']);
        LessonRecording::factory()->for($this->lesson)->withAudio()->finished()->create(['started_at' => '2026-10-23 08:00:00']);

        $html = $this->html();

        $this->assertStringContainsString('<audio controls preload="none" class="mt-4 w-full" aria-label="Aufnahme vom 24.10.2026, 10:15 Uhr abspielen"', $html);
        $this->assertStringContainsString('aria-label="Aufnahme vom 23.10.2026, 08:00 Uhr abspielen"', $html);
    }

    private function recorderScript(): string
    {
        return view('filament.pages.record-lesson-recorder')->render();
    }

    /**
     * The part of the recorder script from one method to the next one.
     */
    private function between(string $script, string $from, string $to): string
    {
        $start = strpos($script, $from);
        $this->assertNotFalse($start, "{$from} is missing");
        $length = strpos($script, $to, $start);
        $this->assertNotFalse($length, "{$to} is missing");

        return substr($script, $start, $length - $start);
    }

    public function test_stopping_releases_the_microphone_before_anything_is_awaited_on_the_network(): void
    {
        $end = $this->between($this->recorderScript(), 'async end()', 'finished(result)');

        $speech = strpos($end, 'this.stopSpeech(true)');
        $recorder = strpos($end, 'recorder.stop()');
        $tracks = strpos($end, 'this.stopTracks()');
        $push = strpos($end, 'await this.pushFinalTranscript()');

        foreach ([$speech, $recorder, $tracks, $push] as $position) {
            $this->assertNotFalse($position);
        }

        $this->assertLessThan($push, $speech);
        $this->assertLessThan($push, $recorder);
        $this->assertLessThan($push, $tracks);
        // The stop event is awaited with a limit, so a browser that never fires it cannot keep the microphone on.
        $this->assertStringContainsString('Promise.race([stopped, sleep(5000)])', $end);
    }

    public function test_every_request_of_the_recorder_has_a_deadline(): void
    {
        $script = $this->recorderScript();

        $this->assertSame(1, substr_count($script, 'fetch('), 'All requests go through the helper with the deadline');
        $this->assertStringContainsString('new AbortController()', $script);
        $this->assertStringContainsString('controller.abort()', $script);

        foreach (['config.startUrl', 'urls.append_url', 'urls.finish_url', 'urls.transcript_url'] as $target) {
            $this->assertMatchesRegularExpression('/request\([^;]*'.preg_quote($target, '/').'/s', $script, "{$target} is requested with a deadline");
        }
    }

    public function test_a_muted_microphone_and_missing_sound_are_shown_where_nobody_can_scroll_away_from_them(): void
    {
        $script = $this->recorderScript();
        $html = $this->html();

        $this->assertStringContainsString("addEventListener('mute'", $script);
        $this->assertStringContainsString("addEventListener('unmute'", $script);
        $this->assertStringContainsString('createAnalyser()', $script);
        $this->assertStringContainsString('Kein Ton: Mikrofon stumm oder getrennt', $script);
        $this->assertStringContainsString('Speichern hakt', $script);
        $this->assertStringContainsString('class="lp-rec-note"', $html);
        $this->assertStringContainsString('Ton kommt an', $html);
        $this->assertStringContainsString('Seit 10 Sekunden kein Ton hörbar', $html);
    }

    public function test_a_good_upload_does_not_wipe_warnings_and_the_end_says_what_happened(): void
    {
        $script = $this->recorderScript();
        $html = $this->html();
        $flush = $this->between($script, 'async flush()', 'setUploadWarning(message)');

        $this->assertStringNotContainsString('this.warning', $flush);
        $this->assertStringContainsString("this.uploadWarning = ''", $flush);
        $this->assertStringContainsString("result.discarded ? 'discarded' : this.earlyReason ? 'early' : 'saved'", $script);
        $this->assertStringContainsString('this.earlyReason = ', $this->between($script, 'trackEnded()', 'abandon()'));
        $this->assertStringContainsString("doneKind === 'saved'", $html);
        $this->assertStringContainsString("doneKind === 'early'", $html);
        $this->assertStringContainsString('Es kam kein Ton an, deshalb wurde nichts gespeichert.', $html);
    }

    public function test_a_lapsed_login_keeps_recording_and_a_refusal_offers_the_unsent_parts(): void
    {
        $script = $this->recorderScript();
        $html = $this->html();
        $flush = $this->between($script, 'async flush()', 'setUploadWarning(message)');
        $fail = $this->between($script, 'fail(message, tryFinish = false)', 'downloadUnsent()');

        // 401 and 419 are retried with a renewed token instead of ending the recording.
        $this->assertMatchesRegularExpression('/\[401, 419\]\.includes\(response\.status\)\) \{[^}]*renewToken\(\)/s', $flush);
        $this->assertStringContainsString('csrf-token', $script);
        // A hard refusal ends the recording, keeps the unsent parts and closes the recording on the server.
        $this->assertStringContainsString('this.unsentParts = queue.length', $fail);
        $this->assertStringContainsString('this.finishQuietly(true)', $fail);
        $this->assertStringContainsString('downloadUnsent()', $html);
        $this->assertStringContainsString('Nicht gesicherten Teil herunterladen', $html);
        $this->assertStringContainsString('headBlob', $script);
    }

    public function test_the_final_live_text_is_retried_and_can_be_copied(): void
    {
        $script = $this->recorderScript();

        $this->assertStringContainsString('async pushFinalTranscript()', $script);
        $this->assertStringContainsString('attempt < 3', $this->between($script, 'async pushFinalTranscript()', 'async copyLiveText()'));
        $this->assertStringContainsString('this.transcriptUnsaved = true', $script);
        $this->assertStringContainsString('Live-Text kopieren', $this->html());
        $this->assertStringContainsString('navigator.clipboard.writeText', $script);
    }

    public function test_a_closed_permission_prompt_is_not_reported_as_blocked(): void
    {
        $script = $this->recorderScript();
        $html = $this->html();
        $explain = $this->between($script, 'async explainMicrophoneError(error)', 'tick()');

        $this->assertMatchesRegularExpression("/case 'NotAllowedError':\s*await this\.checkPermission\(\);/", $explain);
        $this->assertStringContainsString("this.micState === 'prompt'", $explain);
        $this->assertStringContainsString('abgelehnt oder das Fenster geschlossen', $explain);
        $this->assertStringContainsString("x-show=\"micState === 'denied' || accessDenied\"", $html);
        $this->assertStringContainsString('Safari auf iPad oder iPhone', $html);
        $this->assertStringContainsString('Website-Einstellungen', $html);
        $this->assertStringNotContainsString('wie unten beschrieben', $html);
    }

    public function test_the_page_is_reset_when_it_is_left_because_it_may_come_back_from_the_cache(): void
    {
        $script = $this->recorderScript();
        $abandon = $this->between($script, 'abandon() {', 'stopTracks() {');

        $this->assertStringContainsString("this.phase = 'idle'", $abandon);
        $this->assertStringContainsString('clearInterval(timer)', $abandon);
        $this->assertStringContainsString('this.syncChrome()', $abandon);
        $this->assertStringContainsString("window.addEventListener('pageshow'", $script);
        $this->assertStringContainsString('event.persisted', $script);
    }

    public function test_the_https_hint_is_not_hidden_behind_the_browser_hint(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('x-show="! secure"', $html);
        $this->assertStringContainsString('x-show="secure && ! supported"', $html);
        $this->assertStringNotContainsString('x-show="supported && ! secure"', $html);
        $this->assertStringNotContainsString('x-show="! supported"', $html);
    }

    public function test_the_bar_and_the_results_are_announced_by_regions_that_are_always_on_the_page(): void
    {
        $html = $this->html();

        $this->assertSame(1, preg_match('/<div\s+class="lp-rec-bar"[^>]*>/s', $html, $bar));
        $this->assertStringNotContainsString('hidden', $bar[0], 'A display:none live region is not in the accessibility tree before it changes');
        $this->assertStringContainsString("x-bind:class=\"{ 'lp-rec-bar--off': ! active }\"", $html);
        $this->assertMatchesRegularExpression('/<p class="lp-rec-sr" role="status" aria-live="polite" x-text="announcement"><\/p>/', $html);
        $this->assertStringContainsString('.lp-rec-bar.lp-rec-bar--off', $html);
        $this->assertStringContainsString('this.announce(', $this->recorderScript());
    }

    public function test_the_focus_moves_to_the_control_that_replaces_the_one_that_was_hidden(): void
    {
        $script = $this->recorderScript();
        $html = $this->html();

        foreach (['pauseButton', 'resumeButton', 'statusLine', 'doneNotice', 'errorNotice'] as $reference) {
            $this->assertStringContainsString("x-ref=\"{$reference}\"", $html);
            $this->assertStringContainsString("this.focusOn('{$reference}')", $script);
        }

        // Alpine reveals an element in the next animation frame; focusing it earlier would do nothing.
        $this->assertStringContainsString('this.$nextTick(() => requestAnimationFrame(() => attempt(3)))', $script);
        $this->assertStringContainsString('const element = this.$refs[reference];', $script);
        // Focusable programmatically, not by Tab.
        $this->assertMatchesRegularExpression('/x-ref="doneNotice" tabindex="-1"/', $html);
    }

    public function test_the_bar_fits_a_small_phone_and_toasts_are_not_hidden_behind_it(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('font-size: clamp(0.95rem, 4.4vw, 1.125rem);', $html);
        $this->assertMatchesRegularExpression('/html\.lp-rec-active \.fi-no \{\s*inset-block-start: 4\.5rem;/', $html);
    }
}
