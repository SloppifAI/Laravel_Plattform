<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tuto-Mikro stores no sound and no text: a row is only the record of one session (who led it, when, who was present,
 * which consent form version was checked and whether segments went to the AI). The columns for the audio file, the
 * transcript and the analysis are dropped, so nothing of the kind can sit in the database by accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_recordings', function (Blueprint $table): void {
            $table->dropColumn([
                'path', 'mime_type', 'chunks_received', 'consent_confirmed_at',
                'transcript', 'transcript_status', 'transcript_source', 'analysis', 'analysed_at',
            ]);
        });

        Schema::table('lesson_recordings', function (Blueprint $table): void {
            // Snapshot at the start: the ids of the students the teacher marked as present.
            $table->json('present_student_ids')->nullable();
            $table->unsignedSmallInteger('consent_form_version')->nullable();
            // The consent purposes that were checked (keys of ConsentForm::PURPOSES).
            $table->json('purposes')->nullable();
            // Whether any sound segment was handed to the speech recognition of the AI provider.
            $table->boolean('audio_sent_to_ai')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('lesson_recordings', function (Blueprint $table): void {
            $table->dropColumn(['present_student_ids', 'consent_form_version', 'purposes', 'audio_sent_to_ai']);
        });

        Schema::table('lesson_recordings', function (Blueprint $table): void {
            $table->string('path')->default('');
            $table->string('mime_type', 64)->default('audio/webm');
            $table->unsignedInteger('chunks_received')->default(0);
            $table->timestamp('consent_confirmed_at')->nullable();
            $table->longText('transcript')->nullable();
            $table->string('transcript_status', 16)->default('none');
            $table->string('transcript_source', 16)->nullable();
            $table->json('analysis')->nullable();
            $table->timestamp('analysed_at')->nullable();
        });
    }
};
