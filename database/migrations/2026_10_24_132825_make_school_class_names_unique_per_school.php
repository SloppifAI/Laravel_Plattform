<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every school has its own "7b", so a class name only has to be unique within its school.
     */
    public function up(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['school_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropUnique(['school_id', 'name']);
            $table->unique('name');
        });
    }
};
