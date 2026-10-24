<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Nullable because platform operators belong to no school. Restrict on delete: a school with
     * people or classes is blocked, never deleted by accident.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->after('role')->constrained()->restrictOnDelete();
        });

        Schema::table('school_classes', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->after('id')->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
        });
    }
};
