<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `prompt` stays the Standard level of a question; `prompt_basis` (Einstieg) and `prompt_plus` (Profi) are optional
     * variants of it. The `*_simple` columns cache the "Einfache Sprache" version of the text they are named after; they
     * are emptied whenever that text changes and filled on first request.
     */
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->text('prompt_basis')->nullable()->after('prompt');
            $table->text('prompt_plus')->nullable()->after('prompt_basis');
            $table->text('prompt_simple')->nullable()->after('prompt_plus');
            $table->text('prompt_basis_simple')->nullable()->after('prompt_simple');
            $table->text('prompt_plus_simple')->nullable()->after('prompt_basis_simple');
        });

        Schema::table('answers', function (Blueprint $table) {
            $table->string('level')->nullable()->after('answer');
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->text('summary_simple')->nullable()->after('summary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('summary_simple');
        });

        Schema::table('answers', function (Blueprint $table) {
            $table->dropColumn('level');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn(['prompt_basis', 'prompt_plus', 'prompt_simple', 'prompt_basis_simple', 'prompt_plus_simple']);
        });
    }
};
