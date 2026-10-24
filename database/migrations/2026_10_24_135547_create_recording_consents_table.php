<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per student holds the current state of the parents' consent. The link token is stored as a SHA-256 hash only.
     */
    public function up(): void
    {
        Schema::create('recording_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('consent_form_id')->nullable()->constrained()->cascadeOnDelete();
            // Null for a paper consent recorded without ever printing a letter. Unique values only.
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('token_generated_at')->nullable();
            $table->string('guardian_name')->nullable();
            $table->json('purposes')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->enum('source', ['digital', 'paper'])->default('digital');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('student_confirmed_at')->nullable();
            $table->timestamps();

            $table->index('school_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recording_consents');
    }
};
