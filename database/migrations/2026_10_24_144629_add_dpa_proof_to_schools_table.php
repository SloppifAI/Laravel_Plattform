<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * What a school accepted has to stay provable (Art. 28 Abs. 9 DSGVO), also after the text or the settings it depends
     * on changed and after the accepting account was deleted. So the acceptance keeps the fingerprint of the settings
     * behind the text, name and username of the accepting person as plain text, and the contract text as it was shown.
     * The text lives in its own table because it is large and the school list reads the schools table on every request.
     */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('dpa_fingerprint', 64)->nullable()->after('dpa_accepted_by');
            $table->string('dpa_accepted_by_name')->nullable()->after('dpa_fingerprint');
            $table->string('dpa_accepted_by_username')->nullable()->after('dpa_accepted_by_name');
        });

        Schema::create('dpa_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('version');
            $table->string('fingerprint', 64);
            $table->longText('content');
            $table->timestamp('accepted_at');
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accepted_by_name');
            $table->string('accepted_by_username');
            $table->timestamps();

            $table->index(['school_id', 'accepted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dpa_acceptances');

        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['dpa_fingerprint', 'dpa_accepted_by_name', 'dpa_accepted_by_username']);
        });
    }
};
