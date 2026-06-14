<?php

namespace Tests\Feature\Api;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\LearningModule;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptQuestion;
use App\Models\QuizAttemptStep;
use App\Models\QuizAttemptStepData;
use App\Models\QuizOverride;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class QuizControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected Course $course;

    protected Quiz $quiz;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Create test data
        $this->user = User::factory()->create(['role' => 'student']);
        $this->course = Course::factory()->create();
        $this->quiz = Quiz::factory()->create(['course_id' => $this->course->id]);
        CourseEnrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->user->id,
        ]);

        // Create questions for the quiz
        Question::factory()->count(5)->create(['quiz_id' => $this->quiz->id]);
    }

    public function test_can_list_all_quizzes(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson('/api/quizzes');

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
                    ],
                ],
            ]);
    }

    public function test_can_show_quiz_detail(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/quizzes/{$this->quiz->id}");

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
                        ],
                    ],
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertArrayNotHasKey('correct_answer', $response->json('data.questions.0'));
    }

    public function test_can_get_quiz_questions(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/quizzes/{$this->quiz->id}/questions");

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
                    ],
                ],
            ]);

        $this->assertArrayNotHasKey('correct_answer', $response->json('data.0'));
    }

    public function test_can_start_quiz_attempt(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts", []);

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
                ],
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

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->putJson("/api/quizzes/{$this->quiz->id}/attempts/{$attempt->id}", [
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
                ],
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

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/quizzes/{$this->quiz->id}/attempts/{$attempt->id}/result");

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
                ],
            ]);
    }

    public function test_attempt_result_includes_normalized_detail(): void
    {
        // Start and submit an attempt to create normalized detail rows
        $startResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts", []);
        $attemptId = $startResponse->json('data.id');

        $questions = $this->quiz->questions;
        $answers = [];
        foreach ($questions as $question) {
            $answers[$question->id] = $question->correct_answer;
        }

        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->putJson("/api/quizzes/{$this->quiz->id}/attempts/{$attemptId}", [
                'answers' => $answers,
            ])
            ->assertStatus(200);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/quizzes/{$this->quiz->id}/attempts/{$attemptId}/result");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Must include normalized attempt questions
        $this->assertArrayHasKey('attempt_questions', $data);
        $this->assertNotEmpty($data['attempt_questions']);
        $this->assertCount($questions->count(), $data['attempt_questions']);

        // Each attempt question must have slot, question_id, state, steps
        foreach ($data['attempt_questions'] as $aq) {
            $this->assertArrayHasKey('slot', $aq);
            $this->assertArrayHasKey('question_id', $aq);
            $this->assertArrayHasKey('state', $aq);
            $this->assertArrayHasKey('steps', $aq);
            $this->assertNotEmpty($aq['steps']);

            // Each step should have step_data with filtering
            foreach ($aq['steps'] as $step) {
                $this->assertArrayHasKey('sequence_number', $step);
                $this->assertArrayHasKey('state', $step);

                // The graded step should have step_data
                if ($step['state'] === 'graded') {
                    $this->assertArrayHasKey('step_data', $step);
                    $stepDataNames = array_column($step['step_data'], 'name');
                    $this->assertContains('answer', $stepDataNames);
                    $this->assertContains('is_correct', $stepDataNames);
                    $this->assertContains('raw_score', $stepDataNames);
                    $this->assertContains('max_points', $stepDataNames);
                }
            }
        }
    }

    public function test_review_visibility_never_hides_normalized_detail(): void
    {
        $this->quiz->update(['review_visibility' => 'never']);

        // Start and submit an attempt
        $startResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts", []);
        $attemptId = $startResponse->json('data.id');

        $questions = $this->quiz->questions;
        $answers = [];
        foreach ($questions as $question) {
            $answers[$question->id] = $question->correct_answer;
        }

        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->putJson("/api/quizzes/{$this->quiz->id}/attempts/{$attemptId}", [
                'answers' => $answers,
            ])
            ->assertStatus(200);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/quizzes/{$this->quiz->id}/attempts/{$attemptId}/result");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Legacy answers must not be present
        $this->assertArrayNotHasKey('answers', $data);

        // Normalized detail should still be present but step_data filtered
        $this->assertArrayHasKey('attempt_questions', $data);
        foreach ($data['attempt_questions'] as $aq) {
            foreach ($aq['steps'] as $step) {
                if ($step['state'] === 'graded' && isset($step['step_data'])) {
                    $stepDataNames = array_column($step['step_data'], 'name');
                    // Answer-revealing data must be hidden
                    $this->assertNotContains('answer', $stepDataNames);
                    $this->assertNotContains('is_correct', $stepDataNames);
                    $this->assertNotContains('raw_score', $stepDataNames);
                    // Non-revealing data is still allowed
                    $this->assertContains('max_points', $stepDataNames);
                }
            }
        }
    }

    public function test_can_get_user_quiz_attempts(): void
    {
        // Create multiple attempts for the user
        QuizAttempt::factory()
            ->count(3)
            ->state(new Sequence(
                ['attempt_number' => 1],
                ['attempt_number' => 2],
                ['attempt_number' => 3],
            ))
            ->create([
                'quiz_id' => $this->quiz->id,
                'user_id' => $this->user->id,
            ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/users/{$this->user->id}/quiz-attempts");

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
                    ],
                ],
            ]);
    }

    public function test_cannot_start_duplicate_quiz_attempt(): void
    {
        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts", [])
            ->assertStatus(201);

        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts", [])
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        // Scoped count: only count attempts for this quiz+user
        // Avoids brittle global table counts that break when seed data exists
        $this->assertEquals(1, \App\Models\QuizAttempt::query()
            ->where('quiz_id', $this->quiz->id)
            ->where('user_id', $this->user->id)
            ->count());
    }

    public function test_can_start_new_attempt_after_completing_previous(): void
    {
        QuizAttempt::factory()->create([
            'quiz_id' => $this->quiz->id,
            'user_id' => $this->user->id,
            'completed_at' => now(),
            'status' => 'finished',
        ]);

        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts", [])
            ->assertStatus(201);

        // Scoped count: only count attempts for this quiz+user
        $this->assertEquals(2, \App\Models\QuizAttempt::query()
            ->where('quiz_id', $this->quiz->id)
            ->where('user_id', $this->user->id)
            ->count());
    }

    public function test_hidden_quiz_cannot_be_viewed(): void
    {
        $this->quiz->learningModule()->firstOrCreate([], [
            'course_id' => $this->quiz->course_id,
            'module_type' => 'quiz',
            'visible' => true,
            'sort_order' => $this->quiz->id,
        ])->update(['visible' => false]);

        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/quizzes/{$this->quiz->id}")
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_suspended_enrollment_cannot_start_quiz_attempt(): void
    {
        CourseEnrollment::query()
            ->where('course_id', $this->course->id)
            ->where('user_id', $this->user->id)
            ->update(['status' => 'suspended']);

        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts", [])
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_quiz_max_attempts_is_enforced(): void
    {
        $this->quiz->update(['max_attempts' => 1]);

        QuizAttempt::factory()->create([
            'quiz_id' => $this->quiz->id,
            'user_id' => $this->user->id,
            'completed_at' => now(),
            'status' => 'finished',
        ]);

        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts", [])
            ->assertStatus(400)
            ->assertJson(['success' => false]);
    }

    public function test_start_attempt_creates_attempt_question_per_slot(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts", []);

        $response->assertStatus(201);
        $attemptId = $response->json('data.id');

        $questionCount = $this->quiz->questions()->count();
        $this->assertEquals($questionCount, QuizAttemptQuestion::query()
            ->where('quiz_attempt_id', $attemptId)
            ->count());

        $attemptQuestions = QuizAttemptQuestion::query()
            ->where('quiz_attempt_id', $attemptId)
            ->get();

        foreach ($attemptQuestions as $aq) {
            $this->assertEquals('not_answered', $aq->state);
            $this->assertEquals(1, QuizAttemptStep::query()
                ->where('quiz_attempt_question_id', $aq->id)
                ->count());
            $this->assertEquals(0, QuizAttemptStep::query()
                ->where('quiz_attempt_question_id', $aq->id)
                ->value('sequence_number'));
        }
    }

    public function test_submit_creates_step_and_step_data_per_answered_question(): void
    {
        $startResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts", []);
        $attemptId = $startResponse->json('data.id');

        $questions = $this->quiz->questions;
        $answers = [];
        foreach ($questions as $question) {
            $answers[$question->id] = $question->correct_answer;
        }

        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->putJson("/api/quizzes/{$this->quiz->id}/attempts/{$attemptId}", [
                'answers' => $answers,
            ])
            ->assertStatus(200);

        $attemptQuestions = QuizAttemptQuestion::query()
            ->where('quiz_attempt_id', $attemptId)
            ->get();

        foreach ($attemptQuestions as $aq) {
            $this->assertEquals('graded', $aq->state);
            $steps = QuizAttemptStep::query()
                ->where('quiz_attempt_question_id', $aq->id)
                ->orderBy('sequence_number')
                ->get();
            $this->assertCount(2, $steps);
            $this->assertEquals(0, $steps[0]->sequence_number);
            $this->assertEquals('not_answered', $steps[0]->state);
            $this->assertEquals(1, $steps[1]->sequence_number);
            $this->assertEquals('graded', $steps[1]->state);

            $stepData = QuizAttemptStepData::query()
                ->where('quiz_attempt_step_id', $steps[1]->id)
                ->get();
            $this->assertCount(4, $stepData);
            $this->assertNotNull($stepData->firstWhere('name', 'answer'));
            $this->assertNotNull($stepData->firstWhere('name', 'is_correct'));
            $this->assertNotNull($stepData->firstWhere('name', 'raw_score'));
            $this->assertNotNull($stepData->firstWhere('name', 'max_points'));
        }
    }

    public function test_partial_answer_set_marks_unanswered_slots_predictably(): void
    {
        $startResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts", []);
        $attemptId = $startResponse->json('data.id');

        $questions = $this->quiz->questions;
        $firstQuestion = $questions->first();
        $partialAnswers = [$firstQuestion->id => $firstQuestion->correct_answer];

        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->putJson("/api/quizzes/{$this->quiz->id}/attempts/{$attemptId}", [
                'answers' => $partialAnswers,
            ])
            ->assertStatus(200);

        $attemptQuestions = QuizAttemptQuestion::query()
            ->where('quiz_attempt_id', $attemptId)
            ->orderBy('slot')
            ->get();

        $this->assertEquals('graded', $attemptQuestions[0]->state);
        $this->assertCount(2, QuizAttemptStep::query()
            ->where('quiz_attempt_question_id', $attemptQuestions[0]->id)
            ->get());

        for ($i = 1; $i < $attemptQuestions->count(); $i++) {
            $this->assertEquals('not_answered', $attemptQuestions[$i]->state);
            $this->assertCount(1, QuizAttemptStep::query()
                ->where('quiz_attempt_question_id', $attemptQuestions[$i]->id)
                ->get());
        }
    }

    public function test_resubmit_finished_attempt_does_not_create_duplicate_steps(): void
    {
        $startResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts", []);
        $attemptId = $startResponse->json('data.id');

        $questions = $this->quiz->questions;
        $answers = [];
        foreach ($questions as $question) {
            $answers[$question->id] = $question->correct_answer;
        }

        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->putJson("/api/quizzes/{$this->quiz->id}/attempts/{$attemptId}", [
                'answers' => $answers,
            ])
            ->assertStatus(200);

        $stepCountAfterFirstSubmit = QuizAttemptStep::query()
            ->whereIn('quiz_attempt_question_id', function ($q) use ($attemptId) {
                $q->select('id')->from('quiz_attempt_questions')->where('quiz_attempt_id', $attemptId);
            })
            ->count();

        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->putJson("/api/quizzes/{$this->quiz->id}/attempts/{$attemptId}", [
                'answers' => $answers,
            ])
            ->assertStatus(400);

        $stepCountAfterResubmit = QuizAttemptStep::query()
            ->whereIn('quiz_attempt_question_id', function ($q) use ($attemptId) {
                $q->select('id')->from('quiz_attempt_questions')->where('quiz_attempt_id', $attemptId);
            })
            ->count();

        $this->assertEquals($stepCountAfterFirstSubmit, $stepCountAfterResubmit);
    }

    public function test_legacy_attempt_with_json_answers_still_readable(): void
    {
        $legacyAttempt = QuizAttempt::factory()->create([
            'quiz_id' => $this->quiz->id,
            'user_id' => $this->user->id,
            'started_at' => now()->subMinutes(30),
            'completed_at' => now(),
            'score' => 80.0,
            'status' => 'finished',
            'answers' => ['1' => 'answer_a'],
        ]);

        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/quizzes/{$this->quiz->id}/attempts/{$legacyAttempt->id}/result");

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
                ],
            ]);
    }

    public function test_quiz_not_found_returns_404(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson('/api/quizzes/99999');

        $response->assertStatus(404);
    }

    public function test_attempt_not_found_returns_404(): void
    {
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/quizzes/{$this->quiz->id}/attempts/99999/result");

        $response->assertStatus(404);
    }

    public function test_attempt_result_validates_quiz_id(): void
    {
        // Create a completed attempt for the current quiz
        $attempt = QuizAttempt::factory()->create([
            'quiz_id' => $this->quiz->id,
            'user_id' => $this->user->id,
            'started_at' => now()->subMinutes(30),
            'completed_at' => now(),
            'score' => 85,
            'answers' => json_encode([]),
        ]);

        // Try to access result with WRONG quizId
        $wrongQuizId = $this->quiz->id + 999;
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/quizzes/{$wrongQuizId}/attempts/{$attempt->id}/result");

        $response->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_submit_attempt_validates_quiz_id(): void
    {
        // Create a quiz attempt for the current quiz
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

        // Try to submit with WRONG quizId
        $wrongQuizId = $this->quiz->id + 999;
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->putJson("/api/quizzes/{$wrongQuizId}/attempts/{$attempt->id}", [
                'answers' => $answers,
            ]);

        $response->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    #[Test]
    public function user_quiz_override_opens_quiz_before_global_open_time(): void
    {
        // Arrange: quiz with future available_from (not yet open globally)
        $futureQuiz = Quiz::factory()->create([
            'course_id' => $this->course->id,
            'available_from' => now()->addDays(7),
        ]);
        Question::factory()->count(3)->create(['quiz_id' => $futureQuiz->id]);

        // User override opens it now
        QuizOverride::factory()->create([
            'quiz_id' => $futureQuiz->id,
            'user_id' => $this->user->id,
            'course_group_id' => null,
            'available_from' => now()->subHour(),
            'available_until' => now()->addDays(7),
        ]);

        // Act: student starts attempt
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$futureQuiz->id}/attempts");

        // Assert: override allows the attempt despite global future timer
        $response->assertStatus(201);
    }

    #[Test]
    public function group_quiz_override_opens_quiz_when_global_window_would_reject(): void
    {
        // Arrange: quiz with future available_from
        $futureQuiz = Quiz::factory()->create([
            'course_id' => $this->course->id,
            'available_from' => now()->addDays(14),
        ]);
        Question::factory()->count(3)->create(['quiz_id' => $futureQuiz->id]);

        $group = \App\Models\CourseGroup::factory()->create([
            'course_id' => $this->course->id,
            'active' => true,
        ]);
        \App\Models\CourseGroupMember::factory()->create([
            'course_group_id' => $group->id,
            'user_id' => $this->user->id,
        ]);

        // Group override opens it now
        QuizOverride::factory()->create([
            'quiz_id' => $futureQuiz->id,
            'user_id' => null,
            'course_group_id' => $group->id,
            'available_from' => now()->subHour(),
            'available_until' => now()->addDays(7),
        ]);

        // Act: student (in the group) starts attempt
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$futureQuiz->id}/attempts");

        // Assert: group override allows the attempt
        $response->assertStatus(201);
    }

    #[Test]
    public function user_quiz_override_changes_max_attempts(): void
    {
        // Arrange: quiz with max_attempts = 1
        $limitedQuiz = Quiz::factory()->create([
            'course_id' => $this->course->id,
            'max_attempts' => 1,
        ]);
        Question::factory()->count(3)->create(['quiz_id' => $limitedQuiz->id]);

        // User override allows 3 attempts
        QuizOverride::factory()->create([
            'quiz_id' => $limitedQuiz->id,
            'user_id' => $this->user->id,
            'course_group_id' => null,
            'max_attempts' => 3,
        ]);

        // Create 2 completed attempts with sequential attempt numbers
        QuizAttempt::factory()->create([
            'quiz_id' => $limitedQuiz->id,
            'user_id' => $this->user->id,
            'attempt_number' => 1,
            'completed_at' => now(),
            'status' => 'finished',
        ]);
        QuizAttempt::factory()->create([
            'quiz_id' => $limitedQuiz->id,
            'user_id' => $this->user->id,
            'attempt_number' => 2,
            'completed_at' => now(),
            'status' => 'finished',
        ]);

        // Act: start a 3rd attempt — should succeed thanks to override
        $response = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$limitedQuiz->id}/attempts");

        $response->assertStatus(201);
    }

    #[Test]
    public function non_overridden_student_remains_blocked_by_global_quiz_window(): void
    {
        // Arrange: quiz with future available_from
        $futureQuiz = Quiz::factory()->create([
            'course_id' => $this->course->id,
            'available_from' => now()->addDays(7),
        ]);
        Question::factory()->count(3)->create(['quiz_id' => $futureQuiz->id]);

        // Create another student with NO override
        $otherStudent = User::factory()->create(['role' => 'student']);
        CourseEnrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $otherStudent->id,
        ]);

        // Act: non-overridden student tries to start
        $response = $this->withHeader('X-Benchmark-Actor-Id', $otherStudent->id)
            ->postJson("/api/quizzes/{$futureQuiz->id}/attempts");

        // Assert: blocked by global window
        $response->assertStatus(404);
    }

    #[Test]
    public function suspended_user_remains_blocked_even_with_quiz_override(): void
    {
        // Arrange: quiz with future available_from and user override
        $futureQuiz = Quiz::factory()->create([
            'course_id' => $this->course->id,
            'available_from' => now()->addDays(7),
        ]);
        Question::factory()->count(3)->create(['quiz_id' => $futureQuiz->id]);

        // Suspended enrollment
        $suspendedUser = User::factory()->create(['role' => 'student']);
        CourseEnrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $suspendedUser->id,
            'status' => 'suspended',
        ]);

        // Override exists but user is suspended
        QuizOverride::factory()->create([
            'quiz_id' => $futureQuiz->id,
            'user_id' => $suspendedUser->id,
            'available_from' => now()->subHour(),
        ]);

        // Act: suspended user tries to start
        $response = $this->withHeader('X-Benchmark-Actor-Id', $suspendedUser->id)
            ->postJson("/api/quizzes/{$futureQuiz->id}/attempts");

        // Assert: blocked by enrollment check, override doesn't bypass auth
        $response->assertStatus(403);
    }

    #[Test]
    public function instructor_cannot_start_quiz_attempt_through_override_logic(): void
    {
        // Arrange: create instructor user with enrollment as instructor
        $instructor = User::factory()->create(['role' => 'instructor']);
        $course = Course::factory()->create(['instructor_id' => $instructor->id]);
        $quiz = Quiz::factory()->create(['course_id' => $course->id]);
        Question::factory()->count(3)->create(['quiz_id' => $quiz->id]);

        CourseEnrollment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $instructor->id,
            'role' => 'instructor',
        ]);

        // Instructor gets an override (theoretically)
        QuizOverride::factory()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $instructor->id,
            'available_from' => now()->subHour(),
        ]);

        // Act: instructor tries to start attempt
        $response = $this->withHeader('X-Benchmark-Actor-Id', $instructor->id)
            ->postJson("/api/quizzes/{$quiz->id}/attempts");

        // Assert: blocked because canAttemptQuiz requires student role
        $response->assertStatus(403);
    }

    #[Test]
    public function get_quiz_does_not_create_learning_module(): void
    {
        // Arrange: create quiz without a learning module
        $orphanQuiz = Quiz::factory()->create([
            'course_id' => $this->course->id,
        ]);
        // Delete the learning module that the factory created
        $orphanQuiz->learningModule()->delete();
        $orphanQuiz->load('learningModule');
        $this->assertNull($orphanQuiz->learningModule);

        // Act: attempt to read the quiz (should fail since no module)
        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->getJson("/api/quizzes/{$orphanQuiz->id}")
            ->assertStatus(404);

        // Assert: no learning module was created by the read path
        $this->assertNull(
            LearningModule::where('module_type', LearningModule::TYPE_QUIZ)
                ->where('module_id', $orphanQuiz->id)
                ->first()
        );
    }

    // ======== Plan 002: Quiz Aggregate Grade Tests ========

    public function test_first_submit_creates_quiz_grade(): void
    {
        $startResponse = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts", []);
        $attemptId = $startResponse->json('data.id');

        $questions = $this->quiz->questions;
        $answers = [];
        foreach ($questions as $question) {
            $answers[$question->id] = $question->correct_answer;
        }

        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->putJson("/api/quizzes/{$this->quiz->id}/attempts/{$attemptId}", [
                'answers' => $answers,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('quiz_grades', [
            'quiz_id' => $this->quiz->id,
            'user_id' => $this->user->id,
            'attempt_count' => 1,
        ]);
    }

    public function test_quiz_grade_reflects_highest_grading_method(): void
    {
        $this->quiz->update(['grading_method' => 'highest', 'max_attempts' => 3]);

        // First attempt — all correct (100%)
        $resp1 = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts", []);
        $id1 = $resp1->json('data.id');
        $allAnswers = [];
        foreach ($this->quiz->questions as $q) {
            $allAnswers[$q->id] = $q->correct_answer;
        }
        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->putJson("/api/quizzes/{$this->quiz->id}/attempts/{$id1}", ['answers' => $allAnswers])
            ->assertStatus(200);

        // Second attempt — all wrong (0%)
        $resp2 = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts", []);
        $id2 = $resp2->json('data.id');
        $wrongAnswers = [];
        foreach ($this->quiz->questions as $q) {
            $wrongAnswers[$q->id] = 'wrong';
        }
        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->putJson("/api/quizzes/{$this->quiz->id}/attempts/{$id2}", ['answers' => $wrongAnswers])
            ->assertStatus(200);

        $quizGrade = \App\Models\QuizGrade::where('quiz_id', $this->quiz->id)
            ->where('user_id', $this->user->id)
            ->first();

        $this->assertNotNull($quizGrade);
        // Highest should be 100 (question count * points per question)
        $this->assertEquals(100.0, (float) $quizGrade->grade);
        $this->assertEquals(2, $quizGrade->attempt_count);
    }

    public function test_quiz_grade_reflects_average_grading_method(): void
    {
        $this->quiz->update(['grading_method' => 'average', 'max_attempts' => 3]);

        // First attempt — all correct
        $resp1 = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts", []);
        $id1 = $resp1->json('data.id');
        $allAnswers = [];
        foreach ($this->quiz->questions as $q) {
            $allAnswers[$q->id] = $q->correct_answer;
        }
        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->putJson("/api/quizzes/{$this->quiz->id}/attempts/{$id1}", ['answers' => $allAnswers])
            ->assertStatus(200);

        // Second attempt — all wrong
        $resp2 = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts", []);
        $id2 = $resp2->json('data.id');
        $wrongAnswers = [];
        foreach ($this->quiz->questions as $q) {
            $wrongAnswers[$q->id] = 'wrong';
        }
        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->putJson("/api/quizzes/{$this->quiz->id}/attempts/{$id2}", ['answers' => $wrongAnswers])
            ->assertStatus(200);

        $quizGrade = \App\Models\QuizGrade::where('quiz_id', $this->quiz->id)
            ->where('user_id', $this->user->id)
            ->first();

        $this->assertNotNull($quizGrade);
        // Average: (100 + 0) / 2 = 50
        $this->assertEquals(50.0, (float) $quizGrade->grade);
        $this->assertEquals(2, $quizGrade->attempt_count);
    }

    public function test_quiz_grade_first_method_does_not_change_after_later_attempts(): void
    {
        $this->quiz->update(['grading_method' => 'first', 'max_attempts' => 3]);

        // First attempt — all correct
        $resp1 = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts", []);
        $id1 = $resp1->json('data.id');
        $allAnswers = [];
        foreach ($this->quiz->questions as $q) {
            $allAnswers[$q->id] = $q->correct_answer;
        }
        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->putJson("/api/quizzes/{$this->quiz->id}/attempts/{$id1}", ['answers' => $allAnswers])
            ->assertStatus(200);

        // Second attempt — all wrong
        $resp2 = $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->postJson("/api/quizzes/{$this->quiz->id}/attempts", []);
        $id2 = $resp2->json('data.id');
        $wrongAnswers = [];
        foreach ($this->quiz->questions as $q) {
            $wrongAnswers[$q->id] = 'wrong';
        }
        $this->withHeader('X-Benchmark-Actor-Id', $this->user->id)
            ->putJson("/api/quizzes/{$this->quiz->id}/attempts/{$id2}", ['answers' => $wrongAnswers])
            ->assertStatus(200);

        $quizGrade = \App\Models\QuizGrade::where('quiz_id', $this->quiz->id)
            ->where('user_id', $this->user->id)
            ->first();

        $this->assertNotNull($quizGrade);
        // First should stay at 100
        $this->assertEquals(100.0, (float) $quizGrade->grade);
    }
}
