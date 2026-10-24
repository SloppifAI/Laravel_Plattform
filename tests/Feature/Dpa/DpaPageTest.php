<?php

namespace Tests\Feature\Dpa;

use App\Models\DpaAcceptance;
use App\Models\School;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DpaPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        // The panel's own labels (login page, footer link) are asserted in German, the default language.
        config(['lernplattform.locale.default' => 'de']);
    }

    public function test_guests_can_open_the_agreement_without_getting_a_cookie(): void
    {
        $this->assertSame('/auftragsverarbeitung', route('legal.dpa', absolute: false));

        $this->get(route('legal.dpa'))
            ->assertOk()
            ->assertCookieMissing(config('session.cookie'))
            ->assertCookieMissing('XSRF-TOKEN');
    }

    public function test_logged_in_users_can_open_it_too(): void
    {
        foreach (['operator', 'admin', 'teacher', 'student'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create())
                ->get(route('legal.dpa'))
                ->assertOk();
        }
    }

    public function test_it_is_marked_as_a_draft_and_shows_the_version(): void
    {
        config(['lernplattform.dpa.version' => '2031-04']);

        $this->get(route('legal.dpa'))
            ->assertSee('Entwurf – vor Vertragsschluss rechtlich prüfen lassen; keine Rechtsberatung.')
            ->assertSee('Stand/Version: ')
            ->assertSee('<strong>2031-04</strong>', false)
            ->assertSee('<h1>Vertrag zur Auftragsverarbeitung (AVV)</h1>', false);
    }

    public function test_it_names_both_parties(): void
    {
        $this->get(route('legal.dpa'))
            ->assertSee('Auftraggeber (Verantwortlicher)')
            ->assertSee('Auftragnehmer (Auftragsverarbeiter)')
            ->assertSee('Softwareentwicklung Sebastian Bleuel')
            ->assertSee('Ortesweg 10')
            ->assertSee('36145 Hofbieber')
            ->assertSee('info@sebastian-bleuel.de');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sections(): array
    {
        return [
            'Gegenstand und Dauer' => ['1. Gegenstand und Dauer'],
            'Art und Zweck' => ['2. Art und Zweck der Verarbeitung'],
            'Daten und Betroffene' => ['3. Daten und betroffene Personen'],
            'Pflichten des Auftraggebers' => ['4. Pflichten und Rechte des Auftraggebers'],
            'Weisungen' => ['5. Weisungen'],
            'Vertraulichkeit' => ['6. Vertraulichkeit'],
            'Sicherheit' => ['7. Sicherheit der Verarbeitung'],
            'Unterauftragsverarbeiter' => ['8. Unterauftragsverarbeiter'],
            'Betroffenenrechte' => ['9. Unterstützung bei Betroffenenrechten'],
            'Datenschutzverletzungen' => ['10. Unterstützung bei Datenschutzverletzungen, Folgenabschätzung und Konsultation'],
            'Ort der Verarbeitung' => ['11. Ort der Verarbeitung und Drittländer'],
            'Löschung und Rückgabe' => ['12. Löschung und Rückgabe'],
            'Nachweise und Kontrollen' => ['13. Nachweise und Kontrollen'],
            'Haftung' => ['14. Haftung'],
            'Laufzeit und Kündigung' => ['15. Laufzeit und Kündigung'],
            'Schlussbestimmungen' => ['16. Schlussbestimmungen'],
        ];
    }

    #[DataProvider('sections')]
    public function test_every_section_is_numbered_and_listed_in_the_table_of_contents(string $heading): void
    {
        $html = $this->get(route('legal.dpa'))->assertSee($heading)->getContent();
        $pattern = '/<h2 id="([a-z]+)">'.preg_quote(e($heading), '/').'<\/h2>/';

        $this->assertSame(1, preg_match($pattern, $html, $matches), 'The heading carries its number.');
        $this->assertStringContainsString('<a href="#'.$matches[1].'">', $html, 'The table of contents links to the section.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function article28Items(): array
    {
        return [
            'lit. a, documented instructions' => ['Art. 28 Abs. 3 lit. a DSGVO'],
            'lit. b, confidentiality' => ['Art. 28 Abs. 3 lit. b DSGVO'],
            'lit. c, security' => ['Art. 28 Abs. 3 lit. c und Art. 32 DSGVO'],
            'lit. d, sub-processors' => ['Art. 28 Abs. 2 und 4 DSGVO'],
            'lit. e, data subject rights' => ['Art. 28 Abs. 3 lit. e DSGVO'],
            'lit. f, assistance' => ['Art. 28 Abs. 3 lit. f DSGVO'],
            'lit. g, deletion or return' => ['Art. 28 Abs. 3 lit. g DSGVO'],
            'lit. h, evidence and audits' => ['Art. 28 Abs. 3 lit. h DSGVO'],
            'duty to warn about unlawful instructions' => ['Art. 28 Abs. 3 Unterabs. 2 DSGVO'],
            'electronic form' => ['Art. 28 Abs. 9 DSGVO'],
            'liability towards data subjects' => ['Art. 82 Abs. 1, 2 und 4 DSGVO'],
            'liability between the parties' => ['Art. 82 Abs. 5 DSGVO'],
            'breach notification' => ['Art. 33 Abs. 2 DSGVO'],
            'third countries' => ['Art. 44 ff. DSGVO'],
        ];
    }

    #[DataProvider('article28Items')]
    public function test_the_contract_covers_what_article_28_demands(string $reference): void
    {
        $this->get(route('legal.dpa'))->assertSee($reference);
    }

    public function test_it_describes_instructions_sub_processors_and_deletion_in_substance(): void
    {
        $text = html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($this->get(route('legal.dpa'))->getContent())));

        foreach ([
            'dokumentierte Weisung',
            'informiert er den Auftraggeber unverzüglich',
            'allgemeine schriftliche Genehmigung',
            'mindestens 30 Tage vorher',
            'Einspruch',
            'Meine Daten herunterladen',
            'meldet er sie dem Auftraggeber unverzüglich (Art. 33 Abs. 2 DSGVO), spätestens innerhalb von 24 Stunden nach Kenntnis',
            'Datenschutz-Folgenabschätzung',
            'löscht der Auftragnehmer nach Wahl des Auftraggebers',
            'Überprüfungen einschließlich Inspektionen',
            'Servern in Deutschland',
            'Drittland',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $text);
        }
    }

    public function test_the_three_annexes_are_there_and_start_on_their_own_printed_page(): void
    {
        $html = $this->get(route('legal.dpa'))
            ->assertSee('Anlage 1: Gegenstand, Daten, betroffene Personen, Zwecke und Fristen')
            ->assertSee('Anlage 2: Technische und organisatorische Maßnahmen')
            ->assertSee('Anlage 3: Unterauftragsverarbeiter')
            ->assertSee('break-before: page', false)
            ->assertSee('@media print', false)
            ->getContent();

        $this->assertSame(3, substr_count($html, 'class="avv-annex"'));
    }

    public function test_the_optional_modules_are_marked_as_applying_only_when_they_are_in_use(): void
    {
        $html = $this->get(route('legal.dpa'))
            ->assertSee('Optionale Module')
            ->assertSee('Tuto-Mikro')
            ->assertSee('Einwilligungsnachweise')
            ->assertSee('KI-Funktionen')
            ->assertSee('Erziehungsberechtigte')
            ->getContent();

        $this->assertGreaterThanOrEqual(3, substr_count($html, 'class="avv-optional"'));
    }

    public function test_annex_one_takes_the_retention_values_from_the_configuration(): void
    {
        config([
            'lernplattform.retention.school_years_kept' => 3,
            'lernplattform.retention.school_year_starts' => '09-15',
            'lernplattform.recordings.session_keep_days' => 200,
            'lernplattform.recordings.max_session_hours' => 5,
            'lernplattform.notifications.keep_read_days' => 9,
            'lernplattform.notifications.keep_days' => 45,
            'lernplattform.substitutions.max_days' => 30,
            'session.lifetime' => 45,
        ]);

        $text = $this->text();

        foreach ([
            'das laufende und die 2 vorherigen Schuljahre',
            '15. September',
            '200 Tage nach Beginn, dann automatische Löschung',
            'Nachweise über Sitzungen des Tuto-Mikros löscht die Plattform täglich 200 Tage nach Beginn',
            'nach 5 Stunden nichts mehr an',
            '9 Tage nach dem Lesen, höchstens 45 Tage nach dem Eingang',
            'höchstens 30 Tage',
            'nach 45 Minuten ohne Aktivität',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $text);
        }
        $this->assertStringNotContainsString('Übergang:', $text, 'The transition only matters for one kept school year.');

        config(['lernplattform.retention.school_years_kept' => 2, 'lernplattform.retention.school_year_starts' => '08-01']);

        $this->get(route('legal.dpa'))
            ->assertSee('das laufende und das vorherige Schuljahr')
            ->assertSee('1. August');

        config(['lernplattform.retention.school_years_kept' => 1, 'lernplattform.retention.one_year_rule_from' => '2027-08-01']);

        $this->assertStringContainsString('Übergang: Bis zum 01.08.2027 bleiben noch zwei Schuljahre gespeichert', $this->text());
    }

    public function test_annex_two_names_real_measures_and_marks_the_facts_the_operator_must_add(): void
    {
        $html = $this->get(route('legal.dpa'))
            ->assertSee('Trennung der Schulen')
            ->assertSee('die Plattform-Administration keine Unterrichts-')
            ->assertSee('nur als Hash')
            ->assertSee('werden je IP-Adresse begrenzt')
            ->assertSee('Tägliche automatische Löschung')
            ->assertSee('Kein Tracking')
            ->assertSee('[vom Auftragnehmer zu ergänzen: Schutz der Räume und Server', false)
            ->assertSee('[vom Auftragnehmer zu ergänzen: Verschlüsselung der Verbindung (TLS)', false)
            ->assertSee('[vom Auftragnehmer zu ergänzen: Zeitplan, Aufbewahrungsdauer, Speicherort und Verschlüsselung der Backups', false)
            ->assertSee('[vom Auftragnehmer zu ergänzen: Verpflichtung zur Vertraulichkeit, Datenschutzschulung', false)
            ->assertDontSee('vor Vertragsschluss zu ergänzen')
            ->getContent();

        $this->assertStringContainsString('class="avv-placeholder"', $html);
    }

    /**
     * The released contract leaves open only what the operator alone knows: who hosts and mails, and how the servers
     * are run. Everything the code decides (AI provider, retention, Tuto-Mikro, breach deadline) is written out.
     */
    public function test_only_the_operators_own_facts_are_left_open(): void
    {
        config(['lernplattform.ai.enabled' => true, 'lernplattform.ai.provider' => 'gemini']);

        preg_match_all('/\[vom Auftragnehmer zu ergänzen: ([^\]]+)\]/u', html_entity_decode($this->get(route('legal.dpa'))->getContent()), $matches);

        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $placeholder) {
            $this->assertMatchesRegularExpression('/Hosting|E-Mail|Standort und Vertrag|im Einsatz oder entfällt|Vertrag nach Art\. 28 DSGVO mit dem Anbieter|Logfiles|Räume und Server|at rest|Server- und Datenbankzugangs|TLS|Backups|Wiederherstellung|Vertraulichkeit, Datenschutzschulung/u', $placeholder);
            $this->assertDoesNotMatchRegularExpression('/KI|Google|Frist in Stunden|Aufbewahrungsdauer beim/u', $placeholder);
        }
    }

    public function test_annex_three_lists_the_hosting_and_mail_provider_as_placeholders(): void
    {
        $this->get(route('legal.dpa'))
            ->assertSee('[vom Auftragnehmer zu ergänzen: Name und Anschrift des Hosting-Anbieters', false)
            ->assertSee('[vom Auftragnehmer zu ergänzen: Name des E-Mail-Dienstes', false);
    }

    public function test_google_is_the_ai_sub_processor_and_not_in_use_while_the_platform_switch_is_off(): void
    {
        $this->assertFalse((bool) config('lernplattform.ai.enabled'));
        $this->assertSame('gemini', config('lernplattform.ai.provider'));

        $this->get(route('legal.dpa'))
            ->assertSee('Google LLC, 1600 Amphitheatre Parkway, Mountain View, CA 94043, USA')
            ->assertSee('Google Cloud EMEA Limited')
            ->assertSee('Gemini API im kostenpflichtigen Tarif')
            ->assertSee('EU-US Data Privacy Framework')
            ->assertSee('nicht im Einsatz: Die KI-Anbindung der Plattform ist abgeschaltet')
            ->assertSee('Derzeit ist die KI-Anbindung der Plattform abgeschaltet.')
            ->assertDontSee('Name und Anschrift des Anbieters')
            ->assertDontSee('Anthropic')
            ->assertDontSee('Claude')
            ->assertDontSee('Sprachverarbeitung');
    }

    public function test_with_the_platform_switch_on_google_works_only_for_schools_that_switched_the_ai_on(): void
    {
        config(['lernplattform.ai.enabled' => true]);

        $this->get(route('legal.dpa'))
            ->assertSee('im Einsatz nur für Auftraggeber, deren Schuladministration die KI-Funktionen eingeschaltet hat')
            ->assertDontSee('nicht im Einsatz')
            ->assertDontSee('Derzeit ist die KI-Anbindung der Plattform abgeschaltet.');
    }

    public function test_a_local_model_needs_no_sub_processor_and_another_provider_leaves_its_details_to_the_operator(): void
    {
        config(['lernplattform.ai.enabled' => true, 'lernplattform.ai.provider' => 'ollama']);

        $text = $this->text();

        $this->assertStringContainsString('Dafür setzt der Auftragnehmer keinen Unterauftragsverarbeiter ein.', $text);
        $this->assertStringContainsString('Eine Übermittlung in ein Drittland findet dafür nicht statt.', $text);
        $this->assertStringNotContainsString('Google', $text);

        config(['lernplattform.ai.provider' => 'mistral']);

        $this->get(route('legal.dpa'))
            ->assertSee('Mistral AI: <mark class="avv-placeholder">[vom Auftragnehmer zu ergänzen: Name und Anschrift des Anbieters]</mark>', false)
            ->assertDontSee('Google');
    }

    public function test_every_cell_of_the_tables_names_its_column_for_phones(): void
    {
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$this->get(route('legal.dpa'))->getContent(), LIBXML_NOERROR);
        $xpath = new DOMXPath($document);

        $tables = $xpath->query('//table[contains(@class, "legal-table")]');
        $this->assertCount(2, $tables);

        foreach ($tables as $table) {
            $headers = array_map(
                fn ($header): string => trim($header->textContent),
                iterator_to_array($xpath->query('.//thead//th[@scope="col"]', $table)),
            );

            foreach ($xpath->query('.//tbody/tr', $table) as $row) {
                $this->assertSame(1, $xpath->query('./th[@scope="row"]', $row)->length, 'The first cell is the card title.');

                foreach ($xpath->query('./td', $row) as $index => $cell) {
                    $label = $xpath->query('./span[@class="legal-cell-label"]', $cell);

                    $this->assertSame(1, $label->length);
                    $this->assertSame($headers[$index + 1], trim($label->item(0)->textContent));
                }
            }
        }
    }

    public function test_the_public_version_shows_no_school_data(): void
    {
        $school = School::factory()->create(['name' => 'Goethe-Gymnasium', 'school_number' => '111111']);
        $this->actingAs(User::factory()->for($school)->admin()->create());

        $this->get(route('legal.dpa'))
            ->assertOk()
            ->assertDontSee('Goethe-Gymnasium')
            ->assertDontSee('111111')
            ->assertSee('Registrierung auf der Plattform');
    }

    public function test_the_footer_of_the_legal_pages_links_to_the_agreement(): void
    {
        foreach (['legal.imprint', 'legal.privacy', 'legal.dpa'] as $routeName) {
            $this->get(route($routeName))
                ->assertSee('href="'.route('legal.dpa').'"', false)
                ->assertSee('Auftragsverarbeitung (AVV)');
        }
    }

    public function test_the_login_page_and_the_panel_link_to_the_agreement(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('href="'.route('legal.dpa').'"', false)
            ->assertSee('Auftragsverarbeitung (AVV)');

        $this->actingAs(User::factory()->admin()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('href="'.route('legal.dpa').'"', false);
    }

    public function test_the_draft_note_is_shown_until_the_operator_releases_the_contract(): void
    {
        $this->assertFalse(DpaAcceptance::isFinal());

        $text = fn (): string => html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($this->get(route('legal.dpa'))->assertOk()->getContent())));

        $this->assertStringContainsString('Entwurf – vor Vertragsschluss rechtlich prüfen lassen; keine Rechtsberatung.', $text());
        $this->assertStringContainsString('Solange der Betreiber den Vertrag nicht freigegeben hat, kann ihn keine Schule annehmen.', $text());
        $this->assertStringContainsString('Solange der Vertrag ein Entwurf ist, kann ihn keine Schule annehmen.', $text());

        config(['lernplattform.dpa.final' => true]);

        $this->assertStringNotContainsString('Entwurf – vor Vertragsschluss rechtlich prüfen lassen', $text());
        $this->assertStringNotContainsString('kann ihn keine Schule annehmen', $text());
        $this->assertStringContainsString('Die Schuladministration nimmt den Vertrag bei der Registrierung der Schule an', $text());
    }

    public function test_the_table_of_contents_links_every_heading_of_the_page(): void
    {
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$this->get(route('legal.dpa'))->getContent(), LIBXML_NOERROR);
        $xpath = new DOMXPath($document);

        $linked = [];
        foreach ($xpath->query('//nav[contains(@class, "legal-toc")]//a/@href') as $href) {
            $linked[] = ltrim($href->nodeValue, '#');
        }

        $headings = [];
        foreach ($xpath->query('//h2[@id]/@id') as $id) {
            $headings[] = $id->nodeValue;
        }

        $this->assertContains('parteien', $headings);
        $this->assertContains('annahme', $headings);
        $this->assertSame([], array_values(array_diff($headings, $linked)), 'Every h2 is in the table of contents.');
        $this->assertSame([], array_values(array_diff($linked, $headings, ['inhaltsverzeichnis'])), 'Every link has a target.');
    }

    public function test_the_table_of_contents_comes_before_the_parties(): void
    {
        $this->get(route('legal.dpa'))->assertSeeInOrder(['id="inhaltsverzeichnis"', 'id="parteien"', 'id="gegenstand"'], false);
    }

    public function test_each_school_switches_the_ai_on_for_itself_and_the_text_says_so(): void
    {
        $text = $this->text();

        foreach ([
            'Das Tuto-Mikro und die KI-Funktionen schaltet die Schuladministration jeweils für ihre Schule ein und jederzeit wieder aus',
            'Der Auftragnehmer setzt ihn nur für Auftraggeber ein, deren Schuladministration die KI-Funktionen eingeschaltet hat',
            'nur in einem kostenpflichtigen Tarif, in dem der Anbieter Eingaben und Ergebnisse nicht zur Verbesserung seiner Produkte oder zum Training nutzt',
            'indem seine Schuladministration die KI-Funktionen einschaltet',
            'Solange die KI-Funktionen ausgeschaltet sind, übermittelt der Auftragnehmer keine Daten des Auftraggebers in ein Drittland',
            'Ohne Einschalten beantwortet eine regelbasierte Logik auf dem Server des Auftragnehmers alle Anfragen',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $text);
        }

        foreach ([
            'für die Plattform insgesamt ein',
            'Eine Freischaltung nur für einzelne Auftraggeber sieht die Plattform derzeit nicht vor',
            'vorbereitet, nicht aktiv',
        ] as $outdated) {
            $this->assertStringNotContainsString($outdated, $text, 'The AI is switched on per school (School::aiEnabled()).');
        }
    }

    public function test_changes_to_measures_and_sub_processors_do_not_need_a_new_acceptance_and_the_clauses_agree(): void
    {
        $text = html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($this->get(route('legal.dpa'))->getContent())));

        $this->assertStringContainsString('Ausgenommen sind Änderungen der Maßnahmen nach Nummer 7.3 und der Unterauftragsverarbeiter nach Nummer 8.2', $text);
        $this->assertStringContainsString('auch ohne erneute Annahme', $text);
        $this->assertStringContainsString('Ohne Einspruch gilt die Änderung nach Ablauf der Frist als genehmigt und wird Teil dieses Vertrags (Nummer 15.4)', $text);
        $this->assertStringContainsString('die die Plattform mit ihrem Text aufbewahrt', $text);
    }

    public function test_the_tuto_mikro_stores_no_sound_and_names_the_voices_that_reach_the_provider(): void
    {
        $text = $this->text();

        foreach ([
            'Weitere Personen, deren Stimme beim Tuto-Mikro zu hören ist',
            'Die Plattform speichert keinen Ton, sondern nur einen Nachweis über die Sitzung',
            'wird dort nur im Arbeitsspeicher an den KI-Anbieter weitergereicht und mit dem Ende der Anfrage verworfen',
            'Ohne KI-Funktionen verlässt er das Gerät der Lehrkraft nicht',
            'Stimmen im Ton, Namen außerhalb der Klasse und Text in Bildern und PDF sind nicht pseudonymisiert',
            'Ton des Tuto-Mikros wird weder als Datei noch in einer Warteschlange oder der Datenbank gespeichert',
            'Religion, Ethik, Philosophie, Sexualkunde, Sexualerziehung, Werte und Normen, LER',
            'Die Anzeige für den Beamer zeigt nur „Mikro an“ oder „Mikro aus“ und das Thema der Stunde',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $text);
        }

        foreach (['Tonaufnahme', 'Transkript', 'Audiodatei', 'aufnehmende Lehrkraft'] as $gone) {
            $this->assertStringNotContainsString($gone, $text);
        }
    }

    public function test_the_consent_records_are_described_with_their_real_fields_and_retention(): void
    {
        $text = $this->text();

        foreach ([
            'erteilte und widerrufene Einwilligungen mit Zeitpunkten, Fassung des Einwilligungstextes, Art der Erklärung (digital oder auf Papier), erfassende Person, Bestätigung durch die Schüler:in, Prüfwert des Links',
            'bis das Konto der Schüler:in gelöscht wird',
            'Sie werden verarbeitet, sobald die Schule das Einwilligungsverfahren beginnt, also schon vor dem Freischalten des Tuto-Mikros',
            'auch Erziehungsberechtigte auf der Einwilligungsseite',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $text);
        }

        $this->assertStringNotContainsString('Frist, solange der Nachweis gebraucht wird', $text);
    }

    public function test_the_measures_say_only_what_the_code_does(): void
    {
        $text = $this->text();

        foreach ([
            'Rollen und Schulzuordnung lassen sich nur aus einer serverseitig geprüften Auswahl setzen',
            'wer sich mit einem Klassencode selbst registriert, wird immer Schüler:in dieser Schule',
            'Änderungen und Löschungen von Daten werden nicht protokolliert',
            'Die Anwendung verschlüsselt die Datenbank und die Arbeitsblätter nicht zusätzlich',
            '[vom Auftragnehmer zu ergänzen: Verschlüsselung der Datenträger, der Datenbank und des Speichers (at rest)',
            'Die Datei enthält das Konto mit der gewählten Sprache, die Unterrichts- und Lerndaten mit den gewählten Stufen, „Erklär’s mir“, Rückfragen, Klassenbuch, Vertretungen, Benachrichtigungen, den Einwilligungsnachweis und die Sitzungen des Tuto-Mikros',
            'Die Ergebnisse der eigenen Stunden sieht eine Lehrkraft auch dann noch, wenn sie der Klasse nicht mehr zugeordnet ist, die Lernstände, das Klassenbuch und die Einwilligungen der Klasse dann nicht mehr.',
            'danach endet der Zugriff, auch auf die Ergebnisse der Stunden aus der Vertretung',
            'Stunden anlegen, die Lernstände der Klasse sehen, das Klassenbuch mit allen Fehlzeiten lesen und exportieren, Einwilligungen der Eltern erfassen und Tuto-Mikro nutzen',
            'Den Nachweis über eine Sitzung des Tuto-Mikros sieht nur die Lehrkraft der Stunde, auch nicht die Schuladministration',
            'Die Wahl der Einfachen Sprache und die Einstellung „Namen verbergen“ beim Tuto-Mikro speichert nur der Browser',
            'Die Plattform zeigt dann einen Fehler an und schickt die Daten nicht an einen anderen Anbieter',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $text);
        }

        $this->assertStringNotContainsString('legt der Server fest und übernimmt sie nie aus Eingaben', $text);
        $this->assertStringNotContainsString('Alle Inhalte gehören zu einem Konto. Technische Fehler', $text);
        $this->assertStringNotContainsString('enthält diese Datei nicht', $text);
    }

    public function test_the_contract_does_not_exclude_its_own_profiling_and_liability_clauses_are_split(): void
    {
        $text = html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($this->get(route('legal.dpa'))->getContent())));

        $this->assertStringContainsString('nicht für eigene Profilbildung', $text);
        $this->assertStringContainsString('Die Lernstandsauswertung, die der Auftraggeber mit der Plattform beauftragt (Anlage 1), bleibt davon unberührt', $text);
        $this->assertStringContainsString('haftet jede Partei für die Verletzung ihrer Pflichten aus diesem Vertrag und aus der DSGVO', $text);
        $this->assertStringNotContainsString('nur, wenn er seinen speziell den Auftragsverarbeitern auferlegten Pflichten', $text);
        $this->assertStringContainsString('Der Auftraggeber muss die Verletzung selbst binnen 72 Stunden melden (Art. 33 Abs. 1 DSGVO)', $text);
    }

    public function test_the_hosting_row_claims_neither_a_contract_nor_a_status_without_a_name(): void
    {
        $html = $this->get(route('legal.dpa'))
            ->assertSee('Deutschland, <mark class="avv-placeholder">[vom Auftragnehmer zu ergänzen: Vertrag nach Art. 28 DSGVO mit dem Anbieter', false)
            ->assertSee('[vom Auftragnehmer zu ergänzen: im Einsatz oder entfällt]', false)
            ->assertSee('oder Eigenbetrieb auf eigenen Servern und dann die Zeile streichen', false)
            ->getContent();

        $this->assertStringNotContainsString('Deutschland, Vertrag nach Art. 28 DSGVO</td>', $html);
    }

    public function test_the_contract_text_has_no_absolute_links_so_a_stored_copy_works_on_any_host(): void
    {
        $contract = DpaAcceptance::render();

        $this->assertStringContainsString('href="/datenschutzerklaerung"', $contract);
        $this->assertStringNotContainsString('href="http', $contract);
        $this->assertStringNotContainsString(config('app.url'), $contract);
    }

    public function test_the_page_prints_in_light_colours_even_in_dark_mode(): void
    {
        $this->get(route('legal.dpa'))
            ->assertSee("window.addEventListener('beforeprint'", false)
            ->assertSee("root.classList.remove('dark')", false)
            ->assertSee("window.addEventListener('afterprint'", false)
            ->assertSee("root.classList.add('dark')", false);
    }

    public function test_the_page_is_laid_out_for_phones(): void
    {
        $this->get(route('legal.dpa'))
            ->assertSee('font-size: clamp(1.5rem, 7vw, 1.875rem)', false)
            ->assertSee('font-size: clamp(1.1875rem, 5.5vw, 1.375rem)', false)
            ->assertSee('.avv-annex > * + * {', false)
            ->assertSee('@media (max-width: 40rem) {', false);
    }

    public function test_the_personalised_note_has_a_role_and_prints_only_what_belongs_to_the_document(): void
    {
        $school = School::factory()->create(['name' => 'Goethe-Gymnasium']);

        $html = $this->actingAs(User::factory()->for($school)->admin()->create())
            ->get(route('legal.dpa.school'))
            ->assertSee('<div class="legal-note" role="note" aria-labelledby="avv-schule">', false)
            ->getContent();

        $this->assertMatchesRegularExpression('/<p class="avv-noprint">\s*Die Angaben zum Auftraggeber sind mit den Stammdaten Ihrer Schule ausgefüllt/', $html);
        $this->assertDoesNotMatchRegularExpression('/<p>\s*Die Angaben zum Auftraggeber sind/', $html);
        $this->assertMatchesRegularExpression('/<p>\s*Status: noch nicht angenommen\./', $html, 'The status line is part of the document and is printed.');
    }

    /**
     * The visible text of the public agreement, with whitespace and entities normalised.
     */
    private function text(): string
    {
        return html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($this->get(route('legal.dpa'))->assertOk()->getContent())));
    }
}
