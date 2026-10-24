<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Lesson recordings are off for every school until its administration confirms the checklist and switches them on.
     */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->timestamp('recordings_enabled_at')->nullable();
            $table->foreignId('recordings_enabled_by')->nullable()->constrained('users')->nullOnDelete();
            // dsfa_done, personalrat_agreed, parents_informed, consent_form_approved, retention_set: each true/false.
            $table->json('recording_checklist')->nullable();
            // Besides the parents, the students confirm their own agreement.
            $table->boolean('require_student_consent')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recordings_enabled_by');
            $table->dropColumn(['recordings_enabled_at', 'recording_checklist', 'require_student_consent']);
        });
    }
};
