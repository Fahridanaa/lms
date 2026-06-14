<?php

namespace Tests\Feature\Api;

use App\Models\Course;
use App\Models\CourseCompletionCriterion;
use App\Models\CourseEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CourseCompletionTest extends TestCase
{
    use DatabaseTransactions;

    private User $student;

    private User $instructor;

    private User $nonEnrolled;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->student = User::factory()->create(['role' => 'student']);
        $this->nonEnrolled = User::factory()->create(['role' => 'student']);

        $this->course = Course::factory()->create([
            'instructor_id' => $this->instructor->id,
            'is_active' => true,
        ]);

        CourseEnrollment::factory()->create([
            'user_id' => $this->student->id,
            'course_id' => $this->course->id,
            'role' => 'student',
            'status' => 'active',
        ]);

        CourseCompletionCriterion::query()->create([
            'course_id' => $this->course->id,
            'criteriatype' => 'module',
            'module_instance_id' => null,
        ]);
    }

    public function test_student_sees_completion_state(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson('/api/courses/'.$this->course->id.'/completion');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'course_id',
            'completed',
            'completed_at',
            'progress' => [
                'criteria_met',
                'criteria_total',
                'criteria',
            ],
        ]);

        $response->assertJson([
            'course_id' => $this->course->id,
            'progress' => [
                'criteria_met' => 0,
                'criteria_total' => 1,
            ],
        ]);
    }

    public function test_non_enrolled_user_gets_403(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->nonEnrolled->id)
            ->getJson('/api/courses/'.$this->course->id.'/completion');

        $response->assertStatus(403);
    }

    public function test_instructor_can_read_completion(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->instructor->id)
            ->getJson('/api/courses/'.$this->course->id.'/completion');

        $response->assertStatus(200);
    }

    public function test_nonexistent_course_returns_404(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->student->id)
            ->getJson('/api/courses/99999/completion');

        $response->assertStatus(404);
    }
}
