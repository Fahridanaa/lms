<?php

namespace Tests\Feature\Performance;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseCompletionCriterion;
use App\Models\CourseCompletionCriterionCompletion;
use App\Models\CourseEnrollment;
use App\Models\CourseGroup;
use App\Models\CourseGroupMember;
use App\Models\CourseSection;
use App\Models\Grade;
use App\Models\GradeItem;
use App\Models\LearningModule;
use App\Models\Material;
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

    public function test_quiz_submit_30_questions_query_count(): void
    {
        // Create 30 questions (Moodle-like quiz size)
        Question::factory()->count(30)->create(['quiz_id' => $this->quiz->id]);

        // Start attempt
        $startResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts");
        $attemptId = $startResponse->json('data.id');

        // Build answers for all 30 questions
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

        // Target: bounded query count — with bulk upsert, should not grow with question count.
        // Accept a few more queries than the 5-question test for row handling overhead.
        $this->assertLessThan(
            50,
            $queryCount,
            "Quiz submit (30 questions) used {$queryCount} queries, expected < 50"
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
     * Plan 02: Course structure with 30+ modules should use batch queries.
     * Verifies query count grows sublinearly with module count.
     */
    public function test_course_structure_large_course_query_count(): void
    {
        // Create a group for group rules
        $group = CourseGroup::factory()->create([
            'course_id' => $this->course->id,
            'active' => true,
        ]);
        CourseGroupMember::factory()->create([
            'course_group_id' => $group->id,
            'user_id' => $this->student->id,
        ]);

        // Create 2 sections with 15 modules each (30 total)
        $section1 = CourseSection::factory()->create(['course_id' => $this->course->id]);
        $section2 = CourseSection::factory()->create(['course_id' => $this->course->id]);

        $gradeItem = GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'max_score' => 100,
        ]);

        for ($i = 0; $i < 30; $i++) {
            $sectionId = $i < 15 ? $section1->id : $section2->id;

            // Create material (factory auto-creates the learning module)
            $material = Material::factory()->create([
                'course_id' => $this->course->id,
            ]);

            // Move the auto-created learning module to the right section
            $module = $material->learningModule;
            $module->update([
                'course_section_id' => $sectionId,
                'sort_order' => $i,
            ]);

            // Each module gets 3 rules: group, date, and min_grade
            ModuleAvailabilityRule::factory()->create([
                'learning_module_id' => $module->id,
                'rule_type' => 'group',
                'course_group_id' => $group->id,
            ]);
            ModuleAvailabilityRule::factory()->create([
                'learning_module_id' => $module->id,
                'rule_type' => 'date',
                'operator' => 'after',
                'value' => now()->subDay()->toIso8601String(),
            ]);
            ModuleAvailabilityRule::factory()->create([
                'learning_module_id' => $module->id,
                'rule_type' => 'min_grade',
                'grade_item_id' => $gradeItem->id,
                'operator' => '>=',
                'value' => '50',
            ]);
        }

        // Warm authorization cache with a first request
        $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        // Forget only the structure cache (auth cache stays warm)
        Cache::flush();

        // Warm auth cache again
        $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");
        Cache::forget("lms:course:{$this->course->id}:structure:{$this->student->id}");

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertStatus(200);

        // With 30 modules x 3 rules each, old approach would issue 90+ queries
        // for group rules alone. Batch approach should use < 25 queries.
        $this->assertLessThan(
            25,
            $queryCount,
            "Course structure with 30 modules used {$queryCount} queries, expected < 25"
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

    /**
     * Plan 02: Material list with 30+ materials should use bounded queries.
     * Availability rules include group, date, completion, and min-grade.
     */
    public function test_material_list_query_count(): void
    {
        // Create a group and add student
        $group = CourseGroup::factory()->create([
            'course_id' => $this->course->id,
            'active' => true,
        ]);
        CourseGroupMember::factory()->create([
            'course_group_id' => $group->id,
            'user_id' => $this->student->id,
        ]);

        $gradeItem = GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'max_score' => 100,
        ]);

        // Create 30 materials with mixed availability rules
        for ($i = 0; $i < 30; $i++) {
            $material = Material::factory()->create([
                'course_id' => $this->course->id,
            ]);

            $module = $material->learningModule;
            $module->update([
                'sort_order' => $i,
            ]);

            // Group rule on even-indexed modules
            if ($i % 2 === 0) {
                ModuleAvailabilityRule::factory()->create([
                    'learning_module_id' => $module->id,
                    'rule_type' => 'group',
                    'course_group_id' => $group->id,
                ]);
            }

            // Date rule on all modules
            ModuleAvailabilityRule::factory()->create([
                    'learning_module_id' => $module->id,
                    'rule_type' => 'date',
                    'operator' => 'after',
                    'value' => now()->subDay()->toIso8601String(),
                ]);

            // Completion rule on modules divisible by 3
            if ($i % 3 === 0 && $i > 0) {
                // Use a different module as prerequisite
                $prereqMaterial = Material::factory()->create([
                    'course_id' => $this->course->id,
                ]);
                $prereqModule = $prereqMaterial->learningModule;
                ModuleAvailabilityRule::factory()->create([
                    'learning_module_id' => $module->id,
                    'rule_type' => 'completion',
                    'required_module_id' => $prereqModule->id,
                    'operator' => '==',
                    'value' => 'complete',
                ]);
            }

            // Min-grade rule on modules divisible by 5
            if ($i % 5 === 0 && $i > 0) {
                ModuleAvailabilityRule::factory()->create([
                    'learning_module_id' => $module->id,
                    'rule_type' => 'min_grade',
                    'grade_item_id' => $gradeItem->id,
                    'operator' => '>=',
                    'value' => '50',
                ]);
            }
        }

        // Warm auth cache
        $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/materials");

        Cache::forget("lms:course:{$this->course->id}:materials:actor:{$this->student->id}");

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/materials");

        $response->assertStatus(200);

        // Target: < 30 queries (batch-loaded modules, readability, availability data)
        $this->assertLessThan(
            30,
            $queryCount,
            "Material list used {$queryCount} queries, expected < 30"
        );
    }

    /**
     * Plan 02: Assignment list with 30+ assignments should use bounded queries.
     * Availability rules include group and date.
     */
    public function test_assignment_list_query_count(): void
    {
        // Create a group and add student
        $group = CourseGroup::factory()->create([
            'course_id' => $this->course->id,
            'active' => true,
        ]);
        CourseGroupMember::factory()->create([
            'course_group_id' => $group->id,
            'user_id' => $this->student->id,
        ]);

        // Create 30 assignments with mixed availability rules
        for ($i = 0; $i < 30; $i++) {
            $assignment = Assignment::factory()->create([
                'course_id' => $this->course->id,
            ]);

            $module = $assignment->learningModule;
            $module->update([
                'sort_order' => $i,
            ]);

            // Group rule on even-indexed assignments
            if ($i % 2 === 0) {
                ModuleAvailabilityRule::factory()->create([
                    'learning_module_id' => $module->id,
                    'rule_type' => 'group',
                    'course_group_id' => $group->id,
                ]);
            }

            // Date rule on all assignments
            ModuleAvailabilityRule::factory()->create([
                    'learning_module_id' => $module->id,
                    'rule_type' => 'date',
                    'operator' => 'after',
                    'value' => now()->subDay()->toIso8601String(),
                ]);
        }

        // Warm auth cache
        $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/assignments");

        Cache::forget("lms:course:{$this->course->id}:assignments:actor:{$this->student->id}");

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/assignments");

        $response->assertStatus(200);

        // Target: < 25 queries (batch-loaded modules, readability data)
        $this->assertLessThan(
            25,
            $queryCount,
            "Assignment list used {$queryCount} queries, expected < 25"
        );
    }

    /**
     * Plan 04: Course structure with cold auth cache (no prewarming).
     * Verifies query count remains bounded even without warm authorization cache.
     */
    public function test_course_structure_cold_auth_query_count(): void
    {
        $section = CourseSection::factory()->create(['course_id' => $this->course->id]);

        for ($i = 0; $i < 5; $i++) {
            $module = LearningModule::factory()->create([
                'course_id' => $this->course->id,
                'course_section_id' => $section->id,
                'module_type' => 'material',
                'module_id' => 30000 + $i,
                'sort_order' => $i,
            ]);

            ModuleAvailabilityRule::factory()->create([
                'learning_module_id' => $module->id,
                'rule_type' => 'date',
                'operator' => 'after',
                'value' => now()->subDay()->toIso8601String(),
            ]);
        }

        // Flush all caches to simulate cold start
        Cache::flush();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/structure");

        $response->assertStatus(200);

        // Cold auth: more queries than warm, but still bounded
        $this->assertLessThan(
            40,
            $queryCount,
            "Course structure cold auth used {$queryCount} queries, expected < 40"
        );
    }

    /**
     * Plan 04: Instructor user grades — negative authorization case.
     * An instructor who teaches no overlapping course should get 403.
     */
    public function test_instructor_user_grades_negative_authorization(): void
    {
        // Create a different instructor who teaches NO overlapping courses with the student
        $otherInstructor = User::factory()->create(['role' => 'instructor']);
        $otherCourse = Course::factory()->create(['instructor_id' => $otherInstructor->id]);

        CourseEnrollment::factory()->create([
            'course_id' => $otherCourse->id,
            'user_id' => $otherInstructor->id,
            'role' => 'instructor',
            'status' => 'active',
        ]);

        // Do NOT enroll $this->student in $otherCourse — so no shared courses.
        // $this->student is only enrolled in $this->course (taught by $this->instructor).
        // $otherInstructor does not teach $this->course.
        $response = $this->withHeader('X-Benchmark-Actor-Id', $otherInstructor->id)
            ->getJson("/api/users/{$this->student->id}/grades");

        $response->assertStatus(403);
    }

    /**
     * Plan 04: Quiz result authorization — owner, instructor, wrong instructor,
     * wrong quiz id, and missing attempt coverage.
     */
    public function test_quiz_result_authorization(): void
    {
        $quiz = Quiz::factory()->create(['course_id' => $this->course->id]);
        \App\Models\Question::factory()->count(2)->create(['quiz_id' => $quiz->id, 'points' => 50]);

        // Student starts and submits attempt
        $startResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/quizzes/{$quiz->id}/attempts");
        $startResponse->assertStatus(201);
        $attemptId = $startResponse->json('data.id');

        $quiz->refresh();
        $answers = [];
        foreach ($quiz->questions as $question) {
            $answers[$question->id] = 'test';
        }

        $submitResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->putJson("/api/quizzes/{$quiz->id}/attempts/{$attemptId}", ['answers' => $answers]);
        $submitResponse->assertStatus(200);

        // Owner can view result
        $ownerResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/quizzes/{$quiz->id}/attempts/{$attemptId}/result");
        $ownerResponse->assertStatus(200);

        // Instructor for the course can view result
        $instructorResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/quizzes/{$quiz->id}/attempts/{$attemptId}/result");
        $instructorResponse->assertStatus(200);

        // Instructor for a different course cannot view result
        $otherCourse = Course::factory()->create(['instructor_id' => $this->instructor->id]);
        $otherQuiz = Quiz::factory()->create(['course_id' => $otherCourse->id]);
        $wrongInstructor = User::factory()->create(['role' => 'instructor']);
        Course::factory()->create(['instructor_id' => $wrongInstructor->id]);

        $wrongInstructorResponse = $this->withHeader('X-Benchmark-Actor-Id', $wrongInstructor->id)
            ->getJson("/api/quizzes/{$quiz->id}/attempts/{$attemptId}/result");
        $wrongInstructorResponse->assertStatus(403);

        // Wrong quiz id returns 404
        $wrongQuizResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/quizzes/{$otherQuiz->id}/attempts/{$attemptId}/result");
        $wrongQuizResponse->assertStatus(404);

        // Missing attempt returns 404
        $missingResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/quizzes/{$quiz->id}/attempts/99999/result");
        $missingResponse->assertStatus(404);
    }
}
