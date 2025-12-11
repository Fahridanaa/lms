<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponseTrait;
use App\Services\QuizService;
use Illuminate\Http\Request;

class QuizController extends Controller
{
    use ApiResponseTrait;
    public function __construct(
        protected QuizService $quizService
    ) {
    }

    /**
     * Get all quizzes
     * GET /api/quizzes
     */
    public function index()
    {
        $quizzes = $this->quizService->getAllQuizzes();

        return $this->success($quizzes);
    }

    /**
     * Get quiz detail with questions
     * GET /api/quizzes/{id}
     */
    public function show(int $id)
    {
        $quiz = $this->quizService->getQuizWithQuestions($id);

        return $this->success($quiz);
    }

    /**
     * Get questions for a quiz
     * GET /api/quizzes/{id}/questions
     */
    public function questions(int $id)
    {
        $questions = $this->quizService->getQuizQuestions($id);

        return $this->success($questions);
    }

    /**
     * Start a quiz attempt
     * POST /api/quizzes/{id}/attempts
     */
    public function startAttempt(Request $request, int $id)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $attempt = $this->quizService->startQuizAttempt($id, $request->user_id);

        return $this->created($attempt, 'Quiz attempt started successfully');
    }

    /**
     * Submit quiz answers
     * PUT /api/quizzes/{quizId}/attempts/{attemptId}
     */
    public function submitAttempt(Request $request, int $quizId, int $attemptId)
    {
        $request->validate([
            'answers' => 'required|array',
        ]);

        $attempt = $this->quizService->submitQuizAnswers($attemptId, $request->answers);

        return $this->success($attempt, 'Quiz submitted successfully');
    }

    /**
     * Get attempt result
     * GET /api/quizzes/{quizId}/attempts/{attemptId}/result
     */
    public function attemptResult(int $quizId, int $attemptId)
    {
        $result = $this->quizService->getAttemptResult($attemptId);

        return $this->success($result);
    }

    /**
     * Get user's quiz attempts
     * GET /api/users/{userId}/quiz-attempts?quiz_id={quizId}
     */
    public function userAttempts(Request $request, int $userId)
    {
        $quizId = $request->query('quiz_id');
        $attempts = $this->quizService->getUserQuizAttempts($userId, $quizId);

        return $this->success($attempts);
    }
}
