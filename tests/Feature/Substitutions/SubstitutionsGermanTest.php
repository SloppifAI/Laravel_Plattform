<?php

namespace Tests\Feature\Substitutions;

use App\Filament\Pages\HandoverSheet;
use App\Filament\Pages\Substitutions;
use App\Models\ClassSubstitution;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The tests of the substitutions run in English; this one makes sure the German interface (the default) still reads German.
 */
class SubstitutionsGermanTest extends TestCase
{
    use RefreshDatabase;

    private User $leader;

    private User $substitute;

    private SchoolClass $schoolClass;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lernplattform.locale.default' => 'de']);
        app()->setLocale('de');

        $school = School::factory()->create();
        $this->leader = User::factory()->teacher()->for($school)->create();
        $this->substitute = User::factory()->teacher()->for($school)->create(['name' => 'Herr Vogel']);
        $this->schoolClass = SchoolClass::factory()->for($school)->create(['name' => '7b', 'teacher_id' => $this->leader->id]);
    }

    public function test_the_substitutions_page_reads_german(): void
    {
        ClassSubstitution::factory()->for($this->schoolClass)->create(['substitute_id' => $this->substitute->id]);

        $this->actingAs($this->leader)->get(Substitutions::getUrl())
            ->assertOk()
            ->assertSee('Vertretung')
            ->assertSee('Vertretung einrichten')
            ->assertSee('Hinweis zur Übergabe')
            ->assertSee('Aktiv')
            ->assertSee(ClassSubstitution::grants(), false)
            ->assertSee('Klassenbuch mit allen Fehlzeiten', false)
            ->assertDontSee('Set up cover');
    }

    public function test_the_empty_state_does_not_claim_a_position_for_the_button(): void
    {
        $this->actingAs($this->leader)->get(Substitutions::getUrl())
            ->assertOk()
            ->assertSee('richtest du eine mit „Vertretung einrichten“ über der Liste ein.')
            ->assertDontSee('oben rechts');
    }

    public function test_the_handover_sheet_reads_german(): void
    {
        ClassSubstitution::factory()->for($this->schoolClass)->create(['substitute_id' => $this->substitute->id, 'note' => 'Bitte den Beamer vorher testen.']);

        $this->actingAs($this->leader)->get(HandoverSheet::getUrl(['schoolClass' => $this->schoolClass->id]))
            ->assertOk()
            ->assertSee('Übergabe-Mappe')
            ->assertSee('Klasse 7b')
            ->assertSee('Wen du fragen kannst')
            ->assertSee('Offene Hausaufgaben')
            ->assertSee('Zurzeit ist keine Hausaufgabe offen.')
            ->assertSee('lang="de"', false)
            ->assertDontSee('Handover sheet');
    }

    public function test_what_a_substitution_grants_is_one_german_clause(): void
    {
        $this->assertSame(
            'Stunden anlegen, die Lernstände der Klasse sehen, das Klassenbuch mit allen Fehlzeiten lesen und exportieren, Einwilligungen der Eltern erfassen und Tuto-Mikro nutzen',
            ClassSubstitution::grants(),
        );
        $this->assertStringStartsWith('create lessons,', ClassSubstitution::grants('en'));
    }
}
