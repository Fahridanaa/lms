<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->timestamp('available_from')->nullable()->after('is_active');
            $table->timestamp('cutoff_date')->nullable()->after('available_from');
            $table->unsignedInteger('max_attempts')->default(1)->after('cutoff_date');
            $table->boolean('allow_late_submission')->default(false)->after('max_attempts');
            $table->enum('submission_type', ['file', 'text'])->default('file')->after('allow_late_submission');
            $table->index(['course_id', 'is_active'], 'assignments_course_active_index');
            $table->index(['available_from', 'cutoff_date'], 'assignments_availability_index');
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropUnique(['assignment_id', 'user_id']);
            $table->foreignId('grader_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->enum('status', ['draft', 'submitted', 'graded', 'returned'])->default('submitted')->after('feedback');
            $table->unsignedInteger('attempt_number')->default(1)->after('status');
            $table->boolean('is_latest')->default(true)->after('attempt_number');
            $table->unique(['assignment_id', 'user_id', 'attempt_number'], 'submissions_assignment_user_attempt_unique');
            $table->index(['assignment_id', 'user_id', 'is_latest', 'status'], 'submissions_assignment_user_latest_status_index');
        });

        DB::table('submissions')->update([
            'status' => DB::raw("CASE WHEN graded_at IS NULL THEN 'submitted' ELSE 'graded' END"),
            'attempt_number' => 1,
            'is_latest' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropUnique('submissions_assignment_user_attempt_unique');
            $table->dropIndex('submissions_assignment_user_latest_status_index');
            $table->dropConstrainedForeignId('grader_id');
            $table->dropColumn(['status', 'attempt_number', 'is_latest']);
            $table->unique(['assignment_id', 'user_id']);
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->dropIndex('assignments_course_active_index');
            $table->dropIndex('assignments_availability_index');
            $table->dropColumn([
                'available_from',
                'cutoff_date',
                'max_attempts',
                'allow_late_submission',
                'submission_type',
            ]);
        });
    }
};
