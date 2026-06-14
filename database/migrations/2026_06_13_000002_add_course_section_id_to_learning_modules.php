<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_modules', function (Blueprint $table) {
            $table->foreignId('course_section_id')
                ->nullable()
                ->constrained('course_sections')
                ->nullOnDelete();

            $table->index(['course_id', 'course_section_id', 'sort_order'], 'lm_cs_section_sort_index');
        });
    }

    public function down(): void
    {
        Schema::table('learning_modules', function (Blueprint $table) {
            $table->dropIndex('lm_cs_section_sort_index');
            $table->dropForeign(['course_section_id']);
            $table->dropColumn('course_section_id');
        });
    }
};
