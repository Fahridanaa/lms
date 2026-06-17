<?php

namespace Tests\Feature\Performance;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseCompletionCriterion;
use App\Models\CourseCompletionCriterionCompletion;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Grade;
use App\Models\GradeItem;
use App\Models\LearningModule;
use App\Models\ModuleAvailabilityRule;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueryCountTest extends TestCase
{
    use DatabaseTransactions;

    protected User $student;

    protected User $instructor;

    protected Course $course;

    protected Quiz $quiz;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->student = User::factory()->create(['role' => 'student']);
        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->course = Course::factory()->create(['instructor_id' => $this->instructor->id]);
        $this->quiz = Quiz::factory()->create(['course_id' => $this->course->id]);

        CourseEnrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
            'role' => 'student',
            'status' => 'active',
        ]);

        CourseEnrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->instructor->id,
            'role' => 'instructor',
            'status' => 'active',
        ]);
    }

    public function test_quiz_start_query_count(): void
    {
        // Create 10 questions (which auto-create slots)
        Question::factory()->count(10)->create(['quiz_id' => $this->quiz->id]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts");

        $response->assertStatus(201);

        // Target: < 18 queries (was 28+ before bulk INSERT optimization)
        $this->assertLessThan(
            18,
            $queryCount,
            "Quiz start used {$queryCount} queries, expected < 18"
        );
    }

    public function test_quiz_submit_query_count(): void
    {
        // Create 5 questions
        Question::factory()->count(5)->create(['quiz_id' => $this->quiz->id]);

        // Start attempt
        $startResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts");
        $attemptId = $startResponse->json('data.id');

        // Build answers
        $this->quiz->refresh();
        $questions = $this->quiz->questions;
        $answers = [];
        foreach ($questions as $question) {
            $answers[$question->id] = 'test answer';
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->putJson("/api/quizzes/{$this->quiz->id}/attempts/{$attemptId}", [
                'answers' => $answers,
            ]);

        $response->assertStatus(200);

        // Target: < 42 queries (was 50-70 before bulk INSERT optimization)
        $this->assertLessThan(
            42,
            $queryCount,
            "Quiz submit used {$queryCount} queries, expected < 42"
        );
    }

    public function test_course_completion_query_count(): void
    {
        // Create 10 module-type criteria with unique module_id values
        for ($i = 0; $i < 10; $i++) {
            $module = LearningModule::factory()->create([
                'course_id' => $this->course->id,
                'module_type' => 'material',
                'module_id' => 10000 + $i,
            ]);

            $criterion = CourseCompletionCriterion::query()->create([
                'course_id' => $this->course->id,
                'criteriatype' => 'module',
                'module_instance_id' => $module->id,
            ]);

            // Mark some as complete (3 out of 10)
            if ($i < 3) {
                CourseCompletionCriterionCompletion::query()->create([
                    'course_completion_criterion_id' => $criterion->id,
                    'user_id' => $this->student->id,
                    'completed' => true,
                ]);
            }
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/completion");

        $response->assertStatus(200);

        // Target: < 15 queries (was 49 before eager-load + batch optimization)
        $this->assertLessThan(
            15,
            $queryCount,
            "Course completion used {$queryCount} queries, expected < 15"
        );
    }

    public function test_instructor_user_grades_query_count(): void
    {
        // Create additional courses, instructor teaches 5 of them
        $allCourses = [$this->course];
        for ($i = 0; $i < 9; $i++) {
            $course = Course::factory()->create(['instructor_id' => $this->instructor->id]);
            $allCourses[] = $course;

            // Create enrollments for instructor
            CourseEnrollment::factory()->create([
                'course_id' => $course->id,
                'user_id' => $this->instructor->id,
                'role' => 'instructor',
                'status' => 'active',
            ]);

            // enroll the student so they have grades
            CourseEnrollment::factory()->create([
                'course_id' => $course->id,
                'user_id' => $this->student->id,
                'role' => 'student',
                'status' => 'active',
            ]);
        }

        // Create grades for the student in each course
        foreach ($allCourses as $course) {
            Grade::factory()->create([
                'user_id' => $this->student->id,
                'course_id' => $course->id,
                'status' => 'final',
            ]);
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/users/{$this->student->id}/grades");

        $response->assertStatus(200);

        // Target: < 10 queries (was 100-400+ before JOIN optimization)
        $this->assertLessThan(
            10,
            $queryCount,
            "Instructor user grades used {$queryCount} queries, expected < 10"
        );
    }

    /**
     * Plan 02: Course structure with availability rules should use batch queries.
     * Warms authorization cache first, then measures second request.
     */
    public function test_course_structure_availability_query_count(): void
    {
        // Create a section with 5 modules, each with 3 availability rules
        $section = CourseSection::factory()->create(['course_id' => $this->course->id]);

        for ($i = 0; $i < 5; $i++) {
            $module = LearningModule::factory()->create([
                'course_id' => $this->course->id,
                'course_section_id' => $section->id,
                'module_type' => 'material',
                'module_id' => 20000 + $i,
                'sort_order' => $i,
            ]);

            // Add 3 availability rules per module
            ModuleAvailabilityRule::factory()->create([
                'learning_module_id' => $module->id,
                'rule_type' => 'date',
                'operator' => 'after',
                'value' => now()->subDay()->toIso8601String(),
            ]);
            ModuleAvailabilityRule::factory()->create([
                'learning_module_id' => $module->id,
                'rule_type' => 'date',
                'operator' => 'before',
                'value' => now()->addMonth()->toIso8601String(),
            ]);
            ModuleAvailabilityRule::factory()->create([
                'learning_module_id' => $module->id,
                'rule_type' => 'group',
                'course_group_id' => null,
            ]);
        }

        // Warm authorization cache with a first request
        $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        // Forget only the structure cache (auth cache stays warm)
        Cache::forget("lms:course:{$this->course->id}:structure:{$this->student->id}");

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertStatus(200);

        // Target: < 25 queries (auth cache warm, batch-loaded structure data)
        $this->assertLessThan(
            25,
            $queryCount,
            "Course structure used {$queryCount} queries, expected < 25"
        );
    }

    /**
     * Plan 03: Quiz show query count with warm auth cache.
     */
    public function test_quiz_show_query_count(): void
    {
        $quiz = Quiz::factory()->create(['course_id' => $this->course->id]);

        // Warm auth cache
        $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/quizzes/{$quiz->id}");

        Cache::forget("lms:quiz:{$quiz->id}:with-questions");

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/quizzes/{$quiz->id}");

        $response->assertStatus(200);

        // Target: < 20 queries (auth warm, but course+module data + cache lookups)
        $this->assertLessThan(
            20,
            $queryCount,
            "Quiz show used {$queryCount} queries, expected < 20"
        );
    }
}
