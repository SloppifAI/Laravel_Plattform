<?php

namespace Tests\Feature\Schools;

use App\Filament\Pages\Auth\Login;
use App\Http\Middleware\EnsureSchoolIsActive;
use App\Models\School;
use App\Models\User;
use App\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Drawer\Utils;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BlockedSchoolLogoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{Role}>
     */
    public static function rolesOfASchool(): array
    {
        return [
            'Schuladministration' => [Role::Admin],
            'Lehrkraft' => [Role::Teacher],
            'Schüler:in' => [Role::Student],
        ];
    }

    #[DataProvider('rolesOfASchool')]
    public function test_the_next_page_request_signs_the_user_out_and_redirects_to_the_login_page(Role $role): void
    {
        $school = School::factory()->create();
        $user = User::factory()->for($school)->create(['role' => $role]);
        $this->actingAs($user)->get('/')->assertOk();

        $school->forceFill(['blocked_at' => now()])->save();

        $this->actingAs($user->fresh())
            ->get('/')
            ->assertRedirect(route('filament.lernplattform.auth.login'))
            ->assertSessionHas(EnsureSchoolIsActive::BLOCKED_SCHOOL_FLASH_KEY);

        $this->assertGuest();
    }

    public function test_the_login_page_shows_why_the_user_was_signed_out(): void
    {
        $this->withSession([EnsureSchoolIsActive::BLOCKED_SCHOOL_FLASH_KEY => true])
            ->get(route('filament.lernplattform.auth.login'))
            ->assertOk()
            ->assertSee(Login::BLOCKED_SCHOOL_MESSAGE);
    }

    public function test_the_login_page_shows_no_blocked_message_to_everyone_else(): void
    {
        $this->get(route('filament.lernplattform.auth.login'))
            ->assertOk()
            ->assertDontSee(Login::BLOCKED_SCHOOL_MESSAGE);
    }

    public function test_a_livewire_request_of_a_page_opened_before_the_block_signs_the_user_out(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->for($school)->teacher()->create();
        $snapshot = Utils::extractAttributeDataFromHtml($this->actingAs($user)->get('/')->assertOk()->getContent(), 'wire:snapshot');
        $payload = ['components' => [['snapshot' => json_encode($snapshot), 'updates' => [], 'calls' => []]]];

        $this->actingAs($user->fresh())->postJson(Livewire::getUpdateUri(), $payload, ['X-Livewire' => 'true'])->assertOk();

        $school->forceFill(['blocked_at' => now()])->save();
        // Livewire remembers the route it checked last for the rest of the process, which in production is one request.
        Livewire::flushState();

        $this->actingAs($user->fresh())
            ->postJson(Livewire::getUpdateUri(), $payload, ['X-Livewire' => 'true'])
            ->assertRedirect(route('filament.lernplattform.auth.login'));

        $this->assertGuest();
    }

    public function test_the_operator_belongs_to_no_school_and_stays_signed_in(): void
    {
        School::factory()->blocked()->create();
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)->get('/')->assertOk();

        $this->assertAuthenticatedAs($operator);
    }

    public function test_an_active_school_stays_signed_in_while_another_school_is_blocked(): void
    {
        School::factory()->blocked()->create();
        $user = User::factory()->teacher()->create();

        $this->actingAs($user)->get('/')->assertOk();

        $this->assertAuthenticatedAs($user);
    }
}
