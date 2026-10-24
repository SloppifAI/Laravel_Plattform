<?php

namespace Tests\Feature\Speech;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Read aloud and dictation are a browser-side enhancement for pupils only; what the script may and may not do
 * is checked here, how it behaves in a browser is checked by hand.
 */
class SpeechEnhancementTest extends TestCase
{
    use RefreshDatabase;

    private const string MARKER = '<script data-lp-speech>';

    public function test_students_get_the_script_on_their_pages(): void
    {
        $this->actingAs(User::factory()->student()->create());

        $this->get('/')->assertOk()->assertSee(self::MARKER, false);
        $this->get('/erklaer-es-mir')->assertOk()->assertSee(self::MARKER, false);
    }

    public function test_the_script_is_rendered_once_at_the_end_of_the_page(): void
    {
        $this->actingAs(User::factory()->student()->create());

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, self::MARKER));
        $this->assertGreaterThan(strpos($html, 'fi-main'), strpos($html, self::MARKER));
    }

    public function test_nobody_but_students_gets_the_script(): void
    {
        foreach (['teacher', 'admin', 'operator'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create())
                ->get('/')
                ->assertOk()
                ->assertDontSee('data-lp-speech', false);
        }
    }

    public function test_guests_do_not_get_the_script(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('data-lp-speech', false);
    }

    public function test_read_aloud_only_uses_a_german_voice_that_runs_on_the_device(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('voice.localService === true', $script);
        $this->assertStringContainsString('/^de([-_]|$)/i', $script);
        $this->assertStringContainsString("'.fi-section, [data-read-aloud]'", $script);
        $this->assertStringContainsString('aria-pressed', $script);
    }

    public function test_dictation_is_only_offered_for_on_device_recognition_and_never_falls_back_to_the_cloud(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('Recognition.available({ langs: [LANG], processLocally: true })', $script);
        $this->assertStringContainsString("state === 'available' || state === 'downloadable'", $script);
        $this->assertStringContainsString("if (!('processLocally' in recognition))", $script);
        $this->assertStringContainsString('recognition.processLocally = true;', $script);
        $this->assertStringContainsString("dispatchEvent(new Event('input', { bubbles: true }))", $script);
        $this->assertStringContainsString("dispatchEvent(new Event('change', { bubbles: true }))", $script);
        $this->assertStringContainsString('prefers-reduced-motion', $this->rendered());
    }

    public function test_the_live_region_stays_in_the_accessibility_tree_and_the_button_is_opaque(): void
    {
        $rendered = $this->rendered();

        $this->assertDoesNotMatchRegularExpression('/\.lp-speech-status:empty\s*\{[^}]*display:\s*none/', $rendered);
        $this->assertMatchesRegularExpression('/\.lp-speech-status:empty\s*\{[^}]*position:\s*absolute/', $rendered);
        $this->assertDoesNotMatchRegularExpression('/\.lp-speech-btn\s*\{[^}]*background:\s*transparent/', $rendered);
    }

    public function test_the_section_button_sits_inside_the_padded_content_and_is_named_by_its_heading(): void
    {
        $script = $this->script();

        $this->assertStringContainsString(':scope > .fi-section-content-ctn > .fi-section-content', $script);
        $this->assertStringContainsString(':scope > .fi-section-content-ctn :is(h2, h3, h4)', $script);
    }

    public function test_installing_the_language_pack_keeps_the_button_and_announces_the_result(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('upgradeSetupButtons();', $script);
        $this->assertStringContainsString("ready: 'Fertig. Du kannst jetzt diktieren.'", $script);
    }

    public function test_dictation_stops_when_the_page_is_hidden_or_nobody_speaks(): void
    {
        $script = $this->script();

        $this->assertStringContainsString("document.addEventListener('visibilitychange'", $script);
        $this->assertStringContainsString('if (document.hidden)', $script);
        $this->assertStringContainsString('const IDLE_MS = 45000;', $script);
        $this->assertStringContainsString('setTimeout(() => stopDictation(MESSAGES.stopped), IDLE_MS)', $script);
    }

    public function test_the_script_makes_no_requests_and_references_no_external_resource(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '#https?:|//[a-z0-9-]+\.[a-z]{2,}|@import|\burl\(|\bsrc\s*=|\bhref\s*=|fetch\(|XMLHttpRequest|sendBeacon|WebSocket|EventSource|localStorage|document\.cookie#i',
            $this->rendered(),
        );
    }

    public function test_the_script_is_valid_javascript(): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node.js is not installed.');
        }

        $file = tempnam(sys_get_temp_dir(), 'speech').'.js';
        file_put_contents($file, $this->script());

        $check = new Process([$node, '--check', $file]);
        $check->run();
        unlink($file);

        $this->assertTrue($check->isSuccessful(), $check->getErrorOutput());
    }

    private function rendered(): string
    {
        return view('filament.lernplattform.speech')->render();
    }

    private function script(): string
    {
        preg_match('#<script data-lp-speech>(.*?)</script>#s', $this->rendered(), $matches);

        $this->assertArrayHasKey(1, $matches, 'The speech script is missing from the view.');

        return $matches[1];
    }
}
