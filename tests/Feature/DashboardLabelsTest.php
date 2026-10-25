<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The start page is named after the role, and teachers are greeted by name like students.
 */
class DashboardLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_role_sees_a_start_page_named_for_it(): void
    {
        $this->actingAs(User::factory()->teacher()->create(['name' => 'Frau Sommer']))
            ->get('/')->assertOk()->assertSee('My teaching')->assertSee('Hello Frau Sommer!')->assertDontSee('My learning area');

        $this->actingAs(User::factory()->admin()->create())
            ->get('/')->assertOk()->assertSee('School overview')->assertDontSee('My learning area');

        $this->actingAs(User::factory()->operator()->create())
            ->get('/')->assertOk()->assertSee('Platform overview')->assertDontSee('My learning area');

        $this->actingAs(User::factory()->student()->create())
            ->get('/')->assertOk()->assertSee('My learning area');
    }

    public function test_the_german_teacher_start_page_says_hallo_and_mein_unterricht(): void
    {
        config(['lernplattform.locale.default' => 'de']);
        app()->setLocale('de');

        $this->actingAs(User::factory()->teacher()->create(['name' => 'Frau Sommer']))
            ->get('/')->assertOk()->assertSee('Mein Unterricht')->assertSee('Hallo Frau Sommer!');
    }
}
