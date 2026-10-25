<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Resources\Api\V1\LessonResource;
use App\Http\Resources\Api\V1\SchoolClassResource;
use App\Models\AppAuthorizationCode;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function legalPages(): array
    {
        return [
            'imprint' => ['legal.imprint'],
            'privacy' => ['legal.privacy'],
        ];
    }

    #[DataProvider('legalPages')]
    public function test_guests_can_open_the_legal_pages(string $routeName): void
    {
        $this->get(route($routeName))->assertOk();
    }

    #[DataProvider('legalPages')]
    public function test_logged_in_users_can_open_the_legal_pages(string $routeName): void
    {
        foreach (['operator', 'admin', 'teacher', 'student'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create())
                ->get(route($routeName))
                ->assertOk();
        }
    }

    public function test_the_legal_pages_live_next_to_the_panel_without_taking_its_routes(): void
    {
        $this->assertSame('/impressum', route('legal.imprint', absolute: false));
        $this->assertSame('/datenschutzerklaerung', route('legal.privacy', absolute: false));
        $this->get('/login')->assertOk();
    }

    #[DataProvider('legalPages')]
    public function test_the_legal_pages_set_no_cookies(string $routeName): void
    {
        $this->get(route($routeName))
            ->assertOk()
            ->assertCookieMissing(config('session.cookie'))
            ->assertCookieMissing('XSRF-TOKEN');
    }

    public function test_the_imprint_names_the_operator_and_the_legal_basics(): void
    {
        $this->get(route('legal.imprint'))
            ->assertOk()
            ->assertSee('<h1>Impressum</h1>', false)
            ->assertSee('Angaben gemäß § 5 DDG')
            ->assertSee('Softwareentwicklung Sebastian Bleuel')
            ->assertSee('Ortesweg 10')
            ->assertSee('36145 Hofbieber')
            ->assertSee('0157 32599053')
            ->assertSee('info@sebastian-bleuel.de')
            ->assertSee('DE370290830')
            ->assertSee('§ 18 Abs. 2 MStV')
            ->assertSee('weder bereit noch verpflichtet')
            ->assertSee('Haftung für Inhalte')
            ->assertSee('Wir übernehmen keine Gewähr dafür')
            ->assertSee('Haftung für Links')
            ->assertSee('Urheberrecht')
            ->assertSee('Verordnung (EU) 2022/2065')
            ->assertDontSee('§§ 8 bis 10 DDG')
            ->assertDontSee('farmmanager');
    }

    public function test_the_privacy_policy_names_the_controller_and_the_authority(): void
    {
        $this->get(route('legal.privacy'))
            ->assertOk()
            ->assertSee('<h1>Datenschutzerklärung</h1>', false)
            ->assertSee('Stand: Oktober 2026')
            ->assertSee('Softwareentwicklung Sebastian Bleuel')
            ->assertSee('Ortesweg 10')
            ->assertSee('36145 Hofbieber')
            ->assertSee('info@sebastian-bleuel.de')
            ->assertSee('Art. 28 DSGVO')
            ->assertSee('§ 25 Abs. 2 Nr. 2 TDDDG')
            ->assertSee('Hessische Beauftragte für Datenschutz und Informationsfreiheit')
            ->assertDontSee('farmmanager');
    }

    public function test_the_privacy_policy_lists_every_cookie_and_storage_key_the_panel_uses(): void
    {
        $this->get(route('legal.privacy'))
            ->assertSee('<code>'.config('session.cookie').'</code>', false)
            ->assertSee('<code>XSRF-TOKEN</code>', false)
            ->assertSee('remember_web_')
            ->assertSee('filament:theme:mode')
            ->assertSee('filament:sidebar:open')
            ->assertSee('filament:sidebar:desktop_open')
            ->assertSee('filament:sidebar:collapsed_groups')
            ->assertSee('filament.notifications.redirect')
            ->assertSee('tuto-einfache-sprache-')
            ->assertSee('tuto-mikro-hide-names')
            ->assertSee('Sitzungsspeicher')
            ->assertSee(config('session.lifetime').' Minuten');
    }

    public function test_the_privacy_policy_says_that_the_login_page_already_starts_a_session(): void
    {
        $this->get(route('legal.privacy'))
            ->assertSee('schon, wenn Sie die Anmeldeseite')
            ->assertSee('auch ohne Konto')
            ->assertSee('IP-Adresse, die Browserangabe und den Zeitpunkt der letzten Aktivität');
    }

    public function test_the_privacy_policy_makes_no_claim_about_log_retention_the_code_cannot_back(): void
    {
        $this->get(route('legal.privacy'))
            ->assertSee('Fehlerprotokoll')
            ->assertDontSee('wenige Tage');
    }

    public function test_the_privacy_policy_covers_the_password_reset_mail(): void
    {
        $this->get(route('legal.privacy'))
            ->assertSee('Passwort zurücksetzen')
            ->assertSee('password_reset_tokens')
            ->assertSee('Der Link gilt 60 Minuten.')
            ->assertSee('Vertrags nach Art. 28 DSGVO');
    }

    public function test_the_privacy_policy_covers_the_student_import_and_the_school(): void
    {
        $this->get(route('legal.privacy'))
            ->assertSee('Import einer Schülerliste')
            ->assertSee('einmalig im Klartext')
            ->assertSee('die Schule, zu der das Konto gehört');
    }

    public function test_the_privacy_policy_covers_the_registration_of_a_school(): void
    {
        $this->get(route('legal.privacy'))
            ->assertSee('Registrierung einer Schule')
            ->assertSee('Eine Schule registriert sich.')
            ->assertSee('die Daten der Schule: Name, Ort und Bundesland')
            ->assertSee('Kontakt-E-Mail-Adresse der Schule')
            ->assertSee('die Daten des ersten Kontos der Schuladministration')
            ->assertSee('Schulnummer')
            ->assertSee('Gesperrt kann sich niemand der')
            ->assertSee('Art. 6 Abs. 1 lit. b DSGVO, soweit die Schule mit uns die Nutzung der')
            ->assertSee('Für die Angaben, die eine Schule bei ihrer Registrierung macht')
            ->assertSee('Daten der Schule bei der Registrierung:');
    }

    public function test_the_privacy_policy_describes_what_the_operator_can_and_cannot_see(): void
    {
        $this->get(route('legal.privacy'))
            ->assertSee('<h3>Plattform-Administration</h3>', false)
            ->assertSee('Zugänge von Schuladministrationen anlegen')
            ->assertSee('das gemeinsame Kompetenzraster pflegen')
            ->assertSee('Das sieht sie in der Plattform nicht:')
            ->assertSee('Die Plattform-Administration, die die Plattform')
            ->assertSee('Diesen Zugriff nutzen wir nur dafür und nicht, um Unterrichts- oder')
            ->assertSee('Rolle: Plattform-Administration, Schuladministration, Lehrkraft oder Schüler:in');
    }

    public function test_the_privacy_policy_scopes_what_the_administration_sees_to_its_own_school(): void
    {
        $this->get(route('legal.privacy'))
            ->assertSee('Schuladministration (nur die eigene Schule)')
            ->assertSee('alle Konten und Klassen der Schule')
            ->assertSee('nur die Konten der Schuladministrationen')
            ->assertSee('Die Daten verschiedener Schulen sind voneinander getrennt.')
            ->assertSee('Schulen sehen nichts')
            ->assertDontSee('Administration der Plattform')
            ->assertDontSee('Die Administration sieht alle');
    }

    public function test_the_who_sees_what_table_has_a_cell_for_every_role_in_every_row(): void
    {
        $html = $this->get(route('legal.privacy'))->getContent();

        preg_match('/<caption>Wer welche Daten sehen kann<\/caption>(.+?)<\/table>/s', $html, $table);
        preg_match_all('/<th scope="col">(.+?)<\/th>/', $table[1], $headers);
        preg_match('/<tbody>(.+?)<\/tbody>/s', $table[1], $body);
        $rows = array_filter(explode('</tr>', $body[1]), fn (string $row): bool => str_contains($row, '<th scope="row">'));

        $this->assertSame(['Daten', 'Schüler:in', 'Lehrkraft', 'Schuladministration (nur die eigene Schule)', 'Plattform-Administration'], $headers[1]);
        $this->assertCount(12, $rows);

        foreach ($rows as $row) {
            $this->assertSame(4, substr_count($row, '<td>'), $row);
        }
    }

    public function test_the_privacy_policy_and_the_panel_agree_on_self_registration(): void
    {
        $html = $this->get(route('legal.privacy'))->assertOk()->getContent();

        $this->assertSame(
            Filament::getPanel('lernplattform')->hasRegistration(),
            str_contains($html, 'Schüler:innen registrieren sich selbst.'),
            'The privacy policy must describe self-registration exactly when the panel offers it.',
        );
        $this->assertStringNotContainsString('Eine Selbstregistrierung gibt es nicht', $html);
        $this->assertStringNotContainsString('Es gibt keine Selbstregistrierung', $html);
    }

    public function test_the_privacy_policy_and_the_panel_agree_on_external_requests(): void
    {
        $panelHtml = $this->actingAs(User::factory()->student()->create())->get('/')->assertOk()->getContent();
        $ownHost = parse_url(url('/'), PHP_URL_HOST);

        preg_match_all('/<(?:link|script|img|iframe|source)\b[^>]*\b(?:src|href)="(?:https?:)?\/\/([^\/":]+)/', $panelHtml, $matches);
        $externalHosts = array_values(array_unique(array_filter($matches[1], fn (string $host): bool => $host !== $ownHost)));

        $policy = $this->get(route('legal.privacy'))->getContent();

        $this->assertSame(
            in_array('ui-avatars.com', $externalHosts, true),
            str_contains($policy, 'ui-avatars.com'),
            'The privacy policy must name ui-avatars.com exactly when the panel loads the avatar from there.',
        );
        $this->assertSame(
            [],
            array_values(array_diff($externalHosts, ['ui-avatars.com'])),
            'The privacy policy knows no external host other than the avatar service.',
        );
    }

    public function test_the_privacy_table_of_contents_matches_the_numbered_headings(): void
    {
        $html = $this->get(route('legal.privacy'))->getContent();

        preg_match_all('/<li><a href="#([a-z-]+)">(.+?)<\/a><\/li>/u', $html, $contents, PREG_SET_ORDER);
        preg_match_all('/<h2 id="([a-z-]+)">(\d+)\. (.+?)<\/h2>/u', $html, $headings, PREG_SET_ORDER);

        $this->assertCount(16, $contents);
        $this->assertCount(16, $headings);

        foreach ($contents as $index => [, $id, $title]) {
            $this->assertSame($id, $headings[$index][1]);
            $this->assertSame((string) ($index + 1), $headings[$index][2]);
            $this->assertSame($title, $headings[$index][3]);
        }
    }

    public function test_the_privacy_policy_says_the_ai_connection_is_off_while_it_is_disabled(): void
    {
        config(['lernplattform.ai.enabled' => false]);

        $this->get(route('legal.privacy'))
            ->assertSee('Die KI-Anbindung der Plattform ist abgeschaltet.')
            ->assertSee('werden keine Daten an Dritte übermittelt')
            ->assertSee('Jede Schule entscheidet selbst')
            ->assertDontSee('Die KI-Anbindung der Plattform ist eingeschaltet.');
    }

    public function test_the_privacy_policy_names_google_once_the_platform_offers_the_ai(): void
    {
        config(['lernplattform.ai.enabled' => true, 'lernplattform.ai.provider' => 'gemini']);

        $text = $this->text(route('legal.privacy'));

        foreach ([
            'Die KI-Anbindung der Plattform ist eingeschaltet.',
            'Anbieter ist Google mit der Gemini API im kostenpflichtigen Tarif',
            'Google Cloud EMEA Limited',
            'EU-US Data Privacy Framework',
            'Benutzernamen, E-Mail-Adressen, Benutzerkennungen, Klassen- und Schulnamen werden nicht übermittelt.',
            'Schüler:in A',
            'Text in Fotos und PDF-Dateien kann es nicht ersetzen',
            'nicht für spätere Abrufe zu speichern',
            'Es greift dann nicht unbemerkt auf die Offline-Logik',
            'Eine Kopie der Garantien',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $text);
        }
        $this->assertStringNotContainsString('Die KI-Anbindung der Plattform ist abgeschaltet.', $text);
    }

    public function test_a_local_model_sends_nothing_to_a_third_country(): void
    {
        config(['lernplattform.ai.enabled' => true, 'lernplattform.ai.provider' => 'ollama']);

        $text = $this->text(route('legal.privacy'));

        $this->assertStringContainsString('Das Sprachmodell läuft auf unseren eigenen Servern', $text);
        $this->assertStringNotContainsString('Google', $text);
        $this->assertStringNotContainsString('Übermittlung in Drittländer', $text);
    }

    #[DataProvider('legalPages')]
    public function test_the_legal_pages_name_neither_anthropic_nor_the_old_names(string $routeName): void
    {
        foreach ([false, true] as $aiEnabled) {
            config(['lernplattform.ai.enabled' => $aiEnabled]);
            $html = $this->get(route($routeName))->assertOk()->getContent();

            foreach (['Anthropic', 'Claude', 'Frieda', '– Lernplattform', 'Aufnahme-Modul', 'Transkripttext'] as $gone) {
                $this->assertStringNotContainsString($gone, $html);
            }
        }

        $this->get(route($routeName))->assertSee('– Tuto</title>', false);
    }

    public function test_the_privacy_policy_takes_the_retention_periods_from_the_configuration(): void
    {
        config([
            'lernplattform.retention.school_years_kept' => 1,
            'lernplattform.retention.one_year_rule_from' => '2027-08-01',
            'lernplattform.recordings.session_keep_days' => 200,
            'lernplattform.recordings.max_session_hours' => 5,
            'lernplattform.notifications.keep_read_days' => 9,
            'lernplattform.notifications.keep_days' => 45,
            'lernplattform.substitutions.max_days' => 30,
        ]);

        $text = $this->text(route('legal.privacy'));

        foreach ([
            'Aufbewahrt wird das laufende Schuljahr.',
            'Übergangsweise bleiben bis zum 01.08.2027 noch zwei Schuljahre gespeichert',
            'Der Nachweis über eine Sitzung wird 200 Tage nach ihrem Beginn automatisch gelöscht.',
            'nach 5 Stunden nicht beendet ist',
            '9 Tage nachdem sie gelesen wurden, ungelesene spätestens 45 Tage nach dem Eingang',
            'höchstens 30 Tage',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $text);
        }

        config(['lernplattform.retention.one_year_rule_from' => '']);

        $this->assertStringNotContainsString('Übergangsweise', $this->text(route('legal.privacy')));
    }

    public function test_the_privacy_policy_describes_the_tuto_mikro_without_stored_sound(): void
    {
        $text = $this->text(route('legal.privacy'));

        foreach ([
            'Der Ton wird nie gespeichert: nicht als Datei, nicht in einer Warteschlange und nicht in der Datenbank.',
            'Ohne KI-Funktionen verlässt der Ton das Gerät der Lehrkraft nicht.',
            'Diese Abschnitte sind nicht pseudonymisiert',
            'Der erkannte Text bleibt nur im Browser der Lehrkraft',
            'Nur die Lehrkraft der Stunde sieht ihn, auch nicht die Schuladministration.',
            'Religion, Ethik, Philosophie, Sexualkunde, Sexualerziehung, Werte und Normen, LER',
            'keine Namen und keinen Text',
            'Die öffentliche Einwilligungsseite setzt kein Cookie',
            'Code ebenfalls in diesem Zugriffsprotokoll',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $text);
        }
    }

    public function test_the_privacy_policy_covers_class_book_substitutions_questions_and_notifications(): void
    {
        $text = $this->text(route('legal.privacy'));

        foreach ([
            'die fehlenden Schüler:innen (ohne Gründe)',
            'Die Ergebnisse einer Stunde (Abgaben, Gespräche und Rückfragen) sieht dagegen die Lehrkraft, die die Stunde angelegt hat, auch dann noch, wenn sie der Klasse nicht mehr zugeordnet ist.',
            'Wer nur vertreten hat, verliert die Ergebnisse der Stunden aus der Vertretung mit dem Ende der Vertretung.',
            'Stunden anlegen, die Lernstände der Klasse sehen, das Klassenbuch mit allen Fehlzeiten lesen und exportieren, Einwilligungen der Eltern erfassen und Tuto-Mikro nutzen',
            'Rückfragen an die Lehrkraft',
            'nicht per E-Mail verschickt',
            'die gewählte Sprache der Oberfläche',
            'Antworten auf die Fragen mit der gewählten Stufe',
            'Sie enthält nur Texte der Lehrkraft.',
            'Schüler:innen sehen die Zusammenfassung der Lehrkraft vor den Fragen.',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $text);
        }
    }

    /**
     * The section on connected apps states who may connect one, what an app reads and changes and what is stored; the
     * periods are the ones of the code (the one-time code's lifetime and sanctum.expiration).
     */
    public function test_the_privacy_policy_covers_connected_apps(): void
    {
        config(['sanctum.expiration' => 60 * 24 * 77]);

        $text = $this->text(route('legal.privacy'));

        foreach ([
            'Verbundene Apps',
            'Lehrkräfte und Schuladministrationen können eine Smartphone-App, derzeit die Tuto-App, mit ihrem Konto verbinden.',
            'Schüler:innen und die Plattform-Administration können keine App verbinden.',
            'das Passwort erfährt die App nie',
            'Die App tauscht den Code innerhalb von '.AppAuthorizationCode::LIFETIME_MINUTES.' Minuten gegen einen Zugang',
            'Nie: Daten von Schüler:innen, die Notizen einer Stunde, Ergebnisse, Abgaben, Gespräche, die E-Mail-Adresse oder das Passwort.',
            'Einmal-Code: nur als Hash, der Code selbst wird nirgends gespeichert.',
            'der Hash des Zugangsschlüssels (nie der Schlüssel selbst)',
            'Zum Zugang speichert Tuto keine IP-Adresse, keine Gerätekennung und keine E-Mail-Adresse.',
            'Sonst sieht sie niemand, auch nicht die Schuladministration oder die Plattform-Administration.',
            'Der Einmal-Code gilt '.AppAuthorizationCode::LIFETIME_MINUTES.' Minuten und lässt sich nur einmal einlösen.',
            'Ein Zugang gilt 77 Tage ab der Verbindung, auch wenn er genutzt wird.',
            'mit jedem neuen Passwort des Kontos',
            'Ein Zugang gilt 77 Tage, endet früher, wenn die Person ihn entzieht oder das Passwort ändert, und geht mit dem Konto',
            'Das Verbinden einer App ist freiwillig, die Nutzung von Tuto hängt nicht davon ab.',
            'Bitte tragen Sie in Zusammenfassungen, die Sie oder eine App setzen, keine personenbezogenen Daten von Schüler:innen ein',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $text);
        }

        config(['sanctum.expiration' => null]);

        $text = $this->text(route('legal.privacy'));

        $this->assertStringContainsString('Ein Zugang gilt, bis die Person ihn entzieht.', $text);
        $this->assertStringNotContainsString('77 Tage', $text);
    }

    /**
     * What the section says an app sees, stores and may do is what the code does: the columns of the two tables, the
     * abilities, the fields of an answer, the limit on the code exchange and the end of the accesses with a new password.
     */
    public function test_the_statements_on_connected_apps_follow_the_code(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'tokenable_type', 'tokenable_id', 'name', 'token', 'abilities', 'last_used_at', 'expires_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('personal_access_tokens'),
            'A new column of an app access has to be named in the privacy policy and the register entry first.',
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'code', 'user_id', 'client_id', 'redirect_uri', 'code_challenge', 'expires_at', 'used_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('app_authorization_codes'),
            'A new column of a one-time code has to be named in the privacy policy and the register entry first.',
        );
        $this->assertSame(['lessons:read', 'lessons:create', 'lessons:summary'], TokenController::ABILITIES);
        $this->assertSame(
            ['id', 'subject', 'topic', 'held_on', 'class', 'summary', 'summary_from_app', 'released'],
            array_keys((new LessonResource(Lesson::factory()->create()))->toArray(request())),
            'What an app reads of a lesson has to be named in the privacy policy first.',
        );
        $this->assertSame(['id', 'name'], array_keys((new SchoolClassResource(SchoolClass::factory()->create()))->toArray(request())));

        $limit = RateLimiter::limiter('app-token')(Request::create('/api/v1/token'));
        $this->assertSame([10, 60], [$limit->maxAttempts, $limit->decaySeconds], 'The policy says 10 requests per minute.');

        $teacher = User::factory()->teacher()->create();
        $teacher->createToken('Tablet', TokenController::ABILITIES);
        AppAuthorizationCode::issue($teacher, 'tuto-app', 'tutoapp://callback', str_repeat('a', 43));

        $teacher->update(['password' => 'ein-ganz-neues-passwort']);

        $this->assertSame(0, $teacher->tokens()->count());
        $this->assertSame(0, AppAuthorizationCode::query()->whereBelongsTo($teacher)->count());
    }

    public function test_the_legal_pages_link_to_each_other_and_to_the_login(): void
    {
        $imprintLink = 'href="'.route('legal.imprint').'"';
        $privacyLink = 'href="'.route('legal.privacy').'"';
        $loginLink = 'href="'.url('/').'"';

        $this->get(route('legal.imprint'))
            ->assertSee($privacyLink, false)
            ->assertSee($imprintLink, false)
            ->assertSee($loginLink, false);

        $this->get(route('legal.privacy'))
            ->assertSee($imprintLink, false)
            ->assertSee($privacyLink, false)
            ->assertSee($loginLink, false);
    }

    #[DataProvider('legalPages')]
    public function test_the_footer_marks_the_current_page(string $routeName): void
    {
        $html = $this->get(route($routeName))->getContent();

        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route($routeName), '/').'"\s+aria-current="page"/',
            $html,
        );
    }

    #[DataProvider('legalPages')]
    public function test_the_legal_pages_load_nothing_from_other_hosts(string $routeName): void
    {
        $html = $this->get(route($routeName))->getContent();
        $ownHost = preg_quote(parse_url(url('/'), PHP_URL_HOST), '/');

        $this->assertStringContainsString('<html lang="de"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/<(?:link|script|img|iframe|source)\b[^>]*\b(?:src|href)="(?:https?:)?\/\/(?!'.$ownHost.'[:\/"])/',
            $html,
        );
    }

    /**
     * The visible text of a page, with whitespace and entities normalised.
     */
    private function text(string $url): string
    {
        return html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($this->get($url)->assertOk()->getContent())));
    }
}
