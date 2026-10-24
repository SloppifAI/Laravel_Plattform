<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The subjects in which Tuto-Mikro is never available at a school. Null means the school never chose and the
 * suggested list applies (School::DEFAULT_MIKRO_BLOCKED_SUBJECTS); an empty list means it chose to block nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table): void {
            $table->json('mikro_blocked_subjects')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table): void {
            $table->dropColumn('mikro_blocked_subjects');
        });
    }
};
