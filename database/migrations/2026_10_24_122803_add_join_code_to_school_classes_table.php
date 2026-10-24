<?php

use App\Models\SchoolClass;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->string('join_code', 12)->nullable()->unique()->after('name');
        });

        DB::table('school_classes')->whereNull('join_code')->pluck('id')->each(
            fn (int $id) => DB::table('school_classes')->where('id', $id)->update(['join_code' => SchoolClass::generateJoinCode()]),
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropUnique(['join_code']);
            $table->dropColumn('join_code');
        });
    }
};
