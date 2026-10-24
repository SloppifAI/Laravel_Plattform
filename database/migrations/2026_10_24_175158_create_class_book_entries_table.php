<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('class_book_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            // One entry per lesson; the entry outlives a deleted lesson (the Klassenbuch is a record of its own).
            $table->foreignId('lesson_id')->nullable()->unique()->constrained()->nullOnDelete();
            // Like the lesson, the entry outlives the teacher's account (shown as "gelöschtes Konto"): confirmed entries are not lost with it.
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('held_on');
            $table->unsignedSmallInteger('period')->nullable();
            $table->string('subject');
            $table->string('topic');
            // Unterrichtsinhalt
            $table->text('content');
            $table->text('homework')->nullable();
            // Ids of the absent students: personal data, only shown to the class's teachers and the school administration.
            $table->json('absent_student_ids')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['school_class_id', 'held_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_book_entries');
    }
};
