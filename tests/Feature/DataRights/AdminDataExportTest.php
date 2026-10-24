<?php

namespace Tests\Feature\DataRights;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\ExplanationSession;
use App\Models\Lesson;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDataExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_admin_downloads_the_data_of_an_account_of_the_own_school(): void
    {
        $this->travelTo('2026-10-24 10:00:00');
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student($this->classOf($admin))->create(['name' => 'Lena Muster', 'username' => 'lena.muster']);

        $page = Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $student->getRouteKey()])
            ->assertActionVisible('downloadData')
            ->callAction('downloadData')
            ->assertFileDownloaded('lernplattform-daten-lena.muster-2026-10-24.json', contentType: 'application/json; charset=UTF-8');

        $content = base64_decode($page->effects['download']['content']);
        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Lena Muster', $data['konto']['name']);
        $this->assertSame('lena.muster', $data['konto']['benutzername']);
        $this->assertStringContainsString("\n    \"konto\"", $content, 'The file is pretty printed.');
    }

    public function test_the_export_for_an_administration_leaves_out_private_conversations_of_the_student(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student($this->classOf($admin))->create();
        $lesson = Lesson::factory()->for($student->schoolClass)->create();
        ExplanationSession::factory()->for($student, 'student')->create(['topic' => 'Privates Thema']);
        ExplanationSession::factory()->for($student, 'student')->for($lesson)->create(['topic' => 'Thema der Stunde']);

        $page = Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $student->getRouteKey()])
            ->callAction('downloadData');

        $content = base64_decode($page->effects['download']['content']);

        $this->assertStringContainsString('Thema der Stunde', $content);
        $this->assertStringNotContainsString('Privates Thema', $content);
    }

    public function test_admin_of_another_school_cannot_reach_the_account_nor_its_export(): void
    {
        $foreignAdmin = User::factory()->admin()->for(School::factory())->create();
        $student = User::factory()->student()->create();

        $this->actingAs($foreignAdmin)
            ->get(UserResource::getUrl('edit', ['record' => $student]))
            ->assertNotFound();
    }

    public function test_operator_cannot_export_a_student_or_a_teacher_but_an_administration_account(): void
    {
        $operator = User::factory()->operator()->create();
        $student = User::factory()->student()->create();
        $teacher = User::factory()->teacher()->create();
        $admin = User::factory()->admin()->create(['username' => 'schulleitung']);

        foreach ([$student, $teacher] as $account) {
            $this->actingAs($operator)
                ->get(UserResource::getUrl('edit', ['record' => $account]))
                ->assertNotFound();
        }

        Livewire::actingAs($operator)
            ->test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->assertActionVisible('downloadData')
            ->callAction('downloadData')
            ->assertFileDownloaded(contentType: 'application/json; charset=UTF-8');
    }

    public function test_teachers_and_students_cannot_open_the_account_pages(): void
    {
        $student = User::factory()->student()->create();

        foreach ([User::factory()->teacher()->for($student->school)->create(), $student] as $user) {
            $this->actingAs($user)
                ->get(UserResource::getUrl('edit', ['record' => $student]))
                ->assertForbidden();
        }
    }

    public function test_unusual_usernames_cannot_break_out_of_the_file_name(): void
    {
        $admin = User::factory()->admin()->create();
        $teacher = User::factory()->teacher()->for($admin->school)->create(['username' => '../etc/pass wd"x']);

        $page = Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $teacher->getRouteKey()])
            ->callAction('downloadData');

        $this->assertMatchesRegularExpression('/^lernplattform-daten-[A-Za-z0-9._-]+-\d{4}-\d{2}-\d{2}\.json$/', $page->effects['download']['name']);
    }

    private function classOf(User $admin): SchoolClass
    {
        return SchoolClass::factory()->for($admin->school)->create(['teacher_id' => null]);
    }
}
