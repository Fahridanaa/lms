<?php

use App\Models\Course;
use App\Models\CourseEnrollment;
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
        Schema::create('course_enrolment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->onDelete('cascade');
            $table->enum('method', ['manual', 'self', 'cohort']);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->enum('default_role', ['student', 'instructor'])->default('student');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'status'], 'enrolment_methods_course_status_index');
        });

        // Backfill: create a default 'manual' method for every existing course
        // so that existing enrollments remain active through the method check.
        $courses = Course::pluck('id');
        foreach ($courses as $courseId) {
            DB::table('course_enrolment_methods')->insert([
                'course_id' => $courseId,
                'method' => 'manual',
                'status' => 'active',
                'default_role' => 'student',
                'starts_at' => now()->subYears(1),
                'ends_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_enrolment_methods');
    }
};
