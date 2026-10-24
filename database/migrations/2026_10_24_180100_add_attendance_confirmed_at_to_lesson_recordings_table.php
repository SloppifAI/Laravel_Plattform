<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The teacher declares who is in the room, and the declaration is the proof that the list was meant: the moment the
 * teacher confirmed "only the people ticked are in the room" is stored with the session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_recordings', function (Blueprint $table): void {
            $table->timestamp('attendance_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lesson_recordings', function (Blueprint $table): void {
            $table->dropColumn('attendance_confirmed_at');
        });
    }
};
