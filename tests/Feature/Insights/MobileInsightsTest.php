<?php

namespace Tests\Feature\Insights;

use App\Filament\Pages\LearningProgress;
use App\Filament\Pages\MyLearningProgress;
use App\Filament\Pages\ParentReport;
use App\Models\Competency;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\StudentProgress\CreatesLearningResults;
use Tests\TestCase;

/**
 * Below the md breakpoint no insight table scrolls sideways: every table has a card list in front of it that is shown
 * below md only, while the real table appears from md upwards and always in print. The inactive variant is
 * display:none, so screen readers meet every row and link once. The look is CSS; these tests keep the markup.
 */
class MobileInsightsTest extends TestCase
{
    use CreatesLearningResults;
    use RefreshDatabase;

    private User $teacher;

    private User $weakStudent;

    private User $strongStudent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $schoolClass = SchoolClass::factory()->create(['name' => '7b']);
        $this->teacher = $schoolClass->teacher;
        $this->weakStudent = User::factory()->student($schoolClass)->create(['name' => 'Mia Hoffmann']);
        $this->strongStudent = User::factory()->student($schoolClass)->create(['name' => 'Noah Becker']);

        $strength = Competency::factory()->create(['subject' => 'Biologie', 'name' => 'Erkenntnisgewinnung']);
        $weakness = Competency::factory()->create(['subject' => 'Mathematik', 'name' => 'Probleme mathematisch lösen']);

        $submitted = $this->evaluatedHomework($this->weakStudent, 'Fotosynthese', '2026-10-01', ['Lichtreaktion'], 66);
        $this->answer($submitted, $strength, 90);
        $this->answer($submitted, $weakness, 30);
        $this->answer($this->evaluatedHomework($this->strongStudent, 'Zellaufbau', '2026-10-02', [], 85), $strength, 85);

        $unsubmitted = Lesson::factory()->for($schoolClass)->for($this->teacher, 'teacher')->publishedFor([$this->weakStudent])
            ->create(['subject' => 'Mathematik', 'topic' => 'Satz des Pythagoras', 'due_on' => today()->subDay()]);

