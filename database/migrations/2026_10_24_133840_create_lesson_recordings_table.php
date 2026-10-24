<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lesson_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            // Audio file on the private 'local' disk; the name is random, never derived from people.
            $table->string('path');
            $table->string('mime_type', 64);
            $table->unsignedInteger('chunks_received')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('consent_confirmed_at');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->longText('transcript')->nullable();
            // none|live|pending|processing|done|failed (App\TranscriptStatus), on_device|server|manual.
            $table->string('transcript_status', 16)->default('none');
            $table->string('transcript_source', 16)->nullable();
            $table->json('analysis')->nullable();
            $table->timestamp('analysed_at')->nullable();
            $table->timestamps();

            $table->index('started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lesson_recordings');
    }
};
