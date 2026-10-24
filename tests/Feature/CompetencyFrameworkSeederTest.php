<?php

namespace Tests\Feature;

use App\Models\Competency;
use Database\Seeders\CompetencyFrameworkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompetencyFrameworkSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_twice_creates_no_duplicates(): void
    {
        $this->seed(CompetencyFrameworkSeeder::class);
        $countAfterFirstRun = Competency::query()->count();

        $this->seed(CompetencyFrameworkSeeder::class);

        $this->assertSame($countAfterFirstRun, Competency::query()->count());
        $this->assertSame(0, Competency::query()->selectRaw('count(*) as total')->groupBy('subject', 'name')->having('total', '>', 1)->count());
    }

    public function test_every_entry_of_the_framework_is_stored_with_its_area_and_description(): void
    {
        $this->seed(CompetencyFrameworkSeeder::class);

        foreach (CompetencyFrameworkSeeder::FRAMEWORK as $subject => $areas) {
            foreach ($areas as $area => $competencies) {
                foreach ($competencies as $name => $description) {
                    $this->assertDatabaseHas('competencies', [
                        'subject' => $subject === '' ? null : $subject,
                        'area' => $area,
                        'name' => $name,
                        'description' => $description,
                    ]);
                }
            }
        }
    }

    public function test_the_hessian_subjects_have_their_competency_areas(): void
    {
        $this->seed(CompetencyFrameworkSeeder::class);

        $expected = [
            'Physik' => ['Nutzung fachlicher Konzepte', 'Erkenntnisgewinnung', 'Kommunikation', 'Bewertung'],
            'Chemie' => ['Nutzung fachlicher Konzepte', 'Erkenntnisgewinnung', 'Kommunikation', 'Bewertung'],
            'Erdkunde' => ['Geographische Analysekompetenz', 'Räumliche Orientierungskompetenz', 'Geographische Methodenkompetenz', 'Geographische Urteils- und Kommunikationskompetenz'],
            'Politik und Wirtschaft' => ['Analysekompetenz', 'Urteilskompetenz', 'Handlungskompetenz', 'Methodenkompetenz'],
            'Geschichte' => ['Sachkompetenz', 'Methodenkompetenz', 'Urteilskompetenz', 'Wahrnehmungskompetenz', 'Analysekompetenz', 'Orientierungskompetenz'],
            'Biologie' => ['Sachkompetenz', 'Erkenntnisgewinnung', 'Kommunikation', 'Bewertung'],
        ];

        foreach ($expected as $subject => $names) {
            $this->assertEqualsCanonicalizing($names, Competency::query()->where('subject', $subject)->pluck('name')->all(), $subject);
        }
    }

    public function test_competencies_of_a_hessian_subject_include_the_cross_subject_ones(): void
    {
        $this->seed(CompetencyFrameworkSeeder::class);

        $names = Competency::query()->forSubject('Erdkunde')->pluck('name');

        $this->assertTrue($names->contains('Räumliche Orientierungskompetenz'));
        $this->assertTrue($names->contains('Eigenes Lernen reflektieren'));
        $this->assertFalse($names->contains('Mathematisch argumentieren'));
    }

    public function test_existing_entries_and_edited_descriptions_survive_a_new_seeding(): void
    {
        $existing = Competency::factory()->create([
            'subject' => 'Mathematik',
            'area' => 'Allgemeine mathematische Kompetenzen (KMK)',
            'name' => 'Mathematisch argumentieren',
            'description' => 'Von der Schulverwaltung angepasst.',
        ]);

        $this->seed(CompetencyFrameworkSeeder::class);

        $this->assertSame('Von der Schulverwaltung angepasst.', $existing->fresh()->description);
        $this->assertSame(1, Competency::query()->where('subject', 'Mathematik')->where('name', 'Mathematisch argumentieren')->count());
        $this->assertDatabaseHas('competencies', ['subject' => 'Deutsch', 'name' => 'Schreiben', 'area' => 'Kompetenzbereiche (KMK)']);
        $this->assertDatabaseHas('competencies', ['subject' => 'Geschichte', 'name' => 'Urteilskompetenz', 'area' => 'Kompetenzbereiche (Kernlehrplan)']);
    }

    public function test_a_renamed_entry_is_not_created_a_second_time_by_a_new_seeding(): void
    {
        $this->seed(CompetencyFrameworkSeeder::class);
        $countAfterFirstRun = Competency::query()->count();
        $renamed = Competency::query()->where('subject', 'Erdkunde')->where('name', 'Geographische Methodenkompetenz')->sole();
        $renamed->update(['name' => 'Methoden im Fach Erdkunde']);

        $this->seed(CompetencyFrameworkSeeder::class);

        $this->assertSame($countAfterFirstRun, Competency::query()->count());
        $this->assertSame('Methoden im Fach Erdkunde', $renamed->fresh()->name);
        $this->assertDatabaseMissing('competencies', ['subject' => 'Erdkunde', 'name' => 'Geographische Methodenkompetenz']);
    }

    public function test_an_entry_without_code_is_adopted_by_subject_and_name(): void
    {
        $existing = Competency::factory()->create(['subject' => 'Mathematik', 'name' => 'Mathematisch argumentieren', 'code' => null]);

        $this->seed(CompetencyFrameworkSeeder::class);

        $this->assertSame('mathematik.mathematisch-argumentieren', $existing->fresh()->code);
        $this->assertSame(1, Competency::query()->where('subject', 'Mathematik')->where('name', 'Mathematisch argumentieren')->count());
    }

    public function test_a_deleted_entry_is_created_again_by_a_new_seeding(): void
    {
        $this->seed(CompetencyFrameworkSeeder::class);
        Competency::query()->where('subject', 'Physik')->where('name', 'Bewertung')->delete();

        $this->seed(CompetencyFrameworkSeeder::class);

        $this->assertDatabaseHas('competencies', ['subject' => 'Physik', 'name' => 'Bewertung', 'code' => 'physik.bewertung']);
    }
}
