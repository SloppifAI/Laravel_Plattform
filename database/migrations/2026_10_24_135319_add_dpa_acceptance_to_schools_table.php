<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Electronic acceptance of the data processing agreement (Art. 28 Abs. 9 DSGVO): which version, when and by whom.
     * The accepter is kept as a pointer only, so deleting that account never deletes the proof of the acceptance.
     */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('dpa_version')->nullable()->after('contact_email');
            $table->timestamp('dpa_accepted_at')->nullable()->after('dpa_version');
            $table->foreignId('dpa_accepted_by')->nullable()->after('dpa_accepted_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dpa_accepted_by');
            $table->dropColumn(['dpa_version', 'dpa_accepted_at']);
        });
    }
};
