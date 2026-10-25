<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The kind of mistake in an answer that is not quite right (App\MistakeType), only ever a hint. It belongs to the answer
 * and goes with it, so it follows the answer's retention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('answers', function (Blueprint $table) {
            $table->string('mistake_type')->nullable()->after('feedback');
            $table->unsignedTinyInteger('mistake_confidence')->nullable()->after('mistake_type');
            $table->string('mistake_source')->nullable()->after('mistake_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('answers', function (Blueprint $table) {
            $table->dropColumn(['mistake_type', 'mistake_confidence', 'mistake_source']);
        });
    }
};
