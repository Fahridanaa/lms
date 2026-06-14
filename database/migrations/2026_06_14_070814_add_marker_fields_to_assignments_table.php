<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->boolean('marking_allocation_enabled')->default(false);
            $table->unsignedInteger('marker_count')->default(0);
            $table->string('multi_mark_method')->nullable()->default(null);
            $table->boolean('anonymous_marking_enabled')->default(false);
            $table->boolean('hide_grader_from_student')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn([
                'marking_allocation_enabled',
                'marker_count',
                'multi_mark_method',
                'anonymous_marking_enabled',
                'hide_grader_from_student',
            ]);
        });
    }
};
