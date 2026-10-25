<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The weekly timetable: which subject a class has in which period of which weekday (1 = Monday … 5 = Friday), with
     * the teacher and the room. A class has at most one entry per slot; the times of the periods come from the config
     * (lernplattform.timetable.periods). An entry outlives its teacher, who may still have to be replaced. A teacher is in
     * at most one class per slot; entries without a teacher never collide, because NULLs are distinct in a unique index.
     */
    public function up(): void
    {
        Schema::create('timetable_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->unsignedTinyInteger('period');
            $table->string('subject', 100);
            $table->string('room', 50)->nullable();
            $table->timestamps();

            $table->unique(['school_class_id', 'weekday', 'period']);
            $table->unique(['teacher_id', 'weekday', 'period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timetable_entries');
    }
};
