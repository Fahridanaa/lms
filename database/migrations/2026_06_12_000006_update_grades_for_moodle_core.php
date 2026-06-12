<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->foreignId('grader_id')->nullable()->after('course_id')->constrained('users')->nullOnDelete();
            $table->text('feedback')->nullable()->after('percentage');
            $table->enum('status', ['draft', 'final', 'overridden'])->default('final')->after('feedback');
            $table->enum('source', ['quiz', 'assignment', 'manual'])->default('manual')->after('status');
            $table->unique(['user_id', 'course_id', 'gradeable_type', 'gradeable_id'], 'grades_user_course_gradeable_unique');
            $table->index(['course_id', 'status'], 'grades_course_status_index');
            $table->index(['user_id', 'status'], 'grades_user_status_index');
        });

        DB::table('grades')->update([
            'source' => DB::raw("CASE WHEN gradeable_type LIKE '%Quiz%' OR gradeable_type = 'quiz_attempt' THEN 'quiz' WHEN gradeable_type LIKE '%Submission%' OR gradeable_type = 'submission' THEN 'assignment' ELSE 'manual' END"),
        ]);
    }

    public function down(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->dropUnique('grades_user_course_gradeable_unique');
            $table->dropIndex('grades_course_status_index');
            $table->dropIndex('grades_user_status_index');
            $table->dropConstrainedForeignId('grader_id');
            $table->dropColumn(['feedback', 'status', 'source']);
        });
    }
};
