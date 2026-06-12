<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->enum('role', ['student', 'instructor'])->default('student')->after('course_id');
            $table->enum('status', ['active', 'suspended', 'completed'])->default('active')->after('role');
            $table->timestamp('starts_at')->nullable()->after('enrolled_at');
            $table->timestamp('ends_at')->nullable()->after('starts_at');
            $table->index(['course_id', 'status'], 'course_enrollments_course_status_index');
            $table->index(['user_id', 'status'], 'course_enrollments_user_status_index');
        });

        DB::table('course_enrollments')->update([
            'starts_at' => DB::raw('enrolled_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->dropIndex('course_enrollments_course_status_index');
            $table->dropIndex('course_enrollments_user_status_index');
            $table->dropColumn(['role', 'status', 'starts_at', 'ends_at']);
        });
    }
};
