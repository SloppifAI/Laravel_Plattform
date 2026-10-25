<?php

namespace Database\Seeders;

use App\Models\User;
use App\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Runs on every deploy, so every seeder must be idempotent and must not use factories (no dev dependencies in production).
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // The platform operator belongs to no school (see DemoSchoolSeeder for the demo schools).
        User::updateOrCreate(
            ['username' => 'hackathon'],
            ['name' => 'Hackathon', 'password' => DemoSchoolSeeder::PASSWORD, 'role' => Role::Operator, 'school_id' => null, 'school_class_id' => null],
        );

        $this->call([
            CompetencyFrameworkSeeder::class,
            DemoSchoolSeeder::class,
            TimetableSeeder::class,
        ]);
    }
}
