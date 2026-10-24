<?php

namespace Tests\Feature\DataRights;

use App\Filament\Pages\Auth\EditProfile;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileDataDownloadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function roles(): array
    {
        return [
            'operator' => ['operator'],
            'admin' => ['admin'],
            'teacher' => ['teacher'],
            'student' => ['student'],
        ];
    }

    #[DataProvider('roles')]
    public function test_every_role_can_download_its_data_as_a_json_file(string $role): void
    {
        $this->travelTo('2026-10-24 10:00:00');
        $user = User::factory()->{$role}()->create(['username' => 'maxi.muster']);

        $page = Livewire::actingAs($user)
            ->test(EditProfile::class)
            ->assertSee('Meine Daten herunterladen')
            ->callAction('downloadData')
            ->assertFileDownloaded('lernplattform-daten-maxi.muster-2026-10-24.json', contentType: 'application/json; charset=UTF-8');

        $content = base64_decode($page->effects['download']['content']);
        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('maxi.muster', $data['konto']['benutzername']);
        $this->assertStringContainsString("\n    \"konto\"", $content, 'The file is pretty printed.');
    }

    public function test_the_download_holds_only_the_data_of_the_logged_in_user(): void
    {
        $student = User::factory()->student()->create(['username' => 'lena.muster']);
        $other = User::factory()->student($student->schoolClass)->create(['username' => 'tom.sommer', 'name' => 'Tom Sommer']);
        $lesson = Lesson::factory()->for($student->schoolClass)->create();
        Submission::factory()->for($lesson)->for($student, 'student')->create(['summary' => 'Meine Zusammenfassung']);
        Submission::factory()->for($lesson)->for($other, 'student')->create(['summary' => 'Zusammenfassung von Tom']);
        ExplanationSession::factory()->for($other, 'student')->create(['topic' => 'Toms Gespräch']);

        $page = Livewire::actingAs($student)->test(EditProfile::class)->callAction('downloadData');

        $content = base64_decode($page->effects['download']['content']);

        $this->assertStringContainsString('Meine Zusammenfassung', $content);
        foreach (['Tom Sommer', 'tom.sommer', 'Zusammenfassung von Tom', 'Toms Gespräch'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $content);
        }
        $this->assertStringNotContainsString($student->password, $content);
    }

    public function test_unusual_usernames_cannot_break_out_of_the_file_name(): void
    {
        $user = User::factory()->teacher()->create(['username' => '../etc/pass wd"x']);

        $page = Livewire::actingAs($user)->test(EditProfile::class)->callAction('downloadData');

        $this->assertMatchesRegularExpression('/^lernplattform-daten-[A-Za-z0-9._-]+-\d{4}-\d{2}-\d{2}\.json$/', $page->effects['download']['name']);
    }

    public function test_guests_cannot_reach_the_profile_page(): void
    {
        $this->get(route('filament.lernplattform.auth.profile'))->assertRedirect();
    }
}
