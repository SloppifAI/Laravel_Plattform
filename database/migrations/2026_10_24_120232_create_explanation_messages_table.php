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
        Schema::create('explanation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('explanation_session_id')->constrained()->cascadeOnDelete();
            $table->string('author');
            $table->text('content');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('explanation_messages');
    }
};
