<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\AssignmentAllocatedMarker;
use App\Models\AssignmentMark;
use App\Models\AssignmentOverride;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\LearningModule;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class AssignmentControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected User $instructor;

    protected Course $course;

    protected Assignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Create test data
        $this->user = User::factory()->create(['role' => 'student']);
        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->course = Course::factory()->create(['instructor_id' => $this->instructor->id]);
        $this->assignment = Assignment::factory()->create(['course_id' => $this->course->id]);
        CourseEnrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_can_list_course_assignments(): void
    {
        // Create additional assignments for the course
        Assignment::factory()->count(3)->create(['course_id' => $this->course->id]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/courses/{$this->course->id}/assignments");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'course_id',
                        'title',
                        'description',
                        'due_date',
                        'max_score',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);
    }

    public function test_can_show_assignment_detail(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/assignments/{$this->assignment->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'course_id',
                    'title',
                    'description',
                    'due_date',
                    'max_score',
                    'created_at',
                    'updated_at',
                ],
            ]);
    }

    public function test_can_submit_assignment(): void
    {
        $submissionData = [
            'file_path' => '/storage/submissions/test-submission.pdf',
        ];

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/assignments/{$this->assignment->id}/submissions", $submissionData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'assignment_id',
                    'user_id',
                    'file_path',
                    'submitted_at',
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertDatabaseHas('submissions', [
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->user->id,
            'file_path' => '/storage/submissions/test-submission.pdf',
        ]);
    }

    public function test_can_list_assignment_submissions(): void
    {
        // Create multiple submissions
        Submission::factory()->count(5)->create([
            'assignment_id' => $this->assignment->id,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/assignments/{$this->assignment->id}/submissions");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'assignment_id',
                        'user_id',
                        'file_path',
                        'submitted_at',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);
    }

    public function test_can_list_pending_submissions(): void
    {
        // Create graded submissions
        Submission::factory()->count(3)->create([
            'assignment_id' => $this->assignment->id,
            'graded_at' => now(),
            'score' => 85,
        ]);

        // Create pending submissions (not graded)
        Submission::factory()->count(2)->create([
            'assignment_id' => $this->assignment->id,
            'graded_at' => null,
            'score' => null,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/assignments/{$this->assignment->id}/submissions/pending");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'assignment_id',
                        'user_id',
                        'file_path',
                        'submitted_at',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);
    }

    public function test_can_get_assignment_statistics(): void
    {
        // Create submissions with scores
        Submission::factory()->count(5)->create([
            'assignment_id' => $this->assignment->id,
            'graded_at' => now(),
            'score' => 85,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson("/api/assignments/{$this->assignment->id}/statistics");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'total_submissions',
                    'graded_submissions',
                    'pending_submissions',
                    'average_score',
                ],
            ]);
    }

    public function test_can_grade_submission(): void
    {
        // Ensure assignment has max_score of 100 to avoid validation errors
        $this->assignment->update(['max_score' => 100]);

        $submission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->user->id,
            'graded_at' => null,
            'score' => null,
        ]);

        $gradeData = [
            'score' => 90,
            'feedback' => 'Great work!',
        ];

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/submissions/{$submission->id}/grade", $gradeData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'assignment_id',
                    'user_id',
                    'score',
                    'feedback',
                    'graded_at',
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertDatabaseHas('submissions', [
            'id' => $submission->id,
            'score' => 90,
            'feedback' => 'Great work!',
        ]);

        $submission->refresh();
        $this->assertNotNull($submission->graded_at);
    }

    public function test_cannot_submit_assignment_twice(): void
    {
        $submissionData = [
            'file_path' => '/storage/submissions/first.pdf',
        ];

        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/assignments/{$this->assignment->id}/submissions", $submissionData)
            ->assertStatus(201);

        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/assignments/{$this->assignment->id}/submissions", $submissionData)
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        // Scoped count: only count submissions for this assignment+user
        // Avoids brittle global table counts that break when seed data exists
        $this->assertEquals(1, \App\Models\Submission::query()
            ->where('assignment_id', $this->assignment->id)
            ->where('user_id', $this->user->id)
            ->count());
    }

    public function test_hidden_assignment_cannot_be_viewed(): void
    {
        $this->assignment->learningModule()->firstOrCreate([], [
            'course_id' => $this->assignment->course_id,
            'module_type' => 'assignment',
            'visible' => true,
            'sort_order' => $this->assignment->id,
        ])->update(['visible' => false]);

        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/assignments/{$this->assignment->id}")
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_suspended_enrollment_cannot_submit_assignment(): void
    {
        CourseEnrollment::query()
            ->where('course_id', $this->course->id)
            ->where('user_id', $this->user->id)
            ->update(['status' => 'suspended']);

        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/assignments/{$this->assignment->id}/submissions", [
                'file_path' => '/storage/submissions/test-submission.pdf',
            ])->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_submit_assignment_validation_fails_without_required_fields(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/assignments/{$this->assignment->id}/submissions", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file_path']);
    }

    public function test_grade_submission_validation_fails_without_score(): void
    {
        $submission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/submissions/{$submission->id}/grade", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['score']);
    }

    public function test_grade_submission_validation_fails_with_invalid_score(): void
    {
        $submission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->user->id,
        ]);

        $gradeData = [
            'score' => 150, // Exceeds max_score
        ];

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/submissions/{$submission->id}/grade", $gradeData);

        // Business logic validation returns 400, not 422
        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_marker_can_grade_allocated_submission(): void
    {
        $this->assignment->update(['marking_allocation_enabled' => true, 'multi_mark_method' => 'highest', 'max_score' => 100]);

        $submission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->user->id,
        ]);

        // Allocate the instructor as a marker
        AssignmentAllocatedMarker::factory()->create([
            'assignment_id' => $this->assignment->id,
            'submission_id' => $submission->id,
            'student_id' => $this->user->id,
            'marker_id' => $this->instructor->id,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/submissions/{$submission->id}/marker-grade", [
                'score' => 85,
                'feedback' => 'Good effort',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        // Must include marker mark
        $this->assertArrayHasKey('mark', $data);
        $this->assertEquals(85, $data['mark']['score']);

        // Must include final submission and grade (finalization)
        $this->assertArrayHasKey('submission', $data);
        $this->assertArrayHasKey('grade', $data);
        $this->assertEquals(85, $data['submission']['score']);
        $this->assertEquals('graded', $data['submission']['status']);

        // Verify database records
        $this->assertDatabaseHas('assignment_marks', [
            'submission_id' => $submission->id,
            'marker_id' => $this->instructor->id,
            'score' => 85,
        ]);

        $this->assertDatabaseHas('submissions', [
            'id' => $submission->id,
            'score' => 85,
            'status' => 'graded',
        ]);

        $this->assertDatabaseHas('grades', [
            'user_id' => $this->user->id,
            'gradeable_type' => 'submission',
            'gradeable_id' => $submission->id,
            'score' => 85,
            'source' => 'assignment',
        ]);
    }

    public function test_non_allocated_marker_is_rejected(): void
    {
        $this->assignment->update(['marking_allocation_enabled' => true, 'max_score' => 100]);

        $submission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->user->id,
        ]);

        // Instructor is NOT allocated as a marker

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/submissions/{$submission->id}/marker-grade", [
                'score' => 85,
            ]);

        $response->assertStatus(403);
    }

    public function test_marker_grade_validates_score_within_max(): void
    {
        $this->assignment->update(['marking_allocation_enabled' => true, 'max_score' => 50]);

        $submission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->user->id,
        ]);

        AssignmentAllocatedMarker::factory()->create([
            'assignment_id' => $this->assignment->id,
            'submission_id' => $submission->id,
            'student_id' => $this->user->id,
            'marker_id' => $this->instructor->id,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/submissions/{$submission->id}/marker-grade", [
                'score' => 100, // Exceeds max_score of 50
            ]);

        $response->assertStatus(400);
    }

    public function test_assignment_not_found_returns_404(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson('/api/assignments/99999');

        $response->assertStatus(404);
    }

    public function test_submission_not_found_for_grading_returns_404(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson('/api/submissions/99999/grade', [
                'score' => 90,
            ]);

        $response->assertStatus(404);
    }

    #[Test]
    public function user_assignment_override_allows_submission_before_global_available_time(): void
    {
        // Arrange: assignment with future available_from
        $futureAssignment = Assignment::factory()->create([
            'course_id' => $this->course->id,
            'available_from' => now()->addDays(7),
            'due_date' => now()->addDays(14),
            'cutoff_date' => now()->addDays(21),
        ]);

        // User override opens it now
        AssignmentOverride::factory()->create([
            'assignment_id' => $futureAssignment->id,
            'user_id' => $this->user->id,
            'course_group_id' => null,
            'available_from' => now()->subHour(),
        ]);

        // Act: student submits
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/assignments/{$futureAssignment->id}/submissions", [
                'file_path' => 'override-test-submission.pdf',
            ]);

        // Assert: override allows submission despite global future available_from
        $response->assertStatus(201);
    }

    #[Test]
    public function group_assignment_override_extends_due_cutoff_behavior(): void
    {
        // Arrange: group with extended due/cutoff
        $group = \App\Models\CourseGroup::factory()->create([
            'course_id' => $this->course->id,
            'active' => true,
        ]);
        \App\Models\CourseGroupMember::factory()->create([
            'course_group_id' => $group->id,
            'user_id' => $this->user->id,
        ]);

        // Assignment with past due_date but a group override extends it
        $assignment = Assignment::factory()->create([
            'course_id' => $this->course->id,
            'available_from' => now()->subDays(30),
            'due_date' => now()->subDays(1),  // Past due
            'cutoff_date' => now()->subDays(1), // Past cutoff globally
            'allow_late_submission' => true,
        ]);

        AssignmentOverride::factory()->create([
            'assignment_id' => $assignment->id,
            'user_id' => null,
            'course_group_id' => $group->id,
            'due_date' => now()->addDays(7),
            'cutoff_date' => now()->addDays(14),
        ]);

        // Act: group member student submits
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/assignments/{$assignment->id}/submissions", [
                'file_path' => 'group-override-test.pdf',
            ]);

        // Assert: group override allows submission
        $response->assertStatus(201);
    }

    #[Test]
    public function non_overridden_student_remains_blocked_by_assignment_window(): void
    {
        // Arrange: assignment with future available_from
        $futureAssignment = Assignment::factory()->create([
            'course_id' => $this->course->id,
            'available_from' => now()->addDays(7),
            'due_date' => now()->addDays(14),
        ]);

        $otherStudent = User::factory()->create(['role' => 'student']);
        CourseEnrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $otherStudent->id,
        ]);

        // Act: non-overridden student tries to submit
        $response = $this->withHeader('X-Benchmark-Actor-Id', $otherStudent->id)
            ->postJson("/api/assignments/{$futureAssignment->id}/submissions", [
                'file_path' => 'blocked-submission.pdf',
            ]);

        // Assert: blocked by global window (effective available_from rejects)
        $response->assertStatus(400);
    }

    #[Test]
    public function suspended_user_remains_blocked_even_with_assignment_override(): void
    {
        // Arrange: assignment with future available_from and override
        $futureAssignment = Assignment::factory()->create([
            'course_id' => $this->course->id,
            'available_from' => now()->addDays(7),
        ]);

        $suspendedUser = User::factory()->create(['role' => 'student']);
        CourseEnrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $suspendedUser->id,
            'status' => 'suspended',
        ]);

        AssignmentOverride::factory()->create([
            'assignment_id' => $futureAssignment->id,
            'user_id' => $suspendedUser->id,
            'available_from' => now()->subHour(),
        ]);

        // Act: suspended user tries to submit
        $response = $this->withHeader('X-Benchmark-Actor-Id', $suspendedUser->id)
            ->postJson("/api/assignments/{$futureAssignment->id}/submissions", [
                'file_path' => 'suspended-test.pdf',
            ]);

        // Assert: blocked by enrollment check
        $response->assertStatus(403);
    }

    #[Test]
    public function instructor_cannot_submit_assignment_through_override_logic(): void
    {
        // Arrange: instructor with enrollment
        $instructor = User::factory()->create(['role' => 'instructor']);
        $course = Course::factory()->create(['instructor_id' => $instructor->id]);
        $assignment = Assignment::factory()->create([
            'course_id' => $course->id,
            'available_from' => now()->subDay(),
            'due_date' => now()->addDays(7),
        ]);

        CourseEnrollment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $instructor->id,
            'role' => 'instructor',
        ]);

        // Instructor gets an override (theoretically)
        AssignmentOverride::factory()->create([
            'assignment_id' => $assignment->id,
            'user_id' => $instructor->id,
            'available_from' => now()->subDay(),
        ]);

        // Act: instructor tries to submit
        $response = $this->withHeader('X-Benchmark-Actor-Id', $instructor->id)
            ->postJson("/api/assignments/{$assignment->id}/submissions", [
                'file_path' => 'instructor-submission.pdf',
            ]);

        // Assert: blocked because canSubmitAssignment requires student role
        $response->assertStatus(403);
    }

    #[Test]
    public function get_assignment_does_not_create_learning_module(): void
    {
        // Arrange: create assignment without a learning module
        $orphanAssignment = Assignment::factory()->create([
            'course_id' => $this->course->id,
        ]);
        // Delete the learning module that the factory created
        $orphanAssignment->learningModule()->delete();
        $orphanAssignment->load('learningModule');
        $this->assertNull($orphanAssignment->learningModule);

        // Act: attempt to read the assignment (should fail since no module)
        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/assignments/{$orphanAssignment->id}")
            ->assertStatus(404);

        // Assert: no learning module was created by the read path
        $this->assertNull(
            LearningModule::where('module_type', LearningModule::TYPE_ASSIGNMENT)
                ->where('module_id', $orphanAssignment->id)
                ->first()
        );
    }

    #[Test]
    public function marker_latest_method_follows_explicit_recency_ordering(): void
    {
        // Arrange: assignment with multi_mark_method = 'latest'
        $this->assignment->update([
            'marking_allocation_enabled' => true,
            'multi_mark_method' => 'latest',
            'max_score' => 100,
        ]);

        $submission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->user->id,
            'status' => 'submitted',
        ]);

        $marker1 = User::factory()->create(['role' => 'instructor']);
        $marker2 = User::factory()->create(['role' => 'instructor']);

        // Allocate both markers (different markers to avoid unique constraint)
        AssignmentAllocatedMarker::factory()->create([
            'assignment_id' => $this->assignment->id,
            'submission_id' => $submission->id,
            'student_id' => $this->user->id,
            'marker_id' => $marker1->id,
        ]);
        AssignmentAllocatedMarker::factory()->create([
            'assignment_id' => $this->assignment->id,
            'submission_id' => $submission->id,
            'student_id' => $this->user->id,
            'marker_id' => $marker2->id,
        ]);
        // Allocate course instructor as third marker to trigger recalculation
        AssignmentAllocatedMarker::factory()->create([
            'assignment_id' => $this->assignment->id,
            'submission_id' => $submission->id,
            'student_id' => $this->user->id,
            'marker_id' => $this->instructor->id,
        ]);

        // Create an older mark with higher score (marker1, 2 days ago, score=90)
        AssignmentMark::factory()->create([
            'submission_id' => $submission->id,
            'assignment_id' => $this->assignment->id,
            'marker_id' => $marker1->id,
            'score' => 90,
            'workflow_state' => 'completed',
            'updated_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
        ]);

        // Create a newer mark with lower score (marker2, 6 hours ago, score=70)
        // This SHOULD be chosen as 'latest' since it's more recently updated
        AssignmentMark::factory()->create([
            'submission_id' => $submission->id,
            'assignment_id' => $this->assignment->id,
            'marker_id' => $marker2->id,
            'score' => 70,
            'workflow_state' => 'completed',
            'updated_at' => now()->subHours(6),
            'created_at' => now()->subHours(6),
        ]);

        // Act: call markerGrade which records a new mark then recalculates final score
        // The third mark (instructor, newest) will be chosen as latest
        $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/submissions/{$submission->id}/marker-grade", [
                'score' => 80,
                'feedback' => 'Third marker mark',
            ])
            ->assertStatus(200);

        // Assert: the final score should be 80 (newest mark since this API call is the latest)
        $submission->refresh();
        $this->assertEquals(80, $submission->score);

        // More importantly: verify the ordering is deterministic
        // Without explicit ordering, the results would be random
        $marks = \App\Models\AssignmentMark::query()
            ->where('submission_id', $submission->id)
            ->where('workflow_state', 'completed')
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->pluck('id', 'score')
            ->all();

        // The first score (highest sort order) should be 80 (most recent)
        $orderedScores = array_keys($marks);
        $this->assertEquals(80, $orderedScores[0], 'Most recent mark must be first in order');
        $this->assertEquals(70, $orderedScores[1], '6-hour-old mark must be second');
        $this->assertEquals(90, $orderedScores[2], '2-day-old mark must be last');
    }

    #[Test]
    public function marker_latest_tie_breaker_uses_highest_id(): void
    {
        // Arrange: assignment with multi_mark_method = 'latest'
        $this->assignment->update([
            'marking_allocation_enabled' => true,
            'multi_mark_method' => 'latest',
            'max_score' => 100,
        ]);

        $submission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->user->id,
            'status' => 'submitted',
        ]);

        // Use three markers: mark1, mark2 (for tied marks), and course instructor (for trigger)
        $marker1 = User::factory()->create(['role' => 'instructor']);
        $marker2 = User::factory()->create(['role' => 'instructor']);

        // Allocate marker1 and marker2
        AssignmentAllocatedMarker::factory()->create([
            'assignment_id' => $this->assignment->id,
            'submission_id' => $submission->id,
            'student_id' => $this->user->id,
            'marker_id' => $marker1->id,
        ]);
        AssignmentAllocatedMarker::factory()->create([
            'assignment_id' => $this->assignment->id,
            'submission_id' => $submission->id,
            'student_id' => $this->user->id,
            'marker_id' => $marker2->id,
        ]);
        // Also allocate the course instructor as a third marker to trigger recalculation
        AssignmentAllocatedMarker::factory()->create([
            'assignment_id' => $this->assignment->id,
            'submission_id' => $submission->id,
            'student_id' => $this->user->id,
            'marker_id' => $this->instructor->id,
        ]);

        // Create two marks with the same updated_at (tie) but different IDs
        // The mark with higher ID should be chosen as 'latest'
        \Illuminate\Support\Carbon::setTestNow(now()->subDay());
        $mark1 = AssignmentMark::factory()->create([
            'submission_id' => $submission->id,
            'assignment_id' => $this->assignment->id,
            'marker_id' => $marker1->id,
            'score' => 50,
            'workflow_state' => 'completed',
        ]);

        $mark2 = AssignmentMark::factory()->create([
            'submission_id' => $submission->id,
            'assignment_id' => $this->assignment->id,
            'marker_id' => $marker2->id,
            'score' => 85,
            'workflow_state' => 'completed',
        ]);
        \Illuminate\Support\Carbon::setTestNow();

        // Verify the second mark has a higher ID than the first
        $this->assertGreaterThan($mark1->id, $mark2->id);

        // Act: trigger calculateFinalMarkerScore via marker-grade with a third mark
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/submissions/{$submission->id}/marker-grade", [
                'score' => 60,
                'feedback' => 'Tie-breaker third mark',
            ]);

        $response->assertStatus(200);

        // The final score should be 60 (the third mark is newest)
        $submission->refresh();
        $this->assertEquals(60, $submission->score);

        // Verify ordering: marks ordered by updated_at desc, then id desc
        $marks = \App\Models\AssignmentMark::query()
            ->where('submission_id', $submission->id)
            ->where('workflow_state', 'completed')
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->pluck('score');

        $this->assertEquals([60, 85, 50], $marks->values()->all(), 'Ordering must be: newest mark first, then by id desc within same updated_at');
    }

    /* ──────────────────────────────────────────────
     * Plan 04: Assignment Return and Reopen
     * ────────────────────────────────────────────── */

    #[Test]
    public function instructor_can_return_submitted_submission(): void
    {
        $submission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->user->id,
            'status' => 'submitted',
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/submissions/{$submission->id}/return", [
                'reason' => 'Please revise the introduction',
            ]);

        $response->assertStatus(200);

        $submission->refresh();
        $this->assertEquals('returned', $submission->status);
        $this->assertNotNull($submission->returned_at);
        $this->assertEquals('Please revise the introduction', $submission->feedback);
    }

    #[Test]
    public function instructor_can_return_graded_submission(): void
    {
        $submission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->user->id,
            'status' => 'graded',
            'score' => 85,
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/submissions/{$submission->id}/return");

        $response->assertStatus(200);

        $submission->refresh();
        $this->assertEquals('returned', $submission->status);
        $this->assertNotNull($submission->returned_at);
    }

    #[Test]
    public function student_cannot_return_submission(): void
    {
        $submission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->user->id,
            'status' => 'submitted',
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->putJson("/api/submissions/{$submission->id}/return", [
                'reason' => 'Student trying to return',
            ]);

        $response->assertStatus(403);
        $submission->refresh();
        $this->assertEquals('submitted', $submission->status);
    }

    #[Test]
    public function student_cannot_reopen_submission(): void
    {
        $submission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->user->id,
            'status' => 'returned',
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->putJson("/api/submissions/{$submission->id}/reopen");

        $response->assertStatus(403);
        $submission->refresh();
        $this->assertEquals('returned', $submission->status);
    }

    #[Test]
    public function instructor_can_reopen_returned_submission(): void
    {
        $submission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->user->id,
            'status' => 'returned',
            'score' => 70,
            'returned_at' => now()->subDay(),
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/submissions/{$submission->id}/reopen", [
                'reason' => 'You may resubmit for a better grade',
            ]);

        $response->assertStatus(200);

        $submission->refresh();
        $this->assertEquals('reopened', $submission->status);
        $this->assertNotNull($submission->reopened_at);
        // Score should be cleared for fresh attempt
        $this->assertNull($submission->score);
    }

    #[Test]
    public function reopened_submission_allows_new_attempt(): void
    {
        // First submission
        $firstSubmission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->user->id,
            'status' => 'returned',
            'is_latest' => true,
            'attempt_number' => 1,
        ]);

        // Reopen
        $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/submissions/{$firstSubmission->id}/reopen")
            ->assertStatus(200);

        // Student resubmits — should create a new submission (attempt 2)
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/assignments/{$this->assignment->id}/submissions", [
                'file_path' => '/storage/submissions/resubmission.pdf',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('submissions', [
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->user->id,
            'attempt_number' => 2,
            'is_latest' => true,
            'status' => 'submitted',
        ]);

        // Original submission should no longer be is_latest
        $firstSubmission->refresh();
        $this->assertFalse($firstSubmission->is_latest);
    }

    #[Test]
    public function cannot_return_submission_in_invalid_state(): void
    {
        // draft submissions cannot be returned
        $submission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->user->id,
            'status' => 'draft',
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/submissions/{$submission->id}/return");

        $response->assertStatus(400);
    }

    #[Test]
    public function cannot_reopen_non_returned_submission(): void
    {
        $submission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->user->id,
            'status' => 'submitted',
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/submissions/{$submission->id}/reopen");

        $response->assertStatus(400);
    }

    #[Test]
    public function unrelated_instructor_cannot_return_submission(): void
    {
        $otherInstructor = User::factory()->create(['role' => 'instructor']);

        $submission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->user->id,
            'status' => 'submitted',
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $otherInstructor->id)
            ->putJson("/api/submissions/{$submission->id}/return");

        $response->assertStatus(403);
    }

    #[Test]
    public function reopened_submission_marks_gradebook_stale(): void
    {
        $submission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->user->id,
            'status' => 'returned',
            'score' => 75,
            'returned_at' => now()->subDay(),
        ]);

        $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/submissions/{$submission->id}/reopen")
            ->assertStatus(200);

        // Gradebook should be marked stale for the course
        $this->assertDatabaseHas('gradebook_recalculations', [
            'course_id' => $this->assignment->course_id,
            'source_type' => 'submission',
            'source_id' => $submission->id,
        ]);

        $recalc = \App\Models\GradebookRecalculation::query()
            ->where('course_id', $this->assignment->course_id)
            ->first();

        $this->assertNotNull($recalc);
        $this->assertEquals('submission_reopened', $recalc->reason);
        $this->assertNull($recalc->recalculated_at);
    }

    #[Test]
    public function max_attempts_still_applies_after_reopen(): void
    {
        $this->assignment->update(['max_attempts' => 2]);

        // Create first submission (attempt 1) — returned and reopened
        $first = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
            'user_id' => $this->user->id,
            'is_latest' => false,
            'attempt_number' => 1,
            'status' => 'returned',
        ]);

        // Reopen
        $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->putJson("/api/submissions/{$first->id}/reopen")
            ->assertStatus(200);

        // Submit attempt 2
        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/assignments/{$this->assignment->id}/submissions", [
                'file_path' => '/attempt2.pdf',
            ])
            ->assertStatus(201);

        // Attempt 3 should be blocked by max_attempts
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/assignments/{$this->assignment->id}/submissions", [
                'file_path' => '/attempt3.pdf',
            ]);

        $response->assertStatus(400);
    }
}
