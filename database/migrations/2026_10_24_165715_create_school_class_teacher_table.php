<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Teachers used to see every class of their school they held a published lesson in. So that nobody loses a class
     * overnight, exactly those teachers are assigned to those classes; the administration can detach them afterwards.
     */
    public function up(): void
    {
        Schema::create('school_class_teacher', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['school_class_id', 'user_id']);
        });

        $now = now();

        DB::table('lessons')
            ->join('school_classes', 'school_classes.id', '=', 'lessons.school_class_id')
            ->join('users', 'users.id', '=', 'lessons.teacher_id')
            ->whereNotNull('lessons.published_at')
            ->where('users.role', 'teacher')
            ->whereColumn('users.school_id', 'school_classes.school_id')
            ->where(fn ($query) => $query->whereNull('school_classes.teacher_id')->orWhereColumn('school_classes.teacher_id', '<>', 'lessons.teacher_id'))
            ->distinct()
            ->get(['lessons.school_class_id', 'lessons.teacher_id as user_id'])
            ->map(fn (object $row): array => ['school_class_id' => $row->school_class_id, 'user_id' => $row->user_id, 'created_at' => $now, 'updated_at' => $now])
            ->chunk(500)
            ->each(fn ($rows) => DB::table('school_class_teacher')->insert($rows->all()));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_class_teacher');
    }
};
