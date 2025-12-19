<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $instructor;
    protected Course $course;
    protected Assignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->user = User::factory()->create(['role' => 'student']);
        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->course = Course::factory()->create(['instructor_id' => $this->instructor->id]);
        $this->assignment = Assignment::factory()->create(['course_id' => $this->course->id]);
    }

    public function test_can_list_course_assignments(): void
    {
        // Create additional assignments for the course
        Assignment::factory()->count(3)->create(['course_id' => $this->course->id]);

        $response = $this->getJson("/api/courses/{$this->course->id}/assignments");

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
                    ]
                ]
            ]);
    }

    public function test_can_show_assignment_detail(): void
    {
        $response = $this->getJson("/api/assignments/{$this->assignment->id}");

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
                ]
            ]);
    }

    public function test_can_submit_assignment(): void
    {
        $submissionData = [
            'user_id' => $this->user->id,
            'file_path' => '/storage/submissions/test-submission.pdf',
        ];

        $response = $this->postJson("/api/assignments/{$this->assignment->id}/submissions", $submissionData);

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
                ]
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

        $response = $this->getJson("/api/assignments/{$this->assignment->id}/submissions");

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
                    ]
                ]
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

        $response = $this->getJson("/api/assignments/{$this->assignment->id}/submissions/pending");

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
                    ]
                ]
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

        $response = $this->getJson("/api/assignments/{$this->assignment->id}/statistics");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'total_submissions',
                    'graded_submissions',
                    'pending_submissions',
                    'average_score',
                ]
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

        $response = $this->putJson("/api/submissions/{$submission->id}/grade", $gradeData);

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
                ]
            ]);

        $this->assertDatabaseHas('submissions', [
            'id' => $submission->id,
            'score' => 90,
            'feedback' => 'Great work!',
        ]);

        $submission->refresh();
        $this->assertNotNull($submission->graded_at);
    }

    public function test_submit_assignment_validation_fails_without_required_fields(): void
    {
        $response = $this->postJson("/api/assignments/{$this->assignment->id}/submissions", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id', 'file_path']);
    }

    public function test_grade_submission_validation_fails_without_score(): void
    {
        $submission = Submission::factory()->create([
            'assignment_id' => $this->assignment->id,
        ]);

        $response = $this->putJson("/api/submissions/{$submission->id}/grade", []);

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

        $response = $this->putJson("/api/submissions/{$submission->id}/grade", $gradeData);

        // Business logic validation returns 400, not 422
        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_assignment_not_found_returns_404(): void
    {
        $response = $this->getJson('/api/assignments/99999');

        $response->assertStatus(404);
    }

    public function test_submission_not_found_for_grading_returns_404(): void
    {
        $response = $this->putJson('/api/submissions/99999/grade', [
            'score' => 90,
        ]);

        $response->assertStatus(404);
    }
}
