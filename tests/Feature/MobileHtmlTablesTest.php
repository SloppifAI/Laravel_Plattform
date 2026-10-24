<?php

namespace Tests\Feature;

use App\Filament\Pages\RolesAndPermissions;
use App\Models\SchoolClass;
use App\Models\User;
use App\Role;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Below the md breakpoint no table scrolls sideways: rows become cards and every value carries its label.
 * The look is CSS; these tests keep the markup that carries the labels.
 */
class MobileHtmlTablesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_every_cell_of_the_privacy_tables_names_its_column_for_phones(): void
    {
        $html = $this->get(route('legal.privacy'))->assertOk()->getContent();

        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR);
        $xpath = new DOMXPath($document);

        $tables = $xpath->query('//table[contains(@class, "legal-table")]');
        $this->assertCount(3, $tables);

        foreach ($tables as $table) {
            $headers = array_map(
                fn ($header): string => trim($header->textContent),
                iterator_to_array($xpath->query('.//thead//th[@scope="col"]', $table)),
            );
            $rows = $xpath->query('.//tbody/tr', $table);

            $this->assertGreaterThan(1, count($headers));
            $this->assertGreaterThan(0, $rows->length);

            foreach ($rows as $row) {
                $this->assertSame(1, $xpath->query('./th[@scope="row"]', $row)->length, 'The first cell is the card title.');

                $cells = $xpath->query('./td', $row);
                $this->assertSame(count($headers) - 1, $cells->length);

                foreach ($cells as $index => $cell) {
                    $label = $xpath->query('./span[@class="legal-cell-label"]', $cell);

                    $this->assertSame(1, $label->length);
                    $this->assertSame($headers[$index + 1], trim($label->item(0)->textContent));
                }
            }
        }
    }

    public function test_the_privacy_cards_apply_to_screens_only_so_print_keeps_the_real_table(): void
    {
        $this->get(route('legal.privacy'))
            ->assertSee('@media screen and (width < 48rem)', false);
    }

    public function test_a_printed_table_is_not_cut_off_by_a_scroll_box(): void
    {
        $html = $this->get(route('legal.privacy'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/@media print \{[^@]*\.legal-table-wrap \{\s*overflow: visible;/', $html);
        $this->assertStringNotContainsString('legal-table-wide', $html);
    }

    public function test_the_class_codes_label_the_class_and_the_code(): void
    {
        $schoolClass = SchoolClass::factory()->create(['name' => '7b', 'join_code' => 'ABCDEF']);

        $this->view('filament.resources.lessons.class-codes', [
            'schoolClasses' => collect([$schoolClass]),
            'registrationUrl' => null,
        ])
            ->assertSeeInOrder(['Klasse</dt>', '7b</dd>', 'Klassencode</dt>', 'ABCDEF</dd>'], false);
    }

    public function test_the_permission_matrix_is_stacked_with_a_label_per_value_on_phones(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->get(RolesAndPermissions::getUrl())
            ->assertOk()
            ->assertSee('fi-ta-table-stacked-on-mobile');

        foreach (['Was?', ...array_map(fn (Role $role): string => $role->getLabel(), Role::cases())] as $label) {
            $response->assertSee('<div class="fi-ta-cell-label">'.$label.'</div>', false);
        }
    }
}
