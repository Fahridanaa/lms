<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add indexes to optimize gradebook and grade queries.
     *
     * The gradebook endpoint (20% of read-heavy traffic) filters by course_id
     * first, then by user_id. Without a standalone course_id index, MySQL
     * must scan the entire grades table (~30K rows) when aggregating per-course.
     *
     * Also adds a composite index for the common filter pattern
     * (course_id, user_id) with soft-delete awareness.
     */
    public function up(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            // Primary index for gradebook: filter by course first
            $table->index('course_id', 'grades_course_id_index');

            // Composite covering index for the gradebook aggregation query
            // WHERE course_id = ? AND deleted_at IS NULL GROUP BY user_id
            $table->index(['course_id', 'deleted_at', 'user_id'], 'grades_course_agg_index');
        });
    }

    public function down(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->dropIndex('grades_course_id_index');
            $table->dropIndex('grades_course_agg_index');
        });
    }
};