<?php

namespace Tests\Feature\Consents;

use App\Filament\Pages\ClassConsents;
use App\Models\ConsentForm;
use App\Models\RecordingConsent;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ConsentLettersTest extends TestCase
{
    use CreatesConsentFixtures;
    use RefreshDatabase;

    private School $school;

    private ConsentForm $form;

    private SchoolClass $schoolClass;

    /** @var Collection<int, User> */
    private Collection $students;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->school = $this->schoolWithRecordings();
        $this->school->update(['name' => 'Goethe-Gymnasium']);
        $this->form = $this->publishedForm($this->school);
        [$this->schoolClass, $this->students, $this->teacher] = $this->classWithStudents($this->school, 3);
        $this->students->each(fn (User $student, int $index) => $student->update(['name' => ['Anna Müller', 'Ben Schmidt', 'Clara Vogel'][$index]]));
    }

    private function letters(array $data = [], ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->teacher)->post(route('consents.letters', $this->schoolClass), $data);
    }

    /**
     * @return list<string> the tokens printed on the letters, in order
     */
    private function tokensIn(TestResponse $response): array
    {
        preg_match_all('#/einwilligung/([A-Za-z0-9]{48})#', $response->getContent(), $matches);

        return $matches[1];
    }

    public function test_the_teacher_gets_one_printable_letter_per_student_with_link_and_qr_code(): void
    {
        $response = $this->letters()->assertOk();

        $this->assertSame(3, substr_count($response->getContent(), '<section class="letter"'));
        $response
            ->assertSee('Goethe-Gymnasium')
            ->assertSee('Klasse '.$this->schoolClass->name)
            ->assertSee('Anna M.')
            ->assertSee('Ben S.')
            ->assertSee('Clara V.')
            ->assertSee($this->form->title)
            ->assertSee('Fassung 1')
            ->assertSee('Lieber auf Papier?')
            ->assertSee('Tuto speichert keinen Ton.')
            ->assertSee('Drucken')
            ->assertSee('data:image/svg+xml;base64,', false);
        $this->assertCount(3, array_unique($this->tokensIn($response)));
        $this->assertSame(3, substr_count($response->getContent(), 'data:image/svg+xml;base64,'));
    }

    public function test_the_letters_use_an_inline_qr_code_and_load_nothing_from_outside(): void
    {
        $html = $this->letters()->getContent();

        $this->assertStringNotContainsString('http://', str_replace(url('/'), '', $html));
        $this->assertDoesNotMatchRegularExpression('#(src|href)="https?://(?!'.preg_quote(parse_url(url('/'), PHP_URL_HOST), '#').')#', $html);

        preg_match('#data:image/svg\+xml;base64,([A-Za-z0-9+/=]+)#', $html, $match);
        $this->assertStringContainsString('<svg', base64_decode($match[1]));
    }

    public function test_the_links_work_and_only_their_hash_is_stored(): void
    {
        $tokens = $this->tokensIn($this->letters());

        foreach ($tokens as $token) {
            $this->assertNotNull(RecordingConsent::findByToken($token));
            $this->assertDatabaseHas('recording_consents', ['token_hash' => hash('sha256', $token)]);
            $this->assertStringNotContainsString($token, json_encode(DB::table('recording_consents')->get()));
            $this->get(route('consents.show', $token))->assertOk();
        }

        $this->assertSame($this->students->modelKeys(), RecordingConsent::query()->orderBy('id')->pluck('student_id')->all());
    }

    /**
     * StartSession stores the URL of every GET as "previous": a consent link opened in a browser that is signed in
     * must not leave its token in the sessions table, and neither must the letters page of the teacher.
     */
    public function test_the_plain_tokens_reach_no_session(): void
    {
        config(['session.driver' => 'database']);

        $tokens = $this->tokensIn($this->letters());
        $this->assertCount(3, $tokens);

        foreach ($tokens as $token) {
            $this->actingAs($this->teacher)->get(route('consents.show', $token))->assertOk();
        }

        $this->actingAs($this->teacher)->get(ClassConsents::getUrl(['klasse' => $this->schoolClass->getKey()]))->assertOk();

        $sessions = json_encode(DB::table('sessions')->pluck('payload'));

        // Control: the panel page of the same teacher did start a session, so the checks below can fail.
        $this->assertNotSame('[]', $sessions);

        foreach ($tokens as $token) {
            $this->assertStringNotContainsString($token, $sessions);
            $this->assertStringNotContainsString($token, json_encode(session()->all()));
        }

        $this->assertStringNotContainsString('/einwilligung/', $sessions);
    }

    public function test_the_letters_are_not_indexed_or_cached(): void
    {
        $response = $this->letters()->assertOk();

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_making_letters_again_for_the_whole_class_invalidates_the_old_links(): void
    {
        $old = $this->tokensIn($this->letters());

        $new = $this->tokensIn($this->letters(['scope' => 'all']));

        $this->assertCount(3, $new);
        $this->assertEmpty(array_intersect($old, $new));

        foreach ($old as $token) {
            $this->get(route('consents.show', $token))->assertNotFound();
        }

        foreach ($new as $token) {
            $this->get(route('consents.show', $token))->assertOk();
        }
    }

    public function test_by_default_only_students_without_a_letter_get_one_so_links_in_the_parents_hands_stay_valid(): void
    {
        $old = $this->tokensIn($this->letters());
        $newcomer = User::factory()->student($this->schoolClass)->create(['name' => 'Dora Neu']);

        $second = $this->letters();

        $this->assertSame(1, substr_count($second->getContent(), '<section class="letter"'));
        $second->assertSee('Dora N.')->assertDontSee('Anna M.');
        $this->assertCount(1, $this->tokensIn($second));
        $this->assertNotNull(RecordingConsent::query()->where('student_id', $newcomer->getKey())->first()->token_hash);

        foreach ($old as $token) {
            $this->get(route('consents.show', $token))->assertOk();
        }
    }

    public function test_a_lost_letter_is_replaced_for_one_student_only(): void
    {
        $old = $this->tokensIn($this->letters());
        $lost = $this->students[1];

        $response = $this->letters(['student' => $lost->getKey()]);

        $this->assertSame(1, substr_count($response->getContent(), '<section class="letter"'));
        $response->assertSee('Ben S.');
        $new = $this->tokensIn($response);
        $this->assertCount(1, $new);
        $this->get(route('consents.show', $old[1]))->assertNotFound();
        $this->get(route('consents.show', $old[0]))->assertOk();
        $this->get(route('consents.show', $new[0]))->assertOk();
        $this->assertTrue(RecordingConsent::findByToken($new[0])->student->is($lost));
    }

    public function test_a_student_from_another_class_cannot_be_named(): void
    {
        $other = User::factory()->student(SchoolClass::factory()->create(['school_id' => $this->school->getKey()]))->create();

        $this->letters(['student' => $other->getKey()])->assertNotFound();

        $this->assertDatabaseCount('recording_consents', 0);
    }

    public function test_an_invalid_scope_is_refused(): void
    {
        $this->letters(['scope' => 'everybody'])->assertSessionHasErrors('scope');
    }

    public function test_a_regenerated_link_keeps_an_existing_consent(): void
    {
        $this->consentAll([$this->students[0]], $this->form);

        $this->letters(['scope' => 'all'])->assertOk();

        $consent = RecordingConsent::query()->where('student_id', $this->students[0]->getKey())->firstOrFail();
        $this->assertNotNull($consent->granted_at);
        $this->assertNotNull($consent->token_hash);
    }

    public function test_the_school_administration_may_make_letters_for_every_class(): void
    {
        $admin = User::factory()->for($this->school)->admin()->create();

        $this->letters(user: $admin)->assertOk();
    }

    public function test_an_assigned_teacher_may_make_letters(): void
    {
        $colleague = User::factory()->for($this->school)->teacher()->create();
        $this->schoolClass->teachers()->attach($colleague);

        $this->letters(user: $colleague)->assertOk();
    }

    public function test_nobody_else_may_make_letters(): void
    {
        $colleague = User::factory()->for($this->school)->teacher()->create();
        $otherSchool = School::factory()->create();

        $this->letters(user: $colleague)->assertForbidden();
        $this->letters(user: User::factory()->for($otherSchool)->teacher()->create())->assertForbidden();
        $this->letters(user: User::factory()->for($otherSchool)->admin()->create())->assertForbidden();
        $this->letters(user: $this->students->first())->assertForbidden();
        $this->letters(user: User::factory()->operator()->create())->assertForbidden();

        $this->assertDatabaseCount('recording_consents', 0);
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->post(route('consents.letters', $this->schoolClass))->assertRedirect();

        $this->assertDatabaseCount('recording_consents', 0);
    }

    public function test_letters_are_only_made_via_post(): void
    {
        $this->actingAs($this->teacher)->get(route('consents.letters', $this->schoolClass))->assertStatus(405);
    }

    public function test_without_a_published_form_there_are_no_letters_and_no_links(): void
    {
        $this->form->forceFill(['published_at' => null])->save();

        $this->letters()->assertRedirect(ClassConsents::getUrl(['klasse' => $this->schoolClass->getKey()]));

        $this->assertDatabaseCount('recording_consents', 0);
    }

    public function test_the_letter_shows_the_purposes_of_the_current_form(): void
    {
        $this->form->forceFill(['purposes' => ['audio', 'transcript']])->save();

        $this->letters()
            ->assertSee('Ton des Unterrichts (Tuto-Mikro)')
            ->assertSee('Notizen aus der Stunde (aus dem Ton erkannter Text)')
            ->assertDontSee('Spracherkennung und Zusammenfassung durch den KI-Anbieter');
    }
}
