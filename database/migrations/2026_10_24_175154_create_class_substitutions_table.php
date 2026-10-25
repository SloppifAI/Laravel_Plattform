<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A substitution lets a teacher of the school teach a class (lessons, learning data) from `starts_on` to `ends_on`
     * inclusive, without the administration assigning them for good. The row stays after it expired as a record, until PruneLearningData deletes it.
     */
    public function up(): void
    {
        Schema::create('class_substitutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('substitute_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['substitute_id', 'starts_on', 'ends_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_substitutions');
    }
};
