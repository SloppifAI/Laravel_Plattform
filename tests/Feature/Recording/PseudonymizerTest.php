<?php

namespace Tests\Feature\Recording;

use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Pseudonymizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PseudonymizerTest extends TestCase
{
    use RefreshDatabase;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $teacher = User::factory()->teacher()->create(['name' => 'Katrin Meier']);
        $schoolClass = SchoolClass::factory()->for($teacher, 'teacher')->create();
        User::factory()->student($schoolClass)->create(['name' => 'Anna Müller']);
        User::factory()->student($schoolClass)->create(['name' => 'Ben van der Berg']);
        User::factory()->student($schoolClass)->create(['name' => 'Çiğdem Öztürk']);
        // A student of another class is not known to the lesson.
        User::factory()->student()->create(['name' => 'Sophie Wagner']);
        $this->lesson = Lesson::factory()->for($schoolClass)->for($teacher, 'teacher')->create();
    }

    public function test_it_replaces_first_names_last_names_and_full_names_of_the_class_and_the_teacher(): void
    {
        $text = 'Anna Müller meldet sich. Müller sagt: Frau Meier, Katrin Meier hat es doch erklärt. Ben van der Berg und Berg und Ben, außerdem Çiğdem und Öztürk.';

        $result = Pseudonymizer::forLesson($this->lesson)->apply($text);

        foreach (['Anna', 'Müller', 'Katrin', 'Meier', 'Ben', 'Berg', 'Çiğdem', 'Öztürk'] as $name) {
            $this->assertStringNotContainsString($name, $result, "{$name} must be gone");
        }

        $this->assertSame('Schüler:in A meldet sich. Schüler:in A sagt: Frau Lehrkraft, Lehrkraft hat es doch erklärt. Schüler:in B und Schüler:in B und Schüler:in B, außerdem Schüler:in C und Schüler:in C.', $result);
    }

    public function test_it_ignores_case_and_handles_the_genitive_and_punctuation(): void
    {
        $result = Pseudonymizer::forLesson($this->lesson)->apply('anna? ANNAS Heft, Ben\'s Heft, meier: "Müller."');

        $this->assertSame('Schüler:in A? Schüler:in A Heft, Schüler:in B Heft, Lehrkraft: "Schüler:in A."', $result);
    }

    public function test_it_only_replaces_whole_words(): void
    {
        $result = Pseudonymizer::forLesson($this->lesson)->apply('Die Annalena, das Benzin und der Meiergarten bleiben, Anna nicht.');

        $this->assertSame('Die Annalena, das Benzin und der Meiergarten bleiben, Schüler:in A nicht.', $result);
    }

    public function test_it_knows_only_this_lessons_class(): void
    {
        $this->assertSame('Sophie Wagner sagt es.', Pseudonymizer::forLesson($this->lesson)->apply('Sophie Wagner sagt es.'));
    }

    public function test_a_name_part_shared_by_several_people_becomes_a_neutral_label(): void
    {
        $result = Pseudonymizer::forPeople(['Anna Müller', 'Anna Schmidt', 'Tim Meier'], 'Katrin Meier')->apply('Anna sagt: Anna Müller, Anna Schmidt, Tim Meier und Meier.');

        $this->assertSame('Person sagt: Schüler:in A, Schüler:in B, Schüler:in C und Person.', $result);
    }

    public function test_names_beyond_the_alphabet_get_numbers(): void
    {
        $names = array_map(fn (int $number): string => 'Vorname'.chr(65 + $number % 26).' Nachname'.$number, range(0, 27));

        $result = Pseudonymizer::forPeople($names, null)->apply('Nachname27 spricht.');

        $this->assertSame('Schüler:in 28 spricht.', $result);
    }

    public function test_names_written_in_lower_case_or_as_surname_comma_first_name_are_replaced_too(): void
    {
        $result = Pseudonymizer::forPeople(['anna schmidt', 'Müller, Jonas', 'ben  KAYA'], 'peter weber')
            ->apply('Anna, komm bitte an die Tafel. Müller hat recht. Jonas auch. Danke Herr Weber, Peter Weber. Kaya, Schmidt und Ben.');

        $this->assertSame('Schüler:in A, komm bitte an die Tafel. Schüler:in B hat recht. Schüler:in B auch. Danke Herr Lehrkraft, Lehrkraft. Schüler:in C, Schüler:in A und Schüler:in C.', $result);
        $this->assertStringNotContainsStringIgnoringCase('anna', $result);
        $this->assertStringNotContainsStringIgnoringCase('weber', $result);
    }

    public function test_the_full_name_as_it_is_stored_is_replaced_as_a_whole(): void
    {
        $result = Pseudonymizer::forPeople(['Müller, Jonas'], null)->apply('Wir hören Müller, Jonas und danach Jonas.');

        $this->assertSame('Wir hören Schüler:in A und danach Schüler:in A.', $result);
    }

    public function test_name_particles_alone_are_not_replaced_but_the_rest_of_the_name_is(): void
    {
        $result = Pseudonymizer::forPeople(['ben van der berg', 'Lea Von Stein', 'Ali bin Said'], null)->apply('Der Berg von Ben, van Gogh, Stein und Said bin ich, von der Tafel zur Schule.');

        $this->assertSame('Der Schüler:in A von Schüler:in A, van Gogh, Schüler:in B und Schüler:in C bin ich, von der Tafel zur Schule.', $result);
    }

    public function test_initials_and_single_letters_are_not_replaced(): void
    {
        $result = Pseudonymizer::forPeople(['Max M. Meier'], null)->apply('M ist ein Buchstabe, Max Meier meldet sich.');

        $this->assertSame('M ist ein Buchstabe, Schüler:in A Schüler:in A meldet sich.', $result);
    }

    public function test_without_people_the_text_stays_as_it_is(): void
    {
        $this->assertSame('Anna spricht.', Pseudonymizer::forPeople([], null)->apply('Anna spricht.'));
    }
}
