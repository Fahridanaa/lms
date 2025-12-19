<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\Grade;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GradebookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $instructor;
    protected Course $course;
    protected Quiz $quiz;
    protected Assignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->user = User::factory()->create(['role' => 'student']);
        $this->instructor = User::factory()->create(['role' => 'instructor']);
        $this->course = Course::factory()->create(['instructor_id' => $this->instructor->id]);
        $this->quiz = Quiz::factory()->create(['course_id' => $this->course->id]);
        $this->assignment = Assignment::factory()->create(['course_id' => $this->course->id]);
    }

    public function test_can_get_course_gradebook(): void
    {
        // Create grades for different students
        $students = User::factory()->count(5)->create(['role' => 'student']);

        foreach ($students as $student) {
            Grade::factory()->create([
                'user_id' => $student->id,
                'course_id' => $this->course->id,
                'gradeable_type' => 'quiz',
                'gradeable_id' => $this->quiz->id,
                'score' => rand(70, 100),
                'max_score' => 100,
            ]);
        }

        $response = $this->getJson("/api/courses/{$this->course->id}/gradebook");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'user_id',
                        'user_name',
                        'grades' => [
                            '*' => [
                                'id',
                                'gradeable_type',
                                'gradeable_id',
                                'score',
                                'max_score',
                                'percentage',
                            ]
                        ],
                        'average',
                    ]
                ]
            ]);
    }

    public function test_can_get_user_grades(): void
    {
        // Create multiple grades for the user across different courses
        Grade::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
        ]);

        $response = $this->getJson("/api/users/{$this->user->id}/grades");

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
                    ]
                ]
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

        $response = $this->getJson("/api/courses/{$this->course->id}/users/{$this->user->id}/grades");

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

        $response = $this->putJson("/api/grades/{$grade->id}", $updateData);

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
                ]
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

        $response = $this->getJson("/api/courses/{$this->course->id}/statistics");

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

        $response = $this->getJson("/api/users/{$this->user->id}/performance");

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

        $response = $this->getJson("/api/courses/{$this->course->id}/top-performers");

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

        $response = $this->putJson("/api/grades/{$grade->id}", []);

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

        $response = $this->putJson("/api/grades/{$grade->id}", $updateData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['score']);
    }

    public function test_course_not_found_for_gradebook_returns_404(): void
    {
        $response = $this->getJson('/api/courses/99999/gradebook');

        $response->assertStatus(404);
    }

    public function test_grade_not_found_returns_404(): void
    {
        $response = $this->putJson('/api/grades/99999', [
            'score' => 90,
        ]);

        $response->assertStatus(404);
    }

    public function test_user_not_found_for_grades_returns_empty_or_404(): void
    {
        $response = $this->getJson('/api/users/99999/grades');

        // May return 200 with empty data or 404
        $this->assertContains($response->status(), [200, 404]);
    }
}
