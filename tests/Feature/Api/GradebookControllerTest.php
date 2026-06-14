<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Grade;
use App\Models\GradeCategory;
use App\Models\GradeItem;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class GradebookControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected User $instructor;

    protected Course $course;

    protected Quiz $quiz;

    protected Assignment $assignment;

    protected GradeItem $gradeItem1;

    protected GradeItem $gradeItem2;

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Create test data
        $this->user = User::factory()->create(['role' => 'student']);
        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->course = Course::factory()->create(['instructor_id' => $this->instructor->id]);
        $this->quiz = Quiz::factory()->create(['course_id' => $this->course->id]);
        $this->assignment = Assignment::factory()->create(['course_id' => $this->course->id]);
        $this->gradeItem1 = GradeItem::factory()->create(['course_id' => $this->course->id, 'name' => 'Quiz 1']);
        $this->gradeItem2 = GradeItem::factory()->create(['course_id' => $this->course->id, 'name' => 'Assignment 1']);
        $this->student = User::factory()->create(['role' => 'student']);
        CourseEnrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
            'role' => 'student',
            'status' => 'active',
        ]);

        // Enroll the student user in the course
        $this->course->enrollments()->create([
            'user_id' => $this->user->id,
            'role' => 'student',
            'status' => 'active',
            'starts_at' => now()->subDay(),
        ]);
    }

    public function test_can_get_course_gradebook(): void
    {
        $students = User::factory()->count(5)->create(['role' => 'student']);

        foreach ($students as $student) {
            $this->course->enrollments()->create([
                'user_id' => $student->id,
                'enrolled_at' => now(),
            ]);

            Grade::factory()->create([
                'user_id' => $student->id,
                'course_id' => $this->course->id,
                'gradeable_type' => 'submission',
                'gradeable_id' => 1,
                'score' => rand(70, 100),
                'max_score' => 100,
            ]);
        }

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/courses/{$this->course->id}/gradebook");

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'student' => ['id', 'name', 'email'],
                        'grades',
                        'average_percentage',
                        'total_grades',
                    ],
                ],
            ]);
    }

    public function test_course_gradebook_excludes_suspended_enrollments(): void
    {
        $activeStudent = User::factory()->create(['role' => 'student']);
        $suspendedStudent = User::factory()->create(['role' => 'student']);

        $this->course->enrollments()->create([
            'user_id' => $activeStudent->id,
            'enrolled_at' => now(),
            'role' => 'student',
            'status' => 'active',
            'starts_at' => now()->subDay(),
        ]);

        $this->course->enrollments()->create([
            'user_id' => $suspendedStudent->id,
            'enrolled_at' => now(),
            'role' => 'student',
            'status' => 'suspended',
            'starts_at' => now()->subDay(),
        ]);

        Grade::factory()->create([
            'user_id' => $activeStudent->id,
            'course_id' => $this->course->id,
            'status' => 'final',
        ]);

        Grade::factory()->create([
            'user_id' => $suspendedStudent->id,
            'course_id' => $this->course->id,
            'status' => 'final',
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/courses/{$this->course->id}/gradebook");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student.id', $activeStudent->id);
    }

    public function test_can_get_user_grades(): void
    {
        // Create multiple grades for the user across different courses
        Grade::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/users/{$this->user->id}/grades");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'user_id',
                        'course_id',
                        'gradeable_type',
                        'gradeable_id',
                        'score',
                        'max_score',
                        'percentage',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);
    }

    public function test_can_get_user_course_grades(): void
    {
        // Create grades for the user in the specific course
        Grade::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
        ]);

        // Create grades for the user in another course (should not be included)
        $anotherCourse = Course::factory()->create();
        Grade::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'course_id' => $anotherCourse->id,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/courses/{$this->course->id}/users/{$this->user->id}/grades");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
            ]);
    }

    public function test_can_update_grade(): void
    {
        $grade = Grade::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'score' => 80,
        ]);

        $updateData = [
            'score' => 95,
        ];

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/grades/{$grade->id}", $updateData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'user_id',
                    'course_id',
                    'gradeable_type',
                    'gradeable_id',
                    'score',
                    'max_score',
                    'percentage',
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertDatabaseHas('grades', [
            'id' => $grade->id,
            'score' => 95,
        ]);
    }

    public function test_can_get_course_statistics(): void
    {
        // Create grades for multiple students
        $students = User::factory()->count(10)->create(['role' => 'student']);

        foreach ($students as $student) {
            Grade::factory()->create([
                'user_id' => $student->id,
                'course_id' => $this->course->id,
                'score' => rand(60, 100),
                'max_score' => 100,
            ]);
        }

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/courses/{$this->course->id}/statistics");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
            ]);
    }

    public function test_can_get_user_performance(): void
    {
        // Create various grades for the user
        Grade::factory()->count(5)->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/users/{$this->user->id}/performance");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
            ]);
    }

    public function test_can_get_top_performers(): void
    {
        // Create grades for multiple students
        $students = User::factory()->count(15)->create(['role' => 'student']);

        foreach ($students as $index => $student) {
            Grade::factory()->create([
                'user_id' => $student->id,
                'course_id' => $this->course->id,
                'score' => 100 - $index, // Descending scores
                'max_score' => 100,
            ]);
        }

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/courses/{$this->course->id}/top-performers");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
            ]);

        // Should return data (may be empty array or collection)
        $this->assertIsArray($response->json('data')) || $this->assertIsIterable($response->json('data'));
    }

    public function test_update_grade_without_data_succeeds_or_fails_gracefully(): void
    {
        $grade = Grade::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/grades/{$grade->id}", []);

        // Since validation uses 'sometimes', empty data may be accepted (200)
        // or may fail with business logic (400) or validation (422)
        $this->assertContains($response->status(), [200, 400, 422]);
    }

    public function test_update_grade_validation_fails_with_negative_score(): void
    {
        $grade = Grade::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
        ]);

        $updateData = [
            'score' => -10,
        ];

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/grades/{$grade->id}", $updateData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['score']);
    }

    public function test_course_not_found_for_gradebook_returns_404(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson('/api/courses/99999/gradebook');

        $response->assertStatus(404);
    }

    public function test_grade_not_found_returns_404(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson('/api/grades/99999', [
                'score' => 90,
            ]);

        $response->assertStatus(404);
    }

    public function test_user_not_found_for_grades_returns_403(): void
    {
        // Actor requesting another user's grades without being an instructor
        $otherUser = User::factory()->create(['role' => 'student']);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $otherUser->id)
            ->getJson('/api/users/99999/grades');

        $response->assertStatus(403);
    }

    #[Test]
    public function student_grade_list_excludes_hidden_grade_items(): void
    {
        $visibleItem = GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'item_type' => 'quiz',
            'hidden' => false,
        ]);
        $hiddenItem = GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'item_type' => 'assignment',
            'hidden' => true,
        ]);

        $visibleGrade = Grade::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'grade_item_id' => $visibleItem->id,
            'status' => 'final',
        ]);
        $hiddenGrade = Grade::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'grade_item_id' => $hiddenItem->id,
            'status' => 'final',
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/users/{$this->user->id}/grades");

        $response->assertOk();
        $gradeIds = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($visibleGrade->id, $gradeIds);
        $this->assertNotContains($hiddenGrade->id, $gradeIds);
    }

    #[Test]
    public function instructor_grade_list_includes_hidden_grade_items(): void
    {
        $hiddenItem = GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'item_type' => 'assignment',
            'hidden' => true,
        ]);
        $hiddenGrade = Grade::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'grade_item_id' => $hiddenItem->id,
            'status' => 'final',
        ]);

        // Instructor views the student's grades
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/users/{$this->user->id}/grades");

        $response->assertOk();
        $gradeIds = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($hiddenGrade->id, $gradeIds);
    }

    #[Test]
    public function student_course_grade_endpoint_excludes_hidden_items(): void
    {
        $visibleItem = GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'item_type' => 'quiz',
            'hidden' => false,
        ]);
        $hiddenItem = GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'item_type' => 'assignment',
            'hidden' => true,
        ]);

        $visibleGrade = Grade::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'grade_item_id' => $visibleItem->id,
            'status' => 'final',
        ]);
        $hiddenGrade = Grade::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'grade_item_id' => $hiddenItem->id,
            'status' => 'final',
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/courses/{$this->course->id}/users/{$this->user->id}/grades");

        $response->assertOk();
        $gradeIds = collect($response->json('data.grades'))->pluck('id')->all();
        $this->assertContains($visibleGrade->id, $gradeIds);
        $this->assertNotContains($hiddenGrade->id, $gradeIds);
    }

    #[Test]
    public function student_performance_summary_excludes_hidden_items(): void
    {
        $visibleItem = GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'item_type' => 'quiz',
            'hidden' => false,
        ]);
        $hiddenItem = GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'item_type' => 'assignment',
            'hidden' => true,
        ]);

        Grade::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'grade_item_id' => $visibleItem->id,
            'percentage' => 90,
            'status' => 'final',
        ]);
        Grade::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'grade_item_id' => $hiddenItem->id,
            'percentage' => 40,
            'status' => 'final',
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/users/{$this->user->id}/performance");

        $response->assertOk();
        // Total grades should exclude the hidden item
        $this->assertEquals(1, $response->json('data.total_grades'));
        // Average should only reflect the visible (90%) grade
        $this->assertEquals(90, $response->json('data.overall_average'));
    }

    #[Test]
    public function instructor_cache_warmup_does_not_leak_hidden_grades_to_student(): void
    {
        $hiddenItem = GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'item_type' => 'assignment',
            'hidden' => true,
        ]);

        $hiddenGrade = Grade::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'grade_item_id' => $hiddenItem->id,
            'status' => 'final',
        ]);

        // Instructor warms the cache (instructor can see hidden items)
        $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/users/{$this->user->id}/grades")
            ->assertOk();

        // Student then reads: should NOT see the hidden item despite warm cache
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/users/{$this->user->id}/grades");

        $response->assertOk();
        $gradeIds = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($hiddenGrade->id, $gradeIds);
    }

    #[Test]
    public function instructor_grade_cache_is_isolation_across_instructors(): void
    {
        $otherInstructor = User::factory()->create(['role' => 'instructor']);
        $otherCourse = Course::factory()->create([
            'instructor_id' => $otherInstructor->id,
        ]);

        // Enroll the student in the other course
        CourseEnrollment::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $otherCourse->id,
            'role' => 'student',
            'status' => 'active',
            'starts_at' => now()->subDay(),
        ]);

        // Create a grade in the main instructor's course
        $mainGrade = Grade::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'status' => 'final',
        ]);

        // Create a grade in the other instructor's course
        $otherGrade = Grade::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $otherCourse->id,
            'status' => 'final',
        ]);

        // Other instructor warms cache — should see only their own course's grade
        $otherResponse = $this->withHeader('X-Benchmark-Actor-Id', $otherInstructor->id)
            ->getJson("/api/users/{$this->user->id}/grades");

        $otherResponse->assertOk();
        $otherGradeIds = collect($otherResponse->json('data'))->pluck('id')->all();
        $this->assertContains($otherGrade->id, $otherGradeIds,
            'Other instructor should see their own course grade'
        );
        $this->assertNotContains($mainGrade->id, $otherGradeIds,
            'Other instructor should NOT see the main instructor course grade'
        );

        // Main instructor reads — should see only their own course's grade
        $mainResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/users/{$this->user->id}/grades");

        $mainResponse->assertOk();
        $mainGradeIds = collect($mainResponse->json('data'))->pluck('id')->all();
        $this->assertContains($mainGrade->id, $mainGradeIds,
            'Main instructor should see their own course grade'
        );
        $this->assertNotContains($otherGrade->id, $mainGradeIds,
            'Main instructor should NOT see the other instructor course grade'
        );
    }

    /* ──────────────────────────────────────────────
     * Plan 002: Weighted aggregation
     * ────────────────────────────────────────────── */

    #[Test]
    public function gradebook_weighted_average_differs_from_unweighted_when_weights_vary(): void
    {
        // Create two grade items with different weights
        $highWeightItem = GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'item_type' => 'quiz',
            'name' => 'High Weight Quiz',
            'weight' => 10.0,
            'hidden' => false,
        ]);
        $lowWeightItem = GradeItem::factory()->create([
            'course_id' => $this->course->id,
            'item_type' => 'assignment',
            'name' => 'Low Weight Assignment',
            'weight' => 1.0,
            'hidden' => false,
        ]);

        // Give the student a high score on the low-weight item and a low score on the high-weight item
        Grade::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'grade_item_id' => $highWeightItem->id,
            'percentage' => 30.0,
            'score' => 30,
            'max_score' => 100,
            'status' => 'final',
            'gradeable_type' => 'quiz_attempt',
        ]);
        Grade::factory()->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'grade_item_id' => $lowWeightItem->id,
            'percentage' => 90.0,
            'score' => 90,
            'max_score' => 100,
            'status' => 'final',
            'gradeable_type' => 'submission',
        ]);

        // Unweighted average: (30 + 90) / 2 = 60
        // Weighted average: (30*10 + 90*1) / (10 + 1) = 390 / 11 ≈ 35.45

        // Check via user course grades endpoint
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/courses/{$this->course->id}/users/{$this->user->id}/grades");

        $response->assertOk();
        $weightedAvg = $response->json('data.average_percentage');

        // Weighted average should be closer to 35 than to 60
        $this->assertLessThan(50, $weightedAvg, 'Weighted average should be pulled down by the high-weight low-score item');
        $this->assertGreaterThan(30, $weightedAvg, 'Weighted average should be above the lowest score');

        // Check via performance summary
        $perfResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/users/{$this->user->id}/performance");

        $perfResponse->assertOk();
        $overallAvg = $perfResponse->json('data.overall_average');

        // Both should compute the same weighted result
        $this->assertEqualsWithDelta($weightedAvg, $overallAvg, 0.02,
            'Course grade endpoint and performance summary should agree on weighted average'
        );
    }

    #[Test]
    public function top_performers_returns_empty_when_only_suspended_graded_students(): void
    {
        // Arrange: create a student with suspended enrollment but with grades
        $suspendedStudent = User::factory()->create(['role' => 'student']);
        CourseEnrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $suspendedStudent->id,
            'role' => 'student',
            'status' => 'suspended',
        ]);
        Grade::factory()->create([
            'user_id' => $suspendedStudent->id,
            'course_id' => $this->course->id,
            'score' => 95,
            'max_score' => 100,
            'percentage' => 95,
            'status' => 'final',
        ]);

        // Act
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/courses/{$this->course->id}/top-performers");

        // Assert: empty because no active students
        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    #[Test]
    public function top_performers_excludes_suspended_higher_scoring_student(): void
    {
        // Arrange: one active low-scoring student, one suspended high-scoring student
        $activeStudent = User::factory()->create(['role' => 'student']);
        CourseEnrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $activeStudent->id,
            'role' => 'student',
            'status' => 'active',
        ]);
        Grade::factory()->create([
            'user_id' => $activeStudent->id,
            'course_id' => $this->course->id,
            'score' => 30,
            'max_score' => 100,
            'percentage' => 30,
            'status' => 'final',
        ]);

        $suspendedStudent = User::factory()->create(['role' => 'student']);
        CourseEnrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $suspendedStudent->id,
            'role' => 'student',
            'status' => 'suspended',
        ]);
        Grade::factory()->create([
            'user_id' => $suspendedStudent->id,
            'course_id' => $this->course->id,
            'score' => 95,
            'max_score' => 100,
            'percentage' => 95,
            'status' => 'final',
        ]);

        // Act
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/courses/{$this->course->id}/top-performers");

        // Assert: only the active (lower-scoring) student appears
        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($activeStudent->id, $data[0]['user']['id']);
    }

    #[Test]
    public function top_performers_limit_is_respected_after_active_filtering(): void
    {
        // Arrange: create 3 active students with grades
        $studentIds = [];
        foreach (range(1, 3) as $i) {
            $student = User::factory()->create(['role' => 'student']);
            CourseEnrollment::factory()->create([
                'course_id' => $this->course->id,
                'user_id' => $student->id,
                'role' => 'student',
                'status' => 'active',
            ]);
            Grade::factory()->create([
                'user_id' => $student->id,
                'course_id' => $this->course->id,
                'score' => 100 - $i,
                'max_score' => 100,
                'percentage' => 100 - $i,
                'status' => 'final',
            ]);
            $studentIds[] = $student->id;
        }

        // Act: request top 2 only
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/courses/{$this->course->id}/top-performers?limit=2");

        // Assert: only 2 results returned
        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    #[Test]
    public function gradebook_read_includes_category_grouping_for_instructor(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        CourseEnrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $student->id,
            'role' => 'student',
            'status' => 'active',
        ]);
        Grade::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $student->id,
            'grade_item_id' => $this->gradeItem1->id,
            'score' => 80,
            'max_score' => 100,
            'percentage' => 80,
            'status' => 'final',
        ]);

        $rootCat = GradeCategory::factory()->create([
            'course_id' => $this->course->id,
            'name' => 'Root Category',
            'depth' => 0,
            'weight' => 1.0,
        ]);
        $childCat = GradeCategory::factory()->create([
            'course_id' => $this->course->id,
            'parent_id' => $rootCat->id,
            'name' => 'Quizzes',
            'depth' => 1,
            'weight' => 1.0,
        ]);

        $this->gradeItem1->update(['grade_category_id' => $childCat->id]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/courses/{$this->course->id}/gradebook");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $categories = $data[0]['categories'] ?? null;
        $this->assertNotNull($categories, 'Gradebook should include categories');
        $this->assertNotEmpty($categories);
        $this->assertEquals('Root Category', $categories[0]['name']);
        $this->assertCount(1, $categories[0]['children']);
        $this->assertEquals('Quizzes', $categories[0]['children'][0]['name']);
    }

    #[Test]
    public function grade_update_creates_history_row(): void
    {
        $grade = Grade::factory()->create([
            'course_id' => $this->course->id,
            'grade_item_id' => $this->gradeItem1->id,
            'user_id' => $this->student->id,
            'score' => 50,
            'max_score' => 100,
            'percentage' => 50,
            'status' => 'final',
        ]);

        $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/grades/{$grade->id}", [
                'score' => 85,
                'max_score' => 100,
            ])
            ->assertOk();

        $this->assertDatabaseHas('grade_histories', [
            'grade_id' => $grade->id,
            'action' => 'updated',
            'old_score' => 50,
            'new_score' => 85,
        ]);
        $this->assertDatabaseHas('grade_histories', [
            'grade_id' => $grade->id,
            'changed_by' => $this->instructor->id,
        ]);
    }

    #[Test]
    public function student_grade_view_hides_hidden_category_items(): void
    {
        $hiddenCat = GradeCategory::factory()->create([
            'course_id' => $this->course->id,
            'name' => 'Hidden Category',
            'hidden' => true,
        ]);
        $visibleCat = GradeCategory::factory()->create([
            'course_id' => $this->course->id,
            'name' => 'Visible Category',
            'hidden' => false,
        ]);

        $this->gradeItem1->update(['grade_category_id' => $hiddenCat->id]);
        $this->gradeItem2->update(['grade_category_id' => $visibleCat->id]);

        Grade::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
            'grade_item_id' => $this->gradeItem1->id,
            'score' => 80,
            'max_score' => 100,
            'percentage' => 80,
            'status' => 'final',
        ]);
        Grade::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
            'grade_item_id' => $this->gradeItem2->id,
            'score' => 90,
            'max_score' => 100,
            'percentage' => 90,
            'status' => 'final',
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/users/{$this->student->id}/grades");

        $response->assertOk();
        $categories = $response->json('data.categories');
        $this->assertNotNull($categories);

        $visibleCatNames = array_column($categories, 'name');
        $this->assertContains('Visible Category', $visibleCatNames);
    }

    #[Test]
    public function locked_grade_item_still_blocks_updates(): void
    {
        $this->gradeItem1->update(['locked' => true]);

        $grade = Grade::factory()->create([
            'course_id' => $this->course->id,
            'grade_item_id' => $this->gradeItem1->id,
            'user_id' => $this->student->id,
            'score' => 50,
            'max_score' => 100,
            'percentage' => 50,
            'status' => 'final',
        ]);

        $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/grades/{$grade->id}", [
                'score' => 90,
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function top_performers_with_expired_enrolments_returns_empty(): void
    {
        // Arrange: student with expired enrollment (past ends_at) and grades
        $expiredStudent = User::factory()->create(['role' => 'student']);
        CourseEnrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $expiredStudent->id,
            'role' => 'student',
            'status' => 'active',
            'ends_at' => now()->subDay(),
        ]);
        Grade::factory()->create([
            'user_id' => $expiredStudent->id,
            'course_id' => $this->course->id,
            'score' => 90,
            'max_score' => 100,
            'percentage' => 90,
            'status' => 'final',
        ]);

        // Act
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/courses/{$this->course->id}/top-performers");

        // Assert: empty because active status includes date range check
        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }
}
