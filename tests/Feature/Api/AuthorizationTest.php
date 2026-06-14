<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseGroup;
use App\Models\Material;
use App\Models\ModuleAvailabilityRule;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class AuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    private User $student;

    private User $instructor;

    private User $otherStudent;

    private Course $course;

    private Course $otherCourse;

    private Quiz $quiz;

    private Assignment $assignment;

    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        /** @var User $instructor */
        $instructor = User::factory()->create(['role' => 'instructor']);
        $this->instructor = $instructor;

        /** @var User $student */
        $student = User::factory()->create(['role' => 'student']);
        $this->student = $student;

        /** @var User $otherStudent */
        $otherStudent = User::factory()->create(['role' => 'student']);
        $this->otherStudent = $otherStudent;

        /** @var Course $course */
        $course = Course::factory()->create([
            'instructor_id' => $this->instructor->id,
            'is_active' => true,
        ]);
        $this->course = $course;

        /** @var Course $otherCourse */
        $otherCourse = Course::factory()->create([
            'instructor_id' => $this->instructor->id,
            'is_active' => true,
        ]);
        $this->otherCourse = $otherCourse;

        // Enroll student in the main course
        CourseEnrollment::factory()->create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'role' => 'student',
            'status' => 'active',
        ]);

        // Create a quiz (factory auto-creates LearningModule via afterCreating)
        $this->quiz = Quiz::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);

        // Create an assignment (factory auto-creates LearningModule via afterCreating)
        $this->assignment = Assignment::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);

        // Create a material (factory auto-creates LearningModule via afterCreating)
        $this->material = Material::factory()->create([
            'course_id' => $this->course->id,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function student_can_read_own_enrolled_active_course(): void
    {
        $this->withoutExceptionHandling();

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/materials");

        $response->assertOk();
    }

    #[Test]
    public function student_cannot_read_unrelated_course(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->otherCourse->id}/materials");

        $response->assertStatus(403);
    }

    #[Test]
    public function student_can_submit_own_assignment(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/assignments/{$this->assignment->id}/submissions", [
                'file_path' => '/test/submission.pdf',
            ]);

        $response->assertStatus(201);
    }

    #[Test]
    public function student_cannot_submit_assignment_for_unrelated_course(): void
    {
        // Create an assignment in a course the student isn't enrolled in
        $unrelatedAssignment = Assignment::factory()->create([
            'course_id' => $this->otherCourse->id,
            'is_active' => true,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/assignments/{$unrelatedAssignment->id}/submissions", [
                'file_path' => '/test/submission.pdf',
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function instructor_can_read_own_course_gradebook(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/courses/{$this->course->id}/gradebook");

        $response->assertOk();
    }

    #[Test]
    public function student_cannot_read_gradebook(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/courses/{$this->course->id}/gradebook");

        $response->assertStatus(403);
    }

    #[Test]
    public function instructor_cannot_grade_submission_outside_own_course(): void
    {
        // Create a course with a DIFFERENT instructor
        $otherInstructor = User::factory()->create(['role' => 'instructor']);
        $otherCourse = Course::factory()->create([
            'instructor_id' => $otherInstructor->id,
            'is_active' => true,
        ]);

        $otherCourseAssignment = Assignment::factory()->create([
            'course_id' => $otherCourse->id,
            'is_active' => true,
        ]);

        $submission = \App\Models\Submission::factory()->create([
            'assignment_id' => $otherCourseAssignment->id,
            'user_id' => $this->student->id,
            'status' => 'submitted',
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/submissions/{$submission->id}/grade", [
                'score' => 85,
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function suspended_enrollment_cannot_access_student_flows(): void
    {
        // Suspend the student's enrollment
        CourseEnrollment::query()
            ->where('user_id', $this->student->id)
            ->where('course_id', $this->course->id)
            ->update(['status' => 'suspended']);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->postJson("/api/assignments/{$this->assignment->id}/submissions", [
                'file_path' => '/test/submission.pdf',
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function missing_actor_header_receives_401(): void
    {
        $response = $this->getJson("/api/courses/{$this->course->id}/materials");

        $response->assertStatus(401);
    }

    #[Test]
    public function invalid_actor_id_receives_401(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', 999999)
            ->getJson("/api/courses/{$this->course->id}/materials");

        $response->assertStatus(401);
    }

    #[Test]
    public function student_cannot_read_another_users_grades(): void
    {
        $otherStudent = User::factory()->create(['role' => 'student']);

        // Enroll the other student in the course
        CourseEnrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $otherStudent->id,
            'role' => 'student',
            'status' => 'active',
        ]);

        // Student tries to read the other student's grades
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/users/{$otherStudent->id}/grades");

        $response->assertStatus(403);
    }

    #[Test]
    public function student_cannot_read_another_users_performance(): void
    {
        $otherStudent = User::factory()->create(['role' => 'student']);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/users/{$otherStudent->id}/performance");

        $response->assertStatus(403);
    }

    #[Test]
    public function student_cannot_submit_another_users_quiz_attempt(): void
    {
        $otherStudent = User::factory()->create(['role' => 'student']);
        CourseEnrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $otherStudent->id,
            'role' => 'student',
            'status' => 'active',
        ]);

        // Create questions for the quiz
        \App\Models\Question::factory()->count(3)->create([
            'quiz_id' => $this->quiz->id,
        ]);

        // Create a quiz attempt owned by the other student
        $attempt = \App\Models\QuizAttempt::factory()->create([
            'quiz_id' => $this->quiz->id,
            'user_id' => $otherStudent->id,
            'started_at' => now(),
            'completed_at' => null,
        ]);

        // Student tries to submit the other student's attempt
        $answers = [];
        foreach ($this->quiz->fresh()->questions as $question) {
            $answers[$question->id] = (string) $question->correct_answer;
        }

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->putJson("/api/quizzes/{$this->quiz->id}/attempts/{$attempt->id}", [
                'answers' => $answers,
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function instructor_cannot_access_course_they_do_not_teach(): void
    {
        $unrelatedInstructor = User::factory()->create(['role' => 'instructor']);
        $unrelatedCourse = Course::factory()->create([
            'instructor_id' => $unrelatedInstructor->id,
            'is_active' => true,
        ]);

        // Our instructor tries to access the unrelated course's materials
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/courses/{$unrelatedCourse->id}/materials");

        $response->assertStatus(403);
    }

    #[Test]
    public function instructor_can_read_student_grades_in_taught_course(): void
    {
        // Create a grade for the student in the instructor's course

        $grade = \App\Models\Grade::factory()->create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'status' => 'final',
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/users/{$this->student->id}/grades");

        $response->assertOk();
        $gradeIds = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($grade->id, $gradeIds);
    }

    #[Test]
    public function instructor_only_sees_grades_from_taught_courses(): void
    {
        // Create another instructor with their own course
        $otherInstructor = User::factory()->create(['role' => 'instructor']);
        $otherCourse = Course::factory()->create([
            'instructor_id' => $otherInstructor->id,
            'is_active' => true,
        ]);

        // Enroll the student in the other instructor's course
        CourseEnrollment::factory()->create([
            'user_id' => $this->student->id,
            'course_id' => $otherCourse->id,
            'role' => 'student',
            'status' => 'active',
        ]);

        // Create a grade in the main instructor's course
        $mainGrade = \App\Models\Grade::factory()->create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'status' => 'final',
        ]);

        // Create a grade in the other instructor's course
        $otherGrade = \App\Models\Grade::factory()->create([
            'user_id' => $this->student->id,
            'course_id' => $otherCourse->id,
            'status' => 'final',
        ]);

        // Main instructor should only see their course's grade, not the other instructor's
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/users/{$this->student->id}/grades");

        $response->assertOk();
        $gradeIds = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($mainGrade->id, $gradeIds);
        $this->assertNotContains($otherGrade->id, $gradeIds);
    }

    #[Test]
    public function instructor_sees_grades_only_from_taught_courses_when_student_in_shared_course(): void
    {
        // Student is enrolled in course (taught by $this->instructor)
        // The instructor should NOT see grades from a second course where they
        // are NOT the instructor, even if the student is enrolled in both.

        $secondCourse = Course::factory()->create([
            'instructor_id' => User::factory()->create(['role' => 'instructor'])->id,
            'is_active' => true,
        ]);

        // Enroll student in second course (different instructor)
        CourseEnrollment::factory()->create([
            'user_id' => $this->student->id,
            'course_id' => $secondCourse->id,
            'role' => 'student',
            'status' => 'active',
        ]);

        $courseGrade = \App\Models\Grade::factory()->create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'status' => 'final',
        ]);

        $secondCourseGrade = \App\Models\Grade::factory()->create([
            'user_id' => $this->student->id,
            'course_id' => $secondCourse->id,
            'status' => 'final',
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/users/{$this->student->id}/grades");

        $response->assertOk();
        $gradeIds = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($courseGrade->id, $gradeIds);
        $this->assertNotContains($secondCourseGrade->id, $gradeIds,
            'Instructor must not see grades from courses they do not teach'
        );
    }

    /* ──────────────────────────────────────────────
     * Plan 001: Access Control Hardening — regression tests
     * ────────────────────────────────────────────── */

    #[Test]
    public function student_can_read_assignment_detail_in_own_course(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/assignments/{$this->assignment->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $this->assignment->id);
    }

    #[Test]
    public function student_cannot_read_assignment_detail_from_unrelated_course(): void
    {
        $unrelatedAssignment = Assignment::factory()->create([
            'course_id' => $this->otherCourse->id,
            'is_active' => true,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/assignments/{$unrelatedAssignment->id}");

        // Should fail with 403 (access denied) or 404 (not found)
        $response->assertStatus(403);
    }

    #[Test]
    public function student_can_read_quiz_detail_in_own_course(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/quizzes/{$this->quiz->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $this->quiz->id);
    }

    #[Test]
    public function student_cannot_read_quiz_detail_from_unrelated_course(): void
    {
        $unrelatedQuiz = Quiz::factory()->create([
            'course_id' => $this->otherCourse->id,
            'is_active' => true,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/quizzes/{$unrelatedQuiz->id}");

        $response->assertStatus(403);
    }

    #[Test]
    public function student_cannot_read_quiz_questions_from_unrelated_course(): void
    {
        $unrelatedQuiz = Quiz::factory()->create([
            'course_id' => $this->otherCourse->id,
            'is_active' => true,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/quizzes/{$unrelatedQuiz->id}/questions");

        $response->assertStatus(403);
    }

    #[Test]
    public function group_restricted_assignment_detail_is_hidden_from_non_member(): void
    {
        $group = CourseGroup::factory()->create([
            'course_id' => $this->course->id,
            'name' => 'Alpha Team',
            'active' => true,
        ]);

        // Add group restriction to the assignment's learning module
        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $this->assignment->learningModule->id,
            'rule_type' => 'group',
            'course_group_id' => $group->id,
        ]);

        // Student is NOT in the group
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/assignments/{$this->assignment->id}");

        $response->assertStatus(404);
    }

    #[Test]
    public function group_restricted_quiz_detail_is_hidden_from_non_member(): void
    {
        $group = CourseGroup::factory()->create([
            'course_id' => $this->course->id,
            'name' => 'Beta Team',
            'active' => true,
        ]);

        ModuleAvailabilityRule::factory()->create([
            'learning_module_id' => $this->quiz->learningModule->id,
            'rule_type' => 'group',
            'course_group_id' => $group->id,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/quizzes/{$this->quiz->id}");

        $response->assertStatus(404);
    }

    #[Test]
    public function instructor_can_read_quiz_detail_but_cannot_start_attempt(): void
    {
        // Instructor can read quiz detail
        $detailResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/quizzes/{$this->quiz->id}");

        $detailResponse->assertOk();

        // Instructor cannot start a quiz attempt (only students can attempt)
        $attemptResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts");

        $attemptResponse->assertStatus(403);
    }

    #[Test]
    public function suspended_enrollment_cannot_read_assignment_detail(): void
    {
        CourseEnrollment::query()
            ->where('user_id', $this->student->id)
            ->where('course_id', $this->course->id)
            ->update(['status' => 'suspended']);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/assignments/{$this->assignment->id}");

        $response->assertStatus(403);
    }

    #[Test]
    public function suspended_enrollment_cannot_read_quiz_detail(): void
    {
        CourseEnrollment::query()
            ->where('user_id', $this->student->id)
            ->where('course_id', $this->course->id)
            ->update(['status' => 'suspended']);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson("/api/quizzes/{$this->quiz->id}");

        $response->assertStatus(403);
    }
}