        ExplanationSession::factory()->for($this->weakStudent, 'student')->finished(70)
            ->create(['lesson_id' => $unsubmitted->id, 'topic' => 'Fotosynthese erklärt']);
        ExplanationSession::factory()->for($this->weakStudent, 'student')
            ->create(['lesson_id' => $unsubmitted->id, 'topic' => 'Zellatmung erklärt']);
    }

    public function test_the_competency_matrix_is_one_card_per_student_below_md_and_the_table_from_md(): void
    {
        $xpath = $this->xpath($this->actingAs($this->teacher)->get(LearningProgress::getUrl())->assertOk()->getContent());

        $this->assertCardsBeforeTables($xpath, 2);

        $reportLink = fn (User $student): string => 'a[@href="'.ParentReport::getUrl(['student' => $student]).'"]';
        $matrixCards = '(//ul['.$this->hasClasses('md:hidden', 'print:hidden').'])[1]/li';

        $this->assertSame(2, $xpath->query($matrixCards)->length);

        $weakCard = $xpath->query($matrixCards.'[.//'.$reportLink($this->weakStudent).']')->item(0);
        $strongCard = $xpath->query($matrixCards.'[.//'.$reportLink($this->strongStudent).']')->item(0);

        // One link per student in each variant, so a screen reader never meets it twice.
        $this->assertSame(1, $xpath->query($matrixCards.'//'.$reportLink($this->weakStudent))->length);
        $this->assertSame(1, $xpath->query('(//table)[1]//'.$reportLink($this->weakStudent))->length);

        $this->assertSame(
            ['Biologie Erkenntnisgewinnung 90 · Gut verstanden', 'Mathematik Probleme mathematisch lösen 30 · Förderbedarf'],
            $this->rows($xpath, $weakCard),
        );
        $this->assertStringContainsString('Förderbedarf', $this->text($xpath->query('./div[1]', $weakCard)->item(0)));
        $this->assertStringContainsString('bg-danger-50', $weakCard->getAttribute('class'));

        $this->assertSame(
            ['Biologie Erkenntnisgewinnung 85 · Gut verstanden', 'Mathematik Probleme mathematisch lösen –keine Daten'],
            $this->rows($xpath, $strongCard),
        );
        $this->assertStringNotContainsString('Förderbedarf', $this->text($xpath->query('./div[1]', $strongCard)->item(0)));
        $this->assertStringNotContainsString('danger', $strongCard->getAttribute('class'));
    }

    public function test_the_class_averages_per_lesson_are_cards_below_md_and_the_table_from_md(): void
    {
        $xpath = $this->xpath($this->actingAs($this->teacher)->get(LearningProgress::getUrl())->assertOk()->getContent());

        $lessonCards = '(//ul['.$this->hasClasses('md:hidden', 'print:hidden').'])[2]/li';
        $this->assertSame(3, $xpath->query($lessonCards)->length);

        $card = $xpath->query($lessonCards.'[p[normalize-space()="Fotosynthese"]]')->item(0);
        $this->assertSame(['Datum', 'Fach', 'Abgaben', 'Durchschnitt'], $this->labels($xpath, $card));
        $this->assertStringContainsString('01.10.2026', $this->text($card));
        $this->assertStringContainsString('1 von 1', $this->text($card));
        $this->assertStringContainsString('66 · Teilweise verstanden', $this->text($card));

        $this->assertStringContainsString('noch nicht bewertet', $this->text($xpath->query($lessonCards.'[p[normalize-space()="Satz des Pythagoras"]]')->item(0)));
    }

    public function test_the_parent_report_shows_all_three_tables_as_labelled_cards_below_md(): void
    {
        $xpath = $this->xpath($this->actingAs($this->teacher)->get(ParentReport::getUrl(['student' => $this->weakStudent]))->assertOk()->getContent());

        $this->assertCardsBeforeTables($xpath, 3);

        $cards = fn (int $position): string => '(//ul['.$this->hasClasses('md:hidden', 'print:hidden').'])['.$position.']/li';

        $this->assertSame(2, $xpath->query($cards(1))->length);
        $this->assertSame(['Fach', 'Bereich', 'Antworten', 'Durchschnitt'], $this->labels($xpath, $xpath->query($cards(1))->item(0)));

        $submitted = $xpath->query($cards(2).'[p[normalize-space()="Fotosynthese"]]')->item(0);
        $this->assertSame(['Stunde', 'Fach', 'Abgabe', 'Bewertung', 'Rückmeldung und Lücken'], $this->labels($xpath, $submitted));
        $this->assertStringContainsString('66 · Teilweise verstanden', $this->text($submitted));
        $this->assertStringContainsString('Lichtreaktion', $this->text($submitted));

        $missing = $xpath->query($cards(2).'[p[normalize-space()="Satz des Pythagoras"]]')->item(0);
        $this->assertSame(['Stunde', 'Fach', 'Abgabe', 'Bewertung'], $this->labels($xpath, $missing));
        $this->assertStringContainsString('Nicht abgegeben', $this->text($missing));

        $this->assertSame(2, $xpath->query($cards(3))->length);
        $finished = $xpath->query($cards(3).'[p[normalize-space()="Fotosynthese erklärt"]]')->item(0);
        $this->assertSame(['Datum', 'Bewertung', 'Rückmeldung'], $this->labels($xpath, $finished));
        $this->assertStringContainsString('70 · Teilweise verstanden', $this->text($finished));
        $this->assertStringContainsString('Noch nicht abgeschlossen', $this->text($xpath->query($cards(3).'[p[normalize-space()="Zellatmung erklärt"]]')->item(0)));
    }

    public function test_print_always_uses_the_table_variant_of_the_parent_report(): void
    {
        $xpath = $this->xpath($this->actingAs($this->teacher)->get(ParentReport::getUrl(['student' => $this->weakStudent]))->assertOk()->getContent());

        $this->assertSame(3, $xpath->query('//ul['.$this->hasClasses('print:hidden').']')->length, 'The cards vanish in print at any width.');
        $this->assertSame(3, $xpath->query('//div['.$this->hasClasses('print:block').']/table')->length, 'The tables show in print at any width.');
    }

    public function test_my_learning_progress_has_no_table_that_could_scroll_sideways(): void
    {
        $homework = $this->evaluatedHomework($this->weakStudent, 'Bruchrechnung', '2026-10-05');
        $this->answer($homework, Competency::factory()->create(), 95);
        $this->answer($homework, Competency::factory()->create(), 20);
        $this->answer($homework, Competency::factory()->create(), 60);

        $html = $this->actingAs($this->weakStudent)->get(MyLearningProgress::getUrl())->assertOk()->assertSee('Hier lohnt sich Üben')->getContent();

        $this->assertStringNotContainsString('<table', $html);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR);

        return new DOMXPath($document);
    }

    /**
     * XPath predicate: the class attribute has all given classes.
     */
    private function hasClasses(string ...$classes): string
    {
        return implode(' and ', array_map(
            fn (string $class): string => 'contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")',
            $classes,
        ));
    }

    /**
     * Every table sits in a wrapper that is hidden below md and shown from md and in print, directly behind its card
     * list, which is the other way round. Nothing else on the page is a table.
     */
    private function assertCardsBeforeTables(DOMXPath $xpath, int $expected): void
    {
        $this->assertSame($expected, $xpath->query('//table')->length);
        $this->assertSame($expected, $xpath->query(
            '//ul['.$this->hasClasses('md:hidden', 'print:hidden').']/following-sibling::*[1][self::div and '.$this->hasClasses('hidden', 'md:block', 'print:block').']/table',
        )->length);
    }

    private function text(DOMNode $node): string
    {
        return trim(preg_replace('/\s+/', ' ', $node->textContent));
    }

    /**
     * @return list<string> the dt labels of a card
     */
    private function labels(DOMXPath $xpath, DOMNode $card): array
    {
        return array_map($this->text(...), iterator_to_array($xpath->query('.//dt', $card), false));
    }

    /**
     * @return list<string> the text of every competency row of a student card
     */
    private function rows(DOMXPath $xpath, DOMNode $card): array
    {
        return array_map($this->text(...), iterator_to_array($xpath->query('.//dl/div', $card), false));
    }
}
