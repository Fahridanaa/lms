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
        Schema::table('courses', function (Blueprint $table) {
            $table->unsignedBigInteger('course_category_id')->nullable()->after('instructor_id');

            $table->foreign('course_category_id')->references('id')->on('course_categories')->onDelete('set null');
            $table->index('course_category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropForeign(['course_category_id']);
            $table->dropIndex(['course_category_id']);
            $table->dropColumn('course_category_id');
        });
    }
};
