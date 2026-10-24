<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Each school decides itself whether its texts go to the AI provider (opt-in, as the AVV promises);
     * until then the offline demo logic answers for it.
     */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->timestamp('ai_enabled_at')->nullable();
            $table->foreignId('ai_enabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('ai_checklist')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_enabled_by');
            $table->dropColumn(['ai_enabled_at', 'ai_checklist']);
        });
    }
};
