<?php

namespace Tests\Feature\Api;

use App\Models\Course;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Course $course;
    protected Quiz $quiz;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->user = User::factory()->create(['role' => 'student']);
        $this->course = Course::factory()->create();
        $this->quiz = Quiz::factory()->create(['course_id' => $this->course->id]);

        // Create questions for the quiz
        Question::factory()->count(5)->create(['quiz_id' => $this->quiz->id]);
    }

    public function test_can_list_all_quizzes(): void
    {
        $response = $this->getJson('/api/quizzes');

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
                        'time_limit',
                        'passing_score',
                        'created_at',
                        'updated_at',
                    ]
                ]
            ]);
    }

    public function test_can_show_quiz_detail(): void
    {
        $response = $this->getJson("/api/quizzes/{$this->quiz->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'course_id',
                    'title',
                    'description',
                    'time_limit',
                    'passing_score',
                    'questions' => [
                        '*' => [
                            'id',
                            'quiz_id',
                            'question_text',
                            'options',
                            'points',
                        ]
                    ],
                    'created_at',
                    'updated_at',
                ]
            ]);
    }

    public function test_can_get_quiz_questions(): void
    {
        $response = $this->getJson("/api/quizzes/{$this->quiz->id}/questions");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'quiz_id',
                        'question_text',
                        'options',
                        'points',
                    ]
                ]
            ]);
    }

    public function test_can_start_quiz_attempt(): void
    {
        $response = $this->postJson("/api/quizzes/{$this->quiz->id}/attempts", [
            'user_id' => $this->user->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'quiz_id',
                    'user_id',
                    'started_at',
                    'created_at',
                    'updated_at',
                ]
            ]);

        $this->assertDatabaseHas('quiz_attempts', [
            'quiz_id' => $this->quiz->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_can_submit_quiz_attempt(): void
    {
        // First create an attempt
        $attempt = QuizAttempt::factory()->create([
            'quiz_id' => $this->quiz->id,
            'user_id' => $this->user->id,
            'started_at' => now(),
            'completed_at' => null,
        ]);

        $questions = $this->quiz->questions;
        $answers = [];
        foreach ($questions as $question) {
            $answers[$question->id] = $question->correct_answer;
        }

        $response = $this->putJson("/api/quizzes/{$this->quiz->id}/attempts/{$attempt->id}", [
            'answers' => $answers,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'quiz_id',
                    'user_id',
                    'score',
                    'started_at',
                    'completed_at',
                    'created_at',
                    'updated_at',
                ]
            ]);

        $this->assertDatabaseHas('quiz_attempts', [
            'id' => $attempt->id,
        ]);

        $attempt->refresh();
        $this->assertNotNull($attempt->completed_at);
        $this->assertNotNull($attempt->score);
    }

    public function test_can_get_attempt_result(): void
    {
        // Create a completed attempt
        $attempt = QuizAttempt::factory()->create([
            'quiz_id' => $this->quiz->id,
            'user_id' => $this->user->id,
            'started_at' => now()->subMinutes(30),
            'completed_at' => now(),
            'score' => 85,
            'answers' => json_encode([]),
        ]);

        $response = $this->getJson("/api/quizzes/{$this->quiz->id}/attempts/{$attempt->id}/result");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'quiz_id',
                    'user_id',
                    'score',
                    'started_at',
                    'completed_at',
                    'created_at',
                    'updated_at',
                ]
            ]);
    }

    public function test_can_get_user_quiz_attempts(): void
    {
        // Create multiple attempts for the user
        QuizAttempt::factory()->count(3)->create([
            'quiz_id' => $this->quiz->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->getJson("/api/users/{$this->user->id}/quiz-attempts");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'quiz_id',
                        'user_id',
                        'started_at',
                        'created_at',
                        'updated_at',
                    ]
                ]
            ]);
    }

    public function test_quiz_not_found_returns_404(): void
    {
        $response = $this->getJson('/api/quizzes/99999');

        $response->assertStatus(404);
    }

    public function test_attempt_not_found_returns_404(): void
    {
        $response = $this->getJson("/api/quizzes/{$this->quiz->id}/attempts/99999/result");

        $response->assertStatus(404);
    }
}
