<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A competency of the shipped framework carries a stable code, so it is still recognised after the
     * administration has renamed it. Entries created by the administration have no code.
     */
    public function up(): void
    {
        Schema::table('competencies', function (Blueprint $table) {
            $table->string('code')->nullable()->unique()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('competencies', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
